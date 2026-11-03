<?php

namespace App\Services;

use App\Exceptions\AuthorizationLifecycleException;
use App\Support\AccessCompatibilityDatabaseGuard;
use App\Support\AuthorizationCompatibilityDatabaseGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AuthorizationPhaseBSchemaInspector
{
    /**
     * @var array<string, array<int, string>>
     */
    private const COLUMNS = [
        'users' => [
            'id',
            'oidc_sub',
            'name',
            'identity_type',
            'email',
            'email_verified_at',
            'legacy_access_level',
            'ldap_groups',
            'password',
            'remember_token',
            'authorization_epoch',
            'suspended_at',
            'suspended_by',
            'suspension_reason',
            'created_at',
            'updated_at',
        ],
        'roles' => [
            'id',
            'team_id',
            'name',
            'guard_name',
            'is_protected',
            'created_at',
            'updated_at',
        ],
        'permissions' => [
            'id',
            'name',
            'guard_name',
            'is_code_owned',
            'created_at',
            'updated_at',
        ],
        'role_has_permissions' => ['permission_id', 'role_id'],
        'model_has_roles' => [
            'role_id',
            'team_id',
            'model_type',
            'model_id',
        ],
        'model_has_permissions' => [
            'permission_id',
            'team_id',
            'model_type',
            'model_id',
        ],
        'authorization_states' => [
            'id',
            'authorization_epoch',
            'cutover_completed_at',
            'session_cutover_completed_at',
            'directory_sync_owner_token',
            'directory_sync_expires_at',
            'created_at',
            'updated_at',
        ],
        'directory_groups' => [
            'id',
            'name',
            'external_id',
            'member_count',
            'status',
            'first_seen_at',
            'last_seen_at',
            'last_synced_at',
            'created_at',
            'updated_at',
        ],
        'directory_sync_runs' => [
            'id',
            'source',
            'status',
            'queue_job_uuid',
            'queue_attempt',
            'lease_owner_token',
            'started_at',
            'finished_at',
            'groups_seen',
            'groups_missing',
            'error_code',
            'created_at',
            'updated_at',
        ],
        'directory_group_role_mappings' => [
            'id',
            'directory_group_id',
            'role_id',
            'is_immutable',
            'created_at',
            'updated_at',
        ],
        'role_sources' => [
            'id',
            'user_id',
            'role_id',
            'source',
            'source_key',
            'directory_group_id',
            'granted_by',
            'created_at',
            'updated_at',
        ],
        'permission_sources' => [
            'id',
            'user_id',
            'permission_id',
            'team_id',
            'source',
            'source_key',
            'granted_by',
            'created_at',
            'updated_at',
        ],
    ];

    /**
     * @var array<string, array<int, array{array<int, string>, ?string}>>
     */
    private const INDEXES = [
        'users' => [
            [['id'], 'primary'],
            [['oidc_sub'], 'unique'],
            [['email'], 'unique'],
            [['identity_type'], null],
            [['legacy_access_level'], null],
        ],
        'roles' => [
            [['id'], 'primary'],
            [['name', 'guard_name'], 'unique'],
            [['team_id'], null],
            [['is_protected'], null],
        ],
        'permissions' => [
            [['id'], 'primary'],
            [['name', 'guard_name'], 'unique'],
            [['is_code_owned'], null],
        ],
        'role_has_permissions' => [
            [['permission_id', 'role_id'], 'primary'],
        ],
        'model_has_roles' => [
            [['role_id', 'model_id', 'model_type'], 'primary'],
            [['model_id', 'model_type'], null],
            [['team_id'], null],
        ],
        'model_has_permissions' => [
            [['permission_id', 'model_id', 'model_type'], 'primary'],
            [['model_id', 'model_type'], null],
            [['team_id'], null],
        ],
        'authorization_states' => [
            [['id'], 'primary'],
        ],
        'directory_groups' => [
            [['id'], 'primary'],
            [['name'], 'unique'],
            [['external_id'], 'unique'],
            [['status'], null],
        ],
        'directory_sync_runs' => [
            [['id'], 'primary'],
            [['source'], null],
            [['status'], null],
            [['queue_job_uuid', 'queue_attempt'], 'unique'],
        ],
        'directory_group_role_mappings' => [
            [['id'], 'primary'],
            [['directory_group_id', 'role_id'], 'unique'],
        ],
        'role_sources' => [
            [['id'], 'primary'],
            [['source'], null],
            [['user_id', 'role_id', 'source_key'], 'unique'],
        ],
        'permission_sources' => [
            [['id'], 'primary'],
            [['source'], null],
            [['team_id'], null],
            [['user_id', 'permission_id', 'source_key'], 'unique'],
        ],
    ];

    /**
     * @var array<string, array<int, array{
     *     array<int, string>,
     *     string,
     *     array<int, string>,
     *     string
     * }>>
     */
    private const FOREIGN_KEYS = [
        'users' => [
            [['suspended_by'], 'users', ['id'], 'set null'],
        ],
        'role_has_permissions' => [
            [['permission_id'], 'permissions', ['id'], 'cascade'],
            [['role_id'], 'roles', ['id'], 'cascade'],
        ],
        'model_has_roles' => [
            [['role_id'], 'roles', ['id'], 'cascade'],
        ],
        'model_has_permissions' => [
            [['permission_id'], 'permissions', ['id'], 'cascade'],
        ],
        'directory_group_role_mappings' => [
            [['directory_group_id'], 'directory_groups', ['id'], 'cascade'],
            [['role_id'], 'roles', ['id'], 'cascade'],
        ],
        'role_sources' => [
            [['user_id'], 'users', ['id'], 'cascade'],
            [['role_id'], 'roles', ['id'], 'cascade'],
            [['directory_group_id'], 'directory_groups', ['id'], 'cascade'],
            [['granted_by'], 'users', ['id'], 'set null'],
        ],
        'permission_sources' => [
            [['user_id'], 'users', ['id'], 'cascade'],
            [['permission_id'], 'permissions', ['id'], 'cascade'],
            [['granted_by'], 'users', ['id'], 'set null'],
        ],
    ];

    /**
     * @var array<string, array<string, array<int, string>>>
     */
    private const VALUE_CONSTRAINTS = [
        'directory_sync_runs' => [
            'source' => ['manual', 'scheduled'],
            'status' => ['running', 'succeeded', 'failed'],
        ],
        'role_sources' => [
            'source' => [
                'system',
                'directory',
                'local',
                'legacy-compatibility',
            ],
        ],
        'permission_sources' => [
            'source' => ['system', 'local'],
        ],
    ];

    public function __construct(
        private readonly AccessCompatibilityDatabaseGuard $phaseAGuard,
        private readonly AuthorizationCompatibilityDatabaseGuard $phaseBGuard,
    ) {}

    public function assertReady(): void
    {
        $this->assertStructure();

        if (Schema::hasColumn('users', 'role')
            || $this->phaseAGuard->isInstalled()
            || ! $this->hasVerifiedPhaseBState()) {
            $this->fail();
        }
    }

    public function assertCutoverRetryReady(): void
    {
        $this->assertStructure();

        if (! Schema::hasColumn('users', 'role')
            || $this->phaseAGuard->isInstalled()
            || ! $this->hasVerifiedPhaseBState()) {
            $this->fail();
        }
    }

    private function assertStructure(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)
                || ! Schema::hasColumns($table, $columns)) {
                $this->fail();
            }
        }

        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as [$columns, $type]) {
                if (! Schema::hasIndex($table, $columns, $type)) {
                    $this->fail();
                }
            }
        }

        foreach (self::FOREIGN_KEYS as $table => $expectedKeys) {
            $actualKeys = Schema::getForeignKeys($table);

            foreach ($expectedKeys as $expected) {
                if (! $this->hasForeignKey($actualKeys, ...$expected)) {
                    $this->fail();
                }
            }
        }

        foreach (self::VALUE_CONSTRAINTS as $table => $columns) {
            foreach ($columns as $column => $values) {
                if (! $this->hasExactValueConstraint(
                    $table,
                    $column,
                    $values,
                )) {
                    $this->fail();
                }
            }
        }
    }

    private function hasVerifiedPhaseBState(): bool
    {
        return $this->phaseBGuardSatisfied()
            && DB::table('authorization_states')->count() === 1
            && DB::table('authorization_states')
                ->where('id', 1)
                ->whereNotNull('cutover_completed_at')
                ->count() === 1;
    }

    private function phaseBGuardSatisfied(): bool
    {
        if ($this->phaseBGuard->isInstalled()) {
            return true;
        }

        if ($this->phaseBGuard->isSuperAdminRevisionInstalled()) {
            return $this->hasPendingMigration(
                '2026_11_02_000100_merge_super_admin_into_administrator',
            );
        }

        return $this->phaseBGuard->isLegacyInstalled()
            && $this->hasPendingMigration(
                '2026_08_24_000003_extend_directory_authorization_roles',
            );
    }

    private function hasPendingMigration(string $migration): bool
    {
        if (! Schema::hasTable('migrations')) {
            return false;
        }

        return ! DB::table('migrations')
            ->where('migration', $migration)
            ->exists()
            && file_exists(database_path("migrations/{$migration}.php"));
    }

    /**
     * @param  array<int, array{
     *     name: ?string,
     *     columns: array<int, string>,
     *     foreign_schema: ?string,
     *     foreign_table: string,
     *     foreign_columns: array<int, string>,
     *     on_update: ?string,
     *     on_delete: ?string
     * }>  $keys
     * @param  array<int, string>  $columns
     * @param  array<int, string>  $foreignColumns
     */
    private function hasForeignKey(
        array $keys,
        array $columns,
        string $foreignTable,
        array $foreignColumns,
        string $onDelete,
    ): bool {
        foreach ($keys as $key) {
            if ($key['columns'] === $columns
                && $key['foreign_table'] === $foreignTable
                && $key['foreign_columns'] === $foreignColumns
                && strtolower((string) $key['on_delete']) === $onDelete) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $values
     */
    private function hasExactValueConstraint(
        string $table,
        string $column,
        array $values,
    ): bool {
        $driver = DB::connection()->getDriverName();
        $expected = $column.'in('.implode(
            ',',
            array_map(
                static fn (string $value): string => "'{$value}'",
                $values,
            ),
        ).')';

        if ($driver === 'sqlite') {
            $definition = DB::selectOne(
                "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?",
                [$table],
            )?->sql;

            return is_string($definition)
                && str_contains(
                    $this->normalizeDefinition($definition),
                    $expected,
                );
        }

        if ($driver === 'mysql') {
            $columnType = DB::table('information_schema.COLUMNS')
                ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
                ->where('TABLE_NAME', $table)
                ->where('COLUMN_NAME', $column)
                ->value('COLUMN_TYPE');

            return is_string($columnType)
                && $this->normalizeDefinition($columnType)
                    === 'enum('.implode(
                        ',',
                        array_map(
                            static fn (string $value): string => "'{$value}'",
                            $values,
                        ),
                    ).')';
        }

        return false;
    }

    private function normalizeDefinition(string $definition): string
    {
        return strtolower((string) preg_replace(
            '/[\s"`]+/',
            '',
            $definition,
        ));
    }

    private function fail(): never
    {
        throw new AuthorizationLifecycleException(
            'authorization_schema',
        );
    }
}
