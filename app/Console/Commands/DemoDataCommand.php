<?php

namespace App\Console\Commands;

use App\Core\Accounts\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Demo\DemoEnvironment;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Console\Command;

/**
 * Loads the deterministic local demo data (#722). Local only: it refuses to run when
 * APP_ENV is not local, on Cloudron, or against a database whose name does not end in _local.
 */
class DemoDataCommand extends Command
{
    protected $signature = 'myapes:demo-data
        {--reset : Drop every table in the local database, migrate, then load the demo data}
        {--without-qa : Leave out the LocalQaSeeder fixtures, so /login shows the sign-in form}';

    protected $description = 'Load fake demo data into the local Herd database (local only)';

    public function handle(): int
    {
        $refusal = DemoEnvironment::refusalReason();
        if ($refusal !== null) {
            $this->components->error($refusal);

            return self::FAILURE;
        }

        if ($this->option('reset')) {
            $this->components->info('Resetting the local database (migrate:fresh).');
            if ($this->call('migrate:fresh', ['--force' => true]) !== self::SUCCESS) {
                return self::FAILURE;
            }
        }

        if (! $this->option('without-qa')) {
            $this->call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);
        }

        $this->call('db:seed', ['--class' => DemoDataSeeder::class, '--force' => true]);

        $this->table(
            ['Sign in as', 'Role', 'Sign-in page'],
            collect(DemoDataSeeder::LOGINS)->map(static fn (array $login, string $email): array => [
                $email,
                $login['job_role'] ?? $login['access'],
                $login['access'] === User::ROLE_SERVICE_USER ? '/login' : '/staff/login',
            ])->values()->all(),
        );
        $this->components->info('Demo data loaded. The shared demo password is in docs/herd/demo-data.md.');

        return self::SUCCESS;
    }
}
