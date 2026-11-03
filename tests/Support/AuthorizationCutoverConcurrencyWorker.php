<?php

use App\Services\AuthorizationIntegrityChecker;
use App\Services\AuthorizationPhaseBSchemaInspector;
use App\Support\AuthorizationCompatibilityDatabaseGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$application = require dirname(__DIR__, 2).'/bootstrap/app.php';
$application->make(Kernel::class)->bootstrap();

[$script, $mode, $userId, $accessLevel, $stateDirectory] = array_pad(
    $argv,
    5,
    null,
);

if (! is_string($mode)
    || ! in_array(
        $mode,
        [
            'writer-hold',
            'writer-once',
            'reconcile-hold',
            'reconcile-once',
            'integrity-check-once',
        ],
        true,
    )
    || filter_var($userId, FILTER_VALIDATE_INT) === false
    || ! is_string($accessLevel)
    || ! in_array(
        $accessLevel,
        ['service_user', 'staff', 'admin'],
        true,
    )
    || ! is_string($stateDirectory)
    || ! is_dir($stateDirectory)) {
    fwrite(STDERR, 'Invalid concurrency worker arguments.'.PHP_EOL);
    exit(2);
}

if (! in_array(
    DB::connection()->getDriverName(),
    ['mysql'],
    true,
)) {
    fwrite(STDERR, 'Concurrency worker requires MySQL.'.PHP_EOL);
    exit(2);
}

$signal = static function (string $name, array $payload = []) use (
    $stateDirectory,
): void {
    $payload['signal'] = $name;
    $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
    $path = $stateDirectory.DIRECTORY_SEPARATOR.$name.'.json';
    $temporaryPath = $path.'.'.getmypid().'.tmp';

    if (file_put_contents($temporaryPath, $encoded, LOCK_EX) === false
        || ! rename($temporaryPath, $path)) {
        throw new RuntimeException('Unable to publish worker state.');
    }
};

$waitForRelease = static function (string $name) use ($stateDirectory): void {
    $path = $stateDirectory.DIRECTORY_SEPARATOR.$name;
    $deadline = microtime(true) + 75;

    while (! is_file($path) && microtime(true) < $deadline) {
        usleep(25_000);
    }

    if (! is_file($path)) {
        throw new RuntimeException('Timed out waiting for worker release.');
    }
};

$connectionId = (int) DB::scalar('SELECT CONNECTION_ID()');
$transactionOpen = false;

try {
    if ($mode === 'writer-hold') {
        DB::beginTransaction();
        $transactionOpen = true;
        $signal('writer-connected', ['connection_id' => $connectionId]);

        $user = DB::table('users')
            ->where('id', (int) $userId)
            ->lockForUpdate()
            ->first(['id']);

        if ($user === null) {
            throw new RuntimeException('Writer could not update its target.');
        }

        DB::table('users')
            ->where('id', (int) $userId)
            ->update(['legacy_access_level' => $accessLevel]);

        $signal('writer-updated', ['connection_id' => $connectionId]);
        $waitForRelease('release-writer');
        DB::commit();
        $transactionOpen = false;
        $signal('writer-done', ['connection_id' => $connectionId]);
    } elseif ($mode === 'writer-once') {
        $user = DB::table('users')
            ->where('id', (int) $userId)
            ->first(['id']);

        if ($user === null) {
            throw new RuntimeException('Writer could not update its target.');
        }

        DB::beginTransaction();
        $transactionOpen = true;
        $signal('writer-ready', ['connection_id' => $connectionId]);
        $waitForRelease('start-writer');

        DB::table('users')
            ->where('id', (int) $userId)
            ->update(['legacy_access_level' => $accessLevel]);

        DB::commit();
        $transactionOpen = false;
        $signal('writer-done', ['connection_id' => $connectionId]);
    } elseif ($mode === 'reconcile-hold') {
        $guard = $application->make(
            AuthorizationCompatibilityDatabaseGuard::class,
        );

        if (! $guard->isInstalled()) {
            throw new RuntimeException(
                'Authorization compatibility database guard is unavailable.',
            );
        }

        DB::beginTransaction();
        $transactionOpen = true;
        $signal('reconciler-ready', ['connection_id' => $connectionId]);
        $waitForRelease('start-reconciler');
        $guard->reconcileLegacySources();
        $signal('reconciler-reconciled', ['connection_id' => $connectionId]);
        $waitForRelease('release-reconciler');
        DB::commit();
        $transactionOpen = false;
        $signal('reconciler-done', ['connection_id' => $connectionId]);
    } elseif ($mode === 'reconcile-once') {
        $guard = $application->make(
            AuthorizationCompatibilityDatabaseGuard::class,
        );

        if (! $guard->isInstalled()) {
            throw new RuntimeException(
                'Authorization compatibility database guard is unavailable.',
            );
        }

        $signal('reconciler-ready', ['connection_id' => $connectionId]);
        $waitForRelease('start-reconciler');
        $guard->reconcileLegacySources();
        $signal('reconciler-done', ['connection_id' => $connectionId]);
    } else {
        $checker = $application->make(AuthorizationIntegrityChecker::class);
        $application
            ->make(AuthorizationPhaseBSchemaInspector::class)
            ->assertReady();
        $signal('checker-ready', ['connection_id' => $connectionId]);
        $waitForRelease('start-checker');
        $checker->check();
        $signal('checker-done', ['connection_id' => $connectionId]);
    }
} catch (Throwable $exception) {
    if ($transactionOpen) {
        DB::rollBack();
    }

    fwrite(
        STDERR,
        $exception::class.': '.$exception->getMessage().PHP_EOL,
    );
    exit(1);
} finally {
    DB::disconnect();
}

exit(0);
