<?php

namespace Tests\Feature\Auth;

use App\Core\Accounts\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StepUpAuthTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_PASSWORD = 'Correct-horse-42-step-up!';

    public function test_sensitive_password_change_rejects_without_recent_confirmation(): void
    {
        $user = User::factory()->create([
            'email' => 'stepup.reject@example.com',
            'password' => 'password',
        ]);

        $this->actingAs($user)
            ->from(route('account.security'))
            ->put(route('profile.password.update'), [
                'current_password' => 'password',
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertRedirect(route('password.confirm'));

        $user->refresh();
        $this->assertTrue(Hash::check('password', $user->password));
        $this->assertFalse(session()->has('auth.password_confirmed_at'));
    }

    public function test_sensitive_password_change_accepts_after_recent_confirmation(): void
    {
        $user = User::factory()->create([
            'email' => 'stepup.accept@example.com',
            'password' => 'password',
        ]);

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => now()->unix()])
            ->from(route('account.security'))
            ->put(route('profile.password.update'), [
                'current_password' => 'password',
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertRedirect(route('account.security').'#password')
            ->assertSessionHas('status', __('flash.password_updated'));

        $user->refresh();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->password));
    }

    public function test_confirm_password_form_and_store_regenerate_session_and_elevate(): void
    {
        $user = User::factory()->create([
            'email' => 'stepup.confirm@example.com',
            'password' => 'password',
        ]);

        $this->actingAs($user)
            ->get(route('password.confirm'))
            ->assertOk()
            ->assertSeeText(__('auth.confirm_password.heading'))
            ->assertSee('name="password"', false)
            ->assertSee('autocomplete="current-password"', false);

        $this->actingAs($user)
            ->from(route('password.confirm'))
            ->withSession(['url.intended' => route('profile.password.update')])
            ->post(route('password.confirm.store'), [
                'password' => 'password',
            ])
            ->assertRedirect(route('account.security').'#password');

        $this->assertAuthenticatedAs($user);
        $this->assertSame('password', session('myapes.authentication_method'));
        $this->assertNotNull(session('auth.password_confirmed_at'));
        $this->assertLessThanOrEqual(5, now()->unix() - (int) session('auth.password_confirmed_at'));
    }

    public function test_confirm_password_rejects_wrong_password(): void
    {
        $user = User::factory()->create([
            'email' => 'stepup.wrong@example.com',
            'password' => 'password',
        ]);

        $this->actingAs($user)
            ->from(route('password.confirm'))
            ->post(route('password.confirm.store'), [
                'password' => 'not-the-password',
            ])
            ->assertRedirect(route('password.confirm'))
            ->assertSessionHasErrors('password');

        $this->assertNull(session('auth.password_confirmed_at'));
    }

    public function test_expired_confirmation_reprompts_before_password_change(): void
    {
        $user = User::factory()->create([
            'email' => 'stepup.expired@example.com',
            'password' => 'password',
        ]);

        $timeout = (int) config('auth.password_timeout');

        $this->actingAs($user)
            ->withSession([
                'auth.password_confirmed_at' => now()->unix() - $timeout - 1,
            ])
            ->from(route('account.security'))
            ->put(route('profile.password.update'), [
                'current_password' => 'password',
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertRedirect(route('password.confirm'));

        $user->refresh();
        $this->assertTrue(Hash::check('password', $user->password));
    }

    public function test_confirm_password_redirects_directory_identity_to_oidc_step_up(): void
    {
        $directory = User::factory()
            ->directoryIdentity('stepup-directory-subject')
            ->create([
                'email' => 'stepup.directory@example.com',
                'password' => 'password',
            ]);

        $this->actingAs($directory)
            ->get(route('password.confirm'))
            ->assertRedirect(route('staff.auth.step-up'));

        $this->assertSame(
            [
                'user_id' => $directory->id,
                'oidc_sub' => 'stepup-directory-subject',
            ],
            session('myapes.oidc_step_up'),
        );

        $this->actingAs($directory)
            ->post(route('password.confirm.store'), [
                'password' => 'password',
            ])
            ->assertRedirect(route('staff.auth.step-up'));
    }
}
