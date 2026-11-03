<?php

namespace Tests\Unit;

use App\Exceptions\DirectoryUnavailable;
use App\Jobs\RunDirectorySync;
use App\Services\LdapGroupResolver;
use ReflectionMethod;
use stdClass;
use Tests\TestCase;

class LdapGroupResolverTest extends TestCase
{
    public function test_ldap_timeout_configuration_is_bounded(): void
    {
        $connectTimeout = config('myapes.ldap.connect_timeout_seconds');
        $searchTimeout = config('myapes.ldap.search_timeout_seconds');

        $this->assertIsInt($connectTimeout);
        $this->assertGreaterThanOrEqual(1, $connectTimeout);
        $this->assertLessThanOrEqual(30, $connectTimeout);
        $this->assertIsInt($searchTimeout);
        $this->assertGreaterThanOrEqual(1, $searchTimeout);
        $this->assertLessThanOrEqual(60, $searchTimeout);
        $this->assertLessThan(
            RunDirectorySync::TIMEOUT_SECONDS,
            $connectTimeout + $searchTimeout,
        );
    }

    public function test_every_required_connection_option_failure_is_sanitized_and_unbound(): void
    {
        $requiredOptions = [
            LDAP_OPT_PROTOCOL_VERSION,
            LDAP_OPT_REFERRALS,
            LDAP_OPT_NETWORK_TIMEOUT,
        ];

        foreach ($requiredOptions as $failingOption) {
            $resolver = new class($failingOption) extends LdapGroupResolver
            {
                /**
                 * @var array<int, int>
                 */
                public array $appliedOptions = [];

                public int $unbindCount = 0;

                public function __construct(
                    private readonly int $failingOption,
                ) {}

                protected function openConnection(
                    string $host,
                    int $port,
                ): mixed {
                    return new stdClass;
                }

                protected function applyConnectionOption(
                    mixed $connection,
                    int $option,
                    mixed $value,
                ): bool {
                    $this->appliedOptions[] = $option;

                    return $option !== $this->failingOption;
                }

                protected function unbindConnection(mixed $connection): void
                {
                    $this->unbindCount++;
                }
            };
            $connect = new ReflectionMethod(LdapGroupResolver::class, 'connect');

            try {
                $connect->invoke($resolver, [
                    'host' => 'ldap-host-canary',
                    'port' => 389,
                    'bind_dn' => 'bind-identity-canary',
                    'bind_password' => 'bind-password-canary',
                    'connect_timeout_seconds' => 5,
                    'start_tls' => false,
                ]);
                $this->fail('An LDAP option failure was accepted.');
            } catch (DirectoryUnavailable $exception) {
                $this->assertSame(
                    'LDAP connection options could not be applied.',
                    $exception->getMessage(),
                );
                $this->assertStringNotContainsString(
                    'canary',
                    $exception->getMessage(),
                );
            }

            $this->assertSame(1, $resolver->unbindCount);
            $this->assertSame(
                array_slice(
                    $requiredOptions,
                    0,
                    array_search($failingOption, $requiredOptions, true) + 1,
                ),
                $resolver->appliedOptions,
            );
        }
    }

    public function test_enumeration_returns_normalized_metadata_for_populated_and_empty_groups(): void
    {
        config([
            'myapes.ldap.groups_base_dn' => 'ou=groups,dc=cloudron',
        ]);

        $resolver = new class extends LdapGroupResolver
        {
            public string $searchedBaseDn = '';

            public string $searchedFilter = '';

            /**
             * @var array<int, string>
             */
            public array $searchedAttributes = [];

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
                $this->searchedBaseDn = $baseDn;
                $this->searchedFilter = $filter;
                $this->searchedAttributes = $attributes;

                return [
                    'count' => 2,
                    'result_code' => 0,
                    0 => [
                        'cn' => ['count' => 1, 0 => '  myapesaccount.staff  '],
                        'gidnumber' => ['count' => 1, 0 => '4101'],
                        'memberuid' => [
                            'count' => 2,
                            0 => 'member-identifier-canary-one',
                            1 => 'member-identifier-canary-two',
                        ],
                    ],
                    1 => [
                        'cn' => ['count' => 1, 0 => 'myapes.empty'],
                        'memberuid' => ['count' => 0],
                    ],
                ];
            }
        };

        $groups = $resolver->enumerateGroups();

