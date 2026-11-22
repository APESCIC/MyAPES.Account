<?php

namespace App\Http\Controllers;

use App\Core\Push\PushDeviceLabels;
use App\Core\Push\PushEndpointValidator;
use App\Core\Push\PushPreferenceInitializer;
use App\Core\Push\VapidCredentials;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use NotificationChannels\WebPush\PushSubscription;

class PushSubscriptionController extends Controller
{
    public function vapidPublicKey(VapidCredentials $vapid): JsonResponse
    {
        return response()->json([
            'publicKey' => $vapid->publicKey(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:1024'],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string', 'max:500'],
            'keys.auth' => ['required', 'string', 'max:500'],
            'contentEncoding' => ['nullable', 'string', 'max:50'],
        ]);

        if (! PushEndpointValidator::accepts($validated['endpoint'])) {
            throw ValidationException::withMessages([
                'endpoint' => __('validation.push_endpoint'),
            ]);
        }

        $user = $request->user();
        abort_unless($user !== null, 401);

        $firstDevice = ! $user->pushSubscriptions()->exists();

        $user->updatePushSubscription(
            $validated['endpoint'],
            $validated['keys']['p256dh'],
            $validated['keys']['auth'],
            $validated['contentEncoding'] ?? null,
        );

        if ($firstDevice) {
            app(PushPreferenceInitializer::class)->seedDefaultsForUser($user);
        }

        $labels = app(PushDeviceLabels::class)->summarise($request->userAgent());
        PushSubscription::query()
            ->where('endpoint', $validated['endpoint'])
            ->update([
                'device_browser' => $labels['browser'],
                'device_system' => $labels['system'],
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 512),
            ]);

        return response()->json(['saved' => true]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:1024'],
        ]);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $user->deletePushSubscription($validated['endpoint']);

        return response()->json(['removed' => true]);
    }
}
