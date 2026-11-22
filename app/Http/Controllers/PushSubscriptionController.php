<?php

namespace App\Http\Controllers;

use App\Core\Push\PushEndpointValidator;
use App\Core\Push\VapidCredentials;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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

        $user->updatePushSubscription(
            $validated['endpoint'],
            $validated['keys']['p256dh'],
            $validated['keys']['auth'],
            $validated['contentEncoding'] ?? null,
        );

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
