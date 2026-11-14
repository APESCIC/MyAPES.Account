<?php

namespace Tests\Feature\Admin;

use App\Core\Accounts\AuditLog;
use App\Core\Accounts\User;
use App\Core\Mail\Inbound\Diagnostics\MailboxTransport;
use App\Core\Secrets\SecretRegistry;
use App\Core\Settings\AppSetting;
use App\Services\AuthorizationProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\Fakes\FakeImapServer;
use Tests\TestCase;

/**
 * Admin → Settings → Keys and secrets (#810).
 */
class KeysAndSecretsTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'mail-client-app-password-never-echoed';

    private const TYPED = 'typed-once-only-for-test';

    private User $admin;

    private FakeImapServer $server;

    protected function setUp(): void
    {
        parent::setUp();
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
        $this->admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();
        $this->server = new FakeImapServer;
        $this->app->instance(MailboxTransport::class, $this->server);
    }

    public function test_non_admins_get_403_and_admins_need_step_up_before_changes(): void
    {
        $user = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create();
        $this->actingAs($user)->get(route('admin.settings.show', 'keys-and-secrets'))->assertForbidden();

        $this->actingAs($this->admin)
            ->put(route('admin.settings.keys-and-secrets.store'), ['password' => self::PASSWORD])
            ->assertRedirect(route('password.confirm'));

        $this->actingAs($this->admin)
            ->withSession(['auth.password_confirmed_at' => now()->unix()])
            ->put(route('admin.settings.keys-and-secrets.store'), ['password' => self::PASSWORD])
            ->assertRedirect(route('admin.settings.show', 'keys-and-secrets'))
            ->assertSessionHasNoErrors();
    }

    public function test_saved_value_is_encrypted_and_never_appears_in_html_json_logs_or_audit(): void
    {
        $this->actingAs($this->admin)
            ->withSession(['auth.password_confirmed_at' => now()->unix()])
            ->put(route('admin.settings.keys-and-secrets.store'), ['password' => self::PASSWORD])
            ->assertSessionHasNoErrors();

        $row = AppSetting::query()->where('group', SecretRegistry::GROUP)->where('key', SecretRegistry::INBOUND_MAIL_IMAP_PASSWORD)->sole();
        $this->assertTrue($row->is_secret);
        $this->assertNotSame(self::PASSWORD, $row->value);
        $this->assertSame(self::PASSWORD, Crypt::decryptString($row->value));

        $html = $this->actingAs($this->admin)->get(route('admin.settings.show', 'keys-and-secrets'))->assertOk()->getContent();
        $this->assertStringNotContainsString(self::PASSWORD, $html);
        $this->assertStringNotContainsString($row->value, $html);

        $audit = AuditLog::query()->where('event', 'admin.settings.secret_set')->sole();
        $this->assertSame(['key' => SecretRegistry::INBOUND_MAIL_IMAP_PASSWORD], $audit->context);
        $this->assertStringNotContainsString(self::PASSWORD, json_encode($audit->context));
    }

    public function test_status_shows_date_user_env_fallback_and_not_set(): void
    {
        $this->actingAs($this->admin)->get(route('admin.settings.show', 'keys-and-secrets'))
            ->assertOk()
            ->assertSee(__('admin.settings.keys_and_secrets.status.not_set'), false);

        $this->actingAs($this->admin)
            ->withSession(['auth.password_confirmed_at' => now()->unix()])
            ->put(route('admin.settings.keys-and-secrets.store'), ['password' => self::PASSWORD]);

        $this->actingAs($this->admin)->get(route('admin.settings.show', 'keys-and-secrets'))
            ->assertOk()
            ->assertSee(__('admin.settings.keys_and_secrets.status.set', [
                'date' => now()->timezone(config('app.timezone'))->format('j M Y H:i'),
                'user' => $this->admin->name,
            ]), false);

        $this->actingAs($this->admin)
            ->withSession(['auth.password_confirmed_at' => now()->unix()])
            ->delete(route('admin.settings.keys-and-secrets.destroy'), ['confirm' => '1']);

        config(['app-settings.env.INBOUND_MAIL_IMAP_PASSWORD' => 'env-only-password']);
        $this->actingAs($this->admin)->get(route('admin.settings.show', 'keys-and-secrets'))
            ->assertOk()
            ->assertSee(__('admin.settings.keys_and_secrets.status.env'), false);
    }

    public function test_typed_test_never_stores_and_reports_known_error_types(): void
    {
        $this->server->loginResponse = 'NO [AUTHENTICATIONFAILED] Authentication failed.';

        $this->actingAs($this->admin)
            ->withSession(['auth.password_confirmed_at' => now()->unix()])
            ->followingRedirects()
            ->post(route('admin.settings.keys-and-secrets.test'), ['password' => self::TYPED])
            ->assertOk()
            ->assertSee(__('admin.emails.import.errors.login_failed.message'), false)
            ->assertDontSee(self::TYPED);

        $this->assertFalse(app(SecretRegistry::class)->source(SecretRegistry::INBOUND_MAIL_IMAP_PASSWORD) === SecretRegistry::SOURCE_KEYS);
        $this->assertSame(0, AppSetting::query()->where('group', SecretRegistry::GROUP)->count());
        $this->assertSame(1, AuditLog::query()->where('event', 'admin.settings.secret_tested')->count());
    }

    public function test_each_action_writes_one_audit_row_with_the_key_name_only(): void
    {
        $session = ['auth.password_confirmed_at' => now()->unix()];

        $this->actingAs($this->admin)->withSession($session)
            ->put(route('admin.settings.keys-and-secrets.store'), ['password' => self::PASSWORD]);
        $this->actingAs($this->admin)->withSession($session)
            ->put(route('admin.settings.keys-and-secrets.store'), ['password' => self::PASSWORD.'-2']);
        $this->actingAs($this->admin)->withSession($session)
            ->delete(route('admin.settings.keys-and-secrets.destroy'), ['confirm' => '1']);

        $actions = AuditLog::query()->whereIn('event', [
            'admin.settings.secret_set',
            'admin.settings.secret_replaced',
            'admin.settings.secret_cleared',
        ])->orderBy('id')->pluck('event')->all();

        $this->assertSame([
            'admin.settings.secret_set',
            'admin.settings.secret_replaced',
            'admin.settings.secret_cleared',
        ], $actions);
    }

    public function test_there_is_no_field_for_app_key_or_token_key(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.settings.show', 'keys-and-secrets'))->assertOk()->getContent();

        $this->assertStringContainsString('data-server-secret="APP_KEY"', $html);
        $this->assertStringContainsString('data-server-secret="INBOUND_MAIL_TOKEN_KEY"', $html);
        $this->assertDoesNotMatchRegularExpression('/name=["\']APP_KEY["\']/', $html);
        $this->assertDoesNotMatchRegularExpression('/name=["\']INBOUND_MAIL_TOKEN_KEY["\']/', $html);
        $this->assertDoesNotMatchRegularExpression('/name=["\']token_key["\']/', $html);
    }
}
