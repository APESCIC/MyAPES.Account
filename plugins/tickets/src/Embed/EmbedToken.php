<?php

namespace Plugins\Tickets\Embed;

use Illuminate\Support\Facades\Crypt;
use Plugins\Tickets\Models\TicketEmbedForm;
use Throwable;

/**
 * Short-lived signed embed session token (#686): binds a form to a parent origin.
 */
final class EmbedToken
{
    public const TTL_SECONDS = 3600;

    public static function mint(TicketEmbedForm $form, string $origin): string
    {
        $origin = TicketEmbedForm::normaliseOrigin($origin);

        return Crypt::encryptString(json_encode([
            'form_id' => $form->id,
            'public_key' => $form->public_key,
            'origin' => $origin,
            'exp' => now()->getTimestamp() + self::TTL_SECONDS,
            'v' => 1,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{form_id: int, public_key: string, origin: string, exp: int}|null
     */
    public static function parse(?string $token): ?array
    {
        if ($token === null || $token === '') {
            return null;
        }

        try {
            $payload = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($payload)
            || ! isset($payload['form_id'], $payload['public_key'], $payload['origin'], $payload['exp'])
            || (int) ($payload['v'] ?? 0) !== 1) {
            return null;
        }

        return [
            'form_id' => (int) $payload['form_id'],
            'public_key' => (string) $payload['public_key'],
            'origin' => TicketEmbedForm::normaliseOrigin((string) $payload['origin']),
            'exp' => (int) $payload['exp'],
        ];
    }

    public static function validFor(TicketEmbedForm $form, ?string $token, ?string $origin): bool
    {
        $payload = self::parse($token);
        if ($payload === null) {
            return false;
        }

        if ($payload['form_id'] !== $form->id || $payload['public_key'] !== $form->public_key) {
            return false;
        }

        if ($payload['exp'] < now()->getTimestamp()) {
            return false;
        }

        $origin = TicketEmbedForm::normaliseOrigin((string) $origin);

        return $origin !== ''
            && $payload['origin'] === $origin
            && $form->allowsOrigin($origin);
    }
}
