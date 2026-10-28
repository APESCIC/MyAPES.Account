<?php

namespace App\Core\Mail\Concerns;

use App\Core\Accounts\User;

/**
 * Optional-category mail only goes to people who turned on the Email contact preference (#360).
 * Security and service mail must not use this.
 */
trait RespectsMailPreference
{
    /**
     * @param  list<string>  $channels
     * @return list<string>
     */
    protected function optionalMailChannels(object $notifiable, array $channels = ['mail']): array
    {
        $allowsEmail = $notifiable instanceof User
            && (bool) $notifiable->contactPreference?->email;

        return $allowsEmail
            ? $channels
            : array_values(array_filter($channels, static fn (string $channel): bool => $channel !== 'mail'));
    }
}
