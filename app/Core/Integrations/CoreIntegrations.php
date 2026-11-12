<?php

namespace App\Core\Integrations;

/**
 * The integrations Core ships (#636, #637). Each field's .env variable still
 * wins when it is set, so existing deployments keep working unchanged.
 */
final class CoreIntegrations
{
    public const TINYMCE = 'tinymce';

    /**
     * @return list<Integration>
     */
    public static function all(): array
    {
        return [self::tinymce()];
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
