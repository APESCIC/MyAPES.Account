<?php

namespace App\Core\Accounts;

use Illuminate\Support\Str;

/**
 * Sets and checks phone verification PINs (#476 / #477).
 *
 * Each digit position is stored as HMAC-SHA256(app key, salt|position|digit)
 * so staff can later check two chosen positions without the PIN ever being
 * stored, shown or logged. Partial checks are rate limited by the caller.
 */
final class PhoneVerificationPinService
{
    public const MIN_LENGTH = 4;

    public const MAX_LENGTH = 8;

    public function set(User $user, string $pin): PhoneVerificationPin
    {
        if (preg_match('/^\d{'.self::MIN_LENGTH.','.self::MAX_LENGTH.'}$/', $pin) !== 1) {
            throw new \InvalidArgumentException('A PIN must be numeric and the right length.');
        }

        $salt = Str::random(48);
        $hashes = [];
        foreach (str_split($pin) as $index => $digit) {
            $hashes[] = $this->digitHash($salt, $index + 1, $digit);
        }

        return PhoneVerificationPin::query()->updateOrCreate(
            ['user_id' => $user->getKey()],
            ['length' => strlen($pin), 'salt' => $salt, 'digit_hashes' => $hashes, 'set_at' => now()],
        );
    }

    public function for(User $user): ?PhoneVerificationPin
    {
        return PhoneVerificationPin::query()->where('user_id', $user->getKey())->first();
    }

    /**
     * @param  array<int, string>  $digitsByPosition  1-based position => one digit
     */
    public function matches(User $user, array $digitsByPosition): bool
    {
        $pin = $this->for($user);
        if ($pin === null || $digitsByPosition === []) {
            return false;
        }

        $ok = true;
        foreach ($digitsByPosition as $position => $digit) {
            $expected = $pin->digit_hashes[$position - 1] ?? null;
            $given = preg_match('/^\d$/', (string) $digit) === 1
                ? $this->digitHash($pin->salt, (int) $position, (string) $digit)
                : '';

            $ok = is_string($expected) && hash_equals($expected, $given) && $ok;
        }

        return $ok;
    }

    private function digitHash(string $salt, int $position, string $digit): string
    {
        return hash_hmac('sha256', $salt.'|'.$position.'|'.$digit, (string) config('app.key'));
    }
}
