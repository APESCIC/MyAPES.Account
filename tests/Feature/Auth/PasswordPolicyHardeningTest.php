<?php

namespace Tests\Feature\Auth;

use App\Core\Accounts\User;
use App\Notifications\Auth\PasswordChangedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class PasswordPolicyHardeningTest extends TestCase
{
    use RefreshDatabase;

    private const STRONG_PASSWORD = 'Correct-horse-42-policy!';

    public function test_registration_rejects_passwords_shorter_than_twelve_characters(): void
    {
        $this->from(route('public.register'))
            ->post(route('public.register.submit'), [
                'first_name' => 'Short',
                'last_name' => 'Password',
                'date_of_birth' => '14/03/1990',
                'username' => 'short.password',
                'email' => 'short.password@example.com',
                'password' => 'Short-horse',
                'password_confirmation' => 'Short-horse',
                'services' => ['apes-cic'],
                'registration_consent' => '1',
            ])
            ->assertRedirect(route('public.register'))
            ->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('users', [
            'email' => 'short.password@example.com',
        ]);
    }

    public function test_registration_rejects_compromised_passwords_via_hibp_stub(): void
    {
        $this->stubUncompromisedPasswords(false);

        $this->from(route('public.register'))
            ->post(route('public.register.submit'), [
                'first_name' => 'Breached',
                'last_name' => 'Password',
                'date_of_birth' => '14/03/1990',
                'username' => 'breached.password',
                'email' => 'breached.password@example.com',
                'password' => self::STRONG_PASSWORD,
                'password_confirmation' => self::STRONG_PASSWORD,
                'services' => ['apes-cic'],
                'registration_consent' => '1',
            ])
            ->assertRedirect(route('public.register'))
            ->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('users', [
            'email' => 'breached.password@example.com',
        ]);
    }

    public function test_profile_password_change_rejects_short_and_compromised_passwords(): void
    {
        $public = User::factory()->create([
            'email' => 'policy.change@example.com',
            'password' => 'password',
        ]);

        $this->actingAs($public)
            ->withSession(['auth.password_confirmed_at' => now()->unix()])
            ->from(route('profile.edit'))
            ->put(route('profile.password.update'), [
                'current_password' => 'password',
                'password' => 'Short-horse',
                'password_confirmation' => 'Short-horse',
            ])
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHasErrors('password');

        $this->stubUncompromisedPasswords(false);

        $this->actingAs($public)
            ->withSession(['auth.password_confirmed_at' => now()->unix()])
            ->from(route('profile.edit'))
            ->put(route('profile.password.update'), [
                'current_password' => 'password',
                'password' => self::STRONG_PASSWORD,
                'password_confirmation' => self::STRONG_PASSWORD,
            ])
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHasErrors('password');

        $public->refresh();
        $this->assertTrue(Hash::check('password', $public->password));
    }

    public function test_guest_reset_rejects_short_passwords_and_fires_password_changed_mail(): void
    {
        Notification::fake();

        $public = User::factory()->create([
            'email' => 'policy.reset@example.com',
            'password' => 'password',
        ]);

        $token = Password::broker()->createToken($public);

        $this->from(route('password.reset', ['token' => $token]))
            ->post(route('password.update'), [
                'token' => $token,
                'email' => $public->email,
                'password' => 'Short-horse',
                'password_confirmation' => 'Short-horse',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('password');

        $public->refresh();
        $this->assertTrue(Hash::check('password', $public->password));
        Notification::assertNotSentTo($public, PasswordChangedNotification::class);

        $token = Password::broker()->createToken($public);

        $this->from(route('password.reset', ['token' => $token]))
            ->post(route('password.update'), [
                'token' => $token,
                'email' => $public->email,
                'password' => self::STRONG_PASSWORD,
                'password_confirmation' => self::STRONG_PASSWORD,
            ])
            ->assertRedirect(route('dashboard'));

        $public->refresh();
        $this->assertTrue(Hash::check(self::STRONG_PASSWORD, $public->password));
        Notification::assertSentTo($public, PasswordChangedNotification::class);
    }

    public function test_signed_in_password_change_sends_password_changed_notification(): void
    {
        Notification::fake();

        $public = User::factory()->create([
            'email' => 'policy.notify@example.com',
            'password' => 'password',
        ]);

        $this->actingAs($public)
            ->withSession(['auth.password_confirmed_at' => now()->unix()])
            ->from(route('profile.edit'))
            ->put(route('profile.password.update'), [
                'current_password' => 'password',
                'password' => self::STRONG_PASSWORD,
                'password_confirmation' => self::STRONG_PASSWORD,
            ])
            ->assertRedirect(route('profile.edit'));

        Notification::assertSentTo($public, PasswordChangedNotification::class);
    }

    public function test_public_login_is_throttled_per_account_after_five_failures(): void
    {
        $email = 'policy.login.throttle@example.com';
        RateLimiter::clear('account|'.$email);
        RateLimiter::clear('ip|127.0.0.1');

        User::factory()->create([
            'email' => $email,
            'password' => 'correct-password',
        ]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('public.login.submit'), [
                'login' => $email,
                'password' => 'wrong-password',
            ])->assertRedirect();
        }

        $this->post(route('public.login.submit'), [
            'login' => $email,
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    public function test_password_reset_request_is_throttled_per_account(): void
    {
        $email = 'policy.reset.throttle@example.com';
        RateLimiter::clear('account|'.$email);
        RateLimiter::clear('ip|127.0.0.1');

        User::factory()->create([
            'email' => $email,
            'password' => 'password',
        ]);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->from(route('password.request'))
                ->post(route('password.email'), [
                    'email' => $email,
                ])
                ->assertRedirect(route('password.request'));
        }

        $this->from(route('password.request'))
            ->post(route('password.email'), [
                'email' => $email,
            ])
            ->assertStatus(429);
    }
}
