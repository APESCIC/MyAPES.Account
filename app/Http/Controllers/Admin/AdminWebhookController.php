<?php

namespace App\Http\Controllers\Admin;

use App\Core\Api\ApiAbilities;
use App\Core\Webhooks\WebhookDelivery;
use App\Core\Webhooks\WebhookDispatcher;
use App\Core\Webhooks\WebhookEndpoint;
use App\Core\Webhooks\WebhookEvents;
use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Admin → Settings → Webhooks (#641, #642). The signing secret is flashed
 * once for the next page only; it is never shown again, logged or audited.
 */
class AdminWebhookController extends Controller
{
    public const SECRET_FLASH_KEY = 'webhook_new_secret';

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'url' => ['required', 'string', 'max:500', 'url:https', self::publicHttpsUrl()],
            'organisation' => ['required', Rule::in(ApiAbilities::ORGANISATIONS)],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', Rule::in(WebhookEvents::all())],
        ]);

        $secret = self::newSecret();
        $endpoint = WebhookEndpoint::query()->create([
            ...$validated,
            'events' => array_values(array_unique($validated['events'])),
            'secret' => $secret,
            'active' => true,
            'created_by' => $request->user()->id,
        ]);

        $audit->record('admin.webhooks.created', $request->user(), context: [
            'webhook_endpoint_id' => $endpoint->id,
            'name' => $endpoint->name,
            'host' => parse_url($endpoint->url, PHP_URL_HOST),
            'organisation' => $endpoint->organisation,
            'events' => $endpoint->events,
        ]);

        return $this->back()
            ->with('status', __('admin.settings.webhooks.created', ['name' => $endpoint->name]))
            ->with(self::SECRET_FLASH_KEY, ['endpoint' => $endpoint->name, 'secret' => $secret]);
    }

    public function rotateSecret(Request $request, int $webhookEndpoint, AuditLogger $audit): RedirectResponse
    {
        $webhookEndpoint = WebhookEndpoint::query()->findOrFail($webhookEndpoint);
        $secret = self::newSecret();
        $webhookEndpoint->forceFill(['secret' => $secret])->save();
        $audit->record('admin.webhooks.secret_rotated', $request->user(), context: ['webhook_endpoint_id' => $webhookEndpoint->id]);

        return $this->back()
            ->with('status', __('admin.settings.webhooks.secret_rotated', ['name' => $webhookEndpoint->name]))
            ->with(self::SECRET_FLASH_KEY, ['endpoint' => $webhookEndpoint->name, 'secret' => $secret]);
    }

    public function toggle(Request $request, int $webhookEndpoint, AuditLogger $audit): RedirectResponse
    {
        $webhookEndpoint = WebhookEndpoint::query()->findOrFail($webhookEndpoint);
        $active = ! $webhookEndpoint->active;
        $webhookEndpoint->forceFill($active
            ? ['active' => true, 'consecutive_failures' => 0, 'disabled_at' => null, 'disabled_reason' => null]
            : ['active' => false, 'disabled_at' => now(), 'disabled_reason' => 'Turned off by an administrator'])->save();
        $audit->record($active ? 'admin.webhooks.enabled' : 'admin.webhooks.disabled', $request->user(), context: ['webhook_endpoint_id' => $webhookEndpoint->id]);

        return $this->back()->with('status', __($active ? 'admin.settings.webhooks.enabled' : 'admin.settings.webhooks.disabled', ['name' => $webhookEndpoint->name]));
    }

    public function destroy(Request $request, int $webhookEndpoint, AuditLogger $audit): RedirectResponse
    {
        $webhookEndpoint = WebhookEndpoint::query()->findOrFail($webhookEndpoint);
        $audit->record('admin.webhooks.deleted', $request->user(), context: ['webhook_endpoint_id' => $webhookEndpoint->id, 'name' => $webhookEndpoint->name]);
        $webhookEndpoint->delete();

        return $this->back()->with('status', __('admin.settings.webhooks.deleted', ['name' => $webhookEndpoint->name]));
    }

    public function replay(Request $request, int $webhookDelivery, WebhookDispatcher $dispatcher, AuditLogger $audit): RedirectResponse
    {
        $webhookDelivery = WebhookDelivery::query()->with('endpoint')->findOrFail($webhookDelivery);
        abort_unless($webhookDelivery->endpoint->active, 409, __('admin.settings.webhooks.replay_inactive'));

        $replay = $dispatcher->replay($webhookDelivery);
        $audit->record('admin.webhooks.replayed', $request->user(), context: [
            'webhook_delivery_id' => $webhookDelivery->id,
            'replay_delivery_id' => $replay->id,
            'event_id' => $webhookDelivery->event_id,
        ]);

        return $this->back()->with('status', __('admin.settings.webhooks.replayed', ['event' => $webhookDelivery->event]));
    }

    private static function newSecret(): string
    {
        return 'whsec_'.Str::random(40);
    }

    /**
     * HTTPS only, and never this server or a private network address.
     */
    private static function publicHttpsUrl(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            $parts = parse_url((string) $value);
            $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

            $private = filter_var($host, FILTER_VALIDATE_IP) !== false
                && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;

            if (($parts['scheme'] ?? '') !== 'https' || $host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || $private) {
                $fail(__('admin.settings.webhooks.https_only'));
            }
        };
    }

    private function back(): RedirectResponse
    {
        return redirect()->route('admin.settings.show', AdminSettingsController::TAB_WEBHOOKS);
    }
}
