<?php

namespace Tests\Feature\Auth;

use App\Core\Accounts\User;
use App\Notifications\Auth\VerifyEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class UsernameAndVerificationHardeningTest extends TestCase
{
    use RefreshDatabase;

    private const STRONG_PASSWORD = 'Correct-horse-42-policy!';

    public function test_registration_rejects_reserved_and_invalid_usernames(): void
    {
        $this->from(route('public.register'))
            ->post(route('public.register.submit'), $this->registerPayload([
                'username' => 'admin',
                'email' => 'reserved.user@example.com',
            ]))
            ->assertRedirect(route('public.register'))
            ->assertSessionHasErrors('username');

        $this->from(route('public.register'))
            ->post(route('public.register.submit'), $this->registerPayload([
                'username' => '-leading',
                'email' => 'bad.format@example.com',
            ]))
            ->assertRedirect(route('public.register'))
            ->assertSessionHasErrors('username');

        $this->from(route('public.register'))
            ->post(route('public.register.submit'), $this->registerPayload([
                'username' => 'ab',
                'email' => 'too.short@example.com',
            ]))
            ->assertRedirect(route('public.register'))
            ->assertSessionHasErrors('username');

        $this->assertDatabaseMissing('users', ['email' => 'reserved.user@example.com']);
        $this->assertDatabaseMissing('users', ['email' => 'bad.format@example.com']);
        $this->assertDatabaseMissing('users', ['email' => 'too.short@example.com']);
    }

    public function test_registration_rejects_duplicate_username_case_insensitively(): void
    {
        User::factory()->create([
            'username' => 'taken.user',
            'email' => 'taken.owner@example.com',
        ]);

        $this->from(route('public.register'))
            ->post(route('public.register.submit'), $this->registerPayload([
                'username' => 'Taken.User',
                'email' => 'new.person@example.com',
            ]))
            ->assertRedirect(route('public.register'))
            ->assertSessionHasErrors('username');

        $this->assertDatabaseMissing('users', ['email' => 'new.person@example.com']);
    }

    public function test_login_and_forgot_password_use_generic_anti_enumeration_messages(): void
    {
        User::factory()->create([
            'username' => 'known.user',
            'email' => 'known.user@example.com',
            'password' => 'password',
        ]);

        $unknownLogin = $this->from(route('public.login'))
            ->post(route('public.login.submit'), [
                'login' => 'nobody@example.com',
                'password' => 'wrong-password',
            ]);

        $knownWrongPassword = $this->from(route('public.login'))
            ->post(route('public.login.submit'), [
                'login' => 'known.user@example.com',
                'password' => 'wrong-password',
            ]);

        $unknownLogin
            ->assertRedirect(route('public.login'))
            ->assertSessionHasErrors(['login' => __('auth.failed')]);
        $knownWrongPassword
            ->assertRedirect(route('public.login'))
            ->assertSessionHasErrors(['login' => __('auth.failed')]);

        $unknownForgot = $this->from(route('password.request'))
            ->post(route('password.email'), [
                'email' => 'nobody@example.com',
            ]);

        $knownForgot = $this->from(route('password.request'))
            ->post(route('password.email'), [
                'email' => 'known.user@example.com',
            ]);

        $unknownForgot
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('status', __('passwords.request_status'));
        $knownForgot
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('status', __('passwords.request_status'));
    }

    public function test_local_username_change_requires_step_up_and_rejects_reserved_names(): void
    {
        $user = User::factory()->create([
            'username' => 'changeable.user',
            'email' => 'changeable.user@example.com',
            'password' => 'password',
        ]);

        $this->actingAs($user)
            ->from(route('profile.edit'))
            ->put(route('profile.username.update'), [
                'username' => 'fresh.username',
            ])
            ->assertRedirect(route('password.confirm'));

        $this->assertSame('changeable.user', $user->refresh()->username);

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => now()->unix()])
            ->from(route('profile.edit'))
            ->put(route('profile.username.update'), [
                'username' => 'admin',
            ])
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHasErrors('username');

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => now()->unix()])
            ->from(route('profile.edit'))
            ->put(route('profile.username.update'), [
                'username' => 'Fresh.Username',
            ])
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('status', __('flash.username_updated'));

        $this->assertSame('fresh.username', $user->refresh()->username);
    }

    public function test_directory_identity_cannot_change_username(): void
    {
        $directory = User::factory()
            ->directoryIdentity('username-directory-subject')
            ->create([
                'username' => 'directory.user',
                'email' => 'directory.user@example.com',
                'password' => 'password',
            ]);

        $this->actingAs($directory)
            ->withSession(['auth.password_confirmed_at' => now()->unix()])
            ->put(route('profile.username.update'), [
                'username' => 'new.directory.name',
            ])
            ->assertForbidden();

        $this->assertSame('directory.user', $directory->refresh()->username);
    }

    public function test_registration_blocks_duplicate_email_without_enumeration(): void
    {
        User::factory()->create([
            'username' => 'verified.owner',
            'email' => 'taken@example.com',
            'email_verified_at' => now(),
        ]);

        User::factory()->unverified()->create([
            'username' => 'unverified.owner',
            'email' => 'pending@example.com',
        ]);

        $verifiedConflict = $this->from(route('public.register'))
            ->post(route('public.register.submit'), $this->registerPayload([
                'username' => 'new.verified.conflict',
                'email' => 'taken@example.com',
            ]));

        $unverifiedConflict = $this->from(route('public.register'))
            ->post(route('public.register.submit'), $this->registerPayload([
                'username' => 'new.pending.conflict',
                'email' => 'pending@example.com',
            ]));

        $verifiedConflict
            ->assertRedirect(route('public.register'))
            ->assertSessionHasErrors(['email' => __('auth.register.email_unavailable')]);
        $unverifiedConflict
            ->assertRedirect(route('public.register'))
            ->assertSessionHasErrors(['email' => __('auth.register.email_unavailable')]);

        $this->assertDatabaseMissing('users', ['username' => 'new.verified.conflict']);
        $this->assertDatabaseMissing('users', ['username' => 'new.pending.conflict']);
    }

    public function test_unverified_users_cannot_reach_gated_routes_and_can_resend_verification(): void
    {
        Notification::fake();

        $unverified = User::factory()->unverified()->create([
            'username' => 'unverified.gate',
            'email' => 'unverified.gate@example.com',
            'onboarding_completed_at' => null,
        ]);

        $this->actingAs($unverified)
            ->get(route('dashboard'))
            ->assertRedirect(route('verification.notice'));

        $this->actingAs($unverified)
            ->get(route('profile.edit'))
            ->assertRedirect(route('verification.notice'));

        $this->actingAs($unverified)
            ->get(route('verification.notice'))
            ->assertOk()
            ->assertSeeText(__('auth.verify_email.heading'))
            ->assertSeeText(__('auth.verify_email.intro'))
            ->assertSeeText('unverified.gate@example.com');

        RateLimiter::clear('verification-resend');

        $this->actingAs($unverified)
            ->from(route('verification.notice'))
            ->post(route('verification.send'))
            ->assertRedirect(route('verification.notice'))
            ->assertSessionHas('status', __('auth.verify_email.sent'));

        Notification::assertSentTo($unverified, VerifyEmailNotification::class);
    }

    public function test_register_page_shows_username_and_password_guidance(): void
    {
        $this->get(route('public.register'))
            ->assertOk()
            ->assertSeeText(__('auth.register.username_guidance'))
            ->assertSeeText(__('auth.register.password_guidance'))
            ->assertSeeText(__('auth.register.email_guidance'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function registerPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Public',
            'last_name' => 'Person',
            'date_of_birth' => '14/03/1990',
            'username' => 'public.person',
            'email' => 'public.person@example.com',
            'password' => self::STRONG_PASSWORD,
            'password_confirmation' => self::STRONG_PASSWORD,
            'services' => ['apes-cic'],
            'registration_consent' => '1',
        ], $overrides);
    }
}
