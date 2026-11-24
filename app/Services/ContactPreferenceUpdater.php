<?php

namespace App\Services;

use App\Core\Accounts\User;
use App\Core\Accounts\UserContactPreference;
use Illuminate\Support\Facades\DB;

class ContactPreferenceUpdater
{
    public const CHANNELS = ['calls', 'sms', 'whatsapp', 'telegram', 'email', 'marketing_email'];

    /** @param array<string, bool> $choices */
    public function update(
        User $subject,
        User $actor,
        array $choices,
        string $source,
    ): UserContactPreference {
        return DB::transaction(function () use ($subject, $actor, $choices, $source): UserContactPreference {
            $preference = UserContactPreference::query()->firstOrCreate([
                'user_id' => $subject->id,
            ]);
            $preference = UserContactPreference::query()
                ->whereKey($preference->id)
                ->lockForUpdate()
                ->firstOrFail();
            $policyVersion = (string) config('myapes.consent.policy_version');
            $now = now();

            foreach (self::CHANNELS as $channel) {
                $granted = (bool) ($choices[$channel] ?? false);

                if ((bool) $preference->{$channel} !== $granted) {
                    $subject->contactConsentEvents()->create([
                        'channel' => $channel,
                        'granted' => $granted,
                        'policy_version' => $policyVersion,
                        'source' => $source,
                        'actor_user_id' => $actor->id,
                        'recorded_at' => $now,
                    ]);
                }

                $preference->{$channel} = $granted;
            }

            $preference->policy_version = $policyVersion;
            $preference->confirmed_at = $now;
            $preference->save();

            return $preference;
        });
    }
}
