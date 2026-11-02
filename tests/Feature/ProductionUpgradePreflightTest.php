<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use App\Exceptions\DirectoryUnavailable;
use App\Services\DirectoryUserSynchronizer;
use App\Services\LdapGroupResolver;
use App\Support\DirectoryGroupPrefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\FakeDirectoryUserSynchronizer;
use Tests\TestCase;

class ProductionUpgradePreflightTest extends TestCase
{
    use RefreshDatabase;

    private const ISSUER = 'https://my.cloudron.apes.org.uk/openid';

    /**
     * @var array<string, array<int, string>>
     */
    private array $groupsByEmail = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://myaccount.myapes.me.uk',
            'myapes.oidc.issuer' => self::ISSUER,
            'myapes.oidc.client_id' => 'oidc-client-id-canary',
            'myapes.oidc.client_secret' => 'oidc-client-secret-canary',
            'myapes.oidc.redirect_uri' => 'https://myaccount.myapes.me.uk/staff/auth/callback',
            'myapes.oidc.scopes' => ['openid', 'profile', 'email'],
            'myapes.directory.required_groups' => [
                'myapesaccount.staff',
                'myapesaccount.admin',
                'myapesaccount.volunteer',
                'myapesaccount.student',
            ],
            'myapes.ldap.groups_base_dn' => 'ou=groups,dc=cloudron',
        ]);
        $this->app->instance(
            DirectoryUserSynchronizer::class,
            new FakeDirectoryUserSynchronizer,
        );
        Http::preventStrayRequests();
        Http::fake([
            'https://my.cloudron.apes.org.uk/.well-known/openid-configuration' => Http::response(
                $this->validMetadata(),
            ),
        ]);
        $this->installLegacyCatalogueResolver();
    }

    public function test_production_style_preflight_accepts_legacy_ldap_groups_before_migration(): void
    {
        $superAdmin = User::factory()
            ->accessLevel(User::ROLE_ADMIN)
            ->cloudronIdentity('oidc-subject-canary-production-upgrade')
            ->create([
                'email' => 'production-upgrade-super-person-canary@example.test',
            ]);
        $this->groupsByEmail[$superAdmin->email] = ['myapes.superadmins'];

        $exitCode = Artisan::call('myapes:authorization-preflight');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('Authorization schema: ok (phase_b)', $output);
        $this->assertStringContainsString('Directory groups: ok (4 groups)', $output);
        $this->assertStringContainsString('Authorization preflight: ok', $output);
    }

    private function installLegacyCatalogueResolver(): void
    {
        $groupsByEmail = &$this->groupsByEmail;

        $resolver = new class($groupsByEmail) extends LdapGroupResolver
        {
            /**
             * @param  array<string, array<int, string>>  $groupsByEmail
             */
            public function __construct(private array &$groupsByEmail) {}

            /**
             * Catalogue fakes must not require the LDAP PHP extension.
             *
             * @return array<string, mixed>
             */
            protected function configuration(): array
            {
                $config = config('myapes.ldap');

                if (! is_array($config)) {
                    throw new DirectoryUnavailable('LDAP configuration is incomplete.');
                }

                return $config;
            }

            /**
             * @param  array<string, mixed>  $config
             * @param  array<int, string>  $attributes
             * @return array<string|int, mixed>
             */
            protected function fetchGroupEntries(
                array $config,
                string $baseDn,
                string $filter,
                array $attributes,
            ): array {
                return [
                    'count' => 5,
                    'result_code' => 0,
                    0 => [
                        'cn' => ['count' => 1, 0 => 'myapes.staff'],
                        'memberuid' => ['count' => 1, 0 => 'staff-member'],
                    ],
                    1 => [
                        'cn' => ['count' => 1, 0 => 'myapes.admins'],
                        'memberuid' => ['count' => 1, 0 => 'admin-member'],
                    ],
                    2 => [
                        'cn' => ['count' => 1, 0 => 'myapes.superadmins'],
                        'memberuid' => ['count' => 1, 0 => 'super-member'],
                    ],
                    3 => [
                        'cn' => ['count' => 1, 0 => 'myapes.volunteers'],
                        'memberuid' => ['count' => 0],
                    ],
                    4 => [
                        'cn' => ['count' => 1, 0 => 'myapes.students'],
                        'memberuid' => ['count' => 0],
                    ],
                ];
            }

            /**
             * @return array<int, string>
             */
            public function resolveByEmail(string $email): array
            {
                return DirectoryGroupPrefix::filterGroups(
                    $this->groupsByEmail[$email] ?? [],
                );
            }
        };

        $this->app->instance(LdapGroupResolver::class, $resolver);
    }

    /**
     * @return array<string, mixed>
     */
    private function validMetadata(): array
    {
        return [
            'issuer' => self::ISSUER,
            'authorization_endpoint' => self::ISSUER.'/auth',
            'token_endpoint' => self::ISSUER.'/token',
            'userinfo_endpoint' => self::ISSUER.'/me',
            'jwks_uri' => self::ISSUER.'/jwks',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['client_secret_basic'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'scopes_supported' => ['openid', 'profile', 'email'],
        ];
    }
}
