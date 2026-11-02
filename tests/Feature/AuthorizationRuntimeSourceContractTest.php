<?php

namespace Tests\Feature;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

class AuthorizationRuntimeSourceContractTest extends TestCase
{
    public function test_runtime_authorization_has_no_scalar_or_unprovenanced_bypass(): void
    {
        $violations = [];

        foreach ($this->runtimeSources() as $path => $contents) {
            if (preg_match(
                '/(?:->|(?:User|self|static)::)(?:accessLevel|setAccessLevel|isStaff|isAdmin|accessLevelColumn|staffAccessLevels|adminAccessLevels)\s*\(/',
                $contents,
            ) === 1 && ! in_array($path, [
                'app/Console/Commands/SyncAccessCompatibility.php',
                'app/Services/LegacyAccessCompatibilityAdapter.php',
            ], true)) {
                $violations[] = "{$path}: runtime scalar authorization API";
            }

            if (preg_match(
                '/->(?:givePermissionTo|syncPermissions|revokePermissionTo|assignRole|removeRole|syncRoles)\s*\(/',
                $contents,
            ) === 1) {
                $violations[] = "{$path}: direct permission or unprovenanced role mutation";
            }

            if (preg_match(
                '/->(?:hasRole|hasAnyRole|hasAllRoles)\s*\(/',
                $contents,
            ) === 1) {
                $violations[] = "{$path}: protected role-name authorization outside profile";
            }

            if (str_contains($contents, 'legacy_access_level')
                && ! in_array($path, [
                    'app/Console/Commands/SyncAccessCompatibility.php',
                    'app/Services/AuthorizationIntegrityChecker.php',
                    'app/Services/AuthorizationPhaseBSchemaInspector.php',
                    'app/Services/LegacyAccessCompatibilityAdapter.php',
                    'app/Services/AuthorizationPreflightChecker.php',
                    'app/Support/AccessCompatibilityDatabaseGuard.php',
                    'app/Services/AuthorizationSuperAdminRetirement.php',
                    'app/Support/AuthorizationCompatibilityDatabaseGuard.php',
                ], true)) {
                $violations[] = "{$path}: legacy mirror reference outside compatibility synchronization";
            }

            if (str_contains($contents, 'model_has_permissions')
                && ! in_array($path, [
                    'app/Core/Access/PermissionGrantMigrator.php',
                    'app/Core/Access/PermissionGrantReporter.php',
                    'app/Services/AuthorizationDirectPermissionMaterializer.php',
                    'app/Services/AuthorizationIntegrityChecker.php',
                    'app/Services/AuthorizationPhaseBSchemaInspector.php',
                    'app/Services/AuthorizationPreflightChecker.php',
                    'app/Services/AuthorizationSuperAdminRetirement.php',
                    'app/Support/AuthorizationCompatibilityDatabaseGuard.php',
                ], true)) {
                $violations[] = "{$path}: direct user permission-pivot access";
            }

            if (str_contains($contents, 'permission_sources')
                && ! in_array($path, [
                    'app/Core/Access/PermissionGrantMigrator.php',
                    'app/Core/Access/PermissionGrantReporter.php',
                    'app/Services/AuthorizationDirectPermissionMaterializer.php',
                    'app/Services/AuthorizationIntegrityChecker.php',
                    'app/Services/AuthorizationPhaseBSchemaInspector.php',
                    'app/Services/AuthorizationSuperAdminRetirement.php',
                    'app/Support/AuthorizationCompatibilityDatabaseGuard.php',
                ], true)) {
                $violations[] = "{$path}: direct permission provenance access";
            }

            if ((str_contains($contents, 'model_has_roles')
                    || str_contains(
                        $contents,
                        'permission.table_names.model_has_roles',
                    ))
                && ! in_array($path, [
                    'app/Core/Accounts/User.php',
                    'app/Services/AuthorizationIntegrityChecker.php',
                    'app/Services/AuthorizationPhaseBSchemaInspector.php',
                    'app/Services/AuthorizationPreflightChecker.php',
                    'app/Services/AuthorizationRoleManagementService.php',
                    'app/Services/AuthorizationRoleMaterializer.php',
                    'app/Services/AuthorizationSuperAdminRetirement.php',
                    'app/Support/AuthorizationCompatibilityDatabaseGuard.php',
                ], true)) {
                $violations[] = "{$path}: unprovenanced effective-role pivot access";
            }

            if (str_contains($contents, 'role_has_permissions')
                && ! in_array($path, [
                    'app/Core/Access/PermissionGrantMigrator.php',
                    'app/Core/Access/PermissionGrantReporter.php',
                    'app/Services/AuthorizationIntegrityChecker.php',
                    'app/Services/AuthorizationMetadataSynchronizer.php',
                    'app/Services/AuthorizationPhaseBSchemaInspector.php',
                    'app/Services/AuthorizationPreflightChecker.php',
                    'app/Services/AuthorizationPermissionSynchronizer.php',
                    'app/Services/AuthorizationRoleManagementService.php',
                    'app/Services/AuthorizationSuperAdminRetirement.php',
                    'app/Support/AuthorizationCompatibilityDatabaseGuard.php',
                ], true)) {
                $violations[] = "{$path}: direct role-permission pivot access";
            }

            if (str_contains($contents, 'role_sources')
                && ! in_array($path, [
                    'app/Core/Accounts/User.php',
                    'app/Services/AuthorizationIntegrityChecker.php',
                    'app/Services/AuthorizationPhaseBSchemaInspector.php',
                    'app/Services/AuthorizationPreflightChecker.php',
                    'app/Services/AuthorizationRoleManagementService.php',
                    'app/Services/AuthorizationSuperAdminRetirement.php',
                    'app/Support/AuthorizationCompatibilityDatabaseGuard.php',
                ], true)) {
                $violations[] = "{$path}: direct provenance-table access";
            }

            if (preg_match(
                '/->(?:roles|permissions|roleSources)\s*\(\s*\)\s*->(?:attach|detach|sync|toggle|create|createMany|save|saveMany|update|delete|forceDelete|restore)\s*\(/s',
                $contents,
            ) === 1) {
                $violations[] = "{$path}: direct authorization relation mutation";
            }

            if ($path !== 'app/Services/AuthorizationRoleMaterializer.php'
                && (
                    preg_match('/new\s+RoleSource\b/', $contents) === 1
                    || preg_match(
                        '/RoleSource::(?:create|forceCreate|firstOrCreate|updateOrCreate|upsert|insert)\s*\(/',
                        $contents,
                    ) === 1
                    || preg_match(
                        '/RoleSource::query\s*\(\s*\)(?:(?!;).)*->(?:create|update|delete|forceDelete|restore|upsert|insert)\s*\(/s',
                        $contents,
                    ) === 1
                )) {
                $violations[] = "{$path}: direct provenance mutation";
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Runtime authorization bypasses remain:\n".implode("\n", $violations),
        );
    }

    /**
     * @return array<string, string>
     */
    private function runtimeSources(): array
    {
        $sources = [];

        foreach (['app', 'bootstrap', 'routes', 'resources/views'] as $root) {
            $absoluteRoot = base_path($root);
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absoluteRoot),
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (! $file->isFile()
                    || ! in_array($file->getExtension(), ['php'], true)) {
                    continue;
                }

                $absolutePath = $file->getPathname();
                $relativePath = str_replace(
                    '\\',
                    '/',
                    ltrim(str_replace(base_path(), '', $absolutePath), '\\/'),
                );

                $sources[$relativePath] = (string) file_get_contents($absolutePath);
            }
        }

        ksort($sources);

        return $sources;
    }
}