        $this->assertSame([
            [
                'name' => 'myapesaccount.staff',
                'external_id' => '4101',
                'member_count' => 2,
            ],
        ], $groups);
        $this->assertSame('ou=groups,dc=cloudron', $resolver->searchedBaseDn);
        $this->assertSame('(objectclass=group)', $resolver->searchedFilter);
        $this->assertSame(
            ['cn', 'gidnumber', 'memberuid'],
            $resolver->searchedAttributes,
        );
        $this->assertStringNotContainsString(
            'member-identifier-canary',
            json_encode($groups, JSON_THROW_ON_ERROR),
        );
    }

    public function test_enumeration_normalizes_legacy_myapes_groups_to_canonical_names(): void
    {
        config([
            'myapes.ldap.groups_base_dn' => 'ou=groups,dc=cloudron',
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

        $groups = $resolver->enumerateGroups();

        $this->assertSame([
            'myapesaccount.admin',
            'myapesaccount.staff',
            'myapesaccount.student',
            'myapesaccount.volunteer',
        ], array_column($groups, 'name'));
        $this->assertSame(1, collect($groups)->firstWhere('name', 'myapesaccount.staff')['member_count']);
        $this->assertSame(0, collect($groups)->firstWhere('name', 'myapesaccount.volunteer')['member_count']);
    }

    public function test_enumeration_normalizes_misspelled_myapesaccont_groups_to_canonical_names(): void
    {
        config([
            'myapes.ldap.groups_base_dn' => 'ou=groups,dc=cloudron',
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
                        'cn' => ['count' => 1, 0 => 'myapesaccont.staff'],
                        'memberuid' => ['count' => 1, 0 => 'staff-member'],
                    ],
                    1 => [
                        'cn' => ['count' => 1, 0 => 'myapesaccont.admin'],
                        'memberuid' => ['count' => 1, 0 => 'admin-member'],
                    ],
                    2 => [
                        'cn' => ['count' => 1, 0 => 'myapesaccont.superadmin'],
                        'memberuid' => ['count' => 1, 0 => 'super-member'],
                    ],
                    3 => [
                        'cn' => ['count' => 1, 0 => 'myapesaccont.vounteer'],
                        'memberuid' => ['count' => 0],
                    ],
                    4 => [
                        'cn' => ['count' => 1, 0 => 'myapesaccont.student'],
                        'memberuid' => ['count' => 0],
                    ],
                ];
            }
        };

        $groups = $resolver->enumerateGroups();

        $this->assertSame([
            'myapesaccount.admin',
            'myapesaccount.staff',
            'myapesaccount.student',
            'myapesaccount.volunteer',
        ], array_column($groups, 'name'));
        $this->assertSame(1, collect($groups)->firstWhere('name', 'myapesaccount.staff')['member_count']);
        $this->assertSame(0, collect($groups)->firstWhere('name', 'myapesaccount.volunteer')['member_count']);
    }

    public function test_enumeration_normalizes_myapesaccount_vounteer_to_volunteer(): void
    {
        config([
            'myapes.ldap.groups_base_dn' => 'ou=groups,dc=cloudron',
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
                        'cn' => ['count' => 1, 0 => 'myapesaccount.staff'],
                        'memberuid' => ['count' => 1, 0 => 'staff-member'],
                    ],
                    1 => [
                        'cn' => ['count' => 1, 0 => 'myapesaccount.admin'],
                        'memberuid' => ['count' => 1, 0 => 'admin-member'],
                    ],
                    2 => [
                        'cn' => ['count' => 1, 0 => 'myapesaccount.superadmin'],
                        'memberuid' => ['count' => 1, 0 => 'super-member'],
                    ],
                    3 => [
                        'cn' => ['count' => 1, 0 => 'myapesaccount.vounteer'],
                        'memberuid' => ['count' => 0],
                    ],
                    4 => [
                        'cn' => ['count' => 1, 0 => 'myapesaccount.student'],
                        'memberuid' => ['count' => 0],
                    ],
                ];
            }
        };

        $groups = $resolver->enumerateGroups();

        $this->assertSame([
            'myapesaccount.admin',
            'myapesaccount.staff',
            'myapesaccount.student',
            'myapesaccount.volunteer',
        ], array_column($groups, 'name'));
    }

    public function test_resolve_by_email_normalizes_legacy_group_memberships(): void
    {
        config([
            'myapes.ldap.base_dn' => 'ou=users,dc=cloudron',
            'myapes.ldap.groups_base_dn' => 'ou=groups,dc=cloudron',
            'myapes.ldap.user_filter' => '(mail=%s)',
            'myapes.ldap.group_attribute' => 'memberOf',
        ]);

        $resolver = new class extends LdapGroupResolver
        {
            /**
             * @param  array<string, mixed>  $config
             * @param  array<int, string>  $attributes
             * @return array<string|int, mixed>
             */
            protected function fetchSearchEntries(
                array $config,
                string $baseDn,
                string $filter,
                array $attributes,
            ): array {
                return [
                    'count' => 1,
                    'result_code' => 0,
                    0 => [
                        'memberof' => [
                            'count' => 2,
                            0 => 'cn=myapes.superadmins,ou=groups,dc=cloudron',
                            1 => 'cn=myapes.staff,ou=groups,dc=cloudron',
                        ],
                    ],
                ];
            }
        };

        $this->assertSame(
            ['myapesaccount.admin', 'myapesaccount.staff'],
            $resolver->resolveByEmail('legacy-person-canary@example.test'),
        );
    }

    public function test_resolve_by_email_normalizes_misspelled_myapesaccont_memberships(): void
    {
        config([
            'myapes.ldap.base_dn' => 'ou=users,dc=cloudron',
            'myapes.ldap.groups_base_dn' => 'ou=groups,dc=cloudron',
            'myapes.ldap.user_filter' => '(mail=%s)',
            'myapes.ldap.group_attribute' => 'memberOf',
        ]);

        $resolver = new class extends LdapGroupResolver
        {
            /**
             * @param  array<string, mixed>  $config
             * @param  array<int, string>  $attributes
             * @return array<string|int, mixed>
             */
            protected function fetchSearchEntries(
                array $config,
                string $baseDn,
                string $filter,
                array $attributes,
            ): array {
                return [
                    'count' => 1,
                    'result_code' => 0,
                    0 => [
                        'memberof' => [
                            'count' => 2,
                            0 => 'cn=myapesaccont.superadmin,ou=groups,dc=cloudron',
                            1 => 'cn=myapesaccont.vounteer,ou=groups,dc=cloudron',
                        ],
                    ],
                ];
            }
        };

        $this->assertSame(
            ['myapesaccount.admin', 'myapesaccount.volunteer'],
            $resolver->resolveByEmail('misspelled-person-canary@example.test'),
        );
    }

    public function test_enumeration_rejects_a_partial_ldap_result_as_directory_unavailability(): void
    {
        config([
            'myapes.ldap.groups_base_dn' => 'ou=groups,dc=cloudron',
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
                    'count' => 0,
                    'result_code' => 4,
                ];
            }
        };

        $this->expectException(DirectoryUnavailable::class);
        $this->expectExceptionMessage(
            'LDAP search did not complete successfully.',
        );

        $resolver->enumerateGroups();
    }

    public function test_every_ldap_search_boundary_rejects_non_success_result_codes(): void
    {
        config([
            'myapes.ldap.base_dn' => 'ou=users,dc=cloudron',
            'myapes.ldap.groups_base_dn' => 'ou=groups,dc=cloudron',
            'myapes.ldap.user_filter' => '(mail=%s)',
            'myapes.ldap.group_attribute' => 'memberOf',
        ]);

        $resolver = new class extends LdapGroupResolver
        {
            /**
             * @param  array<string, mixed>  $config
             * @param  array<int, string>  $attributes
             * @return array<string|int, mixed>
             */
            protected function fetchSearchEntries(
                array $config,
                string $baseDn,
                string $filter,
                array $attributes,
            ): array {
                return [
                    'count' => 0,
                    'result_code' => 3,
                ];
            }
        };
        $operations = [
            static fn (): array => $resolver->enumerateGroups(),
            static fn (): array => $resolver->resolveByEmail(
                'identity-canary@example.test',
            ),
            static fn (): array => $resolver->existingGroups([
                'myapesaccount.staff',
            ]),
        ];

        foreach ($operations as $operation) {
            try {
                $operation();
                $this->fail('An incomplete LDAP operation was accepted.');
            } catch (DirectoryUnavailable $exception) {
                $this->assertSame(
                    'LDAP search did not complete successfully.',
                    $exception->getMessage(),
                );
            }
        }
    }
}
