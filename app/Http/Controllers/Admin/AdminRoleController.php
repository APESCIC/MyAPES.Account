<?php

namespace App\Http\Controllers\Admin;

use App\Core\Accounts\Permission;
use App\Core\Accounts\Role;
use App\Core\Extensions\Plugins\PluginEnablement;
use App\Http\Controllers\Controller;
use App\Services\AuthorizationProfile;
use App\Services\AuthorizationRoleManagementService;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class AdminRoleController extends Controller
{
    public function index(Request $request, AuthorizationProfile $profile): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $query = Role::query()
            ->where('guard_name', 'web')
            ->withCount(['permissions', 'users']);

        if (isset($filters['q'])) {
            $query->where(
                'name',
                'like',
                '%'.trim($filters['q']).'%',
            );
        }

        return view('admin.roles.index', [
            'roles' => $query
                ->orderBy('is_protected', 'desc')
                ->orderBy('name')
                ->paginate(25)
                ->withQueryString(),
            'permissions' => $this->assignablePermissions($profile),
            'filters' => $filters,
        ]);
    }

    public function show(string $role, AuthorizationProfile $profile): View
    {
        Gate::authorize('admin.roles.view');
        $managedRole = Role::query()
            ->where('guard_name', 'web')
            ->findOrFail($role);
        $managedRole->load([
            'permissions' => fn ($query) => $query->orderBy('name'),
        ])->loadCount([
            'users',
            'permissions',
        ]);

        return view('admin.roles.show', [
            'managedRole' => $managedRole,
            'permissions' => $this->assignablePermissions($profile),
        ]);
    }

    public function store(
        Request $request,
        AuthorizationRoleManagementService $roles,
        AuthorizationProfile $profile,
    ): RedirectResponse {
        $validated = $this->validateRole($request, $profile);

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
            ->route('admin.roles.show', $role)
            ->with('status', __('admin.flash.custom_role_created'));
    }

    public function update(
        Request $request,
        string $role,
        AuthorizationRoleManagementService $roles,
        AuthorizationProfile $profile,
    ): RedirectResponse {
        Gate::authorize('admin.roles.manage');
        $managedRole = Role::query()
            ->where('guard_name', 'web')
            ->findOrFail($role);
        $validated = $this->validateRole(
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
            ->route('admin.roles.show', $role)
            ->with('status', __('admin.flash.custom_role_updated'));
    }

    public function destroy(
        Request $request,
        string $role,
        AuthorizationRoleManagementService $roles,
    ): RedirectResponse {
        Gate::authorize('admin.roles.manage');
        $managedRole = Role::query()
            ->where('guard_name', 'web')
            ->findOrFail($role);

        try {
            $roles->delete($request->user(), $managedRole);
        } catch (DomainException|InvalidArgumentException $exception) {
            return back()->withErrors([
                'authorization' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('admin.roles.index')
            ->with('status', __('admin.flash.custom_role_deleted'));
    }

    /**
     * @return array{name: string, permissions?: array<int, string>}
     */
    private function validateRole(
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
