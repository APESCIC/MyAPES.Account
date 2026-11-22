<?php

namespace App\Core\Push;

use App\Core\Accounts\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PushNotificationPreferenceUpdater
{
    public function __construct(
        private readonly PushNotificationTypeRegistry $types,
        private readonly PushRoleResolver $roles,
        private readonly ProfileNotificationTypeCatalogue $catalogue,
    ) {}

    /**
     * @param  array{
     *     push?: array<string, bool>,
     *     email?: array<string, bool>,
     *     divisions?: array<string, bool>,
     *     quiet_hours_enabled?: bool,
     *     quiet_hours_start?: string|null,
     *     quiet_hours_end?: string|null,
     * }  $payload
     * @return array{types_updated: int, divisions_updated: int, quiet_hours_enabled: bool}
     */
    public function update(User $user, array $payload): array
    {
        $pushRole = $this->roles->forUser($user);
        if ($pushRole === null) {
            throw ValidationException::withMessages([
                'notifications' => __('push.profile.errors.unsupported_role'),
            ]);
        }

        $allowedTypes = collect($this->catalogue->typesFor($user))->keyBy('key');
        $allowedDivisions = collect($this->catalogue->divisionsFor($user))->keyBy('key');

        $pushChoices = (array) ($payload['push'] ?? []);
        $emailChoices = (array) ($payload['email'] ?? []);
        $divisionChoices = (array) ($payload['divisions'] ?? []);

        foreach (array_keys($pushChoices + $emailChoices) as $typeKey) {
            if (! $allowedTypes->has($typeKey)) {
                throw ValidationException::withMessages([
                    "push.{$typeKey}" => __('validation.in', ['attribute' => $typeKey]),
                ]);
            }
        }

        foreach (array_keys($divisionChoices) as $divisionKey) {
            if (! $allowedDivisions->has($divisionKey)) {
                throw ValidationException::withMessages([
                    "divisions.{$divisionKey}" => __('validation.in', ['attribute' => $divisionKey]),
                ]);
            }
        }

        $quietEnabled = (bool) ($payload['quiet_hours_enabled'] ?? false);
        $quietStart = $payload['quiet_hours_start'] ?? null;
        $quietEnd = $payload['quiet_hours_end'] ?? null;
        if ($quietEnabled && (! is_string($quietStart) || ! is_string($quietEnd) || $quietStart === '' || $quietEnd === '')) {
            throw ValidationException::withMessages([
                'quiet_hours_start' => __('push.profile.errors.quiet_hours_incomplete'),
            ]);
        }

        return DB::transaction(function () use (
            $user,
            $allowedTypes,
            $allowedDivisions,
            $pushChoices,
            $emailChoices,
            $divisionChoices,
            $quietEnabled,
            $quietStart,
            $quietEnd,
        ): array {
            $typesUpdated = 0;

            foreach ($allowedTypes as $typeKey => $row) {
                $type = $this->types->get($typeKey);
                $pushOn = (bool) ($pushChoices[$typeKey] ?? $row['push_enabled']);
                $emailOn = (bool) ($emailChoices[$typeKey] ?? $row['email_enabled']);
                if ($type->securityLocked) {
                    $emailOn = true;
                }

                UserPushPreference::query()->updateOrCreate(
                    ['user_id' => $user->id, 'type' => $typeKey],
                    ['enabled' => $pushOn, 'email_enabled' => $emailOn],
                );
                $typesUpdated++;
            }

            $divisionsUpdated = 0;
            foreach ($allowedDivisions as $divisionKey => $row) {
                $enabled = (bool) ($divisionChoices[$divisionKey] ?? $row['enabled']);
                UserPushDivisionPreference::query()->updateOrCreate(
                    ['user_id' => $user->id, 'division_key' => $divisionKey],
                    ['enabled' => $enabled],
                );
                $divisionsUpdated++;
            }

            $settings = $user->notificationSetting()->firstOrCreate(['user_id' => $user->id]);
            $settings->forceFill([
                'quiet_hours_enabled' => $quietEnabled,
                'quiet_hours_start' => $quietEnabled ? $quietStart : null,
                'quiet_hours_end' => $quietEnabled ? $quietEnd : null,
                'quiet_hours_timezone' => 'Europe/London',
            ])->save();

            return [
                'types_updated' => $typesUpdated,
                'divisions_updated' => $divisionsUpdated,
                'quiet_hours_enabled' => $quietEnabled,
            ];
        });
    }
}
