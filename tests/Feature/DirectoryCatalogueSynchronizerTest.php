<?php

namespace Tests\Feature;

use App\Core\Accounts\AuthorizationState;
use App\Core\Accounts\DirectoryGroup;
use App\Core\Accounts\DirectorySyncRun;
use App\Exceptions\DirectoryUnavailable;
use App\Services\DirectoryCatalogueSynchronizer;
use App\Services\DirectoryUserSynchronizer;
use App\Services\LdapGroupResolver;
use App\Support\DirectoryGroupPrefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\FakeDirectoryUserSynchronizer;
use Tests\TestCase;
use Throwable;

class DirectoryCatalogueSynchronizerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(
            DirectoryUserSynchronizer::class,
            new FakeDirectoryUserSynchronizer,
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_success_atomically_tracks_present_and_missing_groups_without_deleting_mappings(): void
    {
        Carbon::setTestNow('2026-07-28 09:00:00');
        $resolver = $this->catalogueResolver([
            [
                'name' => 'myapesaccount.staff',
                'external_id' => '4101',
                'member_count' => 7,
            ],
            [
                'name' => 'myapes.empty',
                'external_id' => null,
                'member_count' => 0,
            ],
        ]);
        $mappingCount = DB::table('directory_group_role_mappings')->count();
        $transactionLevelBeforeSync = DB::transactionLevel();

        $run = app(DirectoryCatalogueSynchronizer::class)->synchronize(
            DirectorySyncRun::SOURCE_MANUAL,
        );

        $this->assertSame(
            $transactionLevelBeforeSync,
            $resolver->transactionLevelDuringFetch,
        );
        $this->assertSame(DirectorySyncRun::STATUS_SUCCEEDED, $run->status);
        $this->assertSame(1, $run->groups_seen);
        $this->assertSame(5, $run->groups_missing);
        $this->assertDatabaseHas('directory_groups', [
            'name' => 'myapesaccount.staff',
            'external_id' => '4101',
            'member_count' => 7,
            'status' => DirectoryGroup::STATUS_PRESENT,
            'app_enabled' => true,
            'first_seen_at' => '2026-07-28 09:00:00',
            'last_seen_at' => '2026-07-28 09:00:00',
            'last_synced_at' => '2026-07-28 09:00:00',
        ]);
        $this->assertDatabaseMissing('directory_groups', [
            'name' => 'myapes.empty',
        ]);
        $this->assertDatabaseHas('directory_groups', [
            'name' => 'myapesaccount.admin',
            'status' => DirectoryGroup::STATUS_MISSING,
            'app_enabled' => true,
            'last_synced_at' => '2026-07-28 09:00:00',
        ]);
        $this->assertSame(
            $mappingCount,
            DB::table('directory_group_role_mappings')->count(),
        );

        Carbon::setTestNow('2026-07-28 10:00:00');
        $resolver->catalogue = [[
            'name' => 'myapesaccount.staff',
            'external_id' => '4101',
            'member_count' => 8,
        ]];

        $repeated = app(DirectoryCatalogueSynchronizer::class)->synchronize(
            DirectorySyncRun::SOURCE_SCHEDULED,
        );

        $this->assertSame(1, $repeated->groups_seen);
        $this->assertSame(5, $repeated->groups_missing);
        $this->assertSame(6, DirectoryGroup::query()->count());
        $staff = DirectoryGroup::query()
            ->where('name', 'myapesaccount.staff')
            ->firstOrFail();
        $this->assertSame('2026-07-28 09:00:00', $staff->first_seen_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-28 10:00:00', $staff->last_seen_at?->format('Y-m-d H:i:s'));
        $this->assertSame(8, $staff->member_count);
        $this->assertDatabaseMissing('directory_groups', [
            'name' => 'myapes.empty',
        ]);
        $this->assertSame(
            $mappingCount,
            DB::table('directory_group_role_mappings')->count(),
        );
    }

    public function test_directory_failure_preserves_catalogue_and_mappings_and_records_only_a_safe_code(): void
    {
        Carbon::setTestNow('2026-07-28 09:00:00');
        DirectoryGroup::query()
            ->where('name', 'myapesaccount.staff')
            ->update([
                'external_id' => '4101',
                'member_count' => 7,
                'status' => DirectoryGroup::STATUS_PRESENT,
                'first_seen_at' => now()->subDay(),
                'last_seen_at' => now()->subHour(),
                'last_synced_at' => now()->subHour(),
            ]);
        $groupsBefore = DB::table('directory_groups')->orderBy('id')->get();
        $mappingsBefore = DB::table('directory_group_role_mappings')
            ->orderBy('id')
            ->get();
        $resolver = $this->catalogueResolver([]);
        $resolver->failure = new DirectoryUnavailable(
            'ldap-password-canary for member-identifier-canary',
        );

        try {
            app(DirectoryCatalogueSynchronizer::class)->synchronize(
                DirectorySyncRun::SOURCE_MANUAL,
            );
            $this->fail('The catalogue synchronization accepted a directory outage.');
        } catch (DirectoryUnavailable $exception) {
            $this->assertSame(
                'Directory catalogue synchronization is unavailable.',
                $exception->getMessage(),
            );
        }

        $this->assertEquals(
            $groupsBefore,
            DB::table('directory_groups')->orderBy('id')->get(),
        );
        $this->assertEquals(
            $mappingsBefore,
            DB::table('directory_group_role_mappings')->orderBy('id')->get(),
        );
        $this->assertDatabaseHas('directory_sync_runs', [
            'source' => DirectorySyncRun::SOURCE_MANUAL,
            'status' => DirectorySyncRun::STATUS_FAILED,
            'groups_seen' => 0,
            'groups_missing' => 0,
            'error_code' => 'directory_unavailable',
        ]);
        $this->assertStringNotContainsString(
            'canary',
            DB::table('directory_sync_runs')->get()->toJson(),
        );
        $this->assertDatabaseHas('authorization_states', [
            'id' => AuthorizationState::SINGLETON_ID,
            'directory_sync_owner_token' => null,
            'directory_sync_expires_at' => null,
        ]);

        $resolver->failure = null;
        $resolver->catalogue = [[
            'name' => 'myapesaccount.staff',
            'external_id' => '4101',
            'member_count' => 7,
        ]];
        $recovery = app(DirectoryCatalogueSynchronizer::class)->synchronize(
            DirectorySyncRun::SOURCE_SCHEDULED,
        );

        $this->assertSame(
            DirectorySyncRun::STATUS_SUCCEEDED,
            $recovery->status,
        );
    }

    public function test_invalid_complete_result_is_rejected_before_catalogue_mutation(): void
    {
        $groupsBefore = DB::table('directory_groups')->orderBy('id')->get();
        $resolver = $this->catalogueResolver([
            [
                'name' => 'myapesaccount.staff',
                'external_id' => '4101',
                'member_count' => 7,
            ],
            [
                'name' => 'myapesaccount.staff',
                'external_id' => '4102',
                'member_count' => 1,
            ],
        ]);
        $transactionLevelBeforeSync = DB::transactionLevel();

        try {
            app(DirectoryCatalogueSynchronizer::class)->synchronize(
                DirectorySyncRun::SOURCE_SCHEDULED,
            );
            $this->fail('The catalogue synchronization accepted duplicate groups.');
        } catch (DirectoryUnavailable $exception) {
            $this->assertSame(
                'Directory catalogue synchronization is unavailable.',
                $exception->getMessage(),
            );
        }

        $this->assertSame(
            $transactionLevelBeforeSync,
            $resolver->transactionLevelDuringFetch,
        );
        $this->assertEquals(
            $groupsBefore,
            DB::table('directory_groups')->orderBy('id')->get(),
        );
        $this->assertDatabaseHas('directory_sync_runs', [
            'source' => DirectorySyncRun::SOURCE_SCHEDULED,
            'status' => DirectorySyncRun::STATUS_FAILED,
            'error_code' => 'invalid_catalogue',
        ]);
    }

    public function test_catalogue_mutex_is_independent_of_cache_configuration(): void
    {
        config([
            'cache.default' => 'array',
            'myapes.directory.sync_lock_store' => 'array',
        ]);
        $this->catalogueResolver([[
            'name' => 'myapesaccount.staff',
            'external_id' => '4101',
            'member_count' => 7,
        ]]);

        $run = app(DirectoryCatalogueSynchronizer::class)->synchronize(
            DirectorySyncRun::SOURCE_MANUAL,
        );

        $this->assertSame(DirectorySyncRun::STATUS_SUCCEEDED, $run->status);
        $this->assertDatabaseHas('directory_groups', [
            'name' => 'myapesaccount.staff',
            'status' => DirectoryGroup::STATUS_PRESENT,
        ]);
    }

    public function test_expired_worker_is_fenced_before_catalogue_commit(): void
    {
        Carbon::setTestNow('2026-07-28 09:00:00');
        config(['myapes.directory.sync_lock_seconds' => 30]);
        $groupsBefore = DB::table('directory_groups')->orderBy('id')->get();
        $resolver = $this->catalogueResolver([[
            'name' => 'myapesaccount.staff',
            'external_id' => 'stale-external-canary',
            'member_count' => 11,
        ]]);
        $resolver->onEnumerate = static function (): void {
            Carbon::setTestNow('2026-07-28 09:00:31');
        };

        try {
            app(DirectoryCatalogueSynchronizer::class)->synchronize(
                DirectorySyncRun::SOURCE_MANUAL,
            );
            $this->fail('An expired worker committed catalogue changes.');
        } catch (DirectoryUnavailable $exception) {
            $this->assertSame(
                'Directory catalogue synchronization is unavailable.',
                $exception->getMessage(),
            );
        }

        $this->assertEquals(
            $groupsBefore,
            DB::table('directory_groups')->orderBy('id')->get(),
        );
        $this->assertDatabaseHas('directory_sync_runs', [
            'source' => DirectorySyncRun::SOURCE_MANUAL,
            'status' => DirectorySyncRun::STATUS_FAILED,
            'error_code' => 'catalogue_persistence_failed',
        ]);
        $this->assertDatabaseHas('authorization_states', [
            'id' => AuthorizationState::SINGLETON_ID,
            'directory_sync_owner_token' => null,
            'directory_sync_expires_at' => null,
        ]);
    }

    public function test_worker_expiring_during_catalogue_transaction_is_rolled_back(): void
    {
        Carbon::setTestNow('2026-07-28 09:00:00');
        config(['myapes.directory.sync_lock_seconds' => 30]);
        DirectoryGroup::query()->where('name', 'myapesaccount.staff')->delete();
        $groupsBefore = DB::table('directory_groups')->orderBy('id')->get();
        $resolver = $this->catalogueResolver([[
            'name' => 'myapesaccount.staff',
            'external_id' => 'transaction-expiry-canary',
            'member_count' => 5,
        ]]);
        $advanced = false;
        DB::listen(static function (object $query) use (&$advanced): void {
            if (! $advanced
                && preg_match(
                    '/insert\s+into\s+.+directory_groups/i',
                    $query->sql,
                ) === 1) {
                $advanced = true;
                Carbon::setTestNow('2026-07-28 09:00:31');
            }
        });

        try {
            app(DirectoryCatalogueSynchronizer::class)->synchronize(
                DirectorySyncRun::SOURCE_MANUAL,
            );
            $this->fail('A worker committed after expiring during persistence.');
        } catch (DirectoryUnavailable $exception) {
            $this->assertSame(
                'Directory catalogue synchronization is unavailable.',
                $exception->getMessage(),
            );
        }

        $this->assertTrue($advanced);
        $this->assertEquals(
            $groupsBefore,
            DB::table('directory_groups')->orderBy('id')->get(),
        );
        $this->assertDatabaseHas('directory_sync_runs', [
            'source' => DirectorySyncRun::SOURCE_MANUAL,
            'status' => DirectorySyncRun::STATUS_FAILED,
            'error_code' => 'catalogue_persistence_failed',
        ]);
    }

    public function test_expired_lease_takeover_fences_the_stale_worker(): void
    {
        Carbon::setTestNow('2026-07-28 09:00:00');
        config(['myapes.directory.sync_lock_seconds' => 30]);
        $resolver = $this->catalogueResolver([[
            'name' => 'myapesaccount.staff',
            'external_id' => 'stale-external-canary',
            'member_count' => 11,
        ]]);
        $takeoverRun = null;
        $resolver->onEnumerate = function () use (
            $resolver,
            &$takeoverRun,
        ): void {
            Carbon::setTestNow('2026-07-28 09:00:31');
            $resolver->catalogue = [[
                'name' => 'myapesaccount.admin',
                'external_id' => '4202',
                'member_count' => 4,
            ]];
            $takeoverRun = app(DirectoryCatalogueSynchronizer::class)
                ->synchronize(DirectorySyncRun::SOURCE_SCHEDULED);
        };

        try {
            app(DirectoryCatalogueSynchronizer::class)->synchronize(
                DirectorySyncRun::SOURCE_MANUAL,
            );
            $this->fail('A stale worker committed after lease takeover.');
        } catch (DirectoryUnavailable $exception) {
            $this->assertSame(
                'Directory catalogue synchronization is unavailable.',
                $exception->getMessage(),
            );
        }

        $this->assertInstanceOf(DirectorySyncRun::class, $takeoverRun);
        $this->assertSame(
            DirectorySyncRun::STATUS_SUCCEEDED,
            $takeoverRun?->status,
        );
        $this->assertDatabaseHas('directory_groups', [
            'name' => 'myapesaccount.admin',
            'external_id' => '4202',
            'member_count' => 4,
            'status' => DirectoryGroup::STATUS_PRESENT,
        ]);
        $this->assertDatabaseHas('directory_groups', [
            'name' => 'myapesaccount.staff',
            'status' => DirectoryGroup::STATUS_MISSING,
        ]);
        $this->assertDatabaseMissing('directory_groups', [
            'external_id' => 'stale-external-canary',
        ]);
        $this->assertDatabaseHas('directory_sync_runs', [
            'source' => DirectorySyncRun::SOURCE_MANUAL,
            'status' => DirectorySyncRun::STATUS_FAILED,
            'error_code' => 'catalogue_persistence_failed',
        ]);
        $this->assertDatabaseHas('directory_sync_runs', [
            'source' => DirectorySyncRun::SOURCE_SCHEDULED,
            'status' => DirectorySyncRun::STATUS_SUCCEEDED,
        ]);
        $this->assertDatabaseCount('directory_sync_runs', 2);
        $this->assertDatabaseHas('authorization_states', [
            'id' => AuthorizationState::SINGLETON_ID,
            'directory_sync_owner_token' => null,
            'directory_sync_expires_at' => null,
        ]);
    }

    public function test_stale_worker_does_not_clear_a_newer_unexpired_owner(): void
    {
        Carbon::setTestNow('2026-07-28 09:00:00');
        config(['myapes.directory.sync_lock_seconds' => 30]);
        $resolver = $this->catalogueResolver([[
            'name' => 'myapesaccount.staff',
            'external_id' => 'stale-external-canary',
            'member_count' => 11,
        ]]);
        $resolver->onEnumerate = static function (): void {
            Carbon::setTestNow('2026-07-28 09:00:31');
            DB::table('authorization_states')
                ->where('id', AuthorizationState::SINGLETON_ID)
                ->update([
                    'directory_sync_owner_token' => 'replacement-owner',
                    'directory_sync_expires_at' => now()->addMinutes(5),
                    'updated_at' => now(),
                ]);
        };

        try {
            app(DirectoryCatalogueSynchronizer::class)->synchronize(
                DirectorySyncRun::SOURCE_MANUAL,
            );
            $this->fail('A stale worker committed after its owner changed.');
        } catch (DirectoryUnavailable $exception) {
            $this->assertSame(
                'Directory catalogue synchronization is unavailable.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseHas('authorization_states', [
            'id' => AuthorizationState::SINGLETON_ID,
            'directory_sync_owner_token' => 'replacement-owner',
            'directory_sync_expires_at' => '2026-07-28 09:05:31',
        ]);
        $this->assertDatabaseMissing('directory_groups', [
            'external_id' => 'stale-external-canary',
        ]);
    }

    public function test_concurrent_manual_and_scheduled_attempts_share_one_service_mutex(): void
    {
        Carbon::setTestNow('2026-07-28 09:00:00');
        config(['myapes.directory.sync_lock_seconds' => 9999]);
        $groupsBefore = DB::table('directory_groups')->orderBy('id')->get();
        $resolver = $this->catalogueResolver([[
            'name' => 'myapesaccount.staff',
            'external_id' => '4101',
            'member_count' => 7,
        ]]);
        $concurrentFailure = null;
        $catalogueDuringConcurrentAttempt = null;
        $runsDuringConcurrentAttempt = null;
        $leaseDuringConcurrentAttempt = null;

        $resolver->onEnumerate = function () use (
            &$concurrentFailure,
            &$catalogueDuringConcurrentAttempt,
            &$runsDuringConcurrentAttempt,
            &$leaseDuringConcurrentAttempt,
        ): void {
            $catalogueDuringConcurrentAttempt = DB::table('directory_groups')
                ->orderBy('id')
                ->get();
            $leaseDuringConcurrentAttempt = DB::table('authorization_states')
                ->where('id', AuthorizationState::SINGLETON_ID)
                ->first([
                    'directory_sync_owner_token',
                    'directory_sync_expires_at',
                ]);

            try {
                app(DirectoryCatalogueSynchronizer::class)->synchronize(
                    DirectorySyncRun::SOURCE_SCHEDULED,
                );
            } catch (Throwable $exception) {
                $concurrentFailure = $exception;
            }

            $runsDuringConcurrentAttempt = DirectorySyncRun::query()->count();
        };

        $run = app(DirectoryCatalogueSynchronizer::class)->synchronize(
            DirectorySyncRun::SOURCE_MANUAL,
        );

        $this->assertInstanceOf(
            DirectoryUnavailable::class,
            $concurrentFailure,
        );
        $this->assertSame(
            'Directory catalogue synchronization is already running.',
            $concurrentFailure?->getMessage(),
        );
        $this->assertEquals($groupsBefore, $catalogueDuringConcurrentAttempt);
        $this->assertIsString(
            $leaseDuringConcurrentAttempt?->directory_sync_owner_token,
        );
        $this->assertLessThanOrEqual(
            64,
            strlen(
                $leaseDuringConcurrentAttempt?->directory_sync_owner_token,
            ),
        );
        $this->assertSame(
            '2026-07-28 09:15:00',
            $leaseDuringConcurrentAttempt?->directory_sync_expires_at,
        );
        $this->assertSame(1, $runsDuringConcurrentAttempt);
        $this->assertSame(DirectorySyncRun::STATUS_SUCCEEDED, $run->status);
        $this->assertDatabaseCount('directory_sync_runs', 1);
        $this->assertDatabaseHas('authorization_states', [
            'id' => AuthorizationState::SINGLETON_ID,
            'directory_sync_owner_token' => null,
            'directory_sync_expires_at' => null,
        ]);
    }

    public function test_synchronizer_drops_directory_noise_and_keeps_required_groups_enabled(): void
    {
        Carbon::setTestNow('2026-08-25 09:00:00');
        DirectoryGroup::query()->create([
            'name' => 'intranet.managers',
            'status' => DirectoryGroup::STATUS_PRESENT,
            'member_count' => 3,
            'app_enabled' => false,
            'first_seen_at' => now()->subDay(),
            'last_seen_at' => now()->subDay(),
            'last_synced_at' => now()->subDay(),
        ]);

        $this->rawCatalogueResolver([
            [
                'name' => 'myapesaccount.staff',
                'external_id' => '5101',
                'member_count' => 4,
            ],
            [
                'name' => 'intranet.managers',
                'external_id' => '99',
                'member_count' => 12,
            ],
            [
                'name' => 'users',
                'external_id' => null,
                'member_count' => 100,
            ],
            [
                'name' => 'myapesaccount.ops-review',
                'external_id' => '5102',
                'member_count' => 2,
            ],
        ]);

        $run = app(DirectoryCatalogueSynchronizer::class)->synchronize(
            DirectorySyncRun::SOURCE_MANUAL,
        );

        $this->assertSame(DirectorySyncRun::STATUS_SUCCEEDED, $run->status);
        $this->assertSame(2, $run->groups_seen);
        $this->assertDatabaseHas('directory_groups', [
            'name' => 'myapesaccount.staff',
            'status' => DirectoryGroup::STATUS_PRESENT,
            'app_enabled' => true,
            'member_count' => 4,
        ]);
        $this->assertDatabaseHas('directory_groups', [
            'name' => 'myapesaccount.ops-review',
            'status' => DirectoryGroup::STATUS_PRESENT,
            'app_enabled' => true,
            'member_count' => 2,
        ]);
        $this->assertDatabaseMissing('directory_groups', [
            'name' => 'users',
        ]);
        $this->assertDatabaseHas('directory_groups', [
            'name' => 'intranet.managers',
            'status' => DirectoryGroup::STATUS_PRESENT,
            'member_count' => 3,
        ]);
        $this->assertDatabaseHas('directory_groups', [
            'name' => 'myapesaccount.admin',
            'status' => DirectoryGroup::STATUS_MISSING,
            'app_enabled' => true,
        ]);
        $this->assertDatabaseHas('directory_groups', [
            'name' => 'myapesaccount.volunteer',
            'status' => DirectoryGroup::STATUS_MISSING,
            'app_enabled' => true,
        ]);
        $this->assertDatabaseHas('directory_groups', [
            'name' => 'myapesaccount.student',
            'status' => DirectoryGroup::STATUS_MISSING,
            'app_enabled' => true,
        ]);
    }

    /**
     * @param  array<int, array{name: string, external_id: ?string, member_count: int}>  $catalogue
     */
    private function catalogueResolver(array $catalogue): LdapGroupResolver
    {
        $resolver = new class extends LdapGroupResolver
        {
            /**
             * @var array<int, array{name: string, external_id: ?string, member_count: int}>
             */
            public array $catalogue = [];

            public ?Throwable $failure = null;

            public int $transactionLevelDuringFetch = -1;

            public ?\Closure $onEnumerate = null;

            /**
             * @return array<int, array{name: string, external_id: ?string, member_count: int}>
             */
            public function enumerateGroups(): array
            {
                $this->transactionLevelDuringFetch = DB::transactionLevel();
                $catalogue = $this->catalogue;

                if ($this->onEnumerate instanceof \Closure) {
                    $callback = $this->onEnumerate;
                    $this->onEnumerate = null;
                    $callback();
                }

                if ($this->failure !== null) {
                    throw $this->failure;
                }

                return array_values(array_filter(
                    $catalogue,
                    static fn (array $group): bool => DirectoryGroupPrefix::isManagedGroup(
                        $group['name'],
                    ),
                ));
            }
        };
        $resolver->catalogue = $catalogue;
        $this->app->instance(LdapGroupResolver::class, $resolver);

        return $resolver;
    }

    /**
     * @param  array<int, array{name: string, external_id: ?string, member_count: int}>  $catalogue
     */
    private function rawCatalogueResolver(array $catalogue): LdapGroupResolver
    {
        $resolver = new class extends LdapGroupResolver
        {
            /**
             * @var array<int, array{name: string, external_id: ?string, member_count: int}>
             */
            public array $catalogue = [];

            /**
             * @return array<int, array{name: string, external_id: ?string, member_count: int}>
             */
            public function enumerateGroups(): array
            {
                return $this->catalogue;
            }
        };
        $resolver->catalogue = $catalogue;
        $this->app->instance(LdapGroupResolver::class, $resolver);

        return $resolver;
    }
}
