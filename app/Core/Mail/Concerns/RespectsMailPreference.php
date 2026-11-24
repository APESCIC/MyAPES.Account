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
    protected function optionalMailChannels(
        object $notifiable,
        array $channels = ['mail'],
        string $preferenceColumn = 'email',
    ): array {
        $allowsEmail = $notifiable instanceof User
            && in_array($preferenceColumn, ['email', 'marketing_email'], true)
            && (bool) $notifiable->contactPreference?->{$preferenceColumn};

        return $allowsEmail
            ? $channels
            : array_values(array_filter($channels, static fn (string $channel): bool => $channel !== 'mail'));
    }
}
