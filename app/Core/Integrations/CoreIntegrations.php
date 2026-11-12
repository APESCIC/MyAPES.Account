<?php

namespace App\Core\Integrations;

use App\Core\BotProtection\BotProtection;
use App\Core\BotProtection\RecaptchaVerifier;

/**
 * The integrations Core ships (#636, #637). Each field's .env variable still
 * wins when it is set, so existing deployments keep working unchanged.
 */
final class CoreIntegrations
{
    public const TINYMCE = 'tinymce';

    public const RECAPTCHA = 'recaptcha';

    /**
     * @return list<Integration>
     */
    public static function all(): array
    {
        return [self::tinymce(), self::recaptcha()];
    }

    /**
     * Google reCAPTCHA v3 on the public forms (#637). The site key is public
     * (it is in the page); the secret key is masked and replace-only. Each
     * protected form has its own score threshold, 0.5 by default.
     */
    private static function recaptcha(): Integration
    {
        $thresholds = array_map(
            static fn (string $form): IntegrationField => new IntegrationField('threshold_'.$form, default: '0.5', rules: ['numeric', 'between:0,1'], required: false),
            BotProtection::FORMS,
        );

        return new Integration(self::RECAPTCHA, [
            new IntegrationField('site_key', env: 'RECAPTCHA_SITE_KEY', rules: ['string', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/']),
            new IntegrationField('secret_key', secret: true, env: 'RECAPTCHA_SECRET_KEY', rules: ['string', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/']),
            ...$thresholds,
        ], static fn (): ?string => app(RecaptchaVerifier::class)->testSecret(), static fn (): ?string => app(RecaptchaVerifier::class)->warning());
    }

    /**
     * TinyMCE Cloud (#483, #636). The key is public by design (it is part of the
     * editor's script address) and approved per domain at tiny.cloud, so it is a
     * plain field rather than a secret. Without it, rich text fields stay plain textareas.
     */
    private static function tinymce(): Integration
    {
        return new Integration(self::TINYMCE, [
            new IntegrationField('api_key', env: 'TINYMCE_API_KEY', rules: ['string', 'alpha_num', 'max:100']),
        ]);
    }
}
