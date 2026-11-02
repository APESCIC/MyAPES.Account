<?php

namespace Tests\Feature;

use App\Core\Accounts\Role;
use App\Core\Accounts\RoleSource;
use App\Core\Accounts\User;
use App\Support\AuthorizationCompatibilityDatabaseGuard;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\ForwardOnlyDatabaseMigrations;
use Tests\TestCase;

class AuthorizationCutoverConcurrencyTest extends TestCase
{
    use ForwardOnlyDatabaseMigrations;

    private const PROCESS_TIMEOUT_SECONDS = 180;

    private const SIGNAL_TIMEOUT_SECONDS = 90;

    private const LOCK_EVIDENCE_TIMEOUT_SECONDS = 30;

    /**
     * @var list<Process>
     */
    private array $workerProcesses = [];

    /**
     * @var list<string>
     */
    private array $stateDirectories = [];

    protected function afterRefreshingDatabase(): void
    {
        // DatabaseMigrations deliberately rolls Phase B back after each test.
        // Mirror the production maintenance-only downgrade contract for teardown.
        $this->fakeMaintenanceMode();
    }

    public function test_guard_exposes_the_reconciliation_entrypoint(): void
    {
        $this->assertTrue(
            method_exists(
                app(AuthorizationCompatibilityDatabaseGuard::class),
                'reconcileLegacySources',
            ),
            'The Phase B guard must expose the database-owned reconciliation boundary.',
        );
    }

    #[DataProvider('legacyTransitionProvider')]
    public function test_writer_before_reconciliation_serializes_the_transition_and_preserves_canonical_state(
        string $initialAccessLevel,
        string $currentAccessLevel,
        string $expectedRole,
    ): void {
        $this->requireMysqlFamily();

        [$userId, $customRoleId, $unchangedTimestamp] = $this->seedCanonicalUser(
            $initialAccessLevel,
        );
        $this->assertCanonicalState(
            $userId,
            $initialAccessLevel,
            $this->protectedRoleNameFor($initialAccessLevel),
            $customRoleId,
            $unchangedTimestamp,
        );
        $stateDirectory = $this->newStateDirectory();

        $writer = $this->startWorker(
            'writer-hold',
            $userId,
            $currentAccessLevel,
            $stateDirectory,
        );
        $writerConnection = $this->connectionIdFromSignal(
            $stateDirectory,
            'writer-updated',
            $writer,
        );

        $reconciler = $this->startWorker(
            'reconcile-once',
            $userId,
            $currentAccessLevel,
            $stateDirectory,
        );
        $reconcilerConnection = $this->connectionIdFromSignal(
            $stateDirectory,
            'reconciler-ready',
            $reconciler,
        );
        $this->releaseWorker($stateDirectory, 'start-reconciler');

        try {
            $this->assertEngineReportsLockWait(
                $reconcilerConnection,
                $writerConnection,
                $reconciler,
            );
            $this->releaseWorker($stateDirectory, 'release-writer');
            $this->waitForSuccessfulWorker($writer);
            $this->waitForSuccessfulWorker($reconciler);
        } finally {
            $this->releaseWorker($stateDirectory, 'release-writer');
            $this->releaseWorker($stateDirectory, 'release-reconciler');
        }

        $this->assertCanonicalState(
            $userId,
            $currentAccessLevel,
            $expectedRole,
            $customRoleId,
            $unchangedTimestamp,
        );
    }

