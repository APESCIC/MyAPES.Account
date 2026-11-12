<?php

namespace Plugins\PetProfiles\Http\Controllers\Api;

use App\Core\Api\ApiScope;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Plugins\PetProfiles\Models\PetProfile;

/**
 * Pets for EASM (#640): pets:read, limited to the client's organisation and
 * to owners who chose that service. APES CIC has no pets.
 */
class PetApiController extends Controller
{
    private const DOMAINS = [
        'shelter-rescue' => PetProfile::DOMAIN_SHELTER,
        'pet-care-clinic' => PetProfile::DOMAIN_PETCARE,
    ];

    public function __construct(private readonly ApiScope $scope) {}

    public function forUser(Request $request, int $id): JsonResponse
    {
        $client = ApiScope::client($request);
        $owner = $this->scope->people($client)->findOrFail($id);
        $pets = $this->pets($request)->where('user_id', $owner->id)->orderBy('name')->orderBy('id')->paginate(ApiScope::perPage($request))->withQueryString();

        return response()->json([
            'data' => $pets->getCollection()->map(fn (PetProfile $pet): array => $this->present($pet))->values(),
            'meta' => ['current_page' => $pets->currentPage(), 'last_page' => $pets->lastPage(), 'per_page' => $pets->perPage(), 'total' => $pets->total()],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $client = ApiScope::client($request);
        $pet = $this->pets($request)
            ->whereIn('user_id', $this->scope->people($client)->select('users.id'))
            ->findOrFail($id);

        return response()->json(['data' => $this->present($pet)]);
    }

    /**
     * @return Builder<PetProfile>
     */
    private function pets(Request $request): Builder
    {
        $domain = self::DOMAINS[ApiScope::client($request)->organisation] ?? null;
        abort_if($domain === null, 404);

        return PetProfile::query()->where('service_domain', $domain);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PetProfile $pet): array
    {
        return [
            'id' => $pet->id,
            'owner_id' => $pet->user_id,
            'name' => $pet->name,
            'species' => $pet->species,
            'age_years' => $pet->age_years,
            'sex' => $pet->sex,
            'neutering_status' => $pet->neutering_status,
            'health_issues' => $pet->health_issues,
            'created_at' => $pet->created_at?->toIso8601String(),
            'updated_at' => $pet->updated_at?->toIso8601String(),
        ];
    }
}
