<?php

namespace Plugins\Cases\Http\Controllers\Api;

use App\Core\Api\ApiScope;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Plugins\Cases\Models\CaseUpdate;
use Plugins\Cases\Models\ShelterCase;

/**
 * A case for EASM (#640): cases:read, limited to the client's organisation.
 * Internal updates are never returned.
 */
class CaseApiController extends Controller
{
    public function show(Request $request, int $ref): JsonResponse
    {
        $case = ShelterCase::query()
            ->forSubCore(ApiScope::client($request)->organisation)
            ->with([
                'user',
                'petProfile',
                'updates' => static fn ($query) => $query->where('visibility', CaseUpdate::VISIBILITY_PUBLIC)->with('user')->oldest('id'),
            ])
            ->findOrFail($ref);

        return response()->json(['data' => [
            'id' => $case->id,
            'reference' => 'Case #'.$case->id,
            'organisation' => $case->sub_core_key,
            'case_type' => $case->case_type,
            'category' => $case->category,
            'sub_category' => $case->sub_category,
            'title' => $case->title,
            'details' => $case->details,
            'priority' => $case->priority,
            'status' => $case->status,
            'requester' => $case->user === null ? null : ['id' => $case->user->id, 'name' => $case->user->name],
            'pet' => $case->petProfile === null ? null : ['id' => $case->petProfile->id, 'name' => $case->petProfile->name, 'species' => $case->petProfile->species],
            'opened_at' => $case->opened_at?->toIso8601String(),
            'resolved_at' => $case->resolved_at?->toIso8601String(),
            'closed_at' => $case->closed_at?->toIso8601String(),
            'updates' => $case->updates->map(static fn (CaseUpdate $update): array => [
                'id' => $update->id,
                'from' => $update->user_id === $case->user_id ? 'requester' : 'staff',
                'body' => $update->body,
                'via_email' => (bool) $update->via_email,
                'created_at' => $update->created_at?->toIso8601String(),
            ])->values(),
        ]]);
    }
}
