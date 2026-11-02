<?php

namespace App\Support;

final class DirectoryLegacyGroupAliases
{
    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            'position.staff',
            'position.students',
            'position.volunteers',
            'intranet.administrator',
            'intranet.superadmin',
            'myapes.staff',
            'myapes.admin',
            'myapes.admins',
            'myapes.superadmin',
            'myapes.superadmins',
            'myapes.students',
            'myapes.volunteers',
            'myapes.vounteers',
            'myapesaccount.superadmin',
            'myapesaccount.superadmins',
            'myapesaccount.vounteer',
            'myapesaccount.vounteers',
            'myapesaccont.staff',
            'myapesaccont.admin',
            'myapesaccont.admins',
            'myapesaccont.superadmin',
            'myapesaccont.superadmins',
            'myapesaccont.student',
            'myapesaccont.students',
            'myapesaccont.volunteer',
            'myapesaccont.volunteers',
            'myapesaccont.vounteer',
            'myapesaccont.vounteers',
        ];
    }

    public static function canonicalFor(string $group): ?string
    {
        $normalized = strtolower(trim($group));

        if ($normalized === '') {
            return null;
        }

        $aliased = match ($normalized) {
            'myapes.staff', 'myapesaccont.staff' => 'myapesaccount.staff',
            'myapes.admin', 'myapes.admins', 'myapesaccont.admin', 'myapesaccont.admins',
            'myapes.superadmin', 'myapes.superadmins', 'myapesaccont.superadmin', 'myapesaccont.superadmins',
            'myapesaccount.superadmin', 'myapesaccount.superadmins' => 'myapesaccount.admin',
            'myapes.volunteers', 'myapes.vounteers', 'myapesaccount.vounteer', 'myapesaccount.vounteers', 'myapesaccont.volunteer', 'myapesaccont.volunteers', 'myapesaccont.vounteer', 'myapesaccont.vounteers' => 'myapesaccount.volunteer',
            'myapes.students', 'myapesaccont.student', 'myapesaccont.students' => 'myapesaccount.student',
            default => null,
        };

        if ($aliased !== null) {
            return $aliased;
        }

        if (DirectoryGroupPrefix::isManagedGroup($normalized)) {
            return $normalized;
        }

        return null;
    }
}
