<?php

namespace App\Core\Push;

use App\Contracts\ModuleRegistry;
use App\Core\Accounts\User;

/**
 * Types, divisions and devices shown on Profile → Notifications (#795).
 */
final class ProfileNotificationTypeCatalogue
{
    public function __construct(
        private readonly PushNotificationTypeRegistry $types,
        private readonly PushRoleResolver $roles,
        private readonly PushPreferenceReader $preferences,
        private readonly ModuleRegistry $modules,
        private readonly PushDeviceLabels $deviceLabels,
    ) {}

    /**
     * @return list<array{
     *     key: string,
     *     label: string,
     *     push_enabled: bool,
     *     email_enabled: bool,
     *     push_locked: bool,
     *     email_locked: bool,
     *     division_scoped: bool,
     * }>
     */
    public function typesFor(User $user): array
    {
        $pushRole = $this->roles->forUser($user);
        if ($pushRole === null) {
            return [];
        }

        $rows = [];
        foreach ($this->types->all() as $type) {
            if (! $type->availableForRole($pushRole)) {
                continue;
            }

            $stored = $user->pushPreferences()->where('type', $type->key)->first();
            $default = $type->defaultForRole($pushRole);
            $pushEnabled = $stored === null ? $default->enabledByDefault() : (bool) $stored->enabled;
            $emailEnabled = $stored === null ? $default->enabledByDefault() : (bool) $stored->email_enabled;
            if ($type->securityLocked) {
                $emailEnabled = true;
            }

            $rows[] = [
                'key' => $type->key,
                'label' => __($type->labelKey),
                'push_enabled' => $pushEnabled,
                'email_enabled' => $emailEnabled,
                'push_locked' => false,
                'email_locked' => $type->securityLocked,
                'division_scoped' => $type->divisionScoped,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{key: string, name: string, enabled: bool}>
     */
    public function divisionsFor(User $user): array
    {
        $pushRole = $this->roles->forUser($user);
        if (! in_array($pushRole, [PushRoleResolver::ROLE_STAFF, PushRoleResolver::ROLE_VOLUNTEER, PushRoleResolver::ROLE_STUDENT], true)) {
            return [];
        }

        $type = $this->types->get(PushNotificationTypeKeys::TICKET_CREATED);
        if (! $type->availableForRole($pushRole)) {
            return [];
        }

        $rows = [];
        foreach ($this->modules->shippedInstances() as $instance) {
            if ($instance->module->key !== 'tickets') {
                continue;
            }

            $subCoreKey = $instance->subCore->key;
            $permission = "{$subCoreKey}.tickets.view-all";
            if (! $user->can($permission)) {
                continue;
            }

            $stored = $user->pushDivisionPreferences()
                ->where('division_key', $subCoreKey)
                ->value('enabled');

            $rows[] = [
                'key' => $subCoreKey,
                'name' => $instance->subCore->name,
                'enabled' => $stored === null ? true : (bool) $stored,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $rows;
    }

    /**
     * @return list<array{
     *     id: int,
     *     browser: string,
     *     system: string,
     *     added_at: string|null,
     *     last_used_at: string|null,
     * }>
     */
    public function devicesFor(User $user): array
    {
        return $user->pushSubscriptions()
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($subscription): array {
                $labels = $this->deviceLabels->summarise($subscription->user_agent ?? null);

                return [
                    'id' => (int) $subscription->getKey(),
                    'browser' => $subscription->device_browser ?? $labels['browser'],
                    'system' => $subscription->device_system ?? $labels['system'],
                    'added_at' => optional($subscription->created_at)?->format('d/m/Y H:i'),
                    'last_used_at' => optional($subscription->last_used_at)?->format('d/m/Y H:i'),
                ];
            })
            ->all();
    }
}
