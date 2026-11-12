<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Core\Api\ApiAbilities;
use App\Core\Api\ApiClient;
use App\Core\Api\ApiClientManager;
use App\Http\Controllers\Admin\AdminApiClientController;

/**
 * What Admin → Settings → API clients shows (#639): each client with its
 * abilities, allowlist, tokens and last use. Token values are never shown,
 * except the one just issued, once.
 */
final class ApiClientsTab
{
    /**
     * @return array<string, mixed>
     */
    public function viewData(): array
    {
        return [
            'clients' => ApiClient::query()
                ->with(['tokens' => fn ($query) => $query->latest('id'), 'creator'])
                ->orderByRaw('revoked_at is not null')
                ->orderBy('name')
                ->get(),
            'abilities' => ApiAbilities::all(),
            'organisations' => ApiAbilities::ORGANISATIONS,
            'presets' => ApiAbilities::presets(),
            'defaultExpiryDays' => ApiClientManager::DEFAULT_EXPIRY_DAYS,
            'maxExpiryDays' => ApiClientManager::MAX_EXPIRY_DAYS,
            'newToken' => session(AdminApiClientController::TOKEN_FLASH_KEY),
        ];
    }
}
