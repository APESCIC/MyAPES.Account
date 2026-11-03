<?php

namespace Tests\Feature;

use App\Exceptions\DirectoryUnavailable;
use App\Services\LdapGroupResolver;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthReadinessCommandTest extends TestCase
{
    private const ISSUER = 'https://my.cloudron.apes.org.uk/openid';

    private const DISCOVERY = 'https://my.cloudron.apes.org.uk/.well-known/openid-configuration';

    private const CALLBACK = 'https://myaccount.myapes.me.uk/staff/auth/callback';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://myaccount.myapes.me.uk',
            'myapes.oidc.issuer' => self::ISSUER,
            'myapes.oidc.client_id' => 'oidc-client-id-canary',
            'myapes.oidc.client_secret' => 'oidc-client-secret-canary',
            'myapes.oidc.redirect_uri' => self::CALLBACK,
            'myapes.oidc.scopes' => ['openid', 'profile', 'email'],
            'myapes.ldap.bind_dn' => 'cn=ldap-bind-dn-canary,dc=cloudron',
            'myapes.ldap.bind_password' => 'ldap-bind-password-canary',
            'myapes.directory.required_groups' => [
                'myapesaccount.staff',
                'myapesaccount.admin',
                'myapesaccount.volunteer',
                'myapesaccount.student',
            ],
        ]);

        Http::preventStrayRequests();
    }

    public function test_command_passes_with_valid_discovery_and_all_configured_groups_without_using_database(): void
    {
        config(['database.default' => 'auth-readiness-must-not-use-database']);
        Http::fake([
            self::DISCOVERY => Http::response($this->validMetadata()),
        ]);

        $catalogue = $this->configuredCatalogue();
        $this->mock(LdapGroupResolver::class, function (MockInterface $mock) use ($catalogue): void {
            $mock->shouldReceive('enumerateGroups')
                ->once()
                ->andReturn($catalogue);
        });

        $exitCode = $this->callCommand();
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('OIDC configuration: ok', $output);
        $this->assertStringContainsString('OIDC discovery: ok', $output);
        $this->assertStringContainsString('LDAP bind: ok', $output);
        $this->assertStringContainsString('LDAP groups: ok (4 required)', $output);
        $this->assertStringContainsString('Authentication readiness: ok', $output);
        $this->assertCanariesAreAbsent($output);
        Http::assertSentCount(1);
    }

    #[DataProvider('missingOidcValueProvider')]
    public function test_command_rejects_each_missing_oidc_value(string $key): void
    {
        config(["myapes.oidc.{$key}" => '   ']);

        $exitCode = $this->callCommand();
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            "Authentication readiness: failed (oidc_configuration/missing_{$key})",
            $output,
        );
        $this->assertCanariesAreAbsent($output);
        Http::assertNothingSent();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function missingOidcValueProvider(): iterable
    {
        yield 'issuer' => ['issuer'];
        yield 'client id' => ['client_id'];
        yield 'client secret' => ['client_secret'];
        yield 'redirect URI' => ['redirect_uri'];
    }

    public function test_command_rejects_a_redirect_uri_that_does_not_match_the_application_origin(): void
    {
        config(['myapes.oidc.redirect_uri' => 'https://untrusted.example/staff/auth/callback']);

        $exitCode = $this->callCommand();
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            'Authentication readiness: failed (oidc_configuration/redirect_uri_mismatch)',
            $output,
        );
        $this->assertCanariesAreAbsent($output);
        Http::assertNothingSent();
    }

    public function test_command_rejects_missing_required_configured_scopes(): void
    {
        config(['myapes.oidc.scopes' => ['openid', 'email']]);

        $exitCode = $this->callCommand();
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            'Authentication readiness: failed (oidc_configuration/required_scope_missing)',
            $output,
        );
        $this->assertCanariesAreAbsent($output);
        Http::assertNothingSent();
    }

    public function test_command_rejects_an_invalid_issuer_without_making_a_request(): void
    {
        config(['myapes.oidc.issuer' => 'http://oidc-issuer-canary.example/openid']);

        $exitCode = $this->callCommand();
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            'Authentication readiness: failed (oidc_discovery/invalid_issuer_url)',
            $output,
        );
        $this->assertCanariesAreAbsent($output);
        Http::assertNothingSent();
    }

    public function test_command_sanitizes_a_discovery_transport_exception(): void
    {
        Http::fake(static function (): never {
            throw new \RuntimeException(
                'oidc-http-transport-canary for person-canary@example.test',
            );
        });

        $exitCode = $this->callCommand();
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            'Authentication readiness: failed (oidc_discovery/request_failed)',
            $output,
        );
        $this->assertCanariesAreAbsent($output);
    }

    public function test_command_rejects_an_unsuccessful_discovery_response(): void
    {
        Http::fake([
            self::DISCOVERY => Http::response(
                'oidc-http-response-canary',
                503,
            ),
        ]);

        $exitCode = $this->callCommand();
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            'Authentication readiness: failed (oidc_discovery/http_failure)',
            $output,
        );
        $this->assertCanariesAreAbsent($output);
    }

    public function test_command_rejects_invalid_discovery_json(): void
    {
        Http::fake([
            self::DISCOVERY => Http::response(
                'oidc-invalid-json-canary',
                200,
                ['Content-Type' => 'application/json'],
            ),
        ]);

        $exitCode = $this->callCommand();
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            'Authentication readiness: failed (oidc_discovery/invalid_json)',
            $output,
        );
        $this->assertCanariesAreAbsent($output);
    }

    #[DataProvider('invalidDiscoveryMetadataProvider')]
    public function test_command_rejects_incompatible_discovery_metadata(
        string $metadataKey,
        mixed $replacement,
        string $expectedReason,
    ): void {
        $metadata = $this->validMetadata();
        $metadata[$metadataKey] = $replacement;
        Http::fake([
            self::DISCOVERY => Http::response($metadata),
        ]);

        $this->mock(LdapGroupResolver::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('enumerateGroups');
        });

        $exitCode = $this->callCommand();
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            "Authentication readiness: failed (oidc_discovery/{$expectedReason})",
            $output,
        );
        $this->assertCanariesAreAbsent($output);
    }

    /**
     * @return iterable<string, array{string, mixed, string}>
     */
    public static function invalidDiscoveryMetadataProvider(): iterable
    {
        yield 'issuer mismatch' => [
            'issuer',
            'https://another-provider.example/openid',
            'issuer_mismatch',
        ];
        yield 'authorization endpoint mismatch' => [
            'authorization_endpoint',
            'https://another-provider.example/openid/auth',
            'endpoint_mismatch',
        ];
        yield 'authorization code response missing' => [
            'response_types_supported',
            ['id_token'],
            'authorization_code_missing',
        ];
        yield 'authorization code grant missing' => [
            'grant_types_supported',
            ['implicit'],
            'authorization_grant_missing',
        ];
        yield 'PKCE S256 missing' => [
            'code_challenge_methods_supported',
            ['plain'],
            'pkce_s256_missing',
        ];
        yield 'basic client authentication missing' => [
            'token_endpoint_auth_methods_supported',
            ['client_secret_post'],
            'client_auth_missing',
        ];
        yield 'RS256 missing' => [
            'id_token_signing_alg_values_supported',
            ['EdDSA'],
            'rs256_missing',
        ];
        yield 'required provider scope missing' => [
            'scopes_supported',
            ['openid', 'email'],
            'required_scope_missing',
        ];
    }

    public function test_command_sanitizes_http_and_ldap_failures(): void
    {
        Http::fake([
            self::DISCOVERY => Http::response($this->validMetadata()),
        ]);

        $this->mock(LdapGroupResolver::class, function (MockInterface $mock): void {
            $mock->shouldReceive('enumerateGroups')
                ->once()
                ->andThrow(new DirectoryUnavailable(
                    'ldap-bind-password-canary for person-canary@example.test',
                ));
        });

        $exitCode = $this->callCommand();
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            'Authentication readiness: failed (ldap_directory/unavailable)',
            $output,
        );
        $this->assertCanariesAreAbsent($output);
    }

    public function test_command_rejects_a_missing_configured_group_without_naming_it(): void
    {
        Http::fake([
            self::DISCOVERY => Http::response($this->validMetadata()),
        ]);

        $catalogue = array_values(array_filter(
            $this->configuredCatalogue(),
            static fn (array $group): bool => $group['name'] !== 'myapesaccount.staff',
        ));
        $this->mock(LdapGroupResolver::class, function (MockInterface $mock) use ($catalogue): void {
            $mock->shouldReceive('enumerateGroups')
                ->once()
                ->andReturn($catalogue);
        });

        $exitCode = $this->callCommand();
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            'Authentication readiness: failed (ldap_groups/required_group_missing)',
            $output,
        );
        $this->assertStringNotContainsString('myapesaccount.staff', $output);
        $this->assertCanariesAreAbsent($output);
    }

    public function test_command_rejects_a_required_group_without_members(): void
    {
        Http::fake([
            self::DISCOVERY => Http::response($this->validMetadata()),
        ]);

        $catalogue = $this->configuredCatalogue();
        $catalogue[0]['member_count'] = 0;
        $this->mock(LdapGroupResolver::class, function (MockInterface $mock) use ($catalogue): void {
            $mock->shouldReceive('enumerateGroups')
                ->once()
                ->andReturn($catalogue);
        });

        $exitCode = $this->callCommand();
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            'Authentication readiness: failed (ldap_groups/required_group_empty)',
            $output,
        );
        $this->assertCanariesAreAbsent($output);
    }

    public function test_command_requires_exact_required_groups_before_contacting_ldap(): void
    {
        config(['myapes.directory.required_groups' => [
            'myapesaccount.staff',
            'myapesaccount.admin',
        ]]);
        Http::fake([
            self::DISCOVERY => Http::response($this->validMetadata()),
        ]);

        $this->mock(LdapGroupResolver::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('enumerateGroups');
        });

        $exitCode = $this->callCommand();
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            'Authentication readiness: failed (ldap_groups/expected_required_groups)',
            $output,
        );
        $this->assertCanariesAreAbsent($output);
    }

    public function test_command_passes_when_volunteer_and_student_groups_are_empty(): void
    {
        Http::fake([
            self::DISCOVERY => Http::response($this->validMetadata()),
        ]);

        $this->mock(LdapGroupResolver::class, function (MockInterface $mock): void {
            $mock->shouldReceive('enumerateGroups')
                ->once()
                ->andReturn([
                    ['name' => 'myapesaccount.admin', 'external_id' => null, 'member_count' => 1],
                    ['name' => 'myapesaccount.staff', 'external_id' => null, 'member_count' => 1],
                    ['name' => 'myapesaccount.volunteer', 'external_id' => null, 'member_count' => 0],
                    ['name' => 'myapesaccount.student', 'external_id' => null, 'member_count' => 0],
                ]);
        });

        $exitCode = $this->callCommand();
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('LDAP groups: ok (4 required)', $output);
        $this->assertStringContainsString('Authentication readiness: ok', $output);
        $this->assertCanariesAreAbsent($output);
    }

    public function test_command_passes_when_legacy_myapes_groups_are_normalized_in_the_catalogue(): void
    {
        config([
            'myapes.ldap.groups_base_dn' => 'ou=groups,dc=cloudron',
        ]);
        Http::fake([
            self::DISCOVERY => Http::response($this->validMetadata()),
        ]);

        $resolver = new class extends LdapGroupResolver
        {
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
        };
        $this->app->instance(LdapGroupResolver::class, $resolver);

        $exitCode = $this->callCommand();
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('LDAP groups: ok (4 required)', $output);
        $this->assertStringContainsString('Authentication readiness: ok', $output);
        $this->assertCanariesAreAbsent($output);
    }

    public function test_command_fails_when_admin_group_is_empty(): void
    {
        Http::fake([
            self::DISCOVERY => Http::response($this->validMetadata()),
        ]);

        $this->mock(LdapGroupResolver::class, function (MockInterface $mock): void {
            $mock->shouldReceive('enumerateGroups')
                ->once()
                ->andReturn([
                    ['name' => 'myapesaccount.admin', 'external_id' => null, 'member_count' => 0],
                    ['name' => 'myapesaccount.staff', 'external_id' => null, 'member_count' => 1],
                    ['name' => 'myapesaccount.volunteer', 'external_id' => null, 'member_count' => 0],
                    ['name' => 'myapesaccount.student', 'external_id' => null, 'member_count' => 0],
                ]);
        });

        $exitCode = $this->callCommand();
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            'Authentication readiness: failed (ldap_groups/required_group_empty)',
            $output,
        );
        $this->assertCanariesAreAbsent($output);
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

    /**
     * @return array<int, string>
     */
    private function configuredGroups(): array
    {
        return [
            'myapesaccount.admin',
            'myapesaccount.staff',
            'myapesaccount.volunteer',
            'myapesaccount.student',
        ];
    }

    /**
     * @return array<int, array{name: string, external_id: ?string, member_count: int}>
     */
    private function configuredCatalogue(): array
    {
        return array_map(
            static fn (string $group): array => [
                'name' => $group,
                'external_id' => null,
                'member_count' => 1,
            ],
            $this->configuredGroups(),
        );
    }

    private function callCommand(): int
    {
        return Artisan::call('myapes:auth-check', [
            '--no-interaction' => true,
            '--no-ansi' => true,
        ]);
    }

    private function assertCanariesAreAbsent(string $output): void
    {
        foreach ([
            'oidc-client-id-canary',
            'oidc-client-secret-canary',
            'ldap-bind-dn-canary',
            'ldap-bind-password-canary',
            'person-canary@example.test',
            'oidc-issuer-canary',
            'oidc-http-transport-canary',
            'oidc-http-response-canary',
            'oidc-invalid-json-canary',
        ] as $canary) {
            $this->assertStringNotContainsString($canary, $output);
        }
    }
}
