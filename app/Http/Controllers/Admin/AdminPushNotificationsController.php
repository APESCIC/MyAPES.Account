<?php

namespace App\Http\Controllers\Admin;

use App\Core\Push\PushSettings;
use App\Core\Push\PushSubscriptionPurger;
use App\Core\Push\VapidConfigurationSync;
use App\Core\Push\VapidCredentials;
use App\Core\Push\WebPushDeliveryService;
use App\Core\Secrets\SecretRegistry;
use App\Core\Settings\GeneralSettings;
use App\Http\Controllers\Controller;
use App\Notifications\AdminPushTestNotification;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Minishlink\WebPush\VAPID;
use NotificationChannels\WebPush\PushSubscription;

/**
 * Admin → Settings → Push notifications (#803): VAPID keys, pause, test and stats.
 */
class AdminPushNotificationsController extends Controller
{
    private const TESTS_PER_WINDOW = 5;

    private const TEST_WINDOW_SECONDS = 600;

    public function storeVapid(
        Request $request,
        SecretRegistry $secrets,
        PushSubscriptionPurger $purger,
        VapidConfigurationSync $vapidSync,
        AuditLogger $audit,
    ): RedirectResponse {
        $hadStored = $secrets->source(SecretRegistry::VAPID_PUBLIC_KEY) === SecretRegistry::SOURCE_KEYS;

        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:500'],
            'public_key' => ['required', 'string', 'max:500'],
            'private_key' => ['required', 'string', 'max:500'],
            'subscriptions_confirm' => ['accepted'],
        ]);

        try {
            VAPID::validate([
                'subject' => $validated['subject'],
                'publicKey' => $validated['public_key'],
                'privateKey' => $validated['private_key'],
            ]);
        } catch (\ErrorException $exception) {
            throw ValidationException::withMessages([
                'vapid' => __('admin.settings.push_notifications.errors.invalid_vapid'),
            ]);
        }

        $userId = (int) $request->user()->id;
        $this->saveVapidSecrets($secrets, $audit, $request, $validated['subject'], $validated['public_key'], $validated['private_key'], $userId);

        $purger->purgeAll();
        $vapidSync->sync();

        return $this->back()->with('status', $hadStored
            ? __('admin.settings.push_notifications.vapid.replaced')
            : __('admin.settings.push_notifications.vapid.saved'));
    }

    public function clearVapid(
        Request $request,
        SecretRegistry $secrets,
        PushSubscriptionPurger $purger,
        VapidConfigurationSync $vapidSync,
        AuditLogger $audit,
    ): RedirectResponse {
        $request->validate([
            'confirm' => ['accepted'],
            'subscriptions_confirm' => ['accepted'],
        ]);

        foreach ([
            SecretRegistry::VAPID_SUBJECT,
            SecretRegistry::VAPID_PUBLIC_KEY,
            SecretRegistry::VAPID_PRIVATE_KEY,
        ] as $key) {
            if ($secrets->source($key) === SecretRegistry::SOURCE_KEYS) {
                $secrets->clear($key);
                $audit->record('admin.settings.secret_cleared', $request->user(), context: ['key' => $key]);
            }
        }

        $purger->purgeAll();
        $vapidSync->sync();

        return $this->back()->with('status', __('admin.settings.push_notifications.vapid.cleared'));
    }

    public function generateVapid(
        Request $request,
        SecretRegistry $secrets,
        PushSubscriptionPurger $purger,
        VapidConfigurationSync $vapidSync,
        AuditLogger $audit,
        GeneralSettings $general,
    ): RedirectResponse {
        $request->validate(['subscriptions_confirm' => ['accepted']]);

        try {
            $generated = VAPID::createVapidKeys();
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'vapid' => __('admin.settings.push_notifications.errors.generate_failed'),
            ]);
        }
        $supportEmail = $general->supportEmail();
        $subject = $supportEmail !== null && $supportEmail !== ''
            ? 'mailto:'.$supportEmail
            : 'mailto:communications@myapes.me.uk';

        VAPID::validate([
            'subject' => $subject,
            'publicKey' => $generated['publicKey'],
            'privateKey' => $generated['privateKey'],
        ]);

        $userId = (int) $request->user()->id;
        $this->saveVapidSecrets($secrets, $audit, $request, $subject, $generated['publicKey'], $generated['privateKey'], $userId);

        $purger->purgeAll();
        $vapidSync->sync();

        return $this->back()->with('status', __('admin.settings.push_notifications.vapid.generated'));
    }

    public function updatePause(Request $request, PushSettings $pushSettings, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate(['paused' => ['required', 'boolean']]);
        $paused = $request->boolean('paused');

        $pushSettings->setPaused($paused, (int) $request->user()->id);

        $audit->record('admin.push.pause_updated', $request->user(), context: [
            'paused' => $paused,
        ]);

        return $this->back()->with(
            'status',
            $paused
                ? __('admin.settings.push_notifications.pause.enabled')
                : __('admin.settings.push_notifications.pause.disabled'),
        );
    }

    public function sendTest(
        Request $request,
        VapidCredentials $vapid,
        WebPushDeliveryService $delivery,
        AuditLogger $audit,
    ): RedirectResponse {
        if (! $vapid->isConfigured()) {
            return $this->back()->withErrors(['test' => __('admin.settings.push_notifications.test_push.keys_needed')]);
        }

        $limiter = 'admin-push-test:'.$request->user()->id;
        if (RateLimiter::tooManyAttempts($limiter, self::TESTS_PER_WINDOW)) {
            return $this->back()->withErrors(['test' => __('admin.settings.push_notifications.test_push.rate_limited', [
                'minutes' => max(1, (int) ceil(RateLimiter::availableIn($limiter) / 60)),
            ])]);
        }

        $devices = PushSubscription::query()
            ->where('subscribable_id', $request->user()->id)
            ->where('subscribable_type', $request->user()->getMorphClass())
            ->get();

        if ($devices->isEmpty()) {
            return $this->back()->withErrors(['test' => __('admin.settings.push_notifications.test_push.no_devices')]);
        }

        $notification = new AdminPushTestNotification;
        $sent = 0;

        foreach ($devices as $device) {
            try {
                $delivery->send($request->user(), $notification, $device, 1);
                $sent++;
            } catch (\Throwable) {
                continue;
            }
        }

        RateLimiter::hit($limiter, self::TEST_WINDOW_SECONDS);

        $audit->record('admin.push.test_sent', $request->user(), context: [
            'devices' => $sent,
            'result' => $sent > 0 ? 'ok' : 'failed',
        ]);

        if ($sent === 0) {
            return $this->back()->withErrors(['test' => __('admin.settings.push_notifications.test_push.failed')]);
        }

        return $this->back()->with('status', __('admin.settings.push_notifications.test_push.sent'));
    }

  /**
   * @param  array{subject: string, public_key: string, private_key: string}  $validated
   */
    private function saveVapidSecrets(
        SecretRegistry $secrets,
        AuditLogger $audit,
        Request $request,
        string $subject,
        string $publicKey,
        string $privateKey,
        int $userId,
    ): void {
        $pairs = [
            SecretRegistry::VAPID_SUBJECT => $subject,
            SecretRegistry::VAPID_PUBLIC_KEY => $publicKey,
            SecretRegistry::VAPID_PRIVATE_KEY => $privateKey,
        ];

        foreach ($pairs as $key => $value) {
            $hadStored = $secrets->source($key) === SecretRegistry::SOURCE_KEYS;
            $secrets->set($key, $value, $userId);
            $audit->record(
                $hadStored ? 'admin.settings.secret_replaced' : 'admin.settings.secret_set',
                $request->user(),
                context: ['key' => $key],
            );
        }
    }

    private function back(): RedirectResponse
    {
        return redirect()->route('admin.settings.show', AdminSettingsController::TAB_PUSH_NOTIFICATIONS);
    }
}