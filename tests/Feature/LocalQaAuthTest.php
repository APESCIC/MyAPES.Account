<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use Database\Seeders\LocalQaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class LocalQaAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_login_auto_signs_in_seeded_service_user_in_testing(): void
    {
        $this->seed(LocalQaSeeder::class);

        $response = $this->get('/login');

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
        $this->assertAuthenticatedAs(
            User::query()->where('email', LocalQaSeeder::SERVICE_USER_EMAIL)->firstOrFail()
        );
    }

    public function test_role_switcher_can_switch_to_seeded_staff_user(): void
    {
        $this->seed(LocalQaSeeder::class);

        $response = $this->post(route('qa.switch-role'), [
            'role' => User::ROLE_STAFF,
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
        $this->assertSame(User::ROLE_STAFF, auth()->user()?->accessLevel());
        $this->assertSame(LocalQaSeeder::STAFF_EMAIL, auth()->user()?->email);
    }

    public function test_role_switcher_can_switch_to_seeded_public_user(): void
    {
        $this->seed(LocalQaSeeder::class);

        $response = $this->post(route('qa.switch-role'), [
            'role' => User::ROLE_SERVICE_USER,
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
        $this->assertSame(User::ROLE_SERVICE_USER, auth()->user()?->accessLevel());
        $this->assertSame(LocalQaSeeder::SERVICE_USER_EMAIL, auth()->user()?->email);
    }

    public function test_role_switcher_can_switch_to_seeded_admin_user(): void
    {
        $this->seed(LocalQaSeeder::class);

        $response = $this->post(route('qa.switch-role'), [
            'role' => User::ROLE_ADMIN,
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
        $this->assertSame(User::ROLE_ADMIN, auth()->user()?->accessLevel());
        $this->assertSame(LocalQaSeeder::ADMIN_EMAIL, auth()->user()?->email);
    }

    public function test_role_switcher_can_switch_to_seeded_student_user(): void
    {
        $this->seed(LocalQaSeeder::class);

        $response = $this->post(route('qa.switch-role'), [
            'role' => User::ROLE_STUDENT,
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
        $this->assertSame(User::ROLE_STUDENT, auth()->user()?->accessLevel());
        $this->assertSame(LocalQaSeeder::STUDENT_EMAIL, auth()->user()?->email);
    }

    public function test_role_switcher_can_switch_to_seeded_volunteer_user(): void
    {
        $this->seed(LocalQaSeeder::class);

        $response = $this->post(route('qa.switch-role'), [
            'role' => User::ROLE_VOLUNTEER,
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
        $this->assertSame(User::ROLE_VOLUNTEER, auth()->user()?->accessLevel());
        $this->assertSame(LocalQaSeeder::VOLUNTEER_EMAIL, auth()->user()?->email);
    }

    public function test_public_qa_auto_login_does_not_create_remembered_authentication(): void
    {
        $this->seed(LocalQaSeeder::class);
        $recallerName = Auth::guard()->getRecallerName();

        $response = $this->get('/login');

        $response->assertCookieMissing($recallerName);
        $this->assertNull(
            User::query()
                ->where('email', LocalQaSeeder::SERVICE_USER_EMAIL)
                ->value('remember_token'),
        );
    }

    public function test_qa_role_switch_does_not_create_remembered_authentication(): void
    {
        $this->seed(LocalQaSeeder::class);
        $recallerName = Auth::guard()->getRecallerName();

        $response = $this->post(route('qa.switch-role'), [
            'role' => User::ROLE_STAFF,
        ]);

        $response->assertCookieMissing($recallerName);
        $this->assertNull(
            User::query()
                ->where('email', LocalQaSeeder::STAFF_EMAIL)
                ->value('remember_token'),
        );
    }

    public function test_local_qa_staff_login_ignores_remember_requests(): void
    {
        $this->seed(LocalQaSeeder::class);
        $recallerName = Auth::guard()->getRecallerName();

        $response = $this->post(route('staff.local-login.submit'), [
            'email' => LocalQaSeeder::STAFF_EMAIL,
            'password' => LocalQaSeeder::DEFAULT_PASSWORD,
            'remember' => '1',
        ]);

        $response->assertCookieMissing($recallerName);
        $this->assertNull(
            User::query()
                ->where('email', LocalQaSeeder::STAFF_EMAIL)
                ->value('remember_token'),
        );
    }

    public function test_public_qa_auto_login_rejects_a_suspended_seeded_user_before_authentication(): void
    {
        $this->seed(LocalQaSeeder::class);
        $user = User::query()
            ->where('email', LocalQaSeeder::SERVICE_USER_EMAIL)
            ->firstOrFail();
        $user->forceFill([
            'suspended_at' => now(),
            'suspension_reason' => 'QA account review',
        ])->save();

        $response = $this->get('/login');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'auth.suspended_login_denied',
            'user_id' => $user->id,
        ]);
    }

    public function test_qa_role_switch_rejects_a_suspended_target_before_authentication(): void
    {
        $this->seed(LocalQaSeeder::class);
        $user = User::query()
            ->where('email', LocalQaSeeder::STAFF_EMAIL)
            ->firstOrFail();
        $user->forceFill([
            'suspended_at' => now(),
            'suspension_reason' => 'QA account review',
        ])->save();

        $response = $this->from('/')->post(route('qa.switch-role'), [
            'role' => User::ROLE_STAFF,
        ]);

        $response->assertRedirect('/');
        $response->assertSessionHasErrors('role');
        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'auth.suspended_login_denied',
            'user_id' => $user->id,
        ]);
    }

    public function test_role_switcher_admin_has_full_admin_rights_and_no_super_admin_option(): void
    {
        $this->seed(LocalQaSeeder::class);

        $this->post(route('qa.switch-role'), ['role' => 'superadmin'])
            ->assertSessionHasErrors('role');

        $response = $this->post(route('qa.switch-role'), [
            'role' => User::ROLE_ADMIN,
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
        $this->assertSame(User::ROLE_ADMIN, auth()->user()?->accessLevel());
        $this->assertSame(LocalQaSeeder::ADMIN_EMAIL, auth()->user()?->email);
        $this->assertTrue(auth()->user()?->can('admin.modules.manage'));
        $this->assertTrue(auth()->user()?->can('admin.group-mappings.manage'));
        $this->assertFalse(auth()->user()?->can('superadmin.access'));

        $this->get(route('superadmin.index'))
            ->assertRedirect(route('admin.index'));

        $this->followingRedirects()
            ->get(route('superadmin.index'))
            ->assertOk()
            ->assertSee('Admin overview')
            ->assertSee('data-kpi="enabled-modules"', false);
    }

    public function test_role_switcher_rejects_unsupported_role(): void
    {
        $this->seed(LocalQaSeeder::class);

        $response = $this->from('/')->post(route('qa.switch-role'), [
            'role' => 'not-a-role',
        ]);

        $response->assertRedirect('/');
        $response->assertSessionHasErrors('role');
        $this->assertGuest();
    }

    public function test_role_switcher_is_not_available_in_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        $csrfToken = str_repeat('a', 40);

        $this
            ->withSession(['_token' => $csrfToken])
            ->post(route('qa.switch-role'), [
                '_token' => $csrfToken,
                'role' => User::ROLE_STAFF,
            ])
            ->assertNotFound();

        $this->assertGuest();
    }

    public function test_seeded_public_and_staff_accounts_use_separate_profile_types(): void
    {
        $this->seed(LocalQaSeeder::class);

        $public = User::query()
            ->where('email', LocalQaSeeder::SERVICE_USER_EMAIL)
            ->firstOrFail();
        $staff = User::query()
            ->where('email', LocalQaSeeder::STAFF_EMAIL)
            ->firstOrFail();
        $admin = User::query()
            ->where('email', LocalQaSeeder::ADMIN_EMAIL)
            ->firstOrFail();

        $this->assertNotNull($public->profile);
        $this->assertNull($public->staffProfile);
        $this->assertNull($staff->profile);
        $this->assertSame('Rescue coordinator', $staff->staffProfile?->job_title);
        $this->assertNull($admin->profile);
        $this->assertSame('Operations manager', $admin->staffProfile?->job_title);
    }
}
