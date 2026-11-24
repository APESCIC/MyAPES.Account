<?php

namespace App\Http\Controllers;

use App\Core\Push\ProfileNotificationTypeCatalogue;
use App\Core\Push\PushDeviceLabels;
use App\Core\Push\PushNotificationPreferenceUpdater;
use App\Core\Push\WebPushDeliveryService;
use App\Notifications\ProfilePushTestNotification;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use NotificationChannels\WebPush\PushSubscription;

class ProfileNotificationController extends Controller
{
    public function update(
        Request $request,
        PushNotificationPreferenceUpdater $updater,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $request->validate([
            'quiet_hours_start' => ['nullable', 'date_format:H:i'],
            'quiet_hours_end' => ['nullable', 'date_format:H:i'],
        ]);

        $catalogue = app(ProfileNotificationTypeCatalogue::class);
        $payload = [
            'quiet_hours_enabled' => $request->boolean('quiet_hours_enabled'),
            'quiet_hours_start' => $request->input('quiet_hours_start'),
            'quiet_hours_end' => $request->input('quiet_hours_end'),
            'push' => [],
            'email' => [],
            'divisions' => [],
        ];

        foreach ($catalogue->typesFor($user) as $row) {
            $payload['push'][$row['key']] = $request->boolean('push.'.$row['key']);
            $payload['email'][$row['key']] = $request->boolean('email.'.$row['key']);
        }

        foreach ($catalogue->divisionsFor($user) as $row) {
            $payload['divisions'][$row['key']] = $request->boolean('divisions.'.$row['key']);
        }

        $summary = $updater->update($user, $payload);

        $auditLogger->record('profile.notifications.updated', $user, $user, [
            'types_updated' => $summary['types_updated'],
            'divisions_updated' => $summary['divisions_updated'],
            'quiet_hours_enabled' => $summary['quiet_hours_enabled'],
        ]);

        return redirect()
            ->to(route('account.privacy').'#notifications')
            ->with('status', __('flash.notifications_updated'));
    }

    public function destroyDevice(
        Request $request,
        int $subscription,
        ProfileNotificationTypeCatalogue $catalogue,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $device = PushSubscription::query()
            ->whereKey($subscription)
            ->where('subscribable_id', $user->id)
            ->where('subscribable_type', $user->getMorphClass())
            ->firstOrFail();

        $device->delete();

        $auditLogger->record('profile.notifications.device_removed', $user, $user, [
            'device_count' => count($catalogue->devicesFor($user->fresh())),
        ]);

        return redirect()
            ->to(route('account.privacy').'#notifications')
            ->with('status', __('flash.notifications_updated'));
    }

    public function sendTest(
        Request $request,
        int $subscription,
        WebPushDeliveryService $delivery,
        PushDeviceLabels $labels,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $device = PushSubscription::query()
            ->whereKey($subscription)
            ->where('subscribable_id', $user->id)
            ->where('subscribable_type', $user->getMorphClass())
            ->firstOrFail();

        $key = 'profile-push-test:'.$user->id;
        $notification = new ProfilePushTestNotification;

        $sent = RateLimiter::attempt($key, 5, function () use ($delivery, $user, $notification, $device): void {
            $delivery->send($user, $notification, $device, 1);
        }, 600);

        if (! $sent) {
            throw ValidationException::withMessages([
                'test' => __('push.profile.errors.test_rate_limited', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        $browser = $device->device_browser ?? $labels->summarise($device->user_agent)['browser'];

        $auditLogger->record('profile.notifications.test_sent', $user, $user, [
            'device_id' => (int) $device->getKey(),
            'device_browser' => $browser,
        ]);

        return redirect()
            ->to(route('account.privacy').'#notifications')
            ->with('status', __('flash.notifications_test_sent'));
    }
}
