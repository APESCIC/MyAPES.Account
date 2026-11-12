<?php

namespace App\Core\Eloquent;

use App\Core\Accounts\DirectoryGroup;
use App\Core\Accounts\DirectoryGroupRoleMapping;
use App\Core\Accounts\Permission;
use App\Core\Accounts\Role;
use App\Core\Accounts\StaffProfile;
use App\Core\Accounts\User;
use App\Core\Accounts\UserProfile;
use App\Core\Api\ApiClient;
use App\Core\Attachments\SupportAttachment;
use App\Core\Extensions\Models\ModuleInstallation;
use App\Core\Mail\Inbound\InboundEmail;
use App\Core\Maintenance\MaintenanceWindow;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Stable morph aliases for polymorphic columns (#281).
 *
 * Aliases stay fixed when models later move into Core / Modules / Plugins namespaces.
 * Plugin models are referenced by string FQCN so Core does not import plugin packages.
 */
final class MorphMap
{
    /**
     * @return array<string, class-string>
     */
    public static function aliases(): array
    {
        return [
            'user' => User::class,
            'user_profile' => UserProfile::class,
            'staff_profile' => StaffProfile::class,
            // String FQCNs keep Core free of plugin package imports (#289 / #290 / #291).
            'support_ticket' => 'Plugins\\Tickets\\Models\\SupportTicket',
            'support_ticket_message' => 'Plugins\\Tickets\\Models\\SupportTicketMessage',
            'support_attachment' => SupportAttachment::class,
            'case' => 'Plugins\\Cases\\Models\\ShelterCase',
            'case_update' => 'Plugins\\Cases\\Models\\CaseUpdate',
            'pet_profile' => 'Plugins\\PetProfiles\\Models\\PetProfile',
            'consultation' => 'Plugins\\Consultations\\Models\\PetCareConsultation',
            'recruitment_role' => 'Plugins\\Recruitment\\Models\\RecruitmentRole',
            'recruitment_application' => 'Plugins\\Recruitment\\Models\\RecruitmentApplication',
            'maintenance_window' => MaintenanceWindow::class,
            'role' => Role::class,
            'permission' => Permission::class,
            'directory_group' => DirectoryGroup::class,
            'directory_group_role_mapping' => DirectoryGroupRoleMapping::class,
            'module_installation' => ModuleInstallation::class,
            'inbound_email' => InboundEmail::class,
            'api_client' => ApiClient::class,
        ];
    }

    public static function enforce(): void
    {
        Relation::enforceMorphMap(self::aliases());
    }

    /**
     * @param  class-string  $class
     */
    public static function aliasFor(string $class): string
    {
        $alias = array_search($class, self::aliases(), true);

        if ($alias === false) {
            throw new \InvalidArgumentException("No morph alias registered for [{$class}].");
        }

        return $alias;
    }
}
