<?php

namespace App\Core\Mail\Inbound;

use App\Core\Secrets\SecretRegistry;

/**
 * Why replies by email are off and what is still missing (#775, #811).
 * Form items are gone; the checklist keeps token key, password, last test,
 * heartbeat and the plus-address probe.
 */
final class ImportReadiness
{
    public const HEARTBEAT_MINUTES = 5;

    public const FIXED_BY_ADMIN = 'admin';

    public const FIXED_BY_IT = 'it';

    public function __construct(
        private readonly InboundMailSettings $inbound,
        private readonly SecretRegistry $secrets,
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
            $this->item('password', $this->inbound->imapPassword() !== '', $this->passwordFixer()),
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

    private function passwordFixer(): string
    {
        return match ($this->inbound->passwordSource()) {
            InboundMailSettings::PASSWORD_SOURCE_ENV => self::FIXED_BY_IT,
            default => self::FIXED_BY_ADMIN,
        };
    }
}
