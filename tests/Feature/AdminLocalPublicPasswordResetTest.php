<?php

namespace Tests\Feature;

use App\Core\Accounts\AuditLog;
use App\Core\Accounts\User;
use App\Notifications\Auth\PasswordChangedNotification;
use App\Services\AuthorizationProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AdminLocalPublicPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_reset_a_local_public_password_and_the_value_is_shown_once(): void
    {
        Notification::fake();

        $administrator = $this->administrator();
        $public = User::factory()->create([
            'email' => 'local.public@example.com',
            'password' => 'password',
        ]);
        $originalToken = $public->remember_token;
        $originalEpoch = $public->authorization_epoch;

        $list = $this->actingAs($administrator)
            ->get(route('admin.users.index', ['account_type' => 'public']));
        $list->assertOk()
            ->assertSee('Reset password')
            ->assertSee(
                route('admin.users.show', $public).'#local-password',
                false,
            );
        $this->assertDoesNotOfferImpersonation($list->getContent());

        $detail = $this->actingAs($administrator)
            ->get(route('admin.users.show', $public));
        $detail->assertOk()
            ->assertSee('Reset local password')
            ->assertSee('name="confirm"', false)
            ->assertDontSee('One-time temporary password');
        $this->assertDoesNotOfferImpersonation($detail->getContent());

        $response = $this->actingAs($administrator)
            ->from(route('admin.users.show', $public))
            ->post(route('admin.users.password-reset', $public), [
                'confirm' => '1',
            ]);

        $response->assertRedirect(route('admin.users.show', $public));
        $response->assertSessionHas('temporary_password');
        $response->assertSessionHas('status');
        $temporaryPassword = $response->getSession()->get('temporary_password');
        $this->assertIsString($temporaryPassword);
        $this->assertNotSame('', $temporaryPassword);
        $this->assertNotSame('password', $temporaryPassword);

        $shown = $this->actingAs($administrator)
            ->get(route('admin.users.show', $public));
        $shown->assertOk()
            ->assertSee('One-time temporary password')
            ->assertSee($temporaryPassword);

        $again = $this->actingAs($administrator)
            ->get(route('admin.users.show', $public));
        $again->assertOk()
            ->assertDontSee('One-time temporary password')
            ->assertDontSee($temporaryPassword);

        $public->refresh();
        $this->assertTrue(Hash::check($temporaryPassword, $public->password));
        $this->assertFalse(Hash::check('password', $public->password));
        $this->assertSame($originalEpoch + 1, $public->authorization_epoch);
        $this->assertNotSame($originalToken, $public->remember_token);

        $audit = AuditLog::query()
            ->where('event', 'auth.local_public_password_reset')
            ->where('user_id', $administrator->id)
            ->sole();
        $this->assertSame($public->id, $audit->auditable_id);
        $this->assertSame($public->id, $audit->context['target_user_id']);
        $this->assertStringNotContainsString(
            $temporaryPassword,
            json_encode($audit->toArray(), JSON_THROW_ON_ERROR),
        );
        Notification::assertSentTo($public, PasswordChangedNotification::class);

        $this->post(route('auth.logout'));

        $this->post(route('public.login.submit'), [
            'login' => $public->email,
            'password' => 'password',
        ])->assertSessionHasErrors();
        $this->assertGuest();

        $this->post(route('public.login.submit'), [
            'login' => $public->email,
            'password' => $temporaryPassword,
        ])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($public);
    }

    public function test_super_admin_can_reset_a_local_public_password(): void
    {
        Notification::fake();

        $superAdmin = User::factory()
            ->accessLevel(User::ROLE_ADMIN)
            ->create()
            ->refresh();
        $public = User::factory()->create([
            'password' => 'password',
        ]);

        $this->actingAs($superAdmin)
            ->post(route('admin.users.password-reset', $public), [
                'confirm' => '1',
            ])
            ->assertRedirect(route('admin.users.show', $public))
            ->assertSessionHas('temporary_password');

        $public->refresh();
        $this->assertFalse(Hash::check('password', $public->password));
    }

    public function test_directory_and_pending_first_login_accounts_are_refused(): void
    {
        $administrator = $this->administrator();
        $directoryPublic = User::factory()
            ->directoryIdentity('directory-public-subject')
            ->create([
                'name' => 'Directory Public',
                'email' => 'directory.public@example.com',
                'password' => 'password',
            ]);
        $pendingFirstLogin = User::factory()->create([
            'name' => 'Pending Directory',
            'email' => 'pending.directory@example.com',
            'identity_type' => User::IDENTITY_CLOUDRON_OIDC,
            'oidc_sub' => null,
            'password' => 'password',
        ]);
        $directoryStaff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->directoryIdentity('directory-staff-subject')
            ->create([
                'name' => 'Directory Staff',
                'email' => 'directory.staff@example.com',
                'password' => 'password',
            ]);
        $hybrid = User::factory()->create([
            'name' => 'Hybrid Identity',
            'email' => 'hybrid.identity@example.com',
            'identity_type' => User::IDENTITY_HYBRID,
            'oidc_sub' => 'hybrid-subject',
            'password' => 'password',
        ]);
        $localStaff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create([
                'name' => 'Local Staff',
                'email' => 'local.staff@example.com',
                'password' => 'password',
            ]);

        $this->actingAs($administrator)
            ->get(route('admin.users.index', ['account_type' => 'public']))
            ->assertOk()
            ->assertDontSeeText('Directory Public')
            ->assertDontSeeText('Pending Directory');

        $staffList = $this->actingAs($administrator)
            ->get(route('admin.users.index', ['account_type' => 'staff']));
        $staffList->assertOk()
            ->assertSeeText('Directory Public')
            ->assertSeeText('Pending Directory')
            ->assertDontSee(
                route('admin.users.show', $directoryPublic).'#local-password',
                false,
            )
            ->assertDontSee(
                route('admin.users.show', $pendingFirstLogin).'#local-password',
                false,
            );
        $this->assertDoesNotOfferImpersonation($staffList->getContent());

        $this->actingAs($administrator)
            ->get(route('admin.users.show', $directoryPublic))
            ->assertOk()
            ->assertSeeText('This account uses Cloudron directory sign-in.')
            ->assertDontSee('Reset local password')
            ->assertDontSee('name="confirm"', false);

        $this->actingAs($administrator)
            ->get(route('admin.users.show', $pendingFirstLogin))
            ->assertOk()
            ->assertSeeText('This directory account has not completed Staff Login yet.')
            ->assertSeeText('Pending first login')
            ->assertSee('Send Staff Login reminder')
            ->assertDontSee('Reset local password');

        $this->actingAs($administrator)
            ->get(route('admin.users.show', $directoryStaff))
            ->assertOk()
            ->assertDontSee('Reset local password')
            ->assertDontSee('name="confirm"', false);

        foreach ([
            $directoryPublic,
            $pendingFirstLogin,
            $directoryStaff,
            $hybrid,
            $localStaff,
        ] as $refused) {
            $this->actingAs($administrator)
                ->from(route('admin.users.show', $refused))
                ->post(route('admin.users.password-reset', $refused), [
                    'confirm' => '1',
                ])
                ->assertForbidden();

            $refused->refresh();
            $this->assertTrue(Hash::check('password', $refused->password));
        }

        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'auth.local_public_password_reset',
        ]);
    }

    public function test_staff_cannot_reset_a_local_public_password(): void
    {
        $staff = User::factory()
            ->accessLevel(User::ROLE_STAFF)
            ->create()
            ->refresh();
        $public = User::factory()->create([
            'password' => 'password',
        ]);

        $this->actingAs($staff)
            ->post(route('admin.users.password-reset', $public), [
                'confirm' => '1',
            ])
            ->assertForbidden();

        $public->refresh();
        $this->assertTrue(Hash::check('password', $public->password));
    }

    public function test_password_reset_requires_confirmation(): void
    {
        $administrator = $this->administrator();
        $public = User::factory()->create([
            'password' => 'password',
        ]);

        $this->actingAs($administrator)
            ->from(route('admin.users.show', $public))
            ->post(route('admin.users.password-reset', $public))
            ->assertRedirect(route('admin.users.show', $public))
            ->assertSessionHasErrors('confirm')
            ->assertSessionMissing('temporary_password');

        $public->refresh();
        $this->assertTrue(Hash::check('password', $public->password));
    }

    private function administrator(): User
    {
        return User::factory()
            ->accessLevel(User::ROLE_ADMIN)
            ->create()
            ->refresh();
    }

    private function assertDoesNotOfferImpersonation(string $html): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/impersonat|log in as|sign in as/i',
            $html,
        );
    }
}
