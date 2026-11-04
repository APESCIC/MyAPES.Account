<?php

namespace Tests;

use App\Core\Accounts\User;
use App\Services\SessionAuthorizationContext;
use Illuminate\Contracts\Auth\Authenticatable as UserContract;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function refreshApplication()
    {
        parent::refreshApplication();

        $this->withoutVite();
        $this->stubUncompromisedPasswords();
    }

    /**
     * Avoid live Have I Been Pwned calls during feature tests (#230).
     * Override with stubUncompromisedPasswords(false) to assert rejection.
     */
    protected function stubUncompromisedPasswords(bool $allow = true): void
    {
        $this->app->instance(
            UncompromisedVerifier::class,
            new class($allow) implements UncompromisedVerifier
            {
                public function __construct(private bool $allow) {}

                public function verify($data): bool
                {
                    return $this->allow;
                }
            },
        );
    }

    public function actingAs(UserContract $user, $guard = null): static
    {
        parent::actingAs($user, $guard);

        if ($user instanceof User) {
            $values = app(SessionAuthorizationContext::class)->valuesFor(
                $user,
                SessionAuthorizationContext::METHOD_QA,
            );
            $this->withSession($values);

            if (! request()->hasSession()) {
                request()->setLaravelSession(app('session')->driver());
            }

            request()->session()->put($values);
        }

        return $this;
    }

    /**
     * Probe-equivalence checks compare whole pages; the per-request CSP
     * nonce (#485) is the only part allowed to differ.
     */
    protected function assertSameIgnoringCspNonce(string|false $expected, string|false $actual): void
    {
        $normalise = static fn (string|false $html): string => (string) preg_replace('/nonce="[^"]*"/', 'nonce=""', (string) $html);

        $this->assertSame($normalise($expected), $normalise($actual));
    }

    protected function fakeMaintenanceMode(bool $active = true): MaintenanceMode
    {
        $maintenanceMode = new class($active) implements MaintenanceMode
        {
            /** @var array<string, mixed> */
            private array $payload = [];

            public function __construct(private bool $active) {}

            public function activate(array $payload): void
            {
                $this->payload = $payload;
                $this->active = true;
            }

            public function deactivate(): void
            {
                $this->payload = [];
                $this->active = false;
            }

            public function active(): bool
            {
                return $this->active;
            }

            public function data(): array
            {
                return $this->payload;
            }
        };

        $this->app->instance(MaintenanceMode::class, $maintenanceMode);

        return $maintenanceMode;
    }

    protected function runInMaintenanceMode(callable $operation): mixed
    {
        $originalMaintenanceMode = $this->app->make(MaintenanceMode::class);
        $this->fakeMaintenanceMode();

        try {
            return $operation();
        } finally {
            $this->app->instance(
                MaintenanceMode::class,
                $originalMaintenanceMode,
            );
        }
    }
}
