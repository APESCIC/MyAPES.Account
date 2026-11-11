<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Core\Mail\Inbound\InboundMailSettings;

/**
 * What Admin → Settings → Reply mailbox shows (#733).
 */
final class ReplyMailboxTab
{
    public function __construct(private readonly InboundMailSettings $inbound) {}

    /**
     * @return array<string, mixed>
     */
    public function viewData(): array
    {
        return [
            'repliesEnabled' => $this->inbound->repliesEnabled(),
            'pollingConfigured' => $this->inbound->pollingConfigured(),
            'address' => $this->inbound->address(),
        ];
    }
}
