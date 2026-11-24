<?php

namespace App\Services;

use App\Core\Accounts\User;
use Plugins\Cases\Models\ShelterCase;
use Plugins\Tickets\Models\SupportTicket;

/**
 * Builds a self-service export of the signed-in person's own data (#921).
 */
final class AccountPersonalDataExport
{
    /**
     * @return array<string, mixed>
     */
    public function payload(User $user): array
    {
        $user->loadMissing([
            'profile',
            'contactPreference',
            'serviceSelections',
            'notificationSetting',
        ]);

        return [
            'exported_at' => now()->toIso8601String(),
            'policy_version' => (string) config('myapes.consent.policy_version'),
            'account' => [
                'id' => $user->id,
                'username' => $user->username,
                'email' => $user->email,
                'name' => $user->name,
                'locale' => $user->locale,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'onboarding_completed_at' => $user->onboarding_completed_at?->toIso8601String(),
                'registration_consented_at' => $user->registration_consented_at?->toIso8601String(),
                'created_at' => $user->created_at?->toIso8601String(),
            ],
            'profile' => $this->profilePayload($user),
            'contact_preferences' => $this->preferencePayload($user),
            'services' => $user->serviceSelections->pluck('sub_core_key')->values()->all(),
            'notification_settings' => $this->notificationPayload($user),
            'tickets' => $this->ticketSummaries($user),
            'cases' => $this->caseSummaries($user),
        ];
    }

    /**
     * @return list<string, mixed>
     */
    public function jsonLines(User $user): array
    {
        return [
            json_encode($this->payload($user), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    public function downloadFilename(User $user): string
    {
        $stamp = now()->format('Y-m-d_His');

        return "myapes-account-data-{$user->id}-{$stamp}.json";
    }

    /**
     * @return array<string, mixed>|null
     */
    private function profilePayload(User $user): ?array
    {
        $profile = $user->profile;
        if ($profile === null) {
            return null;
        }

        return [
            'preferred_name' => $profile->preferred_name,
            'first_name' => $profile->first_name,
            'last_name' => $profile->last_name,
            'date_of_birth' => $profile->date_of_birth?->format('Y-m-d'),
            'address_line_1' => $profile->address_line_1,
            'address_line_2' => $profile->address_line_2,
            'town_city' => $profile->town_city,
            'county' => $profile->county,
            'postcode' => $profile->postcode,
            'country' => $profile->country,
            'phone' => $profile->phone,
            'mobile_number' => $profile->mobile_number,
            'landline_number' => $profile->landline_number,
            'whatsapp_number' => $profile->whatsapp_number,
            'telegram_username' => $profile->telegram_username,
            'organization' => $profile->organization,
            'support_needs' => $profile->support_needs,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function preferencePayload(User $user): ?array
    {
        $preference = $user->contactPreference;
        if ($preference === null) {
            return null;
        }

        return [
            'calls' => (bool) $preference->calls,
            'sms' => (bool) $preference->sms,
            'whatsapp' => (bool) $preference->whatsapp,
            'telegram' => (bool) $preference->telegram,
            'email' => (bool) $preference->email,
            'marketing_email' => (bool) $preference->marketing_email,
            'policy_version' => $preference->policy_version,
            'confirmed_at' => $preference->confirmed_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function notificationPayload(User $user): ?array
    {
        $settings = $user->notificationSetting;
        if ($settings === null) {
            return null;
        }

        return [
            'quiet_hours_enabled' => (bool) $settings->quiet_hours_enabled,
            'quiet_hours_start' => $settings->quiet_hours_start,
            'quiet_hours_end' => $settings->quiet_hours_end,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function ticketSummaries(User $user): array
    {
        return SupportTicket::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->limit(500)
            ->get(['id', 'subject', 'status', 'category', 'sub_category', 'created_at', 'updated_at'])
            ->map(static fn (SupportTicket $ticket): array => [
                'id' => $ticket->id,
                'subject' => $ticket->subject,
                'status' => $ticket->status,
                'category' => $ticket->category,
                'sub_category' => $ticket->sub_category,
                'created_at' => $ticket->created_at?->toIso8601String(),
                'updated_at' => $ticket->updated_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function caseSummaries(User $user): array
    {
        return ShelterCase::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->limit(500)
            ->get(['id', 'title', 'status', 'category', 'sub_category', 'priority', 'created_at', 'updated_at'])
            ->map(static fn (ShelterCase $case): array => [
                'id' => $case->id,
                'title' => $case->title,
                'status' => $case->status,
                'category' => $case->category,
                'sub_category' => $case->sub_category,
                'priority' => $case->priority,
                'created_at' => $case->created_at?->toIso8601String(),
                'updated_at' => $case->updated_at?->toIso8601String(),
            ])
            ->all();
    }
}