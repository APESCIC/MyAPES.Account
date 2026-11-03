<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class AuthorizationCompatibilityDatabaseGuard
{
    private const INSERT_TRIGGER = 'users_authorization_compatibility_insert';

    private const UPDATE_TRIGGER = 'users_authorization_compatibility_update';

    private const USER_CLEANUP_BEFORE_DELETE_TRIGGER = 'users_authorization_cleanup_before_delete';

    private const USER_CLEANUP_AFTER_DELETE_TRIGGER = 'users_authorization_cleanup_after_delete';

    private const ROLE_PROTECTION_DELETE_TRIGGER = 'roles_authorization_protection_delete';

    private const ROLE_PROTECTION_UPDATE_TRIGGER = 'roles_authorization_protection_update';

    private const ROLE_INSERT_TRIGGER = 'model_roles_require_source_insert';

    private const ROLE_UPDATE_TRIGGER = 'model_roles_require_source_update';

    private const ROLE_DELETE_TRIGGER = 'model_roles_require_source_delete';

    private const PERMISSION_INSERT_TRIGGER = 'model_permissions_no_direct_insert';

    private const PERMISSION_UPDATE_TRIGGER = 'model_permissions_no_direct_update';

    private const PERMISSION_DELETE_TRIGGER = 'model_permissions_no_direct_delete';

    /**
     * Morph alias for App\Core\Accounts\User after #281 Relation::enforceMorphMap().
     * Encoded as CHAR(...) so trigger SQL stays free of quote-escaping hazards.
     * Bytes: u(117) s(115) e(101) r(114) => "user".
     */
    private const USER_MODEL_EXPRESSION = 'CHAR(117, 115, 101, 114)';

    /**
     * Pre-#281 FQCN expression still present on live DBs until migrate rewrites
     * triggers. Preflight must accept either form before the atomic switch.
     * Bytes: App\Core\Accounts\User.
     */
    private const LEGACY_USER_MODEL_EXPRESSION = 'CHAR(65, 112, 112, 92, 77, 111, 100, 101, 108, 115, 92, 85, 115, 101, 114)';

    public function install(bool $force = false): void
    {
        $driver = $this->driverName();

        if (! in_array($driver, ['sqlite', 'mysql'], true)) {
            throw new RuntimeException(
                'Authorization compatibility database guard is unsupported.',
            );
        }

        if (! $force && $this->isInstalled()) {
            return;
        }

        $this->drop();

        try {
            match ($driver) {
                'sqlite' => $this->installSqlite(),
                'mysql' => $this->installMysql(),
            };

            if (! $this->isInstalled()) {
                throw new RuntimeException(
                    'Authorization compatibility database guard verification failed.',
                );
            }
        } catch (Throwable $exception) {
            $this->drop();

            throw $exception;
        }
    }

    public function reconcileLegacySources(): void
    {
        if (! $this->isInstalled()) {
            throw new RuntimeException(
                'Authorization compatibility database guard verification failed.',
            );
        }

        DB::statement(
            'UPDATE users
             SET legacy_access_level = legacy_access_level',
        );

        if (! $this->isInstalled()) {
            throw new RuntimeException(
                'Authorization compatibility database guard verification failed.',
            );
        }
    }

    public function drop(): void
    {
        foreach ($this->triggerNames() as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
    }

    public function upgrade(): void
    {
        $this->install(force: true);
    }

    public function isLegacyInstalled(): bool
    {
        if ($this->isInstalled()) {
            return false;
        }

        $driver = $this->driverName();

        if ($driver === 'sqlite') {
            $insertDefinition = DB::table('sqlite_master')
                ->where('type', 'trigger')
                ->where('name', self::INSERT_TRIGGER)
                ->value('sql');

            if (! is_string($insertDefinition)) {
                return false;
            }

            $normalized = $this->compactDefinition($insertDefinition);

            return str_contains(
                $normalized,
                "new.legacy_access_levelnotin('service_user','staff','admin','superadmin')",
            )
                && ! str_contains(
                    $normalized,
                    "new.legacy_access_levelnotin('service_user','student','volunteer'",
                )
                && $this->legacyTriggerInventoryComplete();
        }

        if ($driver === 'mysql') {
            $insertDefinition = collect(DB::select(
                'SELECT action_statement AS definition
                 FROM information_schema.triggers
                 WHERE trigger_schema = DATABASE()
                   AND trigger_name = ?',
                [self::INSERT_TRIGGER],
            ))->value('definition');

            if (! is_string($insertDefinition)) {
                return false;
            }

            $normalized = $this->compactDefinition($insertDefinition);

            return str_contains(
                $normalized,
                "new.legacy_access_levelnotin('service_user','staff','admin','superadmin')",
            )
                && ! str_contains(
                    $normalized,
                    "new.legacy_access_levelnotin('service_user','student','volunteer'",
                )
                && $this->legacyTriggerInventoryComplete();
        }

        return false;
    }

    public function isInstalled(): bool
    {
        return $this->definitionsInstalled(withSuperAdmin: false);
    }

    /**
     * Guard revision from before #459 removed the super-admin role. Preflight
     * accepts it only while the merge migration is still pending.
     */
    public function isSuperAdminRevisionInstalled(): bool
    {
        return $this->definitionsInstalled(withSuperAdmin: true);
    }

    private function definitionsInstalled(bool $withSuperAdmin): bool
    {
        $driver = $this->driverName();

        if ($driver === 'sqlite') {
            $expected = $this->sqliteDefinitions($withSuperAdmin);
            $actual = DB::table('sqlite_master')
                ->where('type', 'trigger')
                ->whereIn('name', array_keys($expected))
                ->get(['name', 'sql'])
                ->keyBy('name');

            if ($actual->count() !== count($expected)) {
                return false;
            }

            foreach ($expected as $name => $definition) {
                if ($this->normalizeDefinitionForComparison((string) $actual[$name]->sql)
                    !== $this->normalizeDefinitionForComparison($definition)) {
                    return false;
                }
            }

            return true;
        }

        if ($driver === 'mysql') {
            $expected = $this->mysqlDefinitions($withSuperAdmin);
            $actual = collect(DB::select(
                'SELECT trigger_name AS name,
                        event_object_table AS table_name,
                        action_timing AS timing,
                        event_manipulation AS event_name,
                        action_statement AS definition
                 FROM information_schema.triggers
                 WHERE trigger_schema = DATABASE()',
            ))
                ->filter(
                    static fn (object $trigger): bool => array_key_exists(
                        (string) $trigger->name,
                        $expected,
                    ),
                )
                ->keyBy('name');

            if ($actual->count() !== count($expected)) {
                return false;
            }

            foreach ($expected as $name => $definition) {
                $trigger = $actual[$name];

                if ((string) $trigger->table_name !== $definition['table']
                    || strtoupper((string) $trigger->timing) !== $definition['timing']
                    || strtoupper((string) $trigger->event_name) !== $definition['event']
                    || $this->normalizeDefinitionForComparison((string) $trigger->definition)
                        !== $this->normalizeDefinitionForComparison($definition['body'])) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    protected function driverName(): string
    {
        return DB::connection()->getDriverName();
    }

    protected function installSqlite(): void
    {
        foreach ($this->sqliteDefinitions() as $definition) {
            DB::unprepared($definition);
        }
    }

    protected function installMysql(): void
    {
        foreach ($this->mysqlDefinitions() as $name => $definition) {
            DB::unprepared(
                "CREATE TRIGGER {$name}
                 {$definition['timing']} {$definition['event']} ON {$definition['table']}
                 FOR EACH ROW
                 {$definition['body']}",
            );
        }
    }

    /**
     * @return array<string, string>
     */
    private function sqliteDefinitions(bool $withSuperAdmin = false): array
    {
        $userModel = self::USER_MODEL_EXPRESSION;
        $mapping = $this->legacyRoleMapping('NEW.legacy_access_level', $withSuperAdmin);
        $protectedRole = $this->protectedRoleIdentity('OLD', $withSuperAdmin);
        $accessLevels = $this->legacyAccessLevels($withSuperAdmin);

        return [
            self::INSERT_TRIGGER => 'CREATE TRIGGER '.self::INSERT_TRIGGER."
                AFTER INSERT ON users
                BEGIN
                    SELECT RAISE(ABORT, 'Unsupported legacy access level.')
                    WHERE NEW.legacy_access_level IS NULL
                       OR TRIM(NEW.legacy_access_level) = ''
                       OR NEW.legacy_access_level NOT IN (
                           {$accessLevels}
                       );
                    SELECT RAISE(ABORT, 'Protected authorization role is unavailable.')
                    WHERE NOT EXISTS (
                        SELECT 1
                        FROM roles
                        WHERE guard_name = 'web'
                          AND is_protected = 1
                          AND name = {$mapping}
                    );
                    INSERT INTO role_sources (
                        user_id,
                        role_id,
                        `source`,
                        source_key,
                        directory_group_id,
                        granted_by,
                        created_at,
                        updated_at
                    )
                    SELECT
                        NEW.id,
                        roles.id,
                        'legacy-compatibility',
                        'legacy-compatibility',
                        NULL,
                        NULL,
                        CURRENT_TIMESTAMP,
                        CURRENT_TIMESTAMP
                    FROM roles
                    WHERE roles.guard_name = 'web'
                      AND roles.is_protected = 1
                      AND roles.name = {$mapping};
                    INSERT OR IGNORE INTO model_has_roles (
                        role_id,
                        model_type,
                        model_id
                    )
                    SELECT
                        roles.id,
                        {$userModel},
                        NEW.id
                    FROM roles
                    WHERE roles.guard_name = 'web'
                      AND roles.is_protected = 1
                      AND roles.name = {$mapping};
                END",
            self::UPDATE_TRIGGER => 'CREATE TRIGGER '.self::UPDATE_TRIGGER."
                AFTER UPDATE OF legacy_access_level ON users
                BEGIN
                    SELECT RAISE(ABORT, 'Unsupported legacy access level.')
                    WHERE NEW.legacy_access_level IS NULL
                       OR TRIM(NEW.legacy_access_level) = ''
                       OR NEW.legacy_access_level NOT IN (
                           {$accessLevels}
                       );
                    SELECT RAISE(ABORT, 'Protected authorization role is unavailable.')
                    WHERE NOT EXISTS (
                        SELECT 1
                        FROM roles
                        WHERE guard_name = 'web'
                          AND is_protected = 1
                          AND name = {$mapping}
                    );
                    DELETE FROM role_sources
                    WHERE user_id = NEW.id
                      AND source_key = 'legacy-compatibility'
                      AND role_id IN (
                          SELECT id
                          FROM roles
                          WHERE guard_name = 'web'
                            AND is_protected = 1
                      )
                      AND (
                          `source` <> 'legacy-compatibility'
                          OR role_id <> (
                              SELECT id
                              FROM roles
                              WHERE guard_name = 'web'
                                AND is_protected = 1
                                AND name = {$mapping}
                          )
                          OR directory_group_id IS NOT NULL
                          OR granted_by IS NOT NULL
                      );
                    INSERT OR IGNORE INTO role_sources (
                        user_id,
                        role_id,
                        `source`,
                        source_key,
                        directory_group_id,
                        granted_by,
                        created_at,
                        updated_at
                    )
                    SELECT
                        NEW.id,
                        roles.id,
                        'legacy-compatibility',
                        'legacy-compatibility',
                        NULL,
                        NULL,
                        CURRENT_TIMESTAMP,
                        CURRENT_TIMESTAMP
                    FROM roles
                    WHERE roles.guard_name = 'web'
                      AND roles.is_protected = 1
                      AND roles.name = {$mapping};
                    DELETE FROM model_has_roles
                    WHERE model_type = {$userModel}
                      AND model_id = NEW.id
                      AND role_id IN (
                          SELECT id
                          FROM roles
                          WHERE guard_name = 'web'
                            AND is_protected = 1
                      )
                      AND NOT EXISTS (
                          SELECT 1
                          FROM role_sources
                          WHERE role_sources.user_id = NEW.id
                            AND role_sources.role_id = model_has_roles.role_id
                      );
                    INSERT OR IGNORE INTO model_has_roles (
                        role_id,
                        model_type,
                        model_id
                    )
                    SELECT
                        roles.id,
                        {$userModel},
                        NEW.id
                    FROM roles
                    WHERE roles.guard_name = 'web'
                      AND roles.is_protected = 1
                      AND roles.name = {$mapping};
                END",
            self::USER_CLEANUP_BEFORE_DELETE_TRIGGER => 'CREATE TRIGGER '.self::USER_CLEANUP_BEFORE_DELETE_TRIGGER."
                BEFORE DELETE ON users
                BEGIN
                    DELETE FROM permission_sources
                    WHERE user_id = OLD.id;
                    DELETE FROM model_has_permissions
                    WHERE model_type = {$userModel}
                      AND model_id = OLD.id;
                    DELETE FROM role_sources
                    WHERE user_id = OLD.id;
                    DELETE FROM model_has_roles
                    WHERE model_type = {$userModel}
                      AND model_id = OLD.id;
                END",
            self::USER_CLEANUP_AFTER_DELETE_TRIGGER => 'CREATE TRIGGER '.self::USER_CLEANUP_AFTER_DELETE_TRIGGER."
                AFTER DELETE ON users
                BEGIN
                    DELETE FROM model_has_roles
                    WHERE model_type = {$userModel}
                      AND model_id = OLD.id;
                    DELETE FROM model_has_permissions
                    WHERE model_type = {$userModel}
                      AND model_id = OLD.id;
                END",
            self::ROLE_PROTECTION_DELETE_TRIGGER => 'CREATE TRIGGER '.self::ROLE_PROTECTION_DELETE_TRIGGER."
                BEFORE DELETE ON roles
                BEGIN
                    SELECT RAISE(ABORT, 'Protected authorization roles cannot be deleted.')
                    WHERE OLD.is_protected = 1
                       OR ({$protectedRole});
                    SELECT RAISE(ABORT, 'Assigned authorization roles cannot be deleted.')
                    WHERE EXISTS (
                        SELECT 1
                        FROM role_sources
                        WHERE role_id = OLD.id
                    ) OR EXISTS (
                        SELECT 1
                        FROM model_has_roles
                        WHERE role_id = OLD.id
                    );
                END",
            self::ROLE_PROTECTION_UPDATE_TRIGGER => 'CREATE TRIGGER '.self::ROLE_PROTECTION_UPDATE_TRIGGER."
                BEFORE UPDATE ON roles
                BEGIN
                    SELECT RAISE(ABORT, 'Protected authorization roles are immutable.')
                    WHERE {$protectedRole};
                END",
            self::ROLE_INSERT_TRIGGER => 'CREATE TRIGGER '.self::ROLE_INSERT_TRIGGER."
                BEFORE INSERT ON model_has_roles
                WHEN NEW.model_type = {$userModel}
                BEGIN
                    SELECT RAISE(ABORT, 'User role assignment requires provenance.')
                    WHERE NEW.team_id IS NOT NULL
                       OR NOT EXISTS (
                        SELECT 1
                        FROM role_sources
                        WHERE user_id = NEW.model_id
                          AND role_id = NEW.role_id
                    );
                END",
            self::ROLE_UPDATE_TRIGGER => 'CREATE TRIGGER '.self::ROLE_UPDATE_TRIGGER."
                BEFORE UPDATE ON model_has_roles
                WHEN OLD.model_type = {$userModel}
                  OR NEW.model_type = {$userModel}
                BEGIN
                    SELECT RAISE(ABORT, 'Provenanced user roles cannot be reassigned directly.')
                    WHERE (
                        OLD.model_type = {$userModel}
                        AND EXISTS (
                            SELECT 1
                            FROM role_sources
                            WHERE user_id = OLD.model_id
                              AND role_id = OLD.role_id
                        )
                    ) OR (
                        NEW.model_type = {$userModel}
                        AND (
                            NEW.team_id IS NOT NULL
                            OR NOT EXISTS (
                                SELECT 1
                                FROM role_sources
                                WHERE user_id = NEW.model_id
                                  AND role_id = NEW.role_id
                            )
                        )
                    );
                END",
            self::ROLE_DELETE_TRIGGER => 'CREATE TRIGGER '.self::ROLE_DELETE_TRIGGER."
                BEFORE DELETE ON model_has_roles
                WHEN OLD.model_type = {$userModel}
                BEGIN
                    SELECT RAISE(ABORT, 'Provenanced user roles cannot be detached directly.')
                    WHERE EXISTS (
                        SELECT 1
                        FROM role_sources
                        WHERE user_id = OLD.model_id
                          AND role_id = OLD.role_id
                    );
                END",
            self::PERMISSION_INSERT_TRIGGER => $this->sqlitePermissionTrigger(
                self::PERMISSION_INSERT_TRIGGER,
                'INSERT',
                $userModel,
            ),
            self::PERMISSION_UPDATE_TRIGGER => $this->sqlitePermissionTrigger(
                self::PERMISSION_UPDATE_TRIGGER,
                'UPDATE',
                $userModel,
            ),
            self::PERMISSION_DELETE_TRIGGER => $this->sqlitePermissionTrigger(
                self::PERMISSION_DELETE_TRIGGER,
                'DELETE',
                $userModel,
            ),
        ];
    }

    /**
     * @return array<string, array{table: string, timing: string, event: string, body: string}>
     */
    private function mysqlDefinitions(bool $withSuperAdmin = false): array
    {
        $userModel = self::USER_MODEL_EXPRESSION;
        $mapping = $this->legacyRoleMapping('NEW.legacy_access_level', $withSuperAdmin);
        $protectedRole = $this->protectedRoleIdentity('OLD', $withSuperAdmin);
        $accessLevels = $this->legacyAccessLevels($withSuperAdmin);

        return [
            self::INSERT_TRIGGER => [
                'table' => 'users',
                'timing' => 'AFTER',
                'event' => 'INSERT',
                'body' => "BEGIN
                    IF NEW.legacy_access_level IS NULL
                       OR TRIM(NEW.legacy_access_level) = ''
                       OR NEW.legacy_access_level NOT IN (
                           {$accessLevels}
                       )
                    THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'Unsupported legacy access level.';
                    END IF;
                    IF NOT EXISTS (
                        SELECT 1
                        FROM roles
                        WHERE guard_name = 'web'
                          AND is_protected = 1
                          AND name = {$mapping}
                    )
                    THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'Protected authorization role is unavailable.';
                    END IF;
                    INSERT INTO role_sources (
                        user_id,
                        role_id,
                        `source`,
                        source_key,
                        directory_group_id,
                        granted_by,
                        created_at,
                        updated_at
                    )
                    SELECT
                        NEW.id,
                        roles.id,
                        'legacy-compatibility',
                        'legacy-compatibility',
                        NULL,
                        NULL,
                        CURRENT_TIMESTAMP,
                        CURRENT_TIMESTAMP
                    FROM roles
                    WHERE roles.guard_name = 'web'
                      AND roles.is_protected = 1
                      AND roles.name = {$mapping};
                    INSERT IGNORE INTO model_has_roles (
                        role_id,
                        model_type,
                        model_id
                    )
                    SELECT
                        roles.id,
                        {$userModel},
                        NEW.id
                    FROM roles
                    WHERE roles.guard_name = 'web'
                      AND roles.is_protected = 1
                      AND roles.name = {$mapping};
                END",
            ],
            self::UPDATE_TRIGGER => [
                'table' => 'users',
                'timing' => 'AFTER',
                'event' => 'UPDATE',
                'body' => "BEGIN
                        IF NEW.legacy_access_level IS NULL
                           OR TRIM(NEW.legacy_access_level) = ''
                           OR NEW.legacy_access_level NOT IN (
                               {$accessLevels}
                           )
                        THEN
                            SIGNAL SQLSTATE '45000'
                                SET MESSAGE_TEXT = 'Unsupported legacy access level.';
                        END IF;
                        IF NOT EXISTS (
                            SELECT 1
                            FROM roles
                            WHERE guard_name = 'web'
                              AND is_protected = 1
                              AND name = {$mapping}
                        )
                        THEN
                            SIGNAL SQLSTATE '45000'
                                SET MESSAGE_TEXT = 'Protected authorization role is unavailable.';
                        END IF;
                        DELETE FROM role_sources
                        WHERE user_id = NEW.id
                          AND source_key = 'legacy-compatibility'
                          AND role_id IN (
                              SELECT id
                              FROM roles
                              WHERE guard_name = 'web'
                                AND is_protected = 1
                          )
                          AND (
                              `source` <> 'legacy-compatibility'
                              OR role_id <> (
                                  SELECT id
                                  FROM roles
                                  WHERE guard_name = 'web'
                                    AND is_protected = 1
                                    AND name = {$mapping}
                              )
                              OR directory_group_id IS NOT NULL
                              OR granted_by IS NOT NULL
                          );
                        INSERT IGNORE INTO role_sources (
                            user_id,
                            role_id,
                            `source`,
                            source_key,
                            directory_group_id,
                            granted_by,
                            created_at,
                            updated_at
                        )
                        SELECT
                            NEW.id,
                            roles.id,
                            'legacy-compatibility',
                            'legacy-compatibility',
                            NULL,
                            NULL,
                            CURRENT_TIMESTAMP,
                            CURRENT_TIMESTAMP
                        FROM roles
                        WHERE roles.guard_name = 'web'
                          AND roles.is_protected = 1
                          AND roles.name = {$mapping};
                        DELETE model_has_roles
                        FROM model_has_roles
                        INNER JOIN roles ON roles.id = model_has_roles.role_id
                        WHERE model_has_roles.model_type = {$userModel}
                          AND model_has_roles.model_id = NEW.id
                          AND roles.guard_name = 'web'
                          AND roles.is_protected = 1
                          AND NOT EXISTS (
                              SELECT 1
                              FROM role_sources
                              WHERE role_sources.user_id = NEW.id
                                AND role_sources.role_id = model_has_roles.role_id
                          );
                        INSERT IGNORE INTO model_has_roles (
                            role_id,
                            model_type,
                            model_id
                        )
                        SELECT
                            roles.id,
                            {$userModel},
                            NEW.id
                        FROM roles
                        WHERE roles.guard_name = 'web'
                          AND roles.is_protected = 1
                          AND roles.name = {$mapping};
                END",
            ],
            self::USER_CLEANUP_BEFORE_DELETE_TRIGGER => [
                'table' => 'users',
                'timing' => 'BEFORE',
                'event' => 'DELETE',
                'body' => "BEGIN
                    DELETE FROM permission_sources
                    WHERE user_id = OLD.id;
                    DELETE FROM model_has_permissions
                    WHERE model_type = {$userModel}
                      AND model_id = OLD.id;
                    DELETE FROM role_sources
                    WHERE user_id = OLD.id;
                    DELETE FROM model_has_roles
                    WHERE model_type = {$userModel}
                      AND model_id = OLD.id;
                END",
            ],
            self::USER_CLEANUP_AFTER_DELETE_TRIGGER => [
                'table' => 'users',
                'timing' => 'AFTER',
                'event' => 'DELETE',
                'body' => "BEGIN
                    DELETE FROM model_has_roles
                    WHERE model_type = {$userModel}
                      AND model_id = OLD.id;
                    DELETE FROM model_has_permissions
                    WHERE model_type = {$userModel}
                      AND model_id = OLD.id;
                END",
            ],
            self::ROLE_PROTECTION_DELETE_TRIGGER => [
                'table' => 'roles',
                'timing' => 'BEFORE',
                'event' => 'DELETE',
                'body' => "BEGIN
                    IF OLD.is_protected = 1
                       OR ({$protectedRole})
                    THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'Protected authorization roles cannot be deleted.';
                    END IF;
                    IF EXISTS (
                        SELECT 1
                        FROM role_sources
                        WHERE role_id = OLD.id
                    ) OR EXISTS (
                        SELECT 1
                        FROM model_has_roles
                        WHERE role_id = OLD.id
                    )
                    THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'Assigned authorization roles cannot be deleted.';
                    END IF;
                END",
            ],
            self::ROLE_PROTECTION_UPDATE_TRIGGER => [
                'table' => 'roles',
                'timing' => 'BEFORE',
                'event' => 'UPDATE',
                'body' => "BEGIN
                    IF {$protectedRole}
                    THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'Protected authorization roles are immutable.';
                    END IF;
                END",
            ],
            self::ROLE_INSERT_TRIGGER => [
                'table' => 'model_has_roles',
                'timing' => 'BEFORE',
                'event' => 'INSERT',
                'body' => "BEGIN
                    IF NEW.model_type = {$userModel}
                       AND (
                           NEW.team_id IS NOT NULL
                           OR NOT EXISTS (
                               SELECT 1
                               FROM role_sources
                               WHERE user_id = NEW.model_id
                                 AND role_id = NEW.role_id
                           )
                       )
                    THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'User role assignment requires provenance.';
                    END IF;
                END",
            ],
            self::ROLE_UPDATE_TRIGGER => [
                'table' => 'model_has_roles',
                'timing' => 'BEFORE',
                'event' => 'UPDATE',
                'body' => "BEGIN
                    IF (
                        OLD.model_type = {$userModel}
                        AND EXISTS (
                            SELECT 1
                            FROM role_sources
                            WHERE user_id = OLD.model_id
                              AND role_id = OLD.role_id
                        )
                    ) OR (
                        NEW.model_type = {$userModel}
                        AND (
                            NEW.team_id IS NOT NULL
                            OR NOT EXISTS (
                                SELECT 1
                                FROM role_sources
                                WHERE user_id = NEW.model_id
                                  AND role_id = NEW.role_id
                            )
                        )
                    )
                    THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'Provenanced user roles cannot be reassigned directly.';
                    END IF;
                END",
            ],
            self::ROLE_DELETE_TRIGGER => [
                'table' => 'model_has_roles',
                'timing' => 'BEFORE',
                'event' => 'DELETE',
                'body' => "BEGIN
                    IF OLD.model_type = {$userModel}
                       AND EXISTS (
                           SELECT 1
                           FROM role_sources
                           WHERE user_id = OLD.model_id
                             AND role_id = OLD.role_id
                       )
                    THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'Provenanced user roles cannot be detached directly.';
                    END IF;
                END",
            ],
            self::PERMISSION_INSERT_TRIGGER => $this->mysqlPermissionTrigger('INSERT'),
            self::PERMISSION_UPDATE_TRIGGER => $this->mysqlPermissionTrigger('UPDATE'),
            self::PERMISSION_DELETE_TRIGGER => $this->mysqlPermissionTrigger(
                'DELETE',
            ),
        ];
    }

    private function sqlitePermissionTrigger(
        string $name,
        string $event,
        string $userModel,
    ): string {
        if ($event === 'INSERT') {
            return "CREATE TRIGGER {$name}
                BEFORE INSERT ON model_has_permissions
                WHEN NEW.model_type = {$userModel}
                BEGIN
                    SELECT RAISE(ABORT, 'Direct user permission requires provenance.')
                    WHERE NEW.team_id IS NOT NULL
                       OR NOT EXISTS (
                            SELECT 1
                            FROM permission_sources
                            WHERE user_id = NEW.model_id
                              AND permission_id = NEW.permission_id
                              AND team_id IS NULL
                       );
                END";
        }

        if ($event === 'UPDATE') {
            return "CREATE TRIGGER {$name}
                BEFORE UPDATE ON model_has_permissions
                WHEN OLD.model_type = {$userModel}
                  OR NEW.model_type = {$userModel}
                BEGIN
                    SELECT RAISE(ABORT, 'Provenanced direct permissions cannot be reassigned.')
                    WHERE (
                        OLD.model_type = {$userModel}
                        AND EXISTS (
                            SELECT 1
                            FROM permission_sources
                            WHERE user_id = OLD.model_id
                              AND permission_id = OLD.permission_id
                              AND team_id IS NULL
                        )
                    ) OR (
                        NEW.model_type = {$userModel}
                        AND (
                            NEW.team_id IS NOT NULL
                            OR NOT EXISTS (
                                SELECT 1
                                FROM permission_sources
                                WHERE user_id = NEW.model_id
                                  AND permission_id = NEW.permission_id
                                  AND team_id IS NULL
                            )
                        )
                    );
                END";
        }

        return "CREATE TRIGGER {$name}
            BEFORE DELETE ON model_has_permissions
            WHEN OLD.model_type = {$userModel}
            BEGIN
                SELECT RAISE(ABORT, 'Provenanced direct permissions cannot be detached.')
                WHERE EXISTS (
                    SELECT 1
                    FROM permission_sources
                    WHERE user_id = OLD.model_id
                      AND permission_id = OLD.permission_id
                      AND team_id IS NULL
                );
            END";
    }

    /**
     * @return array{table: string, timing: string, event: string, body: string}
     */
    private function mysqlPermissionTrigger(
        string $event,
    ): array {
        $userModel = self::USER_MODEL_EXPRESSION;
        $condition = match ($event) {
            'INSERT' => "NEW.model_type = {$userModel}
                AND (
                    NEW.team_id IS NOT NULL
                    OR NOT EXISTS (
                        SELECT 1
                        FROM permission_sources
                        WHERE user_id = NEW.model_id
                          AND permission_id = NEW.permission_id
                          AND team_id IS NULL
                    )
                )",
            'UPDATE' => "(
                    OLD.model_type = {$userModel}
                    AND EXISTS (
                        SELECT 1
                        FROM permission_sources
                        WHERE user_id = OLD.model_id
                          AND permission_id = OLD.permission_id
                          AND team_id IS NULL
                    )
                ) OR (
                    NEW.model_type = {$userModel}
                    AND (
                        NEW.team_id IS NOT NULL
                        OR NOT EXISTS (
                            SELECT 1
                            FROM permission_sources
                            WHERE user_id = NEW.model_id
                              AND permission_id = NEW.permission_id
                              AND team_id IS NULL
                        )
                    )
                )",
            default => "OLD.model_type = {$userModel}
                AND EXISTS (
                    SELECT 1
                    FROM permission_sources
                    WHERE user_id = OLD.model_id
                      AND permission_id = OLD.permission_id
                      AND team_id IS NULL
                )",
        };
        $message = match ($event) {
            'INSERT' => 'Direct user permission requires provenance.',
            'UPDATE' => 'Provenanced direct permissions cannot be reassigned.',
            default => 'Provenanced direct permissions cannot be detached.',
        };

        return [
            'table' => 'model_has_permissions',
            'timing' => 'BEFORE',
            'event' => $event,
            'body' => "BEGIN
                IF {$condition}
                THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = '{$message}';
                END IF;
            END",
        ];
    }

    private function legacyAccessLevels(bool $withSuperAdmin): string
    {
        $levels = "'service_user', 'student', 'volunteer', 'staff', 'admin'";

        return $withSuperAdmin ? "{$levels}, 'superadmin'" : $levels;
    }

    private function legacyRoleMapping(string $column, bool $withSuperAdmin = false): string
    {
        $superAdmin = $withSuperAdmin
            ? "\n            WHEN 'superadmin' THEN 'super-admin'"
            : '';

        return "CASE {$column}
            WHEN 'service_user' THEN 'service-user'
            WHEN 'student' THEN 'student'
            WHEN 'volunteer' THEN 'volunteer'
            WHEN 'staff' THEN 'staff'
            WHEN 'admin' THEN 'administrator'{$superAdmin}
        END";
    }

    private function protectedRoleIdentity(string $row, bool $withSuperAdmin = false): string
    {
        $superAdmin = $withSuperAdmin ? ", 'super-admin'" : '';

        return "{$row}.guard_name = 'web'
            AND {$row}.name IN (
                'service-user', 'student', 'volunteer', 'staff', 'administrator'{$superAdmin}
            )";
    }

    private function legacyTriggerInventoryComplete(): bool
    {
        $expected = $this->triggerNames();

        if ($this->driverName() === 'sqlite') {
            return DB::table('sqlite_master')
                ->where('type', 'trigger')
                ->whereIn('name', $expected)
                ->count() === count($expected);
        }

        if ($this->driverName() === 'mysql') {
            return collect(DB::select(
                'SELECT trigger_name AS name
                 FROM information_schema.triggers
                 WHERE trigger_schema = DATABASE()',
            ))
                ->filter(
                    static fn (object $trigger): bool => in_array(
                        (string) $trigger->name,
                        $expected,
                        true,
                    ),
                )
                ->count() === count($expected);
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function triggerNames(): array
    {
        return [
            self::INSERT_TRIGGER,
            self::UPDATE_TRIGGER,
            self::USER_CLEANUP_BEFORE_DELETE_TRIGGER,
            self::USER_CLEANUP_AFTER_DELETE_TRIGGER,
            self::ROLE_PROTECTION_DELETE_TRIGGER,
            self::ROLE_PROTECTION_UPDATE_TRIGGER,
            self::ROLE_INSERT_TRIGGER,
            self::ROLE_UPDATE_TRIGGER,
            self::ROLE_DELETE_TRIGGER,
            self::PERMISSION_INSERT_TRIGGER,
            self::PERMISSION_UPDATE_TRIGGER,
            self::PERMISSION_DELETE_TRIGGER,
        ];
    }

    private function normalizeDefinition(string $definition): string
    {
        $normalized = strtolower(str_replace('`', '', trim($definition)));

        return preg_replace('/\s+/', ' ', $normalized) ?? '';
    }

    /**
     * Treat legacy FQCN and morph-alias USER_MODEL CHAR() forms as equivalent
     * so preflight against a not-yet-migrated Phase B database still passes.
     */
    private function normalizeDefinitionForComparison(string $definition): string
    {
        $normalized = $this->normalizeDefinition($definition);
        $legacy = $this->normalizeDefinition(self::LEGACY_USER_MODEL_EXPRESSION);
        $current = $this->normalizeDefinition(self::USER_MODEL_EXPRESSION);

        return str_replace($legacy, $current, $normalized);
    }

    private function compactDefinition(string $definition): string
    {
        return str_replace(' ', '', $this->normalizeDefinition($definition));
    }
}
