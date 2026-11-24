<?php

namespace Tests\Feature;

use App\Core\Accounts\RoleSource;
use App\Core\Accounts\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AuthorizationAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_registration_grants_a_system_baseline_and_password_context(): void
    {
        $response = $this->post(route('public.register.submit'), [
            'first_name' => 'Public',
            'last_name' => 'Registrant',
            'date_of_birth' => '14/03/1990',
            'username' => 'public.registrant',
            'email' => 'registrant@example.com',
            'password' => 'A-secure-public-password-2026!',
            'password_confirmation' => 'A-secure-public-password-2026!',
            'services' => ['apes-cic'],
            'registration_consent' => '1',
        ]);

        $response->assertRedirect(route('verification.notice'));
        $response->assertSessionHas('myapes.authentication_method', 'password');
        $response->assertSessionHas('myapes.authorization_epoch', 1);

        $user = User::query()->sole();
        $this->assertFalse($user->hasVerifiedEmail());
        $this->assertSame(['service-user'], $user->roles()->pluck('name')->all());
        $this->assertDatabaseHas('role_sources', [
            'user_id' => $user->id,
            'source' => RoleSource::SOURCE_SYSTEM,
        ]);
    }

    public function test_suspended_public_login_is_denied_without_a_session(): void
    {
        $user = User::factory()->accessLevel(User::ROLE_SERVICE_USER)->create([
            'email' => 'suspended@example.com',
            'password' => 'suspended-password',
            'suspended_at' => now(),
            'suspension_reason' => 'Account review',
        ]);

        $response = $this->post(route('public.login.submit'), [
            'email' => $user->email,
            'password' => 'suspended-password',
        ]);

        $response->assertRedirect(route('public.login'));
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'auth.suspended_login_denied',
            'user_id' => $user->id,
        ]);
    }

    public function test_staff_eligible_password_login_is_blocked_on_the_public_form(): void
    {
        $user = User::factory()->accessLevel(User::ROLE_ADMIN)->create([
            'email' => 'hybrid@example.com',
            'password' => 'hybrid-password',
            'identity_type' => User::IDENTITY_CLOUDRON_OIDC,
            'oidc_sub' => 'hybrid-subject',
        ]);

        $response = $this->post(route('public.login.submit'), [
            'email' => $user->email,
            'password' => 'hybrid-password',
        ]);

        $response->assertRedirect(route('staff.login'));
        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'auth.public_login_blocked_for_staff',
            'user_id' => $user->id,
        ]);
    }

    public function test_directory_identities_without_staff_eligibility_cannot_use_public_password_login(): void
    {
        $user = User::factory()->accessLevel(User::ROLE_SERVICE_USER)->create([
            'email' => 'directory.nongroup@example.com',
            'password' => 'directory-password',
            'identity_type' => User::IDENTITY_CLOUDRON_OIDC,
            'oidc_sub' => 'directory-without-groups',
        ]);

        $response = $this->post(route('public.login.submit'), [
            'email' => $user->email,
            'password' => 'directory-password',
        ]);

        $response->assertRedirect(route('staff.login'));
        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'auth.public_login_blocked_for_staff',
            'user_id' => $user->id,
        ]);
    }

    public function test_remembered_public_login_restores_a_current_password_context(): void
    {
        $user = User::factory()
            ->accessLevel(User::ROLE_SERVICE_USER)
            ->create([
                'email' => 'remembered@example.com',
                'password' => 'remembered-password',
                'identity_type' => User::IDENTITY_LOCAL,
            ]);

        $login = $this->post(route('public.login.submit'), [
            'email' => $user->email,
            'password' => 'remembered-password',
            'remember' => '1',
        ]);
        $recallerName = Auth::guard()->getRecallerName();
        $recaller = $login->getCookie($recallerName);
        $this->assertNotNull($recaller);

        session()->flush();
        Auth::forgetGuards();

        $response = $this
            ->withCookie($recallerName, $recaller->getValue())
            ->get(route('dashboard'));

        $this->assertSame(200, $response->getStatusCode());
        $response->assertSessionHas(
            'myapes.authentication_method',
            'password',
        );
        $response->assertSessionHas(
            'myapes.authorization_epoch',
            $user->authorization_epoch,
        );
        $this->assertAuthenticatedAs($user);
    }

    public function test_remembered_staff_password_login_is_not_accepted_on_public_routes(): void
    {
        $user = User::factory()
            ->accessLevel(User::ROLE_ADMIN)
            ->create([
                'email' => 'remembered-hybrid@example.com',
                'password' => 'remembered-hybrid-password',
                'identity_type' => User::IDENTITY_CLOUDRON_OIDC,
                'oidc_sub' => 'remembered-hybrid-subject',
            ]);

        $this->post(route('public.login.submit'), [
            'email' => $user->email,
            'password' => 'remembered-hybrid-password',
            'remember' => '1',
        ])->assertRedirect(route('staff.login'));

        $this->assertGuest();
    }
}
