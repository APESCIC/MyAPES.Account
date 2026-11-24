<?php

namespace App\Services;

use App\Core\Accounts\User;
use Plugins\Cases\Models\ShelterCase;

/**
 * Opens an APES CIC data-deletion case for the signed-in account holder (#921).
 */
final class AccountPrivacyDeletionRequest
{
    public function openCase(User $user): ShelterCase
    {
        $existing = ShelterCase::query()
            ->where('user_id', $user->id)
            ->where('sub_core_key', 'apes-cic')
            ->where('category', 'data_access_request')
            ->where('sub_category', 'deletion')
            ->whereNotIn('status', ['resolved', 'closed'])
            ->latest('id')
            ->first();

        if ($existing instanceof ShelterCase) {
            return $existing;
        }

        return ShelterCase::query()->create([
            'category' => 'data_access_request',
            'sub_category' => 'deletion',
            'affected_website_key' => 'myapes_account',
            'priority' => 'medium',
            'title' => __('account.settings.privacy.deletion.case_title'),
            'details' => __('account.settings.privacy.deletion.case_details'),
            'sub_core_key' => 'apes-cic',
            'user_id' => $user->id,
            'status' => 'open',
            'opened_at' => now(),
        ]);
    }
}