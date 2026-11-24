<?php

namespace Tests\Feature;

use App\Contracts\OidcIdentityProvider;
use App\Core\Accounts\User;
use App\Core\Auth\OidcIdentity;
use App\Services\LdapGroupResolver;
use App\Services\LdapUserResolver;
use App\Support\AccessCompatibilityDatabaseGuard;
use Database\Seeders\LocalQaSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Fakes\FakeLdapGroupResolver;
use Tests\Fakes\FakeLdapUserResolver;
use Tests\Fakes\FakeOidcIdentityProvider;
use Tests\TestCase;

class RolelessApplicationCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_registration_and_role_middleware_work_without_role_column(): void
    {
        $this->dropRoleColumn();

        $response = $this->post(route('public.register.submit'), [
            'first_name' => 'Public',
            'last_name' => 'User',
            'date_of_birth' => '14/03/1990',
            'username' => 'public.user',
            'email' => 'public@example.com',
            'password' => 'MyAPES-Test-Password-2026!',
            'password_confirmation' => 'MyAPES-Test-Password-2026!',
            'services' => ['apes-cic'],
            'registration_consent' => '1',
        ]);

        $response->assertRedirect(route('verification.notice'));
        $user = User::query()->sole();
        $this->assertSame(User::IDENTITY_LOCAL, $user->identity_type);
        $this->assertSame(User::ROLE_SERVICE_USER, $user->accessLevel());
        $user->forceFill([
            'email_verified_at' => now(),
            'onboarding_completed_at' => now(),
        ])->save();

        $admin = User::factory()->make(['email' => 'admin@example.com']);
        $admin->setAccessLevel(User::ROLE_ADMIN)->save();

        $this->actingAs($admin)->get(route('admin.index'))->assertOk();
        $this->actingAs($user)->get(route('admin.index'))->assertForbidden();
    }

    public function test_oidc_creation_and_directory_access_work_without_role_column(): void
    {
        config([
            'myapes.roles.staff_groups' => ['position.staff'],
            'myapes.roles.admin_groups' => ['intranet.administrator'],
            'myapes.roles.superadmin_groups' => ['intranet.superadmin'],
        ]);

        $identityProvider = new FakeOidcIdentityProvider;
        $identityProvider->identity = new OidcIdentity(
            'roleless-subject',
            'roleless.staff@example.com',
            'Roleless Staff',
        );
        $directory = new FakeLdapGroupResolver;
        $directoryUsers = new FakeLdapUserResolver;
        $directory->groups = ['myapesaccount.staff'];
        $directoryUsers->groups = ['myapesaccount.staff'];
        $this->app->instance(OidcIdentityProvider::class, $identityProvider);
        $this->app->instance(LdapGroupResolver::class, $directory);
        $this->app->instance(LdapUserResolver::class, $directoryUsers);

        $this->dropRoleColumn();

        $this->get(route('staff.auth.callback'))->assertRedirect(route('dashboard'));

        $user = User::query()->sole();
        $this->assertSame(User::IDENTITY_CLOUDRON_OIDC, $user->identity_type);
        $this->assertSame(User::ROLE_STAFF, $user->accessLevel());
        $this->actingAs($user)->get(route('apes-cic.tickets.index'))->assertOk();
    }

    public function test_local_qa_seeding_and_switching_work_without_role_column(): void
    {
        $this->dropRoleColumn();
        $this->seed(LocalQaSeeder::class);

        config(['myapes.qa_login_enabled' => true]);
        app()->detectEnvironment(static fn (): string => 'local');
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);

        $response = $this->post(route('qa.login'), [
            'profile' => 'admin',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
        $this->assertSame(User::ROLE_ADMIN, auth()->user()?->accessLevel());
        $this->assertSame(LocalQaSeeder::ADMIN_EMAIL, auth()->user()?->email);
        $this->actingAs(auth()->user())->get(route('admin.index'))->assertOk();
    }

    private function dropRoleColumn(): void
    {
        if (! Schema::hasColumn('users', 'role')) {
            return;
        }

        app(AccessCompatibilityDatabaseGuard::class)->drop();

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
}
