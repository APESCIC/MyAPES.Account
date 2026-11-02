<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\ModuleRegistry;
use App\Core\Accounts\DirectoryGroup;
use App\Core\Accounts\DirectoryGroupRoleMapping;
use App\Core\Accounts\Permission;
use App\Core\Accounts\Role;
use App\Core\Extensions\Plugins\PluginEnablement;
use App\Http\Controllers\Controller;
use App\Jobs\RunDirectorySync;
use App\Services\AuditLogger;
use App\Services\AuthorizationProfile;
use App\Services\AuthorizationRoleManagementService;
use App\Services\DirectoryGroupMappingService;
use App\Services\ManualDirectorySyncQueueResolver;
use App\Support\JobRoleCapabilityPacks;
use App\Support\PermissionDescriptions;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use LogicException;

class AdminAccessController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        if (! Gate::any([
            'admin.groups.view',
            'admin.roles.view',
            'admin.permissions.view',
        ])) {
            abort(403);
        }

        $validated = $request->validate([
            'tab' => ['nullable', Rule::in(['groups', 'job-roles', 'permissions'])],
        ]);
        $tab = $validated['tab'] ?? 'groups';

        return match ($tab) {
            'groups' => $this->groupsTab($request),
            'job-roles' => $this->jobRolesTab($request),
            'permissions' => $this->permissionsTab($request),
        };
    }

    public function sync(
        Request $request,
        AuditLogger $auditLogger,
        ManualDirectorySyncQueueResolver $queueResolver,
    ): RedirectResponse {
        Gate::authorize('admin.group-mappings.manage');

        try {
            $connection = $queueResolver->resolve();
        } catch (DomainException $exception) {
            return back()->withErrors([
                'authorization' => $exception->getMessage(),
            ]);
        }

        RunDirectorySync::dispatch()
            ->onConnection($connection);
        $actor = $request->user();
        $auditLogger->record(
            'authorization.directory_sync_requested',
            $actor,
            null,
            [
                'actor_id' => $actor->id,
                'source_key' => 'manual',
            ],
        );

        return redirect()
            ->route('admin.access.index', ['tab' => 'groups'])
            ->with('status', __('admin.flash.directory_synchronization_requested'));
    }

    public function storeMapping(
        Request $request,
        string $directoryGroup,
        DirectoryGroupMappingService $mappings,
    ): RedirectResponse {
        Gate::authorize('admin.group-mappings.manage');
        $managedGroup = DirectoryGroup::query()->findOrFail(
            $directoryGroup,
        );
        $validated = $request->validate([
            'role_id' => [
                'required',
                'integer',
                Rule::exists('roles', 'id')->where(
                    fn ($query) => $query
                        ->where('guard_name', 'web')
                        ->where('is_protected', false),
                ),
            ],
        ]);
        $role = Role::query()->findOrFail($validated['role_id']);

        try {
            $mappings->map($request->user(), $managedGroup, $role);
        } catch (DomainException|InvalidArgumentException|LogicException $exception) {
            return back()->withErrors([
                'authorization' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('admin.access.index', ['tab' => 'groups'])
            ->with('status', __('admin.flash.job_role_mapping_updated'));
    }

    public function destroyMapping(
        Request $request,
        string $mapping,
        DirectoryGroupMappingService $mappings,
    ): RedirectResponse {
        Gate::authorize('admin.group-mappings.manage');
        $managedMapping = DirectoryGroupRoleMapping::query()->findOrFail(
            $mapping,
        );

        try {
            $mappings->remove($request->user(), $managedMapping);
        } catch (DomainException|LogicException $exception) {
            return back()->withErrors([
                'authorization' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('admin.access.index', ['tab' => 'groups'])
            ->with('status', __('admin.flash.job_role_mapping_removed'));
    }

    public function storeJobRole(
        Request $request,
        AuthorizationRoleManagementService $roles,
        AuthorizationProfile $profile,
    ): RedirectResponse {
        Gate::authorize('admin.roles.manage');
        $validated = $this->validateJobRole($request, $profile);

        try {
            $role = $roles->create(
                $request->user(),
                $validated['name'],
                $validated['permissions'] ?? [],
            );
        } catch (DomainException|InvalidArgumentException $exception) {
            return back()->withErrors([
                'authorization' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('admin.access.job-roles.show', $role)
            ->with('status', __('admin.flash.custom_role_created'));
    }

    public function showJobRole(
        string $role,
        AuthorizationProfile $profile,
        ModuleRegistry $modules,
    ): View {
        Gate::authorize('admin.roles.view');
        $managedRole = $this->findEditableJobRole($role);
        $managedRole->load([
            'permissions' => fn ($query) => $query->orderBy('name'),
        ])->loadCount([
            'users',
            'permissions',
        ]);
        $selectedPermissions = $managedRole->permissions->pluck('name')->all();
        $packDefinitions = JobRoleCapabilityPacks::definitions($modules);
        $packStates = [];

        foreach (array_keys($packDefinitions) as $packKey) {
            $packStates[$packKey] = JobRoleCapabilityPacks::state(
                $packKey,
                $selectedPermissions,
                $modules,
            );
        }

        return view('admin.access.job-roles.show', [
            'managedRole' => $managedRole,
            'permissions' => $this->assignablePermissions($profile),
            'packDefinitions' => $packDefinitions,
            'packStates' => $packStates,
        ]);
    }

    public function updateJobRole(
        Request $request,
        string $role,
        AuthorizationRoleManagementService $roles,
        AuthorizationProfile $profile,
    ): RedirectResponse {
        Gate::authorize('admin.roles.manage');
        $managedRole = $this->findEditableJobRole($role);
        $validated = $this->validateJobRole(
            $request,
            $profile,
            $managedRole,
        );

        try {
            $roles->update(
                $request->user(),
                $managedRole,
                $validated['name'],
                $validated['permissions'] ?? [],
            );
        } catch (DomainException|InvalidArgumentException $exception) {
            return back()->withErrors([
                'authorization' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('admin.access.job-roles.show', $role)
            ->with('status', __('admin.flash.custom_role_updated'));
    }

    public function destroyJobRole(
        Request $request,
        string $role,
        AuthorizationRoleManagementService $roles,
    ): RedirectResponse {
        Gate::authorize('admin.roles.manage');
        $managedRole = $this->findEditableJobRole($role);

        try {
            $roles->delete($request->user(), $managedRole);
        } catch (DomainException|InvalidArgumentException $exception) {
            return back()->withErrors([
                'authorization' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('admin.access.index', ['tab' => 'job-roles'])
            ->with('status', __('admin.flash.custom_role_deleted'));
    }

    private function groupsTab(Request $request): View
    {
        Gate::authorize('admin.groups.view');
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => [
                'nullable',
                Rule::in([
                    DirectoryGroup::STATUS_PRESENT,
                    DirectoryGroup::STATUS_MISSING,
                ]),
            ],
        ]);
        $query = DirectoryGroup::query()
            ->with([
                'roles' => fn ($query) => $query->orderBy('name'),
            ])
            ->managedMyApesGroups();

        if (isset($filters['q'])) {
            $query->where(
                'name',
                'like',
                '%'.trim($filters['q']).'%',
            );
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $catalogueSyncedAt = DirectoryGroup::query()
            ->managedMyApesGroups()
            ->max('last_synced_at');

        return view('admin.access.groups', [
            'activeTab' => 'groups',
            'groups' => $query
                ->orderBy('name')
                ->orderBy('id')
                ->paginate(25)
                ->withQueryString(),
            'jobRoles' => Role::query()
                ->where('guard_name', 'web')
                ->where('is_protected', false)
                ->orderBy('name')
                ->get(),
            'filters' => $filters,
            'catalogueSyncedAt' => $catalogueSyncedAt,
        ]);
    }

    private function jobRolesTab(Request $request): View
    {
        Gate::authorize('admin.roles.view');
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $query = Role::query()
            ->where('guard_name', 'web')
            ->where('is_protected', false)
            ->withCount(['permissions', 'users']);

        if (isset($filters['q'])) {
            $query->where(
                'name',
                'like',
                '%'.trim($filters['q']).'%',
            );
        }

        return view('admin.access.job-roles.index', [
            'activeTab' => 'job-roles',
            'roles' => $query
                ->orderBy('name')
                ->paginate(25)
                ->withQueryString(),
            'permissions' => $this->assignablePermissions(
                app(AuthorizationProfile::class),
            ),
            'filters' => $filters,
            'packDefinitions' => JobRoleCapabilityPacks::definitions(
                app(ModuleRegistry::class),
            ),
        ]);
    }

    private function permissionsTab(Request $request): View
    {
        Gate::authorize('admin.permissions.view');
        $profile = app(AuthorizationProfile::class);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'group' => ['nullable', 'string', 'max:64'],
            'layer' => ['nullable', 'string', 'max:32'],
        ]);

        $query = Permission::query()
            ->where('guard_name', 'web')
            ->where('is_code_owned', true)
            ->with([
                'roles' => fn ($builder) => $builder
                    ->where('is_protected', false)
                    ->orderBy('name'),
            ]);

        if (isset($filters['q']) && trim($filters['q']) !== '') {
            $needle = trim($filters['q']);
            $matchedKeys = PermissionDescriptions::matchingKeys($needle);
            $query->where(function ($builder) use ($needle, $matchedKeys): void {
                $builder->where('name', 'like', '%'.$needle.'%');
                if ($matchedKeys !== []) {
                    $builder->orWhereIn('name', $matchedKeys);
                }
            });
        }

        $allMatching = $query->orderBy('name')->get();

        if (isset($filters['layer']) && $filters['layer'] !== '') {
            $layer = $filters['layer'];
            $allMatching = $allMatching->filter(
                fn (Permission $permission): bool => PermissionDescriptions::layer($permission->name) === $layer,
            )->values();
        }

        if (isset($filters['group']) && $filters['group'] !== '') {
            $group = $filters['group'];
            $allMatching = $allMatching->filter(
                fn (Permission $permission): bool => PermissionDescriptions::group($permission->name) === $group,
            )->values();
        }

        $page = max(1, (int) $request->query('page', 1));
        $perPage = 50;
        $permissions = new LengthAwarePaginator(
            $allMatching->forPage($page, $perPage)->values(),
            $allMatching->count(),
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'query' => array_merge(
                    $request->query(),
                    ['tab' => 'permissions'],
                ),
            ],
        );

        return view('admin.access.permissions', [
            'activeTab' => 'permissions',
            'permissions' => $permissions,
            'filters' => $filters,
            'layers' => PermissionDescriptions::layersFor($profile->permissions()),
            'groups' => PermissionDescriptions::groupsFor($profile->permissions()),
        ]);
    }

    private function findEditableJobRole(string $role): Role
    {
        $managedRole = Role::query()
            ->where('guard_name', 'web')
            ->findOrFail($role);

        if ($managedRole->is_protected) {
            abort(404);
        }

        return $managedRole;
    }

    /**
     * @return array{name: string, permissions?: array<int, string>}
     */
    private function validateJobRole(
        Request $request,
        AuthorizationProfile $profile,
        ?Role $role = null,
    ): array {
        $unique = Rule::unique('roles', 'name')
            ->where('guard_name', 'web');

        if ($role !== null) {
            $unique->ignore($role->id);
        }

        return $request->validate([
            'name' => [
                'required',
                'string',
                'min:3',
                'max:64',
                'regex:/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/',
                Rule::notIn($profile->protectedRolesByPrecedence()),
                $unique,
            ],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => [
                'string',
                'distinct',
                Rule::in($this->assignablePermissionNames($profile)),
            ],
        ]);
    }

    /**
     * @return Collection<int, Permission>
     */
    private function assignablePermissions(AuthorizationProfile $profile)
    {
        return Permission::query()
            ->where('guard_name', 'web')
            ->where('is_code_owned', true)
            ->whereIn('name', $this->assignablePermissionNames($profile))
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array<int, string>
     */
    private function assignablePermissionNames(AuthorizationProfile $profile): array
    {
        $enablement = app(PluginEnablement::class);

        return array_values(array_filter(
            $profile->permissions(),
            fn (string $permission): bool => ! $profile->isAdministratorOnlyPermission($permission)
                && $enablement->isOfferedInAccessEditors($permission),
        ));
    }
}
