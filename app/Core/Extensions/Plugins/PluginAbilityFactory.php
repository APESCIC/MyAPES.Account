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
        ];
    }

    /**
     * Roles that may own client records; staff-side roles need a separate public account (#511).
     *
     * @return list<string>
     */
    public static function clientRoles(): array
    {
        return [
            'service-user',
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
        ];
    }

    /** @return list<string> */
    public static function staffDeleteRoles(): array
    {
        return [
            'staff',
            'administrator',
        ];
    }

    /**
     * Roles that may apply for openings; staff apply from a personal public account (#511).
     *
     * @return list<string>
     */
    public static function applicantRoles(): array
    {
        return self::clientRoles();
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
        ];
    }

    public static function public(string $ability, string $label): PluginAbility
    {
        return new PluginAbility($ability, $label, false, self::publicRoles(), public: true);
    }

    public static function client(string $ability, string $label): PluginAbility
    {
        return new PluginAbility($ability, $label, false, self::clientRoles(), public: true);
    }

    public static function applicant(string $ability, string $label): PluginAbility
    {
        return new PluginAbility($ability, $label, false, self::applicantRoles(), public: true);
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

    /**
     * Clinical work held by administrators by default; Vet and Vet Nurse job roles carry it (#535).
     */
    public static function clinical(string $ability, string $label): PluginAbility
    {
        return new PluginAbility($ability, $label, true, self::managerRoles());
    }
}
