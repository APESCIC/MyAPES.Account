<?php

namespace App\Core\Mail\Inbound;

use App\Core\Settings\AppSetting;
use App\Core\Settings\AppSettings;
use App\Services\AuditLogger;
use RuntimeException;

/**
 * Deletes the old Admin Import form rows once polling uses Keys and secrets
 * or .env (#809). Field names only go into the audit trail.
 */
final class RetireStoredMailboxSettings
{
    public function __construct(
        private readonly AppSettings $settings,
        private readonly InboundMailSettings $inbound,
        private readonly AuditLogger $audit,
    ) {}

    public function hasStoredRows(): bool
    {
        return AppSetting::query()->where('group', InboundMailboxSettings::GROUP)->exists();
    }

    /**
     * @return list<string> Keys that were deleted.
     */
    public function retire(?int $userId = null, bool $force = false): array
    {
        if (! $this->hasStoredRows()) {
            return [];
        }

        $source = $this->inbound->passwordSource();
        $newPassword = in_array($source, [
            InboundMailSettings::PASSWORD_SOURCE_KEYS,
            InboundMailSettings::PASSWORD_SOURCE_ENV,
        ], true);

        if (! $force && ! $newPassword) {
            throw new RuntimeException('Cannot retire stored mailbox settings while no Keys and secrets or .env password is present.');
        }

        if (! $force && $source === InboundMailSettings::PASSWORD_SOURCE_LEGACY) {
            throw new RuntimeException('Cannot retire stored mailbox settings while polling still uses the legacy password.');
        }

        $keys = AppSetting::query()
            ->where('group', InboundMailboxSettings::GROUP)
            ->pluck('key')
            ->map(fn ($key): string => (string) $key)
            ->values()
            ->all();

        AppSetting::query()->where('group', InboundMailboxSettings::GROUP)->delete();
        $this->settings->flush(InboundMailboxSettings::GROUP);

        $this->audit->record('admin.settings.mailbox_retired', null, context: [
            'fields' => $keys,
            'password_source' => $source,
            'user_id' => $userId,
        ]);

        return $keys;
    }

    /**
     * Called after a successful poll that used the new password source.
     */
    public function retireAfterSuccessfulPoll(): void
    {
        $source = $this->inbound->passwordSource();
        if (! in_array($source, [
            InboundMailSettings::PASSWORD_SOURCE_KEYS,
            InboundMailSettings::PASSWORD_SOURCE_ENV,
        ], true)) {
            return;
        }

        if (! $this->hasStoredRows()) {
            return;
        }

        $this->retire();
    }
}
