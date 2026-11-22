<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Core\Accounts\User;
use App\Core\Push\PushAdminStats;
use App\Core\Push\PushSettings;
use App\Core\Push\VapidCredentials;
use App\Core\Push\VapidPublicKeyFingerprint;
use App\Core\Secrets\SecretRegistry;

/**
 * View data for Admin → Settings → Push notifications (#803). Never passes
 * secret values to the page: only status, source and a public-key fingerprint.
 */
final class PushNotificationsTab
{
    public function __construct(
        private readonly VapidCredentials $vapid,
        private readonly SecretRegistry $secrets,
        private readonly PushSettings $pushSettings,
        private readonly PushAdminStats $stats,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function viewData(): array
    {
        $subjectStatus = $this->secrets->status(SecretRegistry::VAPID_SUBJECT);
        $publicStatus = $this->secrets->status(SecretRegistry::VAPID_PUBLIC_KEY);
        $privateStatus = $this->secrets->status(SecretRegistry::VAPID_PRIVATE_KEY);

        $setById = $publicStatus['set_by'] ?? $subjectStatus['set_by'] ?? $privateStatus['set_by'];
        $setBy = $setById !== null ? User::query()->find($setById) : null;

        $fingerprint = $this->vapid->isConfigured()
            ? VapidPublicKeyFingerprint::format($this->vapid->publicKey())
            : null;

        return [
            'configured' => $this->vapid->isConfigured(),
            'statusLabel' => $this->vapid->statusLabel(),
            'source' => $this->vapid->source(),
            'fingerprint' => $fingerprint,
            'set_at' => $publicStatus['set_at'] ?? $subjectStatus['set_at'] ?? $privateStatus['set_at'],
            'set_by_name' => $setBy?->name,
            'paused' => $this->pushSettings->paused(),
            'deviceCounts' => $this->stats->activeDevicesByRole(),
            'deliveryCounts' => $this->stats->deliveryTotalsLast7Days(),
        ];
    }
}