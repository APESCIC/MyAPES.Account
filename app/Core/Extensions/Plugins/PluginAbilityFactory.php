<?php

namespace App\Core\Extensions\Plugins;

/**
 * Shared default-role packs for plugin abilities (#287).
 */
final class PluginAbilityFactory
{
    /** @return list<string> */
    public static function publicRoles(): array
    {
        return [
            'service-user',
            'student',
            'volunteer',
            'staff',
            'administrator',
            'super-admin',
        ];
    }

    /** @return list<string> */
    public static function staffWorkRoles(): array
    {
        return [
            'student',
            'volunteer',
            'staff',
            'administrator',
            'super-admin',
        ];
    }

    /** @return list<string> */
    public static function staffDeleteRoles(): array
    {
        return [
            'staff',
            'administrator',
            'super-admin',
        ];
    }

    /**
     * Protected roles that hold manage abilities by default; other staff need a job role.
     *
     * @return list<string>
     */
    public static function managerRoles(): array
    {
        return [
            'administrator',
            'super-admin',
        ];
    }

    public static function public(string $ability, string $label): PluginAbility
    {
        return new PluginAbility($ability, $label, false, self::publicRoles(), public: true);
    }

    public static function staff(string $ability, string $label): PluginAbility
    {
        return new PluginAbility($ability, $label, true, self::staffWorkRoles());
    }

    public static function staffDelete(string $ability, string $label): PluginAbility
    {
        return new PluginAbility($ability, $label, true, self::staffDeleteRoles());
    }

    public static function manage(string $ability, string $label): PluginAbility
    {
        return new PluginAbility($ability, $label, true, self::managerRoles());
    }
}
