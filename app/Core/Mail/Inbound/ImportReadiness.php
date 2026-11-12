<?php

namespace App\Core\Mail\Inbound;

use App\Core\Settings\AppSettings;

/**
 * Why replies by email are off and what is still missing (#775). Each item
 * says who fixes it: an administrator on the Import page, or IT on Cloudron.
 * Secrets are reported only as set or not set.
 */
final class ImportReadiness
{
    public const HEARTBEAT_MINUTES = 5;

    public const FIXED_BY_ADMIN = 'admin';

    public const FIXED_BY_IT = 'it';

    public function __construct(
        private readonly InboundMailSettings $inbound,
        private readonly AppSettings $settings,
        private readonly ReplyMailboxStatus $status,
    ) {}

    /**
     * @return list<array{key: string, ok: bool, message: string, fixed_by: string}>
     */
    public function items(): array
    {
        $lastTest = $this->status->lastTest();
        $heartbeat = $this->status->heartbeatAt();
        $probe = $this->status->probe();

        return [
            $this->item('token_key', $this->inbound->tokenKey() !== '', self::FIXED_BY_IT),
            $this->item('enabled', (bool) $this->settings->get(InboundMailboxSettings::GROUP, 'enabled'), $this->fixer('enabled')),
            $this->item('address', $this->inbound->mailbox() !== null && filter_var($this->inbound->address(), FILTER_VALIDATE_EMAIL) !== false, $this->fixer('address')),
            $this->item('password', $this->inbound->imap('password') !== '', $this->fixer('password')),
            $this->item('test', $lastTest !== null && $lastTest['ok'], self::FIXED_BY_ADMIN),
            $this->item(
                'heartbeat',
                $heartbeat !== null && $heartbeat->gte(now()->subMinutes(self::HEARTBEAT_MINUTES)),
                self::FIXED_BY_IT,
                $heartbeat === null ? 'not_recorded' : null,
            ),
            $this->item('probe', $probe !== null && $probe['arrived_at'] !== null, self::FIXED_BY_ADMIN),
        ];
    }

    public function ready(): bool
    {
        return array_filter($this->items(), static fn (array $item): bool => ! $item['ok']) === [];
    }

    /**
     * @return array{key: string, ok: bool, message: string, fixed_by: string}
     */
    private function item(string $key, bool $ok, string $fixedBy, ?string $missingState = null): array
    {
        $state = $ok ? 'ok' : ($missingState ?? 'missing');

        return [
            'key' => $key,
            'ok' => $ok,
            'message' => __("admin.emails.import.readiness.items.{$key}.{$state}", ['minutes' => self::HEARTBEAT_MINUTES]),
            'fixed_by' => $fixedBy,
        ];
    }

    /**
     * A field set in the server's .env file can only be changed by IT.
     */
    private function fixer(string $key): string
    {
        return $this->settings->overriddenByEnv(InboundMailboxSettings::GROUP, $key) ? self::FIXED_BY_IT : self::FIXED_BY_ADMIN;
    }
}
