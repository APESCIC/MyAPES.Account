<?php

namespace App\Console\Commands;

use App\Exceptions\AuthorizationLifecycleException;
use App\Services\AuthorizationPreflightChecker;
use Illuminate\Console\Command;
use Throwable;

class AuthorizationPreflight extends Command
{
    protected $signature = 'myapes:authorization-preflight';

    protected $description = 'Verify authorization and identity readiness before migration';

    public function handle(AuthorizationPreflightChecker $checker): int
    {
        try {
            $result = $checker->check();
        } catch (AuthorizationLifecycleException $exception) {
            $this->components->error(
                "Authorization preflight: failed ({$exception->label()})",
            );

            return self::FAILURE;
        } catch (Throwable) {
            $this->components->error(
                'Authorization preflight: failed (verification_failed)',
            );

            return self::FAILURE;
        }

        $this->components->info('Database driver: ok');
        $this->components->info(
            "Authorization schema: ok ({$result['phase']})",
        );

        if ($result['phase'] === 'phase_a') {
            $this->components->info(
                "Legacy parity: ok ({$result['users']} users)",
            );
        }

        $this->components->info('OIDC readiness: ok');
        $this->components->info(
            "Directory groups: ok ({$result['groups']} groups)",
        );
        $this->components->info(
            "Eligible OIDC administrators: ok ({$result['administrators']} users)",
        );
        $this->components->info(
            "Authorization preflight: ok ({$result['users']} users)",
        );

        return self::SUCCESS;
    }
}
