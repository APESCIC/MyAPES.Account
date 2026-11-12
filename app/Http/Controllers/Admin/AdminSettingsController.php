<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Settings\ApiClientsTab;
use App\Http\Controllers\Admin\Settings\IntegrationsTab;
use App\Http\Controllers\Admin\Settings\ReplyMailboxTab;
use App\Http\Controllers\Admin\Settings\WebhooksTab;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

/**
 * Admin → Settings (#733): one page per tab. Add a tab by adding its key,
 * label and view here.
 */
class AdminSettingsController extends Controller
{
    public const TAB_REPLY_MAILBOX = 'reply-mailbox';

    public const TAB_INTEGRATIONS = 'integrations';

    public const TAB_API_CLIENTS = 'api-clients';

    public const TAB_WEBHOOKS = 'webhooks';

    public function index(): RedirectResponse
    {
        return redirect()->route('admin.settings.show', self::TAB_REPLY_MAILBOX);
    }

    public function show(string $tab, ReplyMailboxTab $replyMailbox, IntegrationsTab $integrations, ApiClientsTab $apiClients, WebhooksTab $webhooks): Response
    {
        $tabs = $this->tabs();
        abort_unless(isset($tabs[$tab]), 404);

        return response()->view('admin.settings.show', [
            'tabs' => $tabs,
            'activeTab' => $tab,
            'tabView' => 'admin.settings.tabs.'.$tab,
            'tabData' => match ($tab) {
                self::TAB_REPLY_MAILBOX => $replyMailbox->viewData(),
                self::TAB_INTEGRATIONS => $integrations->viewData(),
                self::TAB_API_CLIENTS => $apiClients->viewData(),
                self::TAB_WEBHOOKS => $webhooks->viewData(),
            },
        ])->header('Cache-Control', 'no-store, private');
    }

    /**
     * @return array<string, string>
     */
    private function tabs(): array
    {
        return [
            self::TAB_REPLY_MAILBOX => __('admin.settings.tabs.reply_mailbox'),
            self::TAB_INTEGRATIONS => __('admin.settings.tabs.integrations'),
            self::TAB_API_CLIENTS => __('admin.settings.tabs.api_clients'),
            self::TAB_WEBHOOKS => __('admin.settings.tabs.webhooks'),
        ];
    }
}
