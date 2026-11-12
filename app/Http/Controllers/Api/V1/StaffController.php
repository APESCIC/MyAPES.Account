<?php

namespace App\Http\Controllers\Api\V1;

use App\Core\Accounts\DirectoryGroup;
use App\Core\Api\ApiScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\StaffResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Staff and their groups for APES Newsroom authors (#640): staff:read and groups:read.
 */
class StaffController extends Controller
{
    public function __construct(private readonly ApiScope $scope) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $staff = $this->scope->staff(ApiScope::client($request))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(ApiScope::perPage($request))
            ->withQueryString();

        return StaffResource::collection($staff);
    }

    public function show(Request $request, int $id): StaffResource
    {
        return new StaffResource($this->scope->staff(ApiScope::client($request))->findOrFail($id));
    }

    public function groups(Request $request): JsonResponse
    {
        $groups = DirectoryGroup::query()
            ->managedMyApesGroups()
            ->where('status', 'present')
            ->orderBy('name')
            ->paginate(ApiScope::perPage($request))
            ->withQueryString();

        return response()->json([
            'data' => $groups->getCollection()->map(static fn (DirectoryGroup $group): array => [
                'id' => $group->id,
                'name' => $group->name,
                'member_count' => $group->member_count,
                'app_enabled' => $group->isAppEnabled(),
            ])->values(),
            'meta' => ['current_page' => $groups->currentPage(), 'last_page' => $groups->lastPage(), 'per_page' => $groups->perPage(), 'total' => $groups->total()],
        ]);
    }
}
