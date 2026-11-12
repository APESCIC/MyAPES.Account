<?php

namespace App\Http\Controllers\Admin;

use App\Core\Api\ApiAbilities;
use App\Core\Api\ApiClient;
use App\Core\Api\ApiClientManager;
use App\Http\Controllers\Controller;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Admin → Settings → API clients (#639). A new or rotated token is flashed
 * once for the next page only; it is never stored in plain text, logged or audited.
 */
class AdminApiClientController extends Controller
{
    public const TOKEN_FLASH_KEY = 'api_client_new_token';

    public function store(Request $request, ApiClientManager $manager): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'organisation' => ['required', Rule::in(ApiAbilities::ORGANISATIONS)],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['string', Rule::in(ApiAbilities::all())],
            'expires_in_days' => ['required', 'integer', 'min:1', 'max:'.ApiClientManager::MAX_EXPIRY_DAYS],
            ...$this->accessRules(),
        ]);

        [$client, $plain] = $manager->create([
            'name' => $validated['name'],
            'organisation' => $validated['organisation'],
            'abilities' => array_values(array_unique($validated['abilities'])),
            'ip_allowlist' => $this->allowlist($validated['ip_allowlist'] ?? null),
            'rate_limit_per_minute' => (int) $validated['rate_limit_per_minute'],
        ], $request->user(), (int) $validated['expires_in_days']);

        return $this->back()
            ->with('status', __('admin.settings.api_clients.created', ['name' => $client->name]))
            ->with(self::TOKEN_FLASH_KEY, ['client' => $client->name, 'token' => $plain]);
    }

    public function update(Request $request, ApiClient $apiClient, ApiClientManager $manager): RedirectResponse
    {
        abort_if($apiClient->isRevoked(), 404);
        $validated = $request->validate($this->accessRules());

        $manager->update($apiClient, [
            'ip_allowlist' => $this->allowlist($validated['ip_allowlist'] ?? null),
            'rate_limit_per_minute' => (int) $validated['rate_limit_per_minute'],
        ], $request->user());

        return $this->back()->with('status', __('admin.settings.api_clients.updated', ['name' => $apiClient->name]));
    }

    public function rotate(Request $request, ApiClient $apiClient, ApiClientManager $manager): RedirectResponse
    {
        abort_if($apiClient->isRevoked(), 404);
        $validated = $request->validate([
            'expires_in_days' => ['required', 'integer', 'min:1', 'max:'.ApiClientManager::MAX_EXPIRY_DAYS],
        ]);

        $plain = $manager->rotate($apiClient, $request->user(), (int) $validated['expires_in_days']);

        return $this->back()
            ->with('status', __('admin.settings.api_clients.rotated', ['name' => $apiClient->name, 'hours' => ApiClientManager::ROTATION_OVERLAP_HOURS]))
            ->with(self::TOKEN_FLASH_KEY, ['client' => $apiClient->name, 'token' => $plain]);
    }

    public function revoke(Request $request, ApiClient $apiClient, ApiClientManager $manager): RedirectResponse
    {
        abort_if($apiClient->isRevoked(), 404);
        $manager->revoke($apiClient, $request->user());

        return $this->back()->with('status', __('admin.settings.api_clients.revoked', ['name' => $apiClient->name]));
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function accessRules(): array
    {
        return [
            'rate_limit_per_minute' => ['required', 'integer', 'min:1', 'max:1000'],
            'ip_allowlist' => ['nullable', 'string', 'max:2000', static function (string $attribute, mixed $value, Closure $fail): void {
                foreach (preg_split('/[\s,]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $entry) {
                    if (! self::validIpOrRange($entry)) {
                        $fail(__('admin.settings.api_clients.invalid_ip', ['entry' => $entry]));
                    }
                }
            }],
        ];
    }

    private static function validIpOrRange(string $entry): bool
    {
        [$ip, $bits] = array_pad(explode('/', $entry, 2), 2, null);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        if ($bits === null) {
            return true;
        }
        $max = str_contains($ip, ':') ? 128 : 32;

        return ctype_digit($bits) && (int) $bits >= 0 && (int) $bits <= $max && IpUtils::checkIp($ip, $entry);
    }

    /**
     * @return list<string>|null
     */
    private function allowlist(?string $value): ?array
    {
        $entries = array_values(array_unique(preg_split('/[\s,]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY) ?: []));

        return $entries === [] ? null : $entries;
    }

    private function back(): RedirectResponse
    {
        return redirect()->route('admin.settings.show', AdminSettingsController::TAB_API_CLIENTS);
    }
}
