<?php

namespace App\Core\Push;

use App\Core\Accounts\User;

final class PushDivisionPreferenceReader
{
    public function isEnabled(User $user, string $divisionKey): bool
    {
        $stored = $user->pushDivisionPreferences()
            ->where('division_key', $divisionKey)
            ->value('enabled');

        return $stored === null ? true : (bool) $stored;
    }
}