    #[DataProvider('legacyTransitionProvider')]
    public function test_writer_after_reconciliation_serializes_the_transition_and_preserves_canonical_state(
        string $initialAccessLevel,
        string $currentAccessLevel,
        string $expectedRole,
    ): void {
        $this->requireMysqlFamily();

        [$userId, $customRoleId, $unchangedTimestamp] = $this->seedCanonicalUser(
            $initialAccessLevel,
        );
        $this->assertCanonicalState(
            $userId,
            $initialAccessLevel,
            $this->protectedRoleNameFor($initialAccessLevel),
            $customRoleId,
            $unchangedTimestamp,
        );
        $stateDirectory = $this->newStateDirectory();

        $reconciler = $this->startWorker(
            'reconcile-hold',
            $userId,
            $currentAccessLevel,
            $stateDirectory,
        );
        $reconcilerConnection = $this->connectionIdFromSignal(
            $stateDirectory,
            'reconciler-ready',
            $reconciler,
        );
        $this->releaseWorker($stateDirectory, 'start-reconciler');
        $this->waitForSignal($stateDirectory, 'reconciler-reconciled', $reconciler);

        $writer = $this->startWorker(
            'writer-once',
            $userId,
            $currentAccessLevel,
            $stateDirectory,
        );
        $writerConnection = $this->connectionIdFromSignal(
            $stateDirectory,
            'writer-ready',
            $writer,
        );
        $this->releaseWorker($stateDirectory, 'start-writer');

        try {
            $this->assertEngineReportsLockWait(
                $writerConnection,
                $reconcilerConnection,
                $writer,
            );
            $this->releaseWorker($stateDirectory, 'release-reconciler');
            $this->waitForSuccessfulWorker($reconciler);
            $this->waitForSuccessfulWorker($writer);
        } finally {
            $this->releaseWorker($stateDirectory, 'release-writer');
            $this->releaseWorker($stateDirectory, 'release-reconciler');
        }

        $this->assertCanonicalState(
            $userId,
            $currentAccessLevel,
            $expectedRole,
            $customRoleId,
            $unchangedTimestamp,
        );
    }

