<?php

namespace App\Core\BotProtection;

/**
 * The outcome of one Google reCAPTCHA v3 check (#637). Unverified means
 * Google could not be asked, so the request is allowed under the fallback.
 */
final class RecaptchaResult
{
    public const PASSED = 'passed';

    public const REJECTED = 'rejected';

    public const UNVERIFIED = 'unverified';

    private function __construct(
        public readonly string $status,
        public readonly string $reason,
        public readonly ?float $score = null,
    ) {}

    public static function passed(?float $score): self
    {
        return new self(self::PASSED, 'ok', $score);
    }

    public static function rejected(string $reason, ?float $score = null): self
    {
        return new self(self::REJECTED, $reason, $score);
    }

    public static function unverified(string $reason): self
    {
        return new self(self::UNVERIFIED, $reason);
    }
}
