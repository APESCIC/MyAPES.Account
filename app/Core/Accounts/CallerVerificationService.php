<?php

namespace App\Core\Accounts;

use App\Services\AuditLogger;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Staff check a caller's identity with two digits of their PIN (#477).
 *
 * The system picks two positions; staff never see any digit. Three wrong
 * attempts lock checking for that caller for LOCK_SECONDS, whichever staff
 * member tries. Every attempt is audit-logged with positions only.
 */
final class CallerVerificationService
{
    public const MAX_ATTEMPTS = 3;

    public const LOCK_SECONDS = 900;

    public const RESULT_VERIFIED = 'verified';

    public const RESULT_FAILED = 'failed';

    public const RESULT_LOCKED = 'locked';

    public function __construct(
        private readonly PhoneVerificationPinService $pins,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Two distinct 1-based positions, kept in the session until the next attempt.
     *
     * @return list<int>|null null when the caller has no PIN
     */
    public function positions(Session $session, User $caller): ?array
    {
        $pin = $this->pins->for($caller);
        if ($pin === null) {
            return null;
        }

        $key = $this->sessionKey($caller, 'positions');
        $positions = $session->get($key);

        if (! is_array($positions) || count($positions) !== 2 || max($positions) > $pin->length) {
            $all = range(1, $pin->length);
            shuffle($all);
            $positions = array_slice($all, 0, 2);
            sort($positions);
            $session->put($key, $positions);
        }

        return array_values(array_map('intval', $positions));
    }

    /**
     * @param  array<int|string, mixed>  $digits  position => digit as entered
     * @return self::RESULT_*
     */
    public function attempt(Session $session, User $staff, User $caller, array $digits): string
    {
        $limiterKey = $this->limiterKey($caller);

        if (RateLimiter::tooManyAttempts($limiterKey, self::MAX_ATTEMPTS)) {
            $this->audit->record('caller.verification_refused_locked', $staff, $caller);

            return self::RESULT_LOCKED;
        }

        $positions = $this->positions($session, $caller) ?? [];
        $asked = [];
        foreach ($positions as $position) {
            $asked[$position] = (string) ($digits[$position] ?? '');
        }

        $session->forget($this->sessionKey($caller, 'positions'));

        if ($positions !== [] && $this->pins->matches($caller, $asked)) {
            RateLimiter::clear($limiterKey);
            $this->audit->record('caller.verification_passed', $staff, $caller, ['positions' => $positions]);

            return self::RESULT_VERIFIED;
        }

        RateLimiter::hit($limiterKey, self::LOCK_SECONDS);
        $this->audit->record('caller.verification_failed', $staff, $caller, [
            'positions' => $positions,
            'attempts' => RateLimiter::attempts($limiterKey),
        ]);

        if (RateLimiter::tooManyAttempts($limiterKey, self::MAX_ATTEMPTS)) {
            $this->audit->record('caller.verification_locked', $staff, $caller, ['lock_seconds' => self::LOCK_SECONDS]);

            return self::RESULT_LOCKED;
        }

        return self::RESULT_FAILED;
    }

    public function lockedForSeconds(User $caller): int
    {
        $key = $this->limiterKey($caller);

        return RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS) ? RateLimiter::availableIn($key) : 0;
    }

    public function sessionKey(User $caller, string $name): string
    {
        return 'callers.'.$caller->getKey().'.'.$name;
    }

    private function limiterKey(User $caller): string
    {
        return 'caller-pin:'.$caller->getKey();
    }
}