    public function test_integrity_check_waits_for_a_legacy_writer_and_reads_one_coherent_state(): void
    {
        $this->requireMysqlFamily();

        $user = User::factory()
            ->accessLevel(User::ROLE_ADMIN)
            ->cloudronIdentity('cutover-integrity-subject')
            ->create([
                'ldap_groups' => ['myapesaccount.admin'],
            ]);
        $administrator = Role::query()
            ->where('guard_name', 'web')
            ->where('name', 'administrator')
            ->firstOrFail();
        $directoryGroupId = (int) DB::table('directory_groups')
            ->where('name', 'myapesaccount.admin')
            ->value('id');
        DB::table('role_sources')->insert([
            'user_id' => $user->id,
            'role_id' => $administrator->id,
            'source' => RoleSource::SOURCE_DIRECTORY,
            'source_key' => RoleSource::SOURCE_DIRECTORY.':'.$directoryGroupId,
            'directory_group_id' => $directoryGroupId,
            'granted_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('authorization_states')
            ->where('id', 1)
            ->update([
                'session_cutover_completed_at' => now(),
                'updated_at' => now(),
            ]);
        $stateDirectory = $this->newStateDirectory();
        $writer = $this->startWorker(
            'writer-hold',
            (int) $user->id,
            User::ROLE_ADMIN,
            $stateDirectory,
        );
        $writerConnection = $this->connectionIdFromSignal(
            $stateDirectory,
            'writer-updated',
            $writer,
        );
        $checker = $this->startWorker(
            'integrity-check-once',
            (int) $user->id,
            User::ROLE_ADMIN,
            $stateDirectory,
        );
        $checkerConnection = $this->connectionIdFromSignal(
            $stateDirectory,
            'checker-ready',
            $checker,
        );
        $this->releaseWorker($stateDirectory, 'start-checker');

        try {
            $this->assertEngineReportsLockWait(
                $checkerConnection,
                $writerConnection,
                $checker,
            );
            $this->releaseWorker($stateDirectory, 'release-writer');
            $this->waitForSuccessfulWorker($writer);
            $this->waitForSuccessfulWorker($checker);
        } finally {
            $this->releaseWorker($stateDirectory, 'release-writer');
        }

        $this->assertSame(
            ['administrator'],
            $this->legacyProtectedRoleNames((int) $user->id),
        );
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function legacyTransitionProvider(): array
    {
        return [
            'promotion' => ['service_user', 'staff', 'staff'],
            'demotion' => ['admin', 'staff', 'staff'],
            'revocation' => ['admin', 'service_user', 'service-user'],
        ];
    }

    protected function tearDown(): void
    {
        foreach ($this->stateDirectories as $stateDirectory) {
            $this->releaseWorker($stateDirectory, 'start-writer');
            $this->releaseWorker($stateDirectory, 'start-reconciler');
            $this->releaseWorker($stateDirectory, 'start-checker');
            $this->releaseWorker($stateDirectory, 'release-writer');
            $this->releaseWorker($stateDirectory, 'release-reconciler');
        }

        foreach ($this->workerProcesses as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }

        foreach ($this->stateDirectories as $stateDirectory) {
            $this->removeStateDirectory($stateDirectory);
        }

        parent::tearDown();
    }

    private function requireMysqlFamily(): void
    {
        if (! in_array(
            DB::connection()->getDriverName(),
            ['mysql'],
            true,
        )) {
            $this->markTestSkipped(
                'True cutover concurrency requires MySQL.',
            );
        }
    }

    /**
     * @return array{int, int, string}
     */
    private function seedCanonicalUser(string $initialAccessLevel): array
    {
        $timestamp = '2026-07-28 12:34:56';
        $userId = (int) DB::table('users')->insertGetId([
            'oidc_sub' => null,
            'identity_type' => User::IDENTITY_LOCAL,
            'legacy_access_level' => $initialAccessLevel,
            'name' => 'Concurrency fixture',
            'email' => 'cutover-'.bin2hex(random_bytes(8)).'@example.test',
            'email_verified_at' => $timestamp,
            'password' => null,
            'remember_token' => null,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $customRoleId = (int) DB::table('roles')->insertGetId([
            'name' => 'cutover-custom-'.bin2hex(random_bytes(8)),
            'guard_name' => 'web',
            'is_protected' => false,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('role_sources')->insert([
            'user_id' => $userId,
            'role_id' => $customRoleId,
            'source' => RoleSource::SOURCE_LOCAL,
            'source_key' => RoleSource::SOURCE_LOCAL,
            'directory_group_id' => null,
            'granted_by' => $userId,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        DB::table('model_has_roles')->insert([
            'role_id' => $customRoleId,
            'model_type' => 'user',
            'model_id' => $userId,
        ]);

        return [$userId, $customRoleId, $timestamp];
    }

    private function protectedRoleNameFor(string $accessLevel): string
    {
        return match ($accessLevel) {
            'service_user' => 'service-user',
            'staff' => 'staff',
            'admin' => 'administrator',
        };
    }

    private function startWorker(
        string $mode,
        int $userId,
        string $accessLevel,
        string $stateDirectory,
    ): Process {
        $process = new Process(
            [
                PHP_BINARY,
                base_path(
                    'tests/Support/AuthorizationCutoverConcurrencyWorker.php',
                ),
                $mode,
                (string) $userId,
                $accessLevel,
                $stateDirectory,
            ],
            base_path(),
        );
        $process->setTimeout(self::PROCESS_TIMEOUT_SECONDS);
        $process->start();
        $this->workerProcesses[] = $process;

        return $process;
    }

    private function connectionIdFromSignal(
        string $stateDirectory,
        string $signal,
        Process $process,
    ): int {
        $payload = $this->waitForSignal($stateDirectory, $signal, $process);
        $this->assertArrayHasKey('connection_id', $payload);
        $this->assertIsInt($payload['connection_id']);
        $this->assertGreaterThan(0, $payload['connection_id']);

        return $payload['connection_id'];
    }

    /**
     * @return array<string, mixed>
     */
    private function waitForSignal(
        string $stateDirectory,
        string $signal,
        Process $process,
    ): array {
        $path = $stateDirectory.DIRECTORY_SEPARATOR.$signal.'.json';
        $deadline = microtime(true) + self::SIGNAL_TIMEOUT_SECONDS;

        while (! is_file($path) && microtime(true) < $deadline) {
            if ($process->isTerminated()) {
                $this->fail(
                    "Concurrency worker exited before {$signal}: ".
                    $this->workerOutput($process),
                );
            }

            usleep(25_000);
        }

        if (! is_file($path)) {
            $this->fail(
                "Timed out waiting for concurrency signal {$signal}: ".
                $this->workerOutput($process),
            );
        }

        $payload = json_decode(
            (string) file_get_contents($path),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertIsArray($payload);

        return $payload;
    }

    private function assertEngineReportsLockWait(
        int $waitingConnectionId,
        int $blockingConnectionId,
        Process $process,
    ): void {
        $deadline = microtime(true) + self::LOCK_EVIDENCE_TIMEOUT_SECONDS;
        $evidence = null;

        while (microtime(true) < $deadline) {
            $evidence = $this->engineLockWait(
                $waitingConnectionId,
                $blockingConnectionId,
            );

            if ($evidence !== null
                && (int) $evidence->waiting_connection_id
                    === $waitingConnectionId
                && (int) $evidence->blocking_connection_id
                    === $blockingConnectionId
                && is_string($evidence->requested_lock_id)
                && $evidence->requested_lock_id !== '') {
                $this->assertSame(
                    $waitingConnectionId,
                    (int) $evidence->waiting_connection_id,
                );
                $this->assertSame(
                    $blockingConnectionId,
                    (int) $evidence->blocking_connection_id,
                );
                $this->assertTrue($process->isRunning());

                return;
            }

            if ($process->isTerminated()) {
                $this->fail(
                    'Concurrency worker exited without entering an engine lock wait: '.
                    $this->workerOutput($process),
                );
            }

            usleep(25_000);
        }

        $this->fail(
            'InnoDB did not report the expected waiter-to-blocker relationship. '.
            'Last lock: '.json_encode($evidence).
            '; connections: '.json_encode($this->connectionStates(
                $waitingConnectionId,
                $blockingConnectionId,
            )),
        );
    }

    private function engineLockWait(
        int $waitingConnectionId,
        int $blockingConnectionId,
    ): ?object {
        if (DB::connection()->getDriverName() === 'mysql') {
            return DB::selectOne(
                'SELECT
                     waiting.PROCESSLIST_ID AS waiting_connection_id,
                     blocking.PROCESSLIST_ID AS blocking_connection_id,
                     waits.REQUESTING_ENGINE_LOCK_ID AS requested_lock_id
                 FROM performance_schema.data_lock_waits AS waits
                 INNER JOIN performance_schema.threads AS waiting
                    ON waiting.THREAD_ID = waits.REQUESTING_THREAD_ID
                 INNER JOIN performance_schema.threads AS blocking
                    ON blocking.THREAD_ID = waits.BLOCKING_THREAD_ID
                 WHERE waiting.PROCESSLIST_ID = ?
                   AND blocking.PROCESSLIST_ID = ?',
                [$waitingConnectionId, $blockingConnectionId],
            );
        }

        $wait = DB::selectOne(
            'SELECT
                 waiting.trx_mysql_thread_id AS waiting_connection_id,
                 blocking.trx_mysql_thread_id AS blocking_connection_id,
                 waits.requested_lock_id AS requested_lock_id
             FROM information_schema.INNODB_LOCK_WAITS AS waits
             INNER JOIN information_schema.INNODB_TRX AS waiting
                ON waiting.trx_id = waits.requesting_trx_id
             INNER JOIN information_schema.INNODB_TRX AS blocking
                ON blocking.trx_id = waits.blocking_trx_id
             WHERE waiting.trx_mysql_thread_id = ?
               AND blocking.trx_mysql_thread_id = ?',
            [$waitingConnectionId, $blockingConnectionId],
        );

        if ($wait !== null) {
            return $wait;
        }

        return $this->mariaDbStatusLockWait(
            $waitingConnectionId,
            $blockingConnectionId,
        );
    }

    private function mariaDbStatusLockWait(
        int $waitingConnectionId,
        int $blockingConnectionId,
    ): ?object {
        $statusRow = DB::selectOne('SHOW ENGINE INNODB STATUS');
        $status = null;

        foreach ((array) $statusRow as $column => $value) {
            if (strcasecmp((string) $column, 'status') === 0
                && is_string($value)) {
                $status = $value;

                break;
            }
        }

        if ($status === null) {
            return null;
        }

        $waitingBlock = collect(preg_split(
            '/(?=---TRANSACTION )/',
            $status,
        ) ?: [])
            ->first(
                static fn (string $block): bool => str_contains(
                    $block,
                    'LOCK WAIT',
                ) && preg_match(
                    '/MySQL thread id\s+'.
                    preg_quote((string) $waitingConnectionId, '/').',/',
                    $block,
                ) === 1,
            );
        $blocker = DB::selectOne(
            'SELECT trx_mysql_thread_id
             FROM information_schema.INNODB_TRX
             WHERE trx_mysql_thread_id = ?
               AND trx_state = ?',
            [$blockingConnectionId, 'RUNNING'],
        );

        if (! is_string($waitingBlock) || $blocker === null) {
            return null;
        }

        preg_match(
            '/^([A-Z]+ LOCKS .* waiting)$/m',
            $waitingBlock,
            $lockLine,
        );

        return (object) [
            'waiting_connection_id' => $waitingConnectionId,
            'blocking_connection_id' => $blockingConnectionId,
            'requested_lock_id' => trim(
                $lockLine[1] ?? 'SHOW ENGINE INNODB STATUS: LOCK WAIT',
            ),
        ];
    }

    /**
     * @return list<array{id: int, command: string, state: ?string}>
     */
    private function connectionStates(
        int $waitingConnectionId,
        int $blockingConnectionId,
    ): array {
        return DB::table('information_schema.processlist')
            ->whereIn('id', [$waitingConnectionId, $blockingConnectionId])
            ->orderBy('id')
            ->get([
                DB::raw('ID AS id'),
                DB::raw('COMMAND AS command'),
                DB::raw('STATE AS state'),
            ])
            ->map(static fn (object $connection): array => [
                'id' => (int) $connection->id,
                'command' => (string) $connection->command,
                'state' => is_string($connection->state)
                    ? $connection->state
                    : null,
            ])
            ->all();
    }

    private function releaseWorker(
        string $stateDirectory,
        string $signal,
    ): void {
        if (! is_dir($stateDirectory)) {
            return;
        }

        file_put_contents(
            $stateDirectory.DIRECTORY_SEPARATOR.$signal,
            'continue',
            LOCK_EX,
        );
    }

    private function waitForSuccessfulWorker(Process $process): void
    {
        $exitCode = $process->wait();

        $this->assertSame(
            0,
            $exitCode,
            'Concurrency worker failed: '.$this->workerOutput($process),
        );
    }

    private function assertCanonicalState(
        int $userId,
        string $currentAccessLevel,
        string $expectedRole,
        int $customRoleId,
        string $unchangedTimestamp,
    ): void {
        $this->assertSame(
            $currentAccessLevel,
            DB::table('users')->where('id', $userId)->value(
                'legacy_access_level',
            ),
        );
        $this->assertSame(
            [$expectedRole],
            $this->legacyProtectedRoleNames($userId),
        );
        $this->assertSame(
            [$expectedRole],
            DB::table('model_has_roles')
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->where('model_has_roles.model_type', 'user')
                ->where('model_has_roles.model_id', $userId)
                ->where('roles.guard_name', 'web')
                ->where('roles.is_protected', true)
                ->orderBy('roles.name')
                ->pluck('roles.name')
                ->all(),
        );
        $this->assertDatabaseHas('role_sources', [
            'user_id' => $userId,
            'role_id' => $customRoleId,
            'source' => RoleSource::SOURCE_LOCAL,
            'source_key' => RoleSource::SOURCE_LOCAL,
        ]);
        $this->assertDatabaseHas('model_has_roles', [
            'role_id' => $customRoleId,
            'model_type' => 'user',
            'model_id' => $userId,
        ]);
        $this->assertSame(
            $unchangedTimestamp,
            (string) DB::table('users')
                ->where('id', $userId)
                ->value('updated_at'),
        );
    }

    /**
     * @return list<string>
     */
    private function legacyProtectedRoleNames(int $userId): array
    {
        return DB::table('role_sources')
            ->join('roles', 'roles.id', '=', 'role_sources.role_id')
            ->where('role_sources.user_id', $userId)
            ->where(
                'role_sources.source',
                RoleSource::SOURCE_LEGACY_COMPATIBILITY,
            )
            ->where(
                'role_sources.source_key',
                RoleSource::SOURCE_LEGACY_COMPATIBILITY,
            )
            ->where('roles.guard_name', 'web')
            ->where('roles.is_protected', true)
            ->orderBy('roles.name')
            ->pluck('roles.name')
            ->all();
    }

    private function newStateDirectory(): string
    {
        $temporaryRoot = realpath(sys_get_temp_dir());

        if ($temporaryRoot === false) {
            $this->fail('Unable to resolve the system temporary directory.');
        }

        $stateDirectory = $temporaryRoot.DIRECTORY_SEPARATOR.
            'myapes-authorization-cutover-'.bin2hex(random_bytes(12));

        if (! mkdir($stateDirectory, 0700) && ! is_dir($stateDirectory)) {
            $this->fail('Unable to create the concurrency state directory.');
        }

        $this->stateDirectories[] = $stateDirectory;

        return $stateDirectory;
    }

    private function removeStateDirectory(string $stateDirectory): void
    {
        $temporaryRoot = realpath(sys_get_temp_dir());
        $resolvedDirectory = realpath($stateDirectory);

        if ($temporaryRoot === false
            || $resolvedDirectory === false
            || ! str_starts_with(
                $resolvedDirectory,
                $temporaryRoot.DIRECTORY_SEPARATOR.
                    'myapes-authorization-cutover-',
            )) {
            return;
        }

        foreach (glob($resolvedDirectory.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        rmdir($resolvedDirectory);
    }

    private function workerOutput(Process $process): string
    {
        return trim($process->getOutput().' '.$process->getErrorOutput());
    }
}
