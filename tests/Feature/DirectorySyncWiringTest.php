<?php

namespace Tests\Feature;

use App\Core\Accounts\AuthorizationState;
use App\Core\Accounts\DirectorySyncRun;
use App\Core\Accounts\User;
use App\Exceptions\DirectorySyncInProgress;
use App\Exceptions\DirectoryUnavailable;
use App\Jobs\RunDirectorySync;
use App\Services\DirectoryCatalogueSynchronizer;
use App\Services\DirectoryUserSynchronizer;
use App\Services\LdapGroupResolver;
use App\Services\ManualDirectorySyncQueueResolver;
use App\Services\SessionAuthorizationContext;
use DomainException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Jobs\FakeJob;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Fakes\FakeDirectoryUserSynchronizer;
use Tests\TestCase;
use Throwable;

class DirectorySyncWiringTest extends TestCase
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

    public function test_directory_sync_command_reports_only_counts_and_missing_warning(): void
    {
        $this->installResolver([[
            'name' => 'myapesaccount.staff',
            'external_id' => 'member-identifier-canary-must-not-print',
            'member_count' => 4,
        ]]);

        $exitCode = Artisan::call('myapes:directory-sync', [
            '--source' => DirectorySyncRun::SOURCE_MANUAL,
            '--no-interaction' => true,
            '--no-ansi' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString(
            'Directory catalogue: ok (1 seen, 3 missing)',
            $output,
        );
        $this->assertStringContainsString(
            'Directory groups: warning (3 missing)',
            $output,
        );
        $this->assertStringNotContainsString('myapesaccount.staff', $output);
        $this->assertStringNotContainsString('member-identifier-canary', $output);
    }

    public function test_directory_sync_command_rejects_invalid_sources_and_sanitizes_failures(): void
    {
        $resolver = $this->installResolver([]);
        $resolver->failure = new DirectoryUnavailable(
            'ldap-password-canary member-identifier-canary',
        );

        $failed = Artisan::call('myapes:directory-sync', [
            '--source' => DirectorySyncRun::SOURCE_MANUAL,
            '--no-interaction' => true,
            '--no-ansi' => true,
        ]);
        $failureOutput = Artisan::output();
        $invalid = Artisan::call('myapes:directory-sync', [
            '--source' => 'interactive-canary',
            '--no-interaction' => true,
            '--no-ansi' => true,
        ]);
        $invalidOutput = Artisan::output();

        $this->assertSame(1, $failed);
        $this->assertStringContainsString(
            'Directory catalogue: failed (directory_unavailable)',
            $failureOutput,
        );
        $this->assertStringNotContainsString('ldap-password-canary', $failureOutput);
        $this->assertStringNotContainsString('member-identifier-canary', $failureOutput);
        $this->assertSame(1, $invalid);
        $this->assertStringContainsString(
            'Directory catalogue: failed (invalid_source)',
            $invalidOutput,
        );
        $this->assertStringNotContainsString('interactive-canary', $invalidOutput);
        $this->assertDatabaseCount('directory_sync_runs', 1);
    }

    public function test_directory_sync_command_sanitizes_an_unexpected_service_failure(): void
    {
        $this->mock(
            DirectoryCatalogueSynchronizer::class,
            function (MockInterface $mock): void {
                $mock->shouldReceive('synchronize')
                    ->once()
                    ->andThrow(new RuntimeException(
                        'database-host-canary member-identifier-canary',
                    ));
            },
        );

        $exitCode = Artisan::call('myapes:directory-sync', [
            '--source' => DirectorySyncRun::SOURCE_MANUAL,
            '--no-interaction' => true,
            '--no-ansi' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            'Directory catalogue: failed (synchronization_failed)',
            $output,
        );
        $this->assertStringNotContainsString('database-host-canary', $output);
        $this->assertStringNotContainsString('member-identifier-canary', $output);
    }

    public function test_directory_sync_command_reports_a_stable_sanitized_lock_conflict(): void
    {
        $this->mock(
            DirectoryCatalogueSynchronizer::class,
            function (MockInterface $mock): void {
                $mock->shouldReceive('synchronize')
                    ->once()
                    ->andThrow(new DirectorySyncInProgress(
                        'lock-owner-canary member-identifier-canary',
                    ));
            },
        );

        $exitCode = Artisan::call('myapes:directory-sync', [
            '--source' => DirectorySyncRun::SOURCE_SCHEDULED,
            '--no-interaction' => true,
            '--no-ansi' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            'Directory catalogue: failed (sync_in_progress)',
            $output,
        );
        $this->assertStringNotContainsString('lock-owner-canary', $output);
        $this->assertStringNotContainsString(
            'member-identifier-canary',
            $output,
        );
    }

    public function test_manual_job_is_queued_and_runs_the_catalogue_service_with_manual_source(): void
    {
        Queue::fake();
        RunDirectorySync::dispatch();

        Queue::assertPushed(
            RunDirectorySync::class,
            static fn (RunDirectorySync $job): bool => $job instanceof ShouldQueue
                && $job instanceof ShouldBeUnique
                && $job->source === DirectorySyncRun::SOURCE_MANUAL
                && $job->uniqueId() === 'directory-catalogue-sync'
                && $job->tries === 3
                && $job->timeout === 240
                && $job->uniqueFor() === 1350
                && $job->failOnTimeout
                && $job->backoff() === [30, 120],
        );

        $this->installResolver([[
            'name' => 'myapesaccount.staff',
            'external_id' => null,
            'member_count' => 1,
        ]]);
        config([
            'queue.default' => 'database',
            'queue.connections.database.retry_after' => 300,
        ]);
        (new RunDirectorySync)->handle(
            app(DirectoryCatalogueSynchronizer::class),
            app(ManualDirectorySyncQueueResolver::class),
        );

        $this->assertDatabaseHas('directory_sync_runs', [
            'source' => DirectorySyncRun::SOURCE_MANUAL,
            'status' => DirectorySyncRun::STATUS_SUCCEEDED,
            'groups_seen' => 1,
        ]);
    }

    public function test_queue_resolver_failure_records_a_synthetic_terminal_attempt(): void
    {
        $uuid = '11111111-1111-4111-8111-111111111111';
        $job = $this->queuedJob($uuid, 1);
        $queueResolver = $this->mock(
            ManualDirectorySyncQueueResolver::class,
            function (MockInterface $mock): void {
                $mock->shouldReceive('resolve')
                    ->once()
                    ->andThrow(new DomainException(
                        'queue-credential-canary member-identifier-canary',
                    ));
            },
        );
        $synchronizer = $this->mock(
            DirectoryCatalogueSynchronizer::class,
            static function (MockInterface $mock): void {
                $mock->shouldNotReceive('synchronize');
            },
        );

        try {
            $job->handle($synchronizer, $queueResolver);
            $this->fail('The invalid queue configuration should fail closed.');
        } catch (DomainException $exception) {
            $job->failed($exception);
        }

        $this->assertDatabaseHas('directory_sync_runs', [
            'source' => DirectorySyncRun::SOURCE_MANUAL,
            'status' => DirectorySyncRun::STATUS_FAILED,
            'queue_job_uuid' => $uuid,
            'queue_attempt' => 1,
            'lease_owner_token' => null,
            'error_code' => 'job_failed',
        ]);
        $this->assertStringNotContainsString(
            'canary',
            DirectorySyncRun::query()->get()->toJson(),
        );
    }

    public function test_retry_attempts_have_distinct_idempotent_correlations(): void
    {
        $uuid = '22222222-2222-4222-8222-222222222222';
        $firstAttempt = $this->queuedJob($uuid, 1);
        $secondAttempt = $this->queuedJob($uuid, 2);

        $firstAttempt->failed(new RuntimeException('first-attempt-canary'));
        $secondAttempt->failed(new RuntimeException('second-attempt-canary'));
        $secondAttempt->failed(new RuntimeException('repeated-callback-canary'));

        $this->assertDatabaseCount('directory_sync_runs', 2);
        foreach ([1, 2] as $attempt) {
            $this->assertDatabaseHas('directory_sync_runs', [
                'queue_job_uuid' => $uuid,
                'queue_attempt' => $attempt,
                'status' => DirectorySyncRun::STATUS_FAILED,
                'error_code' => 'job_failed',
            ]);
        }
        $this->assertStringNotContainsString(
            'canary',
            DirectorySyncRun::query()->get()->toJson(),
        );
    }

    public function test_terminal_failure_recorder_avoids_eloquent_reentrancy(): void
    {
        $source = (string) file_get_contents(app_path(
            'Services/DirectorySyncTerminalFailureRecorder.php',
        ));

        $this->assertStringNotContainsString(
            'AuthorizationState::query',
            $source,
        );
        $this->assertStringNotContainsString(
            'DirectorySyncRun::query',
            $source,
        );
        $this->assertStringNotContainsString('->save(', $source);
        $this->assertStringContainsString(
            "DB::table('authorization_states')",
            $source,
        );
        $this->assertStringContainsString(
            "DB::table('directory_sync_runs')",
            $source,
        );
    }

    public function test_final_failure_creates_one_correlated_generation_and_stales_the_session(): void
    {
        $user = User::factory()
            ->accessLevel(User::ROLE_STAFF)
            ->cloudronIdentity('terminal-failure-test-subject')
            ->create();
        $context = app(SessionAuthorizationContext::class);
        $this->actingAs($user);
        if (! request()->hasSession()) {
            request()->setLaravelSession(app('session')->driver());
        }
        request()->session()->put(
            $context->valuesFor(
                $user->refresh(),
                SessionAuthorizationContext::METHOD_CLOUDRON_OIDC,
                now()->timestamp,
            ),
        );
        $this->assertTrue(
            $context->permitsDirectoryRestricted(request(), $user->refresh()),
        );

        $job = $this->queuedJob(
            'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            3,
        );
        $exception = new RuntimeException(
            'ldap-password-canary member-identifier-canary',
        );

        $job->failed($exception);
        $job->failed($exception);

        $this->assertDatabaseCount('directory_sync_runs', 1);
        $this->assertDatabaseHas('directory_sync_runs', [
            'source' => DirectorySyncRun::SOURCE_MANUAL,
            'status' => DirectorySyncRun::STATUS_FAILED,
            'queue_job_uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'queue_attempt' => 3,
            'lease_owner_token' => null,
            'groups_seen' => 0,
            'groups_missing' => 0,
            'error_code' => 'job_failed',
        ]);
        $this->assertFalse(
            $context->permitsDirectoryRestricted(request(), $user->refresh()),
        );
        $this->assertStringNotContainsString(
            'canary',
            DirectorySyncRun::query()->get()->toJson(),
        );
    }

    public function test_final_failure_only_finalizes_its_run_and_matching_lease(): void
    {
        $matchingOwner = str_repeat('a', 64);
        $matching = DirectorySyncRun::query()->create([
            'source' => DirectorySyncRun::SOURCE_SCHEDULED,
            'status' => DirectorySyncRun::STATUS_RUNNING,
            'queue_job_uuid' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            'queue_attempt' => 2,
            'lease_owner_token' => $matchingOwner,
            'started_at' => now(),
            'groups_seen' => 0,
            'groups_missing' => 0,
        ]);
        AuthorizationState::query()
            ->whereKey(AuthorizationState::SINGLETON_ID)
            ->update([
                'directory_sync_owner_token' => $matchingOwner,
                'directory_sync_expires_at' => now()->addMinutes(5),
            ]);

        $this->queuedJob(
            'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            2,
            DirectorySyncRun::SOURCE_SCHEDULED,
        )->failed(new RuntimeException('timeout-canary'));

        $this->assertDatabaseHas('directory_sync_runs', [
            'id' => $matching->id,
            'status' => DirectorySyncRun::STATUS_FAILED,
            'error_code' => 'job_failed',
        ]);
        $this->assertDatabaseHas('authorization_states', [
            'id' => AuthorizationState::SINGLETON_ID,
            'directory_sync_owner_token' => null,
            'directory_sync_expires_at' => null,
        ]);

        $olderOwner = str_repeat('b', 64);
        $newerOwner = str_repeat('c', 64);
        $older = DirectorySyncRun::query()->create([
            'source' => DirectorySyncRun::SOURCE_MANUAL,
            'status' => DirectorySyncRun::STATUS_RUNNING,
            'queue_job_uuid' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
            'queue_attempt' => 1,
            'lease_owner_token' => $olderOwner,
            'started_at' => now(),
            'groups_seen' => 0,
            'groups_missing' => 0,
        ]);
        $newer = DirectorySyncRun::query()->create([
            'source' => DirectorySyncRun::SOURCE_SCHEDULED,
            'status' => DirectorySyncRun::STATUS_RUNNING,
            'queue_job_uuid' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
            'queue_attempt' => 1,
            'lease_owner_token' => $newerOwner,
            'started_at' => now()->addSecond(),
            'groups_seen' => 0,
            'groups_missing' => 0,
        ]);
        AuthorizationState::query()
            ->whereKey(AuthorizationState::SINGLETON_ID)
            ->update([
                'directory_sync_owner_token' => $newerOwner,
                'directory_sync_expires_at' => now()->addMinutes(5),
            ]);

        $this->queuedJob(
            'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
            1,
        )->failed(new RuntimeException('older-timeout-canary'));

        $this->assertDatabaseHas('directory_sync_runs', [
            'id' => $older->id,
            'status' => DirectorySyncRun::STATUS_FAILED,
            'error_code' => 'job_failed',
        ]);
        $this->assertDatabaseHas('directory_sync_runs', [
            'id' => $newer->id,
            'status' => DirectorySyncRun::STATUS_RUNNING,
            'error_code' => null,
        ]);
        $this->assertDatabaseHas('authorization_states', [
            'id' => AuthorizationState::SINGLETON_ID,
            'directory_sync_owner_token' => $newerOwner,
        ]);
    }

    public function test_repeated_terminal_callback_clears_only_its_matching_lease(): void
    {
        $owner = str_repeat('d', 64);
        $run = DirectorySyncRun::query()->create([
            'source' => DirectorySyncRun::SOURCE_MANUAL,
            'status' => DirectorySyncRun::STATUS_FAILED,
            'queue_job_uuid' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
            'queue_attempt' => 1,
            'lease_owner_token' => $owner,
            'started_at' => now()->subSecond(),
            'finished_at' => now(),
            'groups_seen' => 0,
            'groups_missing' => 0,
            'error_code' => 'job_failed',
        ]);
        AuthorizationState::query()
            ->whereKey(AuthorizationState::SINGLETON_ID)
            ->update([
                'directory_sync_owner_token' => $owner,
                'directory_sync_expires_at' => now()->addMinutes(5),
            ]);

        $job = $this->queuedJob(
            'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
            1,
        );
        $job->failed(new RuntimeException('terminal-callback-canary'));
        $job->failed(new RuntimeException('repeated-terminal-callback-canary'));

        $this->assertDatabaseCount('directory_sync_runs', 1);
        $this->assertDatabaseHas('directory_sync_runs', [
            'id' => $run->id,
            'status' => DirectorySyncRun::STATUS_FAILED,
            'error_code' => 'job_failed',
        ]);
        $this->assertDatabaseHas('authorization_states', [
            'id' => AuthorizationState::SINGLETON_ID,
            'directory_sync_owner_token' => null,
            'directory_sync_expires_at' => null,
        ]);
    }

    public function test_active_directory_lease_is_a_secondary_fail_closed_generation_signal(): void
    {
        $user = User::factory()
            ->accessLevel(User::ROLE_STAFF)
            ->cloudronIdentity('active-lease-test-subject')
            ->create();
        $context = app(SessionAuthorizationContext::class);
        $this->actingAs($user);
        if (! request()->hasSession()) {
            request()->setLaravelSession(app('session')->driver());
        }
        request()->session()->put($context->valuesFor(
            $user->refresh(),
            SessionAuthorizationContext::METHOD_CLOUDRON_OIDC,
            now()->timestamp,
        ));
        $this->assertTrue(
            $context->permitsDirectoryRestricted(request(), $user->refresh()),
        );

        $owner = str_repeat('d', 64);
        DirectorySyncRun::query()->create([
            'source' => DirectorySyncRun::SOURCE_SCHEDULED,
            'status' => DirectorySyncRun::STATUS_RUNNING,
            'queue_job_uuid' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
            'queue_attempt' => 1,
            'lease_owner_token' => $owner,
            'started_at' => now(),
            'groups_seen' => 0,
            'groups_missing' => 0,
        ]);
        AuthorizationState::query()
            ->whereKey(AuthorizationState::SINGLETON_ID)
            ->update([
                'directory_sync_owner_token' => $owner,
                'directory_sync_expires_at' => now()->addMinutes(5),
            ]);

        $this->assertFalse(
            $context->permitsDirectoryRestricted(request(), $user->refresh()),
        );
    }

    public function test_queued_job_passes_the_framework_uuid_and_attempt_to_the_synchronizer(): void
    {
        config([
            'queue.default' => 'database',
            'queue.connections.database.retry_after' => 300,
        ]);
        $uuid = 'ffffffff-ffff-4fff-8fff-ffffffffffff';
        $synchronizer = $this->mock(
            DirectoryCatalogueSynchronizer::class,
            function (MockInterface $mock) use ($uuid): void {
                $mock->shouldReceive('synchronize')
                    ->once()
                    ->with(
                        DirectorySyncRun::SOURCE_MANUAL,
                        $uuid,
                        2,
                    );
            },
        );

        $this->queuedJob($uuid, 2)->handle(
            $synchronizer,
            app(ManualDirectorySyncQueueResolver::class),
        );
    }

    public function test_scheduled_sync_is_hourly_and_without_overlap(): void
    {
        $events = collect(app(Schedule::class)->events());
        $event = $events->first(
            static fn (object $candidate): bool => ($candidate->description ?? null)
                === RunDirectorySync::class,
        );

        $this->assertNotNull($event);
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_manual_and_scheduled_requests_coalesce_while_one_job_is_pending(): void
    {
        config([
            'cache.default' => 'array',
            'queue.default' => 'database',
            'queue.connections.database.retry_after' => 300,
        ]);

        RunDirectorySync::dispatch(DirectorySyncRun::SOURCE_MANUAL);

        $job = new RunDirectorySync;
        $maximumAttemptEnvelope = ($job->tries * $job->timeout)
            + array_sum($job->backoff());

        $this->travel(
            $maximumAttemptEnvelope + $job->timeout + 120,
        )->seconds();

        RunDirectorySync::dispatch(DirectorySyncRun::SOURCE_SCHEDULED);

        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_retry_and_timeout_bounds_cover_the_complete_job_lifecycle(): void
    {
        $job = new RunDirectorySync;
        $maximumAttemptEnvelope = ($job->tries * $job->timeout)
            + array_sum($job->backoff());

        $this->assertGreaterThan(
            $maximumAttemptEnvelope,
            $job->uniqueFor(),
        );
        $this->assertSame(
            $maximumAttemptEnvelope + (2 * $job->timeout),
            $job->uniqueFor(),
        );

        foreach (['database', 'beanstalkd', 'redis'] as $connection) {
            $this->assertGreaterThan(
                $job->timeout,
                config("queue.connections.{$connection}.retry_after"),
            );
        }
        $this->assertSame(5, config('myapes.ldap.connect_timeout_seconds'));
        $this->assertSame(10, config('myapes.ldap.search_timeout_seconds'));
    }

    public function test_manual_sync_rejects_a_reservation_shorter_than_the_job_timeout(): void
    {
        config([
            'queue.default' => 'redis',
            'queue.connections.redis.retry_after' => 240,
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            'Manual directory synchronization requires a safely asynchronous queue.',
        );

        app(ManualDirectorySyncQueueResolver::class)->resolve();
    }

    public function test_manual_sync_rejects_missing_or_unverifiable_reservation_bounds(): void
    {
        $unsafeBounds = [
            'missing' => null,
            'numeric-string' => '300',
            'floating-point' => 300.0,
            'boolean' => true,
        ];

        foreach ($unsafeBounds as $case => $retryAfter) {
            config([
                'queue.default' => 'database',
                'queue.connections.database.retry_after' => $retryAfter,
            ]);

            try {
                app(ManualDirectorySyncQueueResolver::class)->resolve();
                $this->fail("The {$case} reservation bound was accepted.");
            } catch (DomainException $exception) {
                $this->assertSame(
                    'Manual directory synchronization requires a safely asynchronous queue.',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function test_manual_sync_accepts_supported_queues_only_with_a_proven_reservation_bound(): void
    {
        foreach (['database', 'beanstalkd', 'redis'] as $driver) {
            config([
                'queue.default' => "bounded-{$driver}",
                "queue.connections.bounded-{$driver}" => [
                    'driver' => $driver,
                    'retry_after' => RunDirectorySync::TIMEOUT_SECONDS + 1,
                ],
            ]);

            $this->assertSame(
                "bounded-{$driver}",
                app(ManualDirectorySyncQueueResolver::class)->resolve(),
            );
        }
    }

    public function test_manual_sync_rejects_unsupported_and_partially_unverifiable_failover_queues(): void
    {
        config([
            'queue.default' => 'unsafe-failover',
            'queue.connections.safe-database' => [
                'driver' => 'database',
                'retry_after' => RunDirectorySync::TIMEOUT_SECONDS + 1,
            ],
            'queue.connections.unverifiable-redis' => [
                'driver' => 'redis',
            ],
            'queue.connections.unsafe-failover' => [
                'driver' => 'failover',
                'connections' => [
                    'safe-database',
                    'unverifiable-redis',
                ],
            ],
        ]);

        try {
            app(ManualDirectorySyncQueueResolver::class)->resolve();
            $this->fail('A failover queue with an unverifiable child was accepted.');
        } catch (DomainException $exception) {
            $this->assertSame(
                'Manual directory synchronization requires a safely asynchronous queue.',
                $exception->getMessage(),
            );
        }

        config([
            'queue.default' => 'unsupported-sqs',
            'queue.connections.unsupported-sqs' => [
                'driver' => 'sqs',
                'retry_after' => RunDirectorySync::TIMEOUT_SECONDS + 1,
            ],
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            'Manual directory synchronization requires a safely asynchronous queue.',
        );

        app(ManualDirectorySyncQueueResolver::class)->resolve();
    }

    /**
     * @param  array<int, array{name: string, external_id: ?string, member_count: int}>  $catalogue
     */
    private function installResolver(array $catalogue): LdapGroupResolver
    {
        $resolver = new class extends LdapGroupResolver
        {
            /**
             * @var array<int, array{name: string, external_id: ?string, member_count: int}>
             */
            public array $catalogue = [];

            public ?Throwable $failure = null;

            /**
             * @return array<int, array{name: string, external_id: ?string, member_count: int}>
             */
            public function enumerateGroups(): array
            {
                if ($this->failure !== null) {
                    throw $this->failure;
                }

                return $this->catalogue;
            }
        };
        $resolver->catalogue = $catalogue;
        $this->app->instance(LdapGroupResolver::class, $resolver);

        return $resolver;
    }

    private function queuedJob(
        string $uuid,
        int $attempt,
        string $source = DirectorySyncRun::SOURCE_MANUAL,
    ): RunDirectorySync {
        $queueJob = new class($uuid, $attempt) extends FakeJob
        {
            public function __construct(
                private readonly string $fixedUuid,
                int $attempt,
            ) {
                $this->attempts = $attempt;
            }

            public function uuid(): string
            {
                return $this->fixedUuid;
            }
        };
        $job = new RunDirectorySync($source);
        $job->setJob($queueJob);

        return $job;
    }
}
