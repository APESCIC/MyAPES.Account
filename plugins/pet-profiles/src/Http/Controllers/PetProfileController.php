<?php

namespace Plugins\PetProfiles\Http\Controllers;

use App\Core\Extensions\Modules\ModuleContext;
use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\SecureUploadService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Plugins\PetProfiles\Models\PetProfile;
use Plugins\PetProfiles\PetProfilePhotoResponder;
use Plugins\PetProfiles\PetProfilesArea;
use Plugins\PetProfiles\Support\StaffPetCreateReturn;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PetProfileController extends Controller
{
    public function __construct(
        private readonly ModuleContext $moduleContext,
    ) {}

    public function index(Request $request): View
    {
        $area = $this->area();
        $user = $request->user();
        if ($area->requireIndexAbility) {
            abort_unless(
                $user->can($area->permission('view-own'))
                    || $user->can($area->permission('view-all')),
                403,
            );
        } else {
            Gate::authorize('viewAny', PetProfile::class);
        }

        $filters = \App\Core\Search\ListFilterQuery::fromRequest($request, [], supportsAssignee: false);
        $query = PetProfile::query()
            ->visibleTo($user, $area->serviceDomain)
            ->with('user')
            ->latest();
        $searchId = $filters['q'] !== null ? ltrim($filters['q'], '#') : null;
        $hasMicrochip = \Illuminate\Support\Facades\Schema::hasColumn((new PetProfile)->getTable(), 'microchip');
        \App\Core\Search\ListFilterQuery::apply(
            $query,
            $filters,
            array_values(array_filter(['name', $hasMicrochip ? 'microchip' : null])),
            searchExtra: static function ($inner, string $like) use ($searchId): void {
                $inner->orWhereHas('user', static function ($userQuery) use ($like): void {
                    $userQuery->where('name', 'like', $like)->orWhere('email', 'like', $like);
                });
                if (is_string($searchId) && ctype_digit($searchId)) {
                    $inner->orWhere('id', (int) $searchId);
                }
            },
        );

        return view('pet-profiles::pets.index', [
            'pets' => $query->paginate(20)->withQueryString()->fragment('list'),
            'filters' => $filters,
            'canCreatePet' => $user->can($area->permission('create')),
            'returnTo' => StaffPetCreateReturn::requestedKey($request->query('return_to')),
            'area' => $area,
        ]);
    }

    public function store(
        Request $request,
        SecureUploadService $secureUploadService,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $area = $this->area();
        Gate::authorize($area->permission('create'));

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'species' => ['nullable', 'string', 'max:255'],
            'age_years' => ['nullable', 'integer', 'between:0,80'],
            'sex' => ['required', 'in:male,female,unknown'],
            'neutering_status' => ['required', 'in:neutered,not_neutered,unknown'],
            'health_issues' => ['nullable', 'string'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
        ]);

        $pet = new PetProfile([
            ...$validated,
            'service_domain' => $area->serviceDomain,
            'user_id' => $request->user()->id,
        ]);

        if ($request->hasFile('photo')) {
            $pet->photo_path = $secureUploadService->storeImage(
                $request->file('photo'),
                'pet-profiles',
                'photo'
            );
        }

        $pet->save();

        $meta = [
            'has_photo' => $request->hasFile('photo'),
        ];
        if ($area->moduleSlug === 'pet-care-clinic') {
            $meta['sub_core_key'] = 'pet-care-clinic';
            $meta['module_key'] = 'pet-profiles';
        }
        $auditLogger->record("{$area->auditEventPrefix}.created", $request->user(), $pet, $meta);

        $continueUrl = StaffPetCreateReturn::continueUrl(
            $request->input('return_to'),
            $pet->id,
        );

        if ($continueUrl !== null) {
            return redirect($continueUrl)
                ->with('status', $area->createFlash);
        }

        $redirect = redirect()->route($area->showRouteName(), $pet);
        if ($area->flashOnCreateRedirect) {
            $redirect->with('status', $area->createFlash);
        }

        return $redirect;
    }

    public function show(PetProfile $pet): View
    {
        $area = $this->area();
        Gate::authorize('view', [$pet, $area->serviceDomain]);

        if ($area->showOwnerMeta) {
            $pet->loadMissing('user');
        }

        return view('pet-profiles::pets.show', [
            'pet' => $pet,
            'canUpdatePet' => Gate::allows('update', [$pet, $area->serviceDomain]),
            'area' => $area,
        ]);
    }

    public function photo(
        PetProfile $pet,
        PetProfilePhotoResponder $photos,
    ): StreamedResponse {
        $area = $this->area();

        return $photos->response($pet, $area->serviceDomain);
    }

    public function update(
        Request $request,
        PetProfile $pet,
        SecureUploadService $secureUploadService,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $area = $this->area();
        Gate::authorize('update', [$pet, $area->serviceDomain]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'species' => ['nullable', 'string', 'max:255'],
            'age_years' => ['nullable', 'integer', 'between:0,80'],
            'sex' => ['required', 'in:male,female,unknown'],
            'neutering_status' => ['required', 'in:neutered,not_neutered,unknown'],
            'health_issues' => ['nullable', 'string'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
        ]);

        if ($request->hasFile('photo')) {
            $previousPhotoPath = $pet->photo_path;
            $validated['photo_path'] = $secureUploadService->storeImage(
                $request->file('photo'),
                'pet-profiles',
                'photo'
            );
            if (is_string($previousPhotoPath) && $previousPhotoPath !== '' && $previousPhotoPath !== $validated['photo_path']) {
                $secureUploadService->deleteIfPresent($previousPhotoPath);
            }
        }

        $pet->update($validated);

        $meta = [
            'photo_replaced' => $request->hasFile('photo'),
        ];
        if ($area->moduleSlug === 'pet-care-clinic') {
            $meta['sub_core_key'] = 'pet-care-clinic';
            $meta['module_key'] = 'pet-profiles';
        }
        $auditLogger->record("{$area->auditEventPrefix}.updated", $request->user(), $pet, $meta);

        return redirect()->route($area->showRouteName(), $pet)
            ->with('status', $area->updateFlash);
    }

    private function area(): PetProfilesArea
    {
        $slug = $this->moduleContext->slug()
            ?? abort(404);

        try {
            return PetProfilesArea::forModule($slug);
        } catch (\InvalidArgumentException) {
            abort(404);
        }
    }

}
