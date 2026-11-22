<?php

namespace Tests\Feature;

use App\Core\Accounts\AuditLog;
use App\Core\Accounts\User;
use App\Notifications\Auth\ResetPasswordNotification;
use App\Notifications\PendingFirstLoginChaseNotification;
use App\Services\AuthorizationProfile;
use App\Services\LocalPublicPasswordResetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AdminPendingFirstLoginChaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_chase_pending_first_login_directory_user_via_staff_login(): void
    {
        Notification::fake();

        $administrator = $this->administrator();
        $pending = User::factory()->create([
            'name' => 'Pending Directory',
            'email' => 'pending.chase@example.com',
            'identity_type' => User::IDENTITY_CLOUDRON_OIDC,
            'oidc_sub' => null,
            'password' => 'password',
        ]);

        $detail = $this->actingAs($administrator)
            ->get(route('admin.staff-accounts.show', ['user' => $pending, 'tab' => 'details']));
        $detail->assertOk()
            ->assertSee('Pending first login')
            ->assertSee('Send Staff Login reminder')
            ->assertSee('name="confirm_chase"', false)
            ->assertSeeText('Staff Login')
            ->assertDontSee('Reset local password')
            ->assertDontSee(route('password.request'), false)
            ->assertDontSeeText('Forgot password');

        $this->actingAs($administrator)
            ->from(route('admin.staff-accounts.show', $pending))
            ->post(route('admin.users.pending-first-login-chase', $pending), [
                'confirm_chase' => '1',
            ])
            ->assertRedirect(route('admin.staff-accounts.show', $pending))
            ->assertSessionHas('status');

        Notification::assertSentTo(
            $pending,
            PendingFirstLoginChaseNotification::class,
            function (PendingFirstLoginChaseNotification $notification) use ($pending): bool {
                $mail = $notification->toMail($pending);
                $rendered = implode("\n", [
                    $mail->subject,
                    ...$mail->introLines,
                    $mail->actionUrl ?? '',
                    ...$mail->outroLines,
                ]);

                $this->assertStringContainsString(route('staff.login'), $rendered);
                $this->assertStringNotContainsString(route('password.request'), $rendered);
                $this->assertStringNotContainsString('/forgot-password', $rendered);
                $this->assertStringContainsString('Cloudron', $rendered);

                return true;
            },
        );
        Notification::assertNotSentTo($pending, ResetPasswordNotification::class);

        $audit = AuditLog::query()
            ->where('event', 'auth.pending_first_login_chase')
            ->where('user_id', $administrator->id)
            ->sole();
        $this->assertSame($pending->id, $audit->auditable_id);
        $this->assertSame($pending->id, $audit->context['target_user_id']);
        $this->assertSame('staff_login', $audit->context['login_path']);
    }

    public function test_public_local_accounts_are_refused_chase_and_keep_password_reset_path(): void
    {
        Notification::fake();

        $administrator = $this->administrator();
        $public = User::factory()->create([
            'email' => 'local.public.chase@example.com',
            'password' => 'password',
        ]);

        $this->actingAs($administrator)
            ->get(route('admin.public-accounts.show', ['user' => $public, 'tab' => 'details']))
            ->assertOk()
            ->assertSee('Reset local password')
            ->assertDontSee('Send Staff Login reminder')
            ->assertDontSee('name="confirm_chase"', false);

        $this->actingAs($administrator)
            ->from(route('admin.public-accounts.show', $public))
            ->post(route('admin.users.pending-first-login-chase', $public), [
                'confirm_chase' => '1',
            ])
            ->assertForbidden();

        Notification::assertNothingSent();
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'auth.pending_first_login_chase',
        ]);

        $this->assertTrue(
            app(LocalPublicPasswordResetService::class)->canReset($administrator, $public),
        );
    }

    public function test_directory_accounts_that_already_logged_in_are_refused(): void
    {
        Notification::fake();

        $administrator = $this->administrator();
        $directory = User::factory()
            ->directoryIdentity('directory-already-linked')
            ->create([
                'email' => 'directory.linked@example.com',
                'password' => 'password',
            ]);

        $this->actingAs($administrator)
            ->get(route('admin.staff-accounts.show', ['user' => $directory, 'tab' => 'details']))
            ->assertOk()
            ->assertDontSee('Send Staff Login reminder');

        $this->actingAs($administrator)
            ->post(route('admin.users.pending-first-login-chase', $directory), [
                'confirm_chase' => '1',
            ])
            ->assertForbidden();

        Notification::assertNothingSent();
    }

    public function test_chase_requires_confirmation(): void
    {
        Notification::fake();

        $administrator = $this->administrator();
        $pending = User::factory()->create([
            'identity_type' => User::IDENTITY_CLOUDRON_OIDC,
            'oidc_sub' => null,
            'password' => 'password',
        ]);

        $this->actingAs($administrator)
            ->from(route('admin.staff-accounts.show', $pending))
            ->post(route('admin.users.pending-first-login-chase', $pending))
            ->assertRedirect(route('admin.staff-accounts.show', $pending))
            ->assertSessionHasErrors('confirm_chase');

        Notification::assertNothingSent();
    }

    public function test_staff_cannot_chase_pending_first_login(): void
    {
        Notification::fake();

        $staff = User::factory()
            ->accessLevel(User::ROLE_STAFF)
            ->create()
            ->refresh();
        $pending = User::factory()->create([
            'identity_type' => User::IDENTITY_CLOUDRON_OIDC,
            'oidc_sub' => null,
            'password' => 'password',
        ]);

        $this->actingAs($staff)
            ->post(route('admin.users.pending-first-login-chase', $pending), [
                'confirm_chase' => '1',
            ])
            ->assertForbidden();

        Notification::assertNothingSent();
    }

    public function test_pending_first_login_still_refuses_local_public_password_reset(): void
    {
        $administrator = $this->administrator();
        $pending = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create([
                'identity_type' => User::IDENTITY_CLOUDRON_OIDC,
                'oidc_sub' => null,
                'password' => 'password',
            ]);

        $this->actingAs($administrator)
            ->post(route('admin.users.password-reset', $pending), [
                'confirm' => '1',
            ])
            ->assertForbidden();

        $this->assertFalse(
            app(LocalPublicPasswordResetService::class)->canReset($administrator, $pending),
        );
    }

    private function administrator(): User
    {
        return User::factory()
            ->accessLevel(User::ROLE_ADMIN)
            ->create()
            ->refresh();
    }
}
