<?php

namespace App\Http\Controllers\Api\V1;

use App\Core\Api\ApiScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PersonResource;
use App\Services\ContactPreferenceUpdater;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Public accounts in the client's organisation (#640): users:read for lookup
 * and details, consent:read for contact consent (email is the mailing-list
 * channel for APES Newsroom).
 */
class PeopleController extends Controller
{
    public function __construct(private readonly ApiScope $scope) {}

    public function lookup(Request $request): PersonResource
    {
        $validated = $request->validate(['email' => ['required', 'email', 'max:255']]);

        $person = $this->scope->people(ApiScope::client($request))
            ->with('serviceSelections')
            ->whereRaw('LOWER(email) = ?', [Str::lower(trim($validated['email']))])
            ->firstOrFail();

        return new PersonResource($person);
    }

    public function show(Request $request, int $id): PersonResource
    {
        return new PersonResource($this->scope->people(ApiScope::client($request))->with('serviceSelections')->findOrFail($id));
    }

    public function consents(Request $request, int $id): JsonResponse
    {
        $person = $this->scope->people(ApiScope::client($request))->with('contactPreference')->findOrFail($id);
        $preference = $person->contactPreference;

        $channels = [];
        foreach (ContactPreferenceUpdater::CHANNELS as $channel) {
            $channels[$channel] = (bool) ($preference?->{$channel} ?? false);
        }

        $latest = $person->contactConsentEvents()
            ->selectRaw('channel, max(recorded_at) as changed_at')
            ->groupBy('channel')
            ->pluck('changed_at', 'channel');

        return response()->json(['data' => [
            'user_id' => $person->id,
            'channels' => $channels,
            'changed_at' => collect(ContactPreferenceUpdater::CHANNELS)
                ->mapWithKeys(static fn (string $channel): array => [$channel => $latest[$channel] ?? null])
                ->all(),
            'policy_version' => $preference?->policy_version,
            'confirmed_at' => $preference?->confirmed_at?->toIso8601String(),
        ]]);
    }
}
