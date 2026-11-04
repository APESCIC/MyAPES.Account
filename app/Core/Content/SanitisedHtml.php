<?php

namespace App\Core\Content;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Model cast that sanitises rich text before it is stored (#484).
 *
 * @implements CastsAttributes<string|null, string|null>
 */
final class SanitisedHtml implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : (string) $value;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : app(RichText::class)->clean((string) $value);
    }
}
