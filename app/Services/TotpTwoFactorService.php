<?php

namespace App\Services;

use App\Core\Accounts\User;
use App\Notifications\Auth\TwoFactorDisabledNotification;
use App\Notifications\Auth\TwoFactorEnabledNotification;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use DomainException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

class TotpTwoFactorService
{
    public const RECOVERY_CODE_COUNT = 8;

    public const SESSION_PLAIN_RECOVERY_CODES = 'two_factor.plain_recovery_codes';

    public const SESSION_LOGIN_ID = 'login.id';

    public const SESSION_LOGIN_REMEMBER = 'login.remember';

    public function __construct(
        private readonly Google2FA $google2fa,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function hasEnabledTwoFactor(User $user): bool
    {
        return filled($user->two_factor_secret)
            && $user->two_factor_confirmed_at !== null;
    }

    public function hasPendingEnrolment(User $user): bool
    {
        return filled($user->two_factor_secret)
            && $user->two_factor_confirmed_at === null;
    }

    /**
     * Begin enrolment: store encrypted secret + hashed recovery codes (unconfirmed).
     *
     * @return array{secret: string, recovery_codes: list<string>, qr_svg: string, otpauth_url: string}
     */
    public function beginEnrolment(User $user): array
    {
        $this->assertLocalIdentity($user);

        if ($this->hasEnabledTwoFactor($user)) {
            throw new DomainException(__('auth.two_factor.already_enabled'));
        }

        $secret = $this->google2fa->generateSecretKey();
        $plainRecoveryCodes = $this->generatePlainRecoveryCodes();

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => $this->hashRecoveryCodes($plainRecoveryCodes),
            'two_factor_confirmed_at' => null,
        ])->save();

        $this->auditLogger->record('auth.two_factor_enrolment_started', $user, $user);

        return [
            'secret' => $secret,
            'recovery_codes' => $plainRecoveryCodes,
            'qr_svg' => $this->qrCodeSvg($user, $secret),
            'otpauth_url' => $this->otpauthUrl($user, $secret),
        ];
    }

    /**
     * Confirm enrolment with a valid TOTP code and notify the user.
     */
    public function confirmEnrolment(User $user, string $code): void
    {
        $this->assertLocalIdentity($user);

        if ($this->hasEnabledTwoFactor($user)) {
            throw new DomainException(__('auth.two_factor.already_enabled'));
        }

        if (! $this->hasPendingEnrolment($user)) {
            throw new DomainException(__('auth.two_factor.no_pending_enrolment'));
        }

        if (! $this->verifyTotp($user, $code)) {
            throw new DomainException(__('auth.two_factor.invalid_code'));
        }

        $user->forceFill([
            'two_factor_confirmed_at' => now(),
        ])->save();

        $user->notify(new TwoFactorEnabledNotification);

        $this->auditLogger->record('auth.two_factor_enabled', $user, $user);
    }

    public function disable(User $user): void
    {
        $this->disableForSubject($user, $user);
    }

    /**
     * Admin/staff-assisted 2FA reset (#522). Audits the actor separately from the subject.
     */
    public function disableForSubject(User $subject, User $actor): void
    {
        $this->assertLocalIdentity($subject);

        if (! $this->hasEnabledTwoFactor($subject) && ! $this->hasPendingEnrolment($subject)) {
            throw new DomainException(__('auth.two_factor.not_enabled'));
        }

        $wasEnabled = $this->hasEnabledTwoFactor($subject);

        $subject->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        if ($wasEnabled) {
            $subject->notify(new TwoFactorDisabledNotification);
        }

        $this->auditLogger->record('auth.two_factor_disabled', $actor, $subject, [
            'was_confirmed' => $wasEnabled,
            'target_user_id' => $subject->id,
            'actor_id' => $actor->id,
        ]);
    }

    /**
     * @return list<string> Fresh plain recovery codes (shown once).
     */
    public function regenerateRecoveryCodes(User $user): array
    {
        $this->assertLocalIdentity($user);

        if (! $this->hasEnabledTwoFactor($user)) {
            throw new DomainException(__('auth.two_factor.not_enabled'));
        }

        $plainRecoveryCodes = $this->generatePlainRecoveryCodes();

        $user->forceFill([
            'two_factor_recovery_codes' => $this->hashRecoveryCodes($plainRecoveryCodes),
        ])->save();

        $this->auditLogger->record('auth.two_factor_recovery_codes_regenerated', $user, $user);

        return $plainRecoveryCodes;
    }

    public function verifyTotp(User $user, string $code): bool
    {
        $secret = $user->two_factor_secret;

        if (! is_string($secret) || $secret === '') {
            return false;
        }

        $code = preg_replace('/\s+/', '', $code) ?? '';

        if ($code === '' || ! ctype_digit($code)) {
            return false;
        }

        return $this->google2fa->verifyKey($secret, $code);
    }

    /**
     * Consume a single recovery code. Returns true if the code matched and was removed.
     */
    public function consumeRecoveryCode(User $user, string $plainCode): bool
    {
        $plainCode = strtoupper(trim(str_replace(' ', '', $plainCode)));

        if ($plainCode === '') {
            return false;
        }

        $hashes = $this->decryptRecoveryCodeHashes($user);

        if ($hashes === []) {
            return false;
        }

        foreach ($hashes as $index => $hashed) {
            if (! is_string($hashed) || ! Hash::check($plainCode, $hashed)) {
                continue;
            }

            unset($hashes[$index]);

            $user->forceFill([
                'two_factor_recovery_codes' => array_values($hashes),
            ])->save();

            $this->auditLogger->record('auth.two_factor_recovery_code_used', $user, $user);

            return true;
        }

        return false;
    }

    public function qrCodeSvg(User $user, ?string $secret = null): string
    {
        $secret ??= $user->two_factor_secret;

        if (! is_string($secret) || $secret === '') {
            throw new DomainException(__('auth.two_factor.no_pending_enrolment'));
        }

        $writer = new Writer(
            new ImageRenderer(
                new RendererStyle(192),
                new SvgImageBackEnd,
            ),
        );

        return $writer->writeString($this->otpauthUrl($user, $secret));
    }

    public function otpauthUrl(User $user, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl(
            (string) config('app.name', 'MyAPES Account'),
            (string) $user->email,
            $secret,
        );
    }

    /**
     * @return list<string>
     */
    public function generatePlainRecoveryCodes(): array
    {
        $codes = [];

        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            $codes[] = Str::upper(Str::random(4).'-'.Str::random(4));
        }

        return $codes;
    }

    /**
     * @param  list<string>  $plainCodes
     * @return list<string>
     */
    public function hashRecoveryCodes(array $plainCodes): array
    {
        return array_map(
            static fn (string $code): string => Hash::make(strtoupper(str_replace(' ', '', $code))),
            $plainCodes,
        );
    }

    /**
     * @return list<string>
     */
    public function decryptRecoveryCodeHashes(User $user): array
    {
        $stored = $user->two_factor_recovery_codes;

        if (! is_array($stored)) {
            return [];
        }

        return array_values(array_filter(
            $stored,
            static fn (mixed $value): bool => is_string($value) && $value !== '',
        ));
    }

    private function assertLocalIdentity(User $user): void
    {
        if (! $user->isLocalPasswordIdentity()) {
            throw new DomainException(__('auth.two_factor.local_only'));
        }
    }
}
