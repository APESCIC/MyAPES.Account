<?php

namespace App\Core\Access;

/**
 * Permission naming convention for Core > Modules > Plugins (#292).
 *
 * ADR 0001 lock: keep every existing `{module}.{plugin}.*` string and keep
 * `admin.*` (plus staff/volunteer/student access). Do not
 * rename to `core.*` in this epic. Prefer zero renames; the alias map and
 * id-preserving migrator exist only if a future child must change a name.
 */
final class PermissionNaming
{
    public const LAYER_CORE = 'Core';

    public const LAYER_MODULE = 'Module';

    public const LAYER_PLUGIN = 'Plugin';

    /** @var list<string> */
    public const MODULE_SLUGS = [
        'apes-cic',
        'pet-care-clinic',
        'shelter-rescue',
    ];

    /** @var list<string> */
    public const PLUGIN_SLUGS = [
        'tickets',
        'cases',
        'recruitment',
        'consultations',
        'pet-profiles',
    ];

    /**
     * Core ability patterns that are not `{module}.{plugin}.{ability}`.
     *
     * @var list<string>
     */
    private const CORE_EXACT = [
        'staff.access',
        'admin.access',
        'volunteer.access',
        'student.access',
    ];

    /**
     * Read-time aliases (old → canonical). Empty while ADR prefers zero renames.
     *
     * @return array<string, string>
     */
    public static function aliases(): array
    {
        return [];
    }

    public static function resolve(string $permission): string
    {
        return self::aliases()[$permission] ?? $permission;
    }

    public static function pluginPermission(
        string $moduleSlug,
        string $pluginSlug,
        string $ability,
    ): string {
        return "{$moduleSlug}.{$pluginSlug}.{$ability}";
    }

    public static function layer(string $permission): string
    {
        $permission = self::resolve($permission);

        if (self::isCore($permission)) {
            return self::LAYER_CORE;
        }

        if (self::isPluginScoped($permission)) {
            return self::LAYER_PLUGIN;
        }

        if (self::isModuleOnly($permission)) {
            return self::LAYER_MODULE;
        }

        return self::LAYER_CORE;
    }

    public static function matchesConvention(string $permission): bool
    {
        $permission = self::resolve($permission);

        return self::isCore($permission)
            || self::isPluginScoped($permission)
            || self::isModuleOnly($permission);
    }

    /**
     * @param  iterable<int, string>  $permissions
     * @return list<string>
     */
    public static function layersFor(iterable $permissions): array
    {
        $layers = [];

        foreach ($permissions as $name) {
            $layers[] = self::layer((string) $name);
        }

        $layers = array_values(array_unique($layers));
        $order = [
            self::LAYER_CORE => 0,
            self::LAYER_MODULE => 1,
            self::LAYER_PLUGIN => 2,
        ];
        usort(
            $layers,
            static fn (string $a, string $b): int => ($order[$a] ?? 99) <=> ($order[$b] ?? 99),
        );

        return $layers;
    }

    public static function isCore(string $permission): bool
    {
        if (in_array($permission, self::CORE_EXACT, true)) {
            return true;
        }

        return str_starts_with($permission, 'admin.');
    }

    public static function isPluginScoped(string $permission): bool
    {
        $parts = explode('.', $permission);

        if (count($parts) < 3) {
            return false;
        }

        [$module, $plugin] = $parts;

        return in_array($module, self::MODULE_SLUGS, true)
            && in_array($plugin, self::PLUGIN_SLUGS, true);
    }

    /**
     * Reserved pattern `{module}.{ability}` — none exist today.
     */
    public static function isModuleOnly(string $permission): bool
    {
        $parts = explode('.', $permission);

        if (count($parts) !== 2) {
            return false;
        }

        [$module, $ability] = $parts;

        if (! in_array($module, self::MODULE_SLUGS, true)) {
            return false;
        }

        if (in_array($ability, ['access'], true)) {
            return false;
        }

        return ! in_array($permission, self::CORE_EXACT, true)
            && ! str_starts_with($permission, 'admin.');
    }
}
