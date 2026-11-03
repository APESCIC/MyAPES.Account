<?php

namespace Tests\Feature;

use App\Core\Accounts\DirectorySyncRun;
use App\Core\Accounts\Permission;
use App\Core\Accounts\Role;
use App\Core\Accounts\RoleSource;
use App\Core\Accounts\User;
use App\Services\ApplicationAuthorizationGate;
use App\Services\AuthorizationRoleMaterializer;
use App\Services\SessionAuthorizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class AuthorizationGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_qa_context_uses_the_exact_protected_permission_matrix(): void
    {
        $staff = User::factory()->accessLevel(User::ROLE_STAFF)->create();
        $administrator = User::factory()->accessLevel(User::ROLE_ADMIN)->create();

        $this->actingAsWithContext($staff, 'qa');
        $this->assertTrue(
            app(SessionAuthorizationContext::class)
                ->permitsDirectoryRestricted(request(), $staff),
        );
        $this->assertTrue(
            app(ApplicationAuthorizationGate::class)
                ->authorize($staff, 'staff.access'),
        );
        $this->assertTrue(Gate::allows('staff.access'));
        $this->assertFalse(Gate::allows('admin.access'));

        $this->actingAsWithContext($administrator, 'qa');
        $this->assertTrue(Gate::allows('staff.access'));
        $this->assertTrue(Gate::allows('admin.access'));
        $this->assertTrue(Gate::allows('admin.roles.manage'));
        $this->assertFalse(Gate::allows('superadmin.access'));
    }

    public function test_password_context_never_satisfies_directory_restricted_permissions(): void
    {
        $hybrid = User::factory()->accessLevel(User::ROLE_ADMIN)->create([
            'identity_type' => User::IDENTITY_HYBRID,
            'oidc_sub' => 'hybrid-directory-subject',
        ]);

        $this->actingAsWithContext($hybrid, 'password');

        $this->assertFalse(Gate::allows('staff.access'));
        $this->assertFalse(Gate::allows('admin.access'));
    }

    public function test_only_current_oidc_validation_satisfies_restricted_permissions(): void
    {
        $now = CarbonImmutable::parse('2026-07-28 12:00:00', 'UTC');
        $this->travelTo($now);
        $user = User::factory()
            ->accessLevel(User::ROLE_STAFF)
            ->cloudronIdentity('directory-subject')
            ->create();

        $this->actingAsWithContext(
            $user,
            'cloudron_oidc',
            $now->subSeconds(299)->timestamp,
        );
        $this->assertTrue(Gate::allows('staff.access'));

        request()->session()->put([
            'myapes.directory_validated_at' => $now->subSeconds(300)->timestamp,
        ]);
        $this->assertFalse(Gate::allows('staff.access'));

        request()->session()->put([
            'myapes.directory_validated_at' => $now->timestamp,
        ]);
        request()->session()->forget(
            SessionAuthorizationContext::DIRECTORY_GENERATION_KEY,
        );
        $this->assertFalse(Gate::allows('staff.access'));
    }

    public function test_current_directory_administrator_is_granted_every_catalogue_permission(): void
    {
        $superAdmin = User::factory()
            ->accessLevel(User::ROLE_ADMIN)
            ->cloudronIdentity('super-admin-subject')
            ->create();
        $this->actingAsWithContext(
            $superAdmin,
            'cloudron_oidc',
            now()->timestamp,
        );

        foreach ([
            'staff.access',
            'admin.access',
            'admin.users.view',
            'admin.users.manage',
            'admin.groups.view',
            'admin.group-mappings.manage',
            'admin.roles.view',
            'admin.roles.manage',
            'admin.permissions.view',
            'admin.modules.view',
            'admin.modules.manage',
            'admin.analytics.view',
            'admin.maintenance.manage',
        ] as $permission) {
            $this->assertTrue(
                Gate::allows($permission),
                "Expected administrator permission [{$permission}].",
            );
        }
    }

    public function test_custom_roles_cannot_grant_super_admin_only_abilities(): void
    {
        $user = User::factory()->accessLevel(User::ROLE_STAFF)->create();
        $customRole = Role::query()->create([
            'name' => 'delegated-role-manager',
            'guard_name' => 'web',
        ]);
        $permissionIds = Permission::query()
            ->whereIn('name', [
                'admin.group-mappings.manage',
                'admin.roles.manage',
            ])
            ->pluck('id');
        $customRole->permissions()->attach($permissionIds);
        app(AuthorizationRoleMaterializer::class)->grant(
            $user,
            $customRole,
            RoleSource::SOURCE_LOCAL,
            actor: $user,
        );

        $this->actingAsWithContext($user, 'qa');

        $this->assertFalse(Gate::allows('admin.group-mappings.manage'));
        $this->assertFalse(Gate::allows('admin.roles.manage'));
    }

    public function test_custom_permissions_require_a_staff_class_protected_baseline(): void
    {
        $serviceUser = User::factory()
            ->accessLevel(User::ROLE_SERVICE_USER)
            ->cloudronIdentity('service-user-directory-subject')
            ->create();
        $staff = User::factory()->accessLevel(User::ROLE_STAFF)->create();
        $customRole = Role::query()->create([
            'name' => 'delegated-user-viewer',
            'guard_name' => 'web',
        ]);
        $customRole->permissions()->attach(
            Permission::query()
                ->whereIn('name', ['staff.access', 'admin.users.view'])
                ->pluck('id'),
        );
        $materializer = app(AuthorizationRoleMaterializer::class);
        $materializer->grant(
            $serviceUser,
            $customRole,
            RoleSource::SOURCE_LOCAL,
            actor: $staff,
        );
        $materializer->grant(
            $staff,
            $customRole,
            RoleSource::SOURCE_LOCAL,
            actor: $staff,
        );

        $this->actingAsWithContext($serviceUser, 'qa');
        $this->assertFalse(Gate::allows('staff.access'));
        $this->assertFalse(Gate::allows('admin.users.view'));

        $this->actingAsWithContext(
            $serviceUser,
            'cloudron_oidc',
            now()->timestamp,
        );
        $this->assertFalse(Gate::allows('staff.access'));
        $this->assertFalse(Gate::allows('admin.users.view'));

        $this->actingAsWithContext($staff, 'qa');
        $this->assertTrue(Gate::allows('staff.access'));
        $this->assertTrue(Gate::allows('admin.users.view'));
    }

    public function test_suspension_denies_catalogue_and_policy_abilities(): void
    {
        $user = User::factory()->accessLevel(User::ROLE_ADMIN)->create([
            'suspended_at' => now(),
        ]);
        $this->actingAsWithContext($user, 'qa');
        Gate::define('test.non-catalogue-policy', static fn (): bool => true);

        $this->assertFalse(Gate::allows('admin.access'));
        $this->assertFalse(Gate::allows('test.non-catalogue-policy'));
    }

    public function test_missing_or_mismatched_session_context_forces_reauthentication(): void
    {
        $user = User::factory()->accessLevel(User::ROLE_SERVICE_USER)->create([
            'authorization_epoch' => 4,
        ]);

        Auth::login($user);
        $this->get(route('dashboard'))
            ->assertRedirect(route('public.login'));
        $this->assertGuest();

        Auth::login($user);
        $this->withSession([
            'myapes.authentication_method' => 'password',
            'myapes.authorization_epoch' => 3,
        ]);
        $this->getJson(route('dashboard'))->assertUnauthorized();
        $this->assertGuest();
    }

    private function actingAsWithContext(
        User $user,
        string $method,
        ?int $validatedAt = null,
    ): void {
        $user->refresh();
        $context = [
            'myapes.authentication_method' => $method,
            'myapes.authorization_epoch' => $user->authorization_epoch,
        ];

        if ($validatedAt !== null) {
            $context['myapes.directory_validated_at'] = $validatedAt;
            $context[SessionAuthorizationContext::DIRECTORY_GENERATION_KEY]
                = (int) (
                    DirectorySyncRun::query()
                        ->whereIn('status', [
                            DirectorySyncRun::STATUS_SUCCEEDED,
                            DirectorySyncRun::STATUS_FAILED,
                        ])
                        ->max('id') ?? 0
                );
        }

        if (! request()->hasSession()) {
            request()->setLaravelSession(app('session')->driver());
        }

        $this->actingAs($user);
        request()->session()->put($context);
    }
}
