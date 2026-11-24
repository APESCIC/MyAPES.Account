<?php

namespace Tests\Feature\Auth;

use App\Core\Accounts\PendingEmailChange;
use App\Core\Accounts\User;
use App\Notifications\Auth\EmailChangeCompletedNotification;
use App\Notifications\Auth\EmailChangeConfirmNotification;
use App\Notifications\Auth\EmailChangeStartedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class SecureEmailChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_profile_offers_email_change_and_requires_step_up(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'current.user@example.com',
            'password' => 'password',
        ]);

        $this->actingAs($user)
            ->get(route('account.show'))
            ->assertOk()
            ->assertSeeText(__('auth.email_change.heading'))
            ->assertSee('id="change-email"', false)
            ->assertDontSeeText('Email cannot be changed here.');

        $this->actingAs($user)
            ->from(route('account.show'))
            ->post(route('profile.email.change'), [
                'email' => 'new.user@example.com',
            ])
            ->assertRedirect(route('password.confirm'));

        $this->assertSame('current.user@example.com', $user->refresh()->email);
        $this->assertDatabaseMissing('pending_email_changes', ['user_id' => $user->id]);
        Notification::assertNothingSent();
    }

    public function test_happy_path_keeps_old_email_until_new_address_confirmed(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'before.change@example.com',
            'email_verified_at' => now()->subDay(),
            'password' => 'password',
        ]);

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => now()->unix()])
            ->from(route('account.show'))
            ->post(route('profile.email.change'), [
                'email' => 'after.change@example.com',
            ])
            ->assertRedirect(route('account.show', [], false).'#account-email')
            ->assertSessionHas('status', __('flash.email_change_started'));

        $user->refresh();
        $this->assertSame('before.change@example.com', $user->email);
        $this->assertNotNull($user->email_verified_at);

        $pending = PendingEmailChange::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($pending);
        $this->assertSame('after.change@example.com', $pending->new_email);

        Notification::assertSentTo($user, EmailChangeStartedNotification::class);
        Notification::assertSentOnDemand(EmailChangeConfirmNotification::class);

        $url = URL::temporarySignedRoute(
            'email.change.confirm',
            $pending->expires_at,
            ['user' => $user->id, 'hash' => $pending->emailHash()],
        );

        $this->actingAs($user)
            ->get($url)
            ->assertRedirect(route('account.show', [], false).'#account-email')
            ->assertSessionHas('status', __('flash.email_change_completed'));

        $user->refresh();
        $this->assertSame('after.change@example.com', $user->email);
        $this->assertNotNull($user->email_verified_at);
        $this->assertDatabaseMissing('pending_email_changes', ['user_id' => $user->id]);

        Notification::assertSentOnDemand(EmailChangeCompletedNotification::class);
        Notification::assertSentTo($user, EmailChangeCompletedNotification::class);
    }

    public function test_signed_confirm_works_for_guest_and_redirects_to_login(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'guest.confirm@example.com',
            'password' => 'password',
        ]);

        $pending = PendingEmailChange::query()->create([
            'user_id' => $user->id,
            'new_email' => 'guest.after@example.com',
            'expires_at' => now()->addHour(),
        ]);

        $url = URL::temporarySignedRoute(
            'email.change.confirm',
            $pending->expires_at,
            ['user' => $user->id, 'hash' => $pending->emailHash()],
        );

        $this->get($url)
            ->assertRedirect(route('public.login'))
            ->assertSessionHas('status', __('flash.email_change_completed'));

        $this->assertSame('guest.after@example.com', $user->refresh()->email);
    }

    public function test_expired_and_invalid_tokens_are_rejected(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'expire.user@example.com',
            'password' => 'password',
        ]);

        $pending = PendingEmailChange::query()->create([
            'user_id' => $user->id,
            'new_email' => 'never.applied@example.com',
            'expires_at' => now()->subMinute(),
        ]);

        $expiredUrl = URL::temporarySignedRoute(
            'email.change.confirm',
            now()->addMinutes(30),
            ['user' => $user->id, 'hash' => $pending->emailHash()],
        );

        $this->get($expiredUrl)
            ->assertRedirect(route('public.login'))
            ->assertSessionHasErrors('email');

        $this->assertSame('expire.user@example.com', $user->refresh()->email);

        $fresh = PendingEmailChange::query()->create([
            'user_id' => $user->id,
            'new_email' => 'tamper.target@example.com',
            'expires_at' => now()->addHour(),
        ]);

        $validUrl = URL::temporarySignedRoute(
            'email.change.confirm',
            $fresh->expires_at,
            ['user' => $user->id, 'hash' => $fresh->emailHash()],
        );
        $tampered = str_replace('signature=', 'signature=x', $validUrl);

        $this->get($tampered)->assertForbidden();
        $this->assertSame('expire.user@example.com', $user->refresh()->email);

        $wrongHashUrl = URL::temporarySignedRoute(
            'email.change.confirm',
            $fresh->expires_at,
            ['user' => $user->id, 'hash' => sha1('other@example.com')],
        );

        $this->get($wrongHashUrl)
            ->assertRedirect(route('public.login'))
            ->assertSessionHasErrors('email');

        $this->assertSame('expire.user@example.com', $user->refresh()->email);
    }

    public function test_pending_change_is_cancellable_and_only_one_outstanding(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'cancel.user@example.com',
            'password' => 'password',
        ]);

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => now()->unix()])
            ->post(route('profile.email.change'), [
                'email' => 'first.pending@example.com',
            ])
            ->assertRedirect(route('account.show', [], false).'#account-email');

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => now()->unix()])
            ->post(route('profile.email.change'), [
                'email' => 'second.pending@example.com',
            ])
            ->assertRedirect(route('account.show', [], false).'#account-email');

        $this->assertDatabaseCount('pending_email_changes', 1);
        $this->assertDatabaseHas('pending_email_changes', [
            'user_id' => $user->id,
            'new_email' => 'second.pending@example.com',
        ]);

        $this->actingAs($user)
            ->from(route('account.show'))
            ->delete(route('profile.email.cancel'))
            ->assertRedirect(route('account.show', [], false).'#account-email')
            ->assertSessionHas('status', __('flash.email_change_cancelled'));

        $this->assertDatabaseMissing('pending_email_changes', ['user_id' => $user->id]);
        $this->assertSame('cancel.user@example.com', $user->refresh()->email);
    }

    public function test_directory_identity_cannot_start_email_change(): void
    {
        $directory = User::factory()
            ->directoryIdentity('email-change-directory-subject')
            ->create([
                'email' => 'directory.email@example.com',
                'password' => 'password',
            ]);

        $this->actingAs($directory)
            ->get(route('account.show'))
            ->assertOk()
            ->assertSeeText(__('auth.email_change.directory_owned'))
            ->assertDontSee('id="change-email"', false);

        $this->actingAs($directory)
            ->withSession(['auth.password_confirmed_at' => now()->unix()])
            ->post(route('profile.email.change'), [
                'email' => 'hijacked@example.com',
            ])
            ->assertForbidden();

        $this->assertSame('directory.email@example.com', $directory->refresh()->email);
        $this->assertDatabaseMissing('pending_email_changes', ['user_id' => $directory->id]);
    }

    public function test_duplicate_new_email_is_rejected_without_changing_account(): void
    {
        Notification::fake();

        User::factory()->create([
            'email' => 'taken@example.com',
            'password' => 'password',
        ]);

        $user = User::factory()->create([
            'email' => 'seeker@example.com',
            'password' => 'password',
        ]);

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => now()->unix()])
            ->from(route('account.show'))
            ->post(route('profile.email.change'), [
                'email' => 'taken@example.com',
            ])
            ->assertRedirect(route('account.show'))
            ->assertSessionHasErrors('email');

        $this->assertSame('seeker@example.com', $user->refresh()->email);
        $this->assertDatabaseMissing('pending_email_changes', ['user_id' => $user->id]);
        Notification::assertNothingSent();
    }
}
