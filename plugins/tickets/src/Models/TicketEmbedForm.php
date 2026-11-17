<?php

namespace Plugins\Tickets\Models;

use App\Core\Accounts\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

#[Fillable([
    'name',
    'public_key',
    'signing_secret',
    'is_active',
    'enabled_services',
    'allowed_origins',
    'spam_score_threshold',
    'confirmation_text',
    'created_by',
])]
class TicketEmbedForm extends Model
{
    public const DEFAULT_SERVICES = ['apes-cic', 'shelter-rescue', 'pet-care-clinic'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'enabled_services' => 'array',
            'allowed_origins' => 'array',
            'spam_score_threshold' => 'float',
        ];
    }

    /**
     * @return Attribute<string, string>
     */
    protected function signingSecret(): Attribute
    {
        return Attribute::make(
            get: static function (?string $value): string {
                if ($value === null || $value === '') {
                    return '';
                }

                return Crypt::decryptString($value);
            },
            set: static fn (string $value): string => Crypt::encryptString($value),
        );
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class, 'embed_form_id');
    }

    public static function newPublicKey(): string
    {
        return 'tef_'.Str::lower(Str::random(40));
    }

    public static function newSigningSecret(): string
    {
        return Str::random(64);
    }

    /**
     * @return list<string>
     */
    public function normalisedOrigins(): array
    {
        return collect($this->allowed_origins ?? [])
            ->map(static fn (mixed $origin): string => self::normaliseOrigin((string) $origin))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function allowsOrigin(?string $origin): bool
    {
        $origin = self::normaliseOrigin((string) $origin);

        return $origin !== '' && in_array($origin, $this->normalisedOrigins(), true);
    }

    public function allowsService(string $service): bool
    {
        return in_array($service, $this->enabled_services ?? [], true);
    }

    public static function normaliseOrigin(string $origin): string
    {
        $origin = trim($origin);
        if ($origin === '') {
            return '';
        }

        $parts = parse_url($origin);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return '';
        }

        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['https', 'http'], true)) {
            return '';
        }

        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return $scheme.'://'.$host.$port;
    }

    public function frameAncestorsSources(): array
    {
        return $this->normalisedOrigins();
    }
}
