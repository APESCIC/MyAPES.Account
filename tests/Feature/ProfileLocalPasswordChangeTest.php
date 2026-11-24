<?php

namespace Tests\Feature;

use App\Core\Accounts\AuditLog;
use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileLocalPasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_PASSWORD = 'Correct-horse-42-change!';

    public function test_local_public_profile_shows_change_password_with_autocomplete_hints(): void
    {
        $public = User::factory()->create([
            'email' => 'local.public.profile@example.com',
            'password' => 'password',
        ]);

        $profile = $this->actingAs($public)->get(route('account.security'));
        $profileHtml = $profile->getContent();
        $profile->assertOk()
            ->assertSeeText('Change password')
            ->assertSee('name="current_password"', false)
            ->assertSee('name="password"', false)
            ->assertSee('name="password_confirmation"', false)
            ->assertSee('autocomplete="current-password"', false)
            ->assertSee('autocomplete="new-password"', false);
        $this->assertDoesNotMatchRegularExpression(
            '/<(input|select|textarea)[^>]*(name="current_password"|id="current_password"|name="password"|id="password"|name="password_confirmation"|id="password_confirmation")[^>]*autocomplete="off"/i',
            $profileHtml,
        );
        $this->assertDoesNotOfferImpersonation($profileHtml);

        $dashboard = $this->actingAs($public)->get(route('dashboard'));
        $dashboard->assertOk()
            ->assertDontSee('name="current_password"', false)
            ->assertDontSee('Change password');
        $this->assertDoesNotOfferImpersonation($dashboard->getContent());
    }

    public function test_signed_in_local_public_user_can_change_password_with_current_new_and_confirm(): void
    {
        $public = User::factory()->create([
            'email' => 'local.public.change@example.com',
            'password' => 'password',
        ]);
        $originalToken = $public->remember_token;
        $originalEpoch = $public->authorization_epoch;

        $this->actingAs($public)
            ->withSession(['auth.password_confirmed_at' => now()->unix()])
            ->from(route('account.security'))
            ->put(route('profile.password.update'), [
                'current_password' => 'password',
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertRedirect(route('account.security').'#password')
            ->assertSessionHas('status', __('flash.password_updated'));

        $this->assertAuthenticatedAs($public);
        $this->assertSame('password', session('myapes.authentication_method'));

        $public->refresh();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $public->password));
        $this->assertFalse(Hash::check('password', $public->password));
        $this->assertSame($originalEpoch + 1, $public->authorization_epoch);
        $this->assertNotSame($originalToken, $public->remember_token);

        $audit = AuditLog::query()
            ->where('event', 'auth.public_local_password_changed')
            ->where('auditable_id', $public->id)
            ->sole();
        $this->assertSame($public->id, $audit->user_id);
        $this->assertSame($public->id, $audit->context['target_user_id']);
        $this->assertArrayNotHasKey('password', $audit->context);
        $this->assertArrayNotHasKey('current_password', $audit->context);
        $this->assertStringNotContainsString(
            self::NEW_PASSWORD,
            json_encode($audit->toArray(), JSON_THROW_ON_ERROR),
        );

        $this->post(route('auth.logout'));
        $this->assertGuest();

        $this->post(route('public.login.submit'), [
            'login' => $public->email,
            'password' => 'password',
        ])->assertSessionHasErrors();
        $this->assertGuest();

        $this->post(route('public.login.submit'), [
            'login' => $public->email,
            'password' => self::NEW_PASSWORD,
        ])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($public);
    }

    public function test_change_password_requires_the_current_password_and_matching_confirmation(): void
    {
        $public = User::factory()->create([
            'password' => 'password',
        ]);
        $originalEpoch = $public->authorization_epoch;

        $this->actingAs($public)
            ->withSession(['auth.password_confirmed_at' => now()->unix()])
            ->from(route('account.security'))
            ->put(route('profile.password.update'), [
                'current_password' => 'wrong-password',
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertRedirect(route('account.security'))
            ->assertSessionHasErrors('current_password');

        $public->refresh();
        $this->assertTrue(Hash::check('password', $public->password));
        $this->assertSame($originalEpoch, $public->authorization_epoch);

        $this->actingAs($public)
            ->withSession(['auth.password_confirmed_at' => now()->unix()])
            ->from(route('account.security'))
            ->put(route('profile.password.update'), [
                'current_password' => 'password',
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => 'Different-horse-42-change!',
            ])
            ->assertRedirect(route('account.security'))
            ->assertSessionHasErrors('password');

        $public->refresh();
        $this->assertTrue(Hash::check('password', $public->password));
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'auth.public_local_password_changed',
        ]);
    }

    public function test_directory_cloudron_and_staff_accounts_do_not_see_or_use_profile_password_fields(): void
    {
        $directoryPublic = User::factory()
            ->directoryIdentity('directory-public-subject')
            ->create([
                'email' => 'directory.public.profile@example.com',
                'password' => 'password',
            ]);
        $pendingFirstLogin = User::factory()->create([
            'email' => 'pending.directory.profile@example.com',
            'identity_type' => User::IDENTITY_CLOUDRON_OIDC,
            'oidc_sub' => null,
            'password' => 'password',
        ]);
        $directoryStaff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->directoryIdentity('directory-staff-subject')
            ->create([
                'email' => 'directory.staff.profile@example.com',
                'password' => 'password',
            ]);
        $hybrid = User::factory()->create([
            'email' => 'hybrid.identity.profile@example.com',
            'identity_type' => User::IDENTITY_HYBRID,
            'oidc_sub' => 'hybrid-subject',
            'password' => 'password',
        ]);
        $localStaff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create([
                'email' => 'local.staff.profile@example.com',
                'password' => 'password',
            ]);

        foreach ([$directoryPublic, $hybrid] as $directoryOnPublicProfile) {
            $page = $this->actingAs($directoryOnPublicProfile)
                ->get(route('account.security'));
            $page->assertOk()
                ->assertDontSee('name="current_password"', false)
                ->assertDontSee('name="password_confirmation"', false)
                ->assertDontSee('Change password')
                ->assertSeeText('This account uses organisation directory sign-in.');
            $this->assertDoesNotOfferImpersonation($page->getContent());
        }

        foreach ([$directoryStaff, $localStaff] as $staff) {
            $page = $this->actingAs($staff)->get(route('account.security'));
            $page->assertOk()
                ->assertDontSee('name="current_password"', false)
                ->assertDontSee('name="password_confirmation"', false)
                ->assertDontSee('Change password');
            $this->assertDoesNotOfferImpersonation($page->getContent());
        }

        $pendingPage = $this->actingAs($pendingFirstLogin)
            ->get(route('account.security'));
        $pendingPage->assertOk()
            ->assertDontSee('name="current_password"', false)
            ->assertDontSee('Change password');

        foreach ([
            $directoryPublic,
            $pendingFirstLogin,
            $directoryStaff,
            $hybrid,
            $localStaff,
        ] as $refused) {
            $this->actingAs($refused)
                ->withSession(['auth.password_confirmed_at' => now()->unix()])
                ->from(route('account.security'))
                ->put(route('profile.password.update'), [
                    'current_password' => 'password',
                    'password' => self::NEW_PASSWORD,
                    'password_confirmation' => self::NEW_PASSWORD,
                ])
                ->assertForbidden();

            $refused->refresh();
            $this->assertTrue(Hash::check('password', $refused->password));
        }

        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'auth.public_local_password_changed',
        ]);
    }

    private function assertDoesNotOfferImpersonation(string $html): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/impersonat|log in as|sign in as/i',
            $html,
        );
    }
}
