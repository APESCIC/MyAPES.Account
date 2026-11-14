<?php

namespace Tests\Feature\Auth;

use App\Contracts\OidcIdentityProvider;
use App\Core\Accounts\User;
use App\Core\Auth\OidcIdentity;
use App\Core\Mail\Inbound\Diagnostics\MailboxTransport;
use App\Http\Cookies\OidcReauthenticationCookie;
use App\Http\Middleware\EnsureRecentStepUp;
use App\Services\AuthorizationProfile;
use App\Services\LdapGroupResolver;
use App\Services\LdapUserResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeImapServer;
use Tests\Fakes\FakeLdapGroupResolver;
use Tests\Fakes\FakeLdapUserResolver;
use Tests\Fakes\FakeOidcIdentityProvider;
use Tests\TestCase;

/**
 * Directory (Cloudron OIDC) step-up for account.step-up (#823).
 */
class OidcStepUpAuthTest extends TestCase
{
    use RefreshDatabase;

    private FakeOidcIdentityProvider $identityProvider;

    private FakeLdapGroupResolver $directory;

    private FakeLdapUserResolver $directoryUsers;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'myapes.roles.staff_groups' => ['position.staff'],
            'myapes.roles.admin_groups' => ['intranet.administrator'],
            'myapes.roles.superadmin_groups' => ['intranet.superadmin'],
        ]);

        $this->identityProvider = new FakeOidcIdentityProvider;
        $this->directory = new FakeLdapGroupResolver;
        $this->directoryUsers = new FakeLdapUserResolver;

        $this->app->instance(OidcIdentityProvider::class, $this->identityProvider);
        $this->app->instance(LdapGroupResolver::class, $this->directory);
        $this->app->instance(LdapUserResolver::class, $this->directoryUsers);
    }

    public function test_directory_user_without_confirmation_is_sent_to_oidc_step_up(): void
    {
        $admin = $this->directoryAdministrator('oidc-stepup-redirect');

        $response = $this->actingAs($admin)
            ->put(route('admin.settings.keys-and-secrets.store'), [
                'password' => 'mail-client-app-password-never-echoed',
            ]);

        $response->assertRedirect(route('staff.auth.step-up'));
        $this->assertSame(
            [
                'user_id' => $admin->id,
                'oidc_sub' => 'oidc-stepup-redirect',
            ],
            session(EnsureRecentStepUp::OIDC_STEP_UP_SESSION_KEY),
        );
        $this->assertTrue(session()->has('url.intended'));
    }

    public function test_step_up_route_forces_prompt_login_and_sets_reauthentication_cookie(): void
    {
        $admin = $this->directoryAdministrator('oidc-stepup-force');

        $response = $this->actingAs($admin)
            ->withSession([
                EnsureRecentStepUp::OIDC_STEP_UP_SESSION_KEY => [
                    'user_id' => $admin->id,
                    'oidc_sub' => 'oidc-stepup-force',
                ],
                'url.intended' => route('admin.settings.keys-and-secrets.store'),
            ])
            ->get(route('staff.auth.step-up'));

        $response->assertRedirect($this->identityProvider->authorizationUrl);
        $response->assertCookie(OidcReauthenticationCookie::NAME, '1');
        $this->assertTrue($this->identityProvider->forceReauthentication);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'auth.oidc_reauthentication_required',
            'user_id' => $admin->id,
        ]);
    }

    public function test_successful_step_up_callback_stamps_confirmation_and_continues(): void
    {
        $admin = $this->directoryAdministrator('oidc-stepup-callback');
        $this->directory->groups = ['myapesaccount.admin'];
        $this->directoryUsers->groups = ['myapesaccount.admin'];
        $this->identityProvider->identity = new OidcIdentity(
            'oidc-stepup-callback',
            $admin->email,
            $admin->name,
        );

        $intended = route('admin.settings.show', 'keys-and-secrets');

        $response = $this->actingAs($admin)
            ->withSession([
                EnsureRecentStepUp::OIDC_STEP_UP_SESSION_KEY => [
                    'user_id' => $admin->id,
                    'oidc_sub' => 'oidc-stepup-callback',
                ],
                'url.intended' => $intended,
            ])
            ->withCookie(OidcReauthenticationCookie::NAME, '1')
            ->get(route('staff.auth.callback'));

        $response->assertRedirect($intended);
        $response->assertCookieExpired(OidcReauthenticationCookie::NAME);
        $this->assertAuthenticatedAs($admin);
        $this->assertNotNull(session('auth.password_confirmed_at'));
        $this->assertLessThanOrEqual(5, now()->unix() - (int) session('auth.password_confirmed_at'));
        $this->assertNull(session(EnsureRecentStepUp::OIDC_STEP_UP_SESSION_KEY));
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'auth.oidc_step_up_success',
            'user_id' => $admin->id,
        ]);
    }

    public function test_step_up_callback_rejects_a_different_cloudron_subject(): void
    {
        $admin = $this->directoryAdministrator('oidc-stepup-expected');
        $other = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->directoryIdentity('oidc-stepup-other')
            ->create([
                'email' => 'other.directory@example.com',
                'ldap_groups' => ['myapesaccount.admin'],
            ]);

        $this->directory->groups = ['myapesaccount.admin'];
        $this->directoryUsers->groups = ['myapesaccount.admin'];
        $this->identityProvider->identity = new OidcIdentity(
            'oidc-stepup-other',
            $other->email,
            $other->name,
        );

        $this->actingAs($admin)
            ->withSession([
                EnsureRecentStepUp::OIDC_STEP_UP_SESSION_KEY => [
                    'user_id' => $admin->id,
                    'oidc_sub' => 'oidc-stepup-expected',
                ],
            ])
            ->get(route('staff.auth.callback'))
            ->assertForbidden();

        $this->assertNull(session('auth.password_confirmed_at'));
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'auth.oidc_step_up_denied',
        ]);
    }

    public function test_directory_admin_can_store_key_after_oidc_step_up_stamp(): void
    {
        config([
            'app-settings.env' => array_fill_keys(array_keys((array) config('app-settings.env')), null),
            'inbound-mail.enabled' => null,
            'inbound-mail.token_key' => 'inbound-test-key',
            'inbound-mail.address' => 'communications@myapes.me.uk',
            'inbound-mail.imap.host' => 'mail.cloudron.apes.org.uk',
            'inbound-mail.imap.port' => 993,
            'inbound-mail.imap.encryption' => 'ssl',
            'inbound-mail.imap.password' => null,
        ]);
        $this->app->instance(MailboxTransport::class, new FakeImapServer);

        $admin = $this->directoryAdministrator('oidc-stepup-keys');

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => now()->unix()])
            ->put(route('admin.settings.keys-and-secrets.store'), [
                'password' => 'mail-client-app-password-never-echoed',
            ])
            ->assertRedirect(route('admin.settings.show', 'keys-and-secrets'))
            ->assertSessionHasNoErrors();
    }

    public function test_local_password_confirm_path_is_unchanged(): void
    {
        $local = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create([
                'email' => 'local.stepup@example.com',
                'password' => 'password',
            ]);

        $this->actingAs($local)
            ->put(route('admin.settings.keys-and-secrets.store'), [
                'password' => 'mail-client-app-password-never-echoed',
            ])
            ->assertRedirect(route('password.confirm'));

        $this->actingAs($local)
            ->get(route('password.confirm'))
            ->assertOk()
            ->assertSeeText(__('auth.confirm_password.heading'));
    }

    private function directoryAdministrator(string $subject): User
    {
        return User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->directoryIdentity($subject)
            ->create([
                'email' => $subject.'@example.com',
                'ldap_groups' => ['myapesaccount.admin'],
            ]);
    }
}
