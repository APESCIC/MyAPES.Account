<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use App\Core\Extensions\Models\ModuleInstallation;
use App\Exceptions\ModuleLifecycleException;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Support\Facades\DB;
use Plugins\Tickets\Models\SupportTicket;
use Symfony\Component\Process\Process;
use Tests\Support\ForwardOnlyDatabaseMigrations;
use Tests\TestCase;

class ModuleLifecycleConcurrencyTest extends TestCase
{
    use ForwardOnlyDatabaseMigrations;

    /** @var list<Process> */
    private array $workers = [];

    /** @var list<string> */
    private array $stateDirectories = [];

    protected function afterRefreshingDatabase(): void
    {
        $this->fakeMaintenanceMode();
    }

    protected function tearDown(): void
    {
        foreach ($this->workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop(1);
            }
        }

        foreach ($this->stateDirectories as $directory) {
            foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }

        parent::tearDown();
    }

    public function test_conflicting_lifecycle_transitions_are_serialized(): void
    {
        $this->requireMysqlFamily();
        $actor = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();
        $installation = $this->tickets();
        $state = $this->newStateDirectory();
        $version = (string) $installation->lock_version;
        $disable = $this->startWorker('disable', $actor->id, 0, $version, $state);
        $enable = $this->startWorker('enable', $actor->id, 0, $version, $state);

        $this->waitForSignal($state, 'disable-ready', $disable);
        $this->waitForSignal($state, 'enable-ready', $enable);
        touch($state.DIRECTORY_SEPARATOR.'start-disable');
        touch($state.DIRECTORY_SEPARATOR.'start-enable');
        $this->waitSuccessfully($disable);
        $this->waitSuccessfully($enable);

        $disableResult = $this->readSignal($state, 'disable-result');
        $enableResult = $this->readSignal($state, 'enable-result');
        $this->assertSame('success', $disableResult['status']);
        $this->assertSame('refused', $enableResult['status']);
        $this->assertContains(
            $enableResult['reason'],
            ['already_enabled', 'stale_transition'],
        );
        $this->assertFalse($installation->fresh()->enabled);
    }

    public function test_instance_lock_remains_held_until_the_operation_finishes(): void
    {
        config([
            'modules.lock_seconds' => 1,
            'modules.lock_wait_seconds' => 5,
        ]);
        $state = $this->newStateDirectory();
        $holder = $this->startWorker('lock-hold', 0, 0, '-', $state);
        $this->waitForSignal($state, 'lock-held', $holder);

        usleep(1_250_000);
        $probe = $this->startWorker('lock-probe', 0, 0, '-', $state);
        $this->waitForSignal($state, 'lock-probe-ready', $probe);
        touch($state.DIRECTORY_SEPARATOR.'start-lock-probe');
        usleep(250_000);

        $this->assertTrue(
            $probe->isRunning(),
            'The instance lock expired before its operation completed.',
        );

        touch($state.DIRECTORY_SEPARATOR.'release-lock');
        $this->waitSuccessfully($holder);
        $this->waitSuccessfully($probe);
        $this->assertSame(
            'success',
            $this->readSignal($state, 'lock-probe-result')['status'],
        );
    }

    public function test_the_same_instance_in_separate_databases_uses_independent_advisory_locks(): void
    {
        $this->requireMysqlFamily();
        config(['modules.lock_wait_seconds' => 1]);
        $siblingDatabase = 'myapes_lock_'.bin2hex(random_bytes(8));
        DB::connection()->statement(
            "CREATE DATABASE `{$siblingDatabase}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
        );
        $holder = null;

        try {
            $state = $this->newStateDirectory();
            $holder = $this->startWorker('lock-hold', 0, 0, '-', $state);
            $this->waitForSignal($state, 'lock-held', $holder);
            $probe = $this->startWorker(
                'lock-probe',
                0,
                0,
                '-',
                $state,
                $siblingDatabase,
            );
            $this->waitForSignal($state, 'lock-probe-ready', $probe);
            touch($state.DIRECTORY_SEPARATOR.'start-lock-probe');
            $this->waitSuccessfully($probe);

            $this->assertTrue(
                $holder->isRunning(),
                'A lock in another database interfered with the primary database.',
            );
            $this->assertSame(
                'success',
                $this->readSignal($state, 'lock-probe-result')['status'],
            );
        } finally {
            try {
                if ($holder instanceof Process) {
                    touch($state.DIRECTORY_SEPARATOR.'release-lock');
                    if ($holder->isRunning()) {
                        $holder->wait();
                    }
                }
            } finally {
                DB::connection()->statement(
                    "DROP DATABASE IF EXISTS `{$siblingDatabase}`",
                );
            }
        }
    }

    public function test_synchronization_fails_closed_before_writing_when_a_dependency_lock_is_busy(): void
    {
        config(['modules.lock_wait_seconds' => 0]);
        ModuleInstallation::query()
            ->where('sub_core_key', 'shelter-rescue')
            ->where('module_key', 'cases')
            ->delete();
        $state = $this->newStateDirectory();
        $holder = $this->startWorker(
            'dependency-lock-hold',
            0,
            0,
            '-',
            $state,
        );
        $this->waitForSignal($state, 'dependency-lock-held', $holder);

        try {
            app(ModuleInstallationSynchronizer::class)->synchronize();
            $this->fail('Synchronization wrote while a lifecycle lock was busy.');
        } catch (ModuleLifecycleException $exception) {
            $this->assertSame('instance_busy', $exception->reason);
        } finally {
            touch($state.DIRECTORY_SEPARATOR.'release-dependency-lock');
            $this->waitSuccessfully($holder);
        }

        $this->assertDatabaseMissing('module_plugins', [
            'sub_core_key' => 'shelter-rescue',
            'module_key' => 'cases',
        ]);
    }

    public function test_synchronization_waits_for_a_dependency_transition_and_uses_its_final_state(): void
    {
        $this->requireMysqlFamily();
        config(['modules.lock_wait_seconds' => 5]);
        ModuleInstallation::query()
            ->where('sub_core_key', 'shelter-rescue')
            ->where('module_key', 'cases')
            ->delete();
        $state = $this->newStateDirectory();
        $writer = $this->startWorker(
            'dependency-disable-hold',
            0,
            0,
            '-',
            $state,
        );
        $this->waitForSignal($state, 'dependency-write-locked', $writer);
        $synchronizer = $this->startWorker(
            'synchronize',
            0,
            0,
            '-',
            $state,
        );
        $this->waitForSignal($state, 'synchronize-ready', $synchronizer);
        touch($state.DIRECTORY_SEPARATOR.'start-synchronize');
        usleep(500_000);

        $this->assertTrue(
            $synchronizer->isRunning(),
            'Synchronization did not wait for the dependency lifecycle lock.',
        );

        touch($state.DIRECTORY_SEPARATOR.'release-dependency-write');
        $this->waitSuccessfully($writer);
        $this->waitSuccessfully($synchronizer);

        $this->assertSame(
            ['status' => 'success'],
            $this->readSignal($state, 'dependency-write-result'),
        );
        $this->assertSame([
            'status' => 'success',
            'created' => 5,
            'existing' => 4,
        ], $this->readSignal($state, 'synchronize-result'));
        $this->assertFalse(ModuleInstallation::query()
            ->where('sub_core_key', 'shelter-rescue')
            ->where('module_key', 'pet-profiles')
            ->firstOrFail()
            ->enabled);
        $this->assertFalse(ModuleInstallation::query()
            ->where('sub_core_key', 'shelter-rescue')
            ->where('module_key', 'cases')
            ->firstOrFail()
            ->enabled);
    }

    public function test_a_concurrent_module_write_completes_before_disablement_rechecks_active_records(): void
    {
        $this->requireMysqlFamily();
        $actor = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();
        $owner = User::factory()->create();
        $installation = $this->tickets();
        $state = $this->newStateDirectory();
        $writer = $this->startWorker(
            'write-hold',
            $actor->id,
            $owner->id,
            '-',
            $state,
        );
        $this->waitForSignal($state, 'write-locked', $writer);

        $disable = $this->startWorker(
            'disable',
            $actor->id,
            0,
            (string) $installation->lock_version,
            $state,
        );
        $this->waitForSignal($state, 'disable-ready', $disable);
        touch($state.DIRECTORY_SEPARATOR.'start-disable');
        usleep(250_000);
        $this->assertTrue($disable->isRunning());

        touch($state.DIRECTORY_SEPARATOR.'release-write');
        $this->waitSuccessfully($writer);
        $this->waitSuccessfully($disable);

        $result = $this->readSignal($state, 'disable-result');
        $this->assertSame('refused', $result['status']);
        $this->assertSame('active_records', $result['reason']);
        $this->assertTrue($installation->fresh()->enabled);
        $this->assertSame(1, SupportTicket::query()->count());
    }

    public function test_module_lock_ownership_outlives_the_former_cache_lease_duration(): void
    {
        $this->requireMysqlFamily();
        config([
            'modules.lock_seconds' => 1,
            'modules.lock_wait_seconds' => 5,
        ]);
        $actor = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();
        $owner = User::factory()->create();
        $installation = $this->tickets();
        $state = $this->newStateDirectory();
        $writer = $this->startWorker(
            'write-hold',
            $actor->id,
            $owner->id,
            '-',
            $state,
        );
        $this->waitForSignal($state, 'write-locked', $writer);

        usleep(1_250_000);
        $disable = $this->startWorker(
            'disable',
            $actor->id,
            0,
            (string) $installation->lock_version,
            $state,
        );
        $this->waitForSignal($state, 'disable-ready', $disable);
        touch($state.DIRECTORY_SEPARATOR.'start-disable');
        usleep(250_000);

        $this->assertTrue(
            $disable->isRunning(),
            'The writer lost its instance lock while the operation was still running.',
        );

        touch($state.DIRECTORY_SEPARATOR.'release-write');
        $this->waitSuccessfully($writer);
        $this->waitSuccessfully($disable);

        $result = $this->readSignal($state, 'disable-result');
        $this->assertSame('refused', $result['status']);
        $this->assertSame('active_records', $result['reason']);
        $this->assertTrue($installation->fresh()->enabled);
    }

    public function test_rollback_compatibility_waits_for_in_flight_module_writes(): void
    {
        $this->requireMysqlFamily();
        config(['modules.lock_wait_seconds' => 5]);
        $actor = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();
        $owner = User::factory()->create();
        $state = $this->newStateDirectory();
        $writer = $this->startWorker(
            'write-hold',
            $actor->id,
            $owner->id,
            '-',
            $state,
        );
        $this->waitForSignal($state, 'write-locked', $writer);
        $rollback = $this->startWorker(
            'rollback-check',
            0,
            0,
            base_path(),
            $state,
        );
        $this->waitForSignal($state, 'rollback-check-ready', $rollback);
        touch($state.DIRECTORY_SEPARATOR.'start-rollback-check');
        usleep(250_000);

        $this->assertTrue(
            $rollback->isRunning(),
            'Rollback compatibility did not drain the active module writer.',
        );

        touch($state.DIRECTORY_SEPARATOR.'release-write');
        $this->waitSuccessfully($writer);
        $this->waitSuccessfully($rollback);
        $this->assertSame(
            'success',
            $this->readSignal($state, 'rollback-check-result')['status'],
        );
    }

    private function requireMysqlFamily(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Cross-process module locking requires MySQL.');
        }
    }

    private function tickets(): ModuleInstallation
    {
        return ModuleInstallation::query()
            ->where('sub_core_key', 'apes-cic')
            ->where('module_key', 'tickets')
            ->firstOrFail();
    }

    private function newStateDirectory(): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR
            .'myapes-module-lifecycle-'.bin2hex(random_bytes(8));
        $this->assertTrue(mkdir($path, 0700, true));
        $this->stateDirectories[] = $path;

        return $path;
    }

    private function startWorker(
        string $mode,
        int $actorId,
        int $ownerId,
        string $version,
        string $state,
        ?string $database = null,
    ): Process {
        $connection = config('database.connections.'.config('database.default'));
        $process = new Process(
            [
                PHP_BINARY,
                base_path('tests/Support/ModuleLifecycleConcurrencyWorker.php'),
                $mode,
                (string) $actorId,
                (string) $ownerId,
                $version,
                $state,
            ],
            base_path(),
            [
                'APP_ENV' => 'testing',
                'APP_KEY' => (string) config('app.key'),
                'CACHE_STORE' => 'database',
                'DB_CONNECTION' => (string) config('database.default'),
                'DB_HOST' => (string) ($connection['host'] ?? ''),
                'DB_PORT' => (string) ($connection['port'] ?? ''),
                'DB_DATABASE' => $database
                    ?? (string) ($connection['database'] ?? ''),
                'DB_USERNAME' => (string) ($connection['username'] ?? ''),
                'DB_PASSWORD' => (string) ($connection['password'] ?? ''),
                'DB_URL' => '',
                'MODULE_LOCK_SECONDS' => (string) config(
                    'modules.lock_seconds',
                    15,
                ),
                'MODULE_LOCK_WAIT_SECONDS' => (string) config(
                    'modules.lock_wait_seconds',
                    5,
                ),
            ],
        );
        $process->setTimeout(90);
        $process->start();
        $this->workers[] = $process;

        return $process;
    }

    private function waitForSignal(
        string $directory,
        string $signal,
        Process $worker,
    ): void {
        $path = $directory.DIRECTORY_SEPARATOR.$signal.'.json';
        $deadline = microtime(true) + 30;

        while (! is_file($path) && microtime(true) < $deadline) {
            if ($worker->isTerminated()) {
                $this->fail('Worker exited early: '.$this->workerOutput($worker));
            }
            usleep(25_000);
        }

        $this->assertFileExists($path);
    }

    /** @return array<string, string> */
    private function readSignal(string $directory, string $signal): array
    {
        $path = $directory.DIRECTORY_SEPARATOR.$signal.'.json';
        $this->assertFileExists($path);
        $decoded = json_decode(
            (string) file_get_contents($path),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function waitSuccessfully(Process $worker): void
    {
        $exitCode = $worker->wait();
        $this->assertSame(0, $exitCode, $this->workerOutput($worker));
    }

    private function workerOutput(Process $worker): string
    {
        return trim($worker->getOutput().' '.$worker->getErrorOutput());
    }
}
