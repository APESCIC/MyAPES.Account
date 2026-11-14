<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Settings\ApiClientsTab;
use App\Http\Controllers\Admin\Settings\ApiDocsTab;
use App\Http\Controllers\Admin\Settings\IntegrationsTab;
use App\Http\Controllers\Admin\Settings\KeysAndSecretsTab;
use App\Http\Controllers\Admin\Settings\WebhooksTab;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

/**
 * Admin → Settings (#733): one page per tab. Add a tab by adding its key,
 * label and view here. The reply mailbox lives in Admin → Emails → Import (#774).
 */
class AdminSettingsController extends Controller
{
    public const TAB_INTEGRATIONS = 'integrations';

    public const TAB_API_CLIENTS = 'api-clients';

    public const TAB_WEBHOOKS = 'webhooks';

    public const TAB_API_DOCS = 'api-docs';

    public const TAB_KEYS_AND_SECRETS = 'keys-and-secrets';

    public function index(): RedirectResponse
    {
        return redirect()->route('admin.settings.show', self::TAB_INTEGRATIONS);
    }

    public function show(
        string $tab,
        IntegrationsTab $integrations,
        ApiClientsTab $apiClients,
        WebhooksTab $webhooks,
        ApiDocsTab $apiDocs,
        KeysAndSecretsTab $keysAndSecrets,
    ): Response {
        $tabs = $this->tabs();
        abort_unless(isset($tabs[$tab]), 404);

        return response()->view('admin.settings.show', [
            'tabs' => $tabs,
            'activeTab' => $tab,
            'tabView' => 'admin.settings.tabs.'.$tab,
            'tabData' => match ($tab) {
                self::TAB_INTEGRATIONS => $integrations->viewData(),
                self::TAB_API_CLIENTS => $apiClients->viewData(),
                self::TAB_WEBHOOKS => $webhooks->viewData(),
                self::TAB_API_DOCS => $apiDocs->viewData(),
                self::TAB_KEYS_AND_SECRETS => $keysAndSecrets->viewData(),
            },
        ])->header('Cache-Control', 'no-store, private');
    }

    /**
     * @return array<string, string>
     */
    private function tabs(): array
    {
        return [
            self::TAB_INTEGRATIONS => __('admin.settings.tabs.integrations'),
            self::TAB_API_CLIENTS => __('admin.settings.tabs.api_clients'),
            self::TAB_WEBHOOKS => __('admin.settings.tabs.webhooks'),
            self::TAB_API_DOCS => __('admin.settings.tabs.api_docs'),
            self::TAB_KEYS_AND_SECRETS => __('admin.settings.tabs.keys_and_secrets'),
        ];
    }
}
