<?php

namespace Tests\Feature\Admin;

use App\Core\Accounts\User;
use App\Core\Mail\Inbound\Diagnostics\MailboxTransport;
use App\Core\Mail\Inbound\InboundMailboxSettings;
use App\Core\Settings\AppSettings;
use App\Services\AuthorizationProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\FakeImapServer;
use Tests\TestCase;

/**
 * Admin → Settings → Reply mailbox form (#735).
 */
class ReplyMailboxSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'not-a-real-mail-app-password';

    private const GROUP = InboundMailboxSettings::GROUP;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app-settings.env' => array_fill_keys(array_keys((array) config('app-settings.env')), null)]);
        $this->admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();
    }

    public function test_an_administrator_saves_the_mailbox_details(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.emails.import.update'), $this->form())
            ->assertRedirect(route('admin.emails.import.index'))
            ->assertSessionHas('status', __('admin.emails.import.saved'));

        $settings = app(AppSettings::class);
        $this->assertTrue($settings->get(self::GROUP, 'enabled'));
        $this->assertSame('imap.example.test', $settings->get(self::GROUP, 'host'));
        $this->assertSame(993, $settings->get(self::GROUP, 'port'));
        $this->assertFalse($settings->get(self::GROUP, 'plus_addressing'));
        $this->assertSame(self::PASSWORD, $settings->get(self::GROUP, 'password'));
        $this->assertStringNotContainsString(self::PASSWORD, (string) DB::table('app_settings')->where('key', 'password')->value('value'));
    }

    public function test_the_password_never_appears_on_the_page(): void
    {
        $this->actingAs($this->admin)->put(route('admin.emails.import.update'), $this->form());

        $this->actingAs($this->admin)
            ->get(route('admin.emails.import.index'))
            ->assertOk()
            ->assertSee(__('admin.emails.import.password_saved'))
            ->assertSee('value="imap.example.test"', false)
            ->assertDontSee(self::PASSWORD);
    }

    public function test_a_blank_password_keeps_the_saved_one(): void
    {
        $this->actingAs($this->admin)->put(route('admin.emails.import.update'), $this->form());

        $this->actingAs($this->admin)->put(route('admin.emails.import.update'), $this->form(['password' => '', 'folder' => 'Replies']));

        $this->assertSame(self::PASSWORD, app(AppSettings::class)->get(self::GROUP, 'password'));
        $this->assertSame('Replies', app(AppSettings::class)->get(self::GROUP, 'folder'));
    }

    public function test_env_values_win_and_are_shown_as_set_by_env(): void
    {
        config([
            'app-settings.env.INBOUND_MAIL_IMAP_HOST' => 'env-imap.example.test',
            'app-settings.env.INBOUND_MAIL_PLUS_ADDRESSING' => true,
        ]);

        $this->actingAs($this->admin)->put(route('admin.emails.import.update'), $this->form());

        $settings = app(AppSettings::class);
        $this->assertSame('env-imap.example.test', $settings->get(self::GROUP, 'host'));
        $this->assertFalse($settings->stored(self::GROUP, 'host'));
        $this->assertTrue($settings->get(self::GROUP, 'plus_addressing'));
        $this->assertFalse($settings->stored(self::GROUP, 'plus_addressing'));

        $this->actingAs($this->admin)
            ->get(route('admin.emails.import.index'))
            ->assertSee('data-env-badge', false)
            ->assertSee(__('admin.emails.import.env_badge'));
    }

    public function test_invalid_values_are_refused(): void
    {
        $this->actingAs($this->admin)
            ->from(route('admin.emails.import.index'))
            ->put(route('admin.emails.import.update'), $this->form(['port' => '0', 'encryption' => 'starttls', 'address' => 'not-an-address']))
            ->assertSessionHasErrors(['port', 'encryption', 'address']);

        $this->assertFalse(app(AppSettings::class)->stored(self::GROUP, 'host'));
    }

    public function test_a_fresh_install_starts_with_the_cloudron_values(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.emails.import.index'))
            ->assertOk()
            ->assertSee('value="mail.cloudron.apes.org.uk"', false)
            ->assertSee('value="993"', false)
            ->assertSee('<option value="ssl" selected>'.__('admin.emails.import.encryption.ssl').'</option>', false)
            ->assertSee(__('admin.emails.import.encryption.tls'))
            ->assertSee(__('admin.emails.import.username_help'))
            ->assertDontSee('my.cloudron.apes.org.uk');
    }

    public function test_port_993_with_starttls_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.emails.import.update'), $this->form(['port' => '993', 'encryption' => 'tls']))
            ->assertSessionHasErrors(['encryption' => __('admin.emails.import.encryption_mismatch', ['port' => 993, 'encryption' => __('admin.emails.import.encryption.ssl')])]);

        $this->assertSame(0, DB::table('app_settings')->count());
    }

    public function test_port_143_with_ssl_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.emails.import.update'), $this->form(['port' => '143', 'encryption' => 'ssl']))
            ->assertSessionHasErrors(['encryption' => __('admin.emails.import.encryption_mismatch', ['port' => 143, 'encryption' => __('admin.emails.import.encryption.tls')])]);

        $this->assertSame(0, DB::table('app_settings')->count());
    }

    public function test_testing_a_port_and_encryption_that_cannot_work_is_refused(): void
    {
        $server = new FakeImapServer;
        $this->app->instance(MailboxTransport::class, $server);

        $this->actingAs($this->admin)
            ->put(route('admin.emails.import.test'), $this->form(['port' => '993', 'encryption' => 'tls']))
            ->assertSessionHasErrors('encryption');

        $this->assertFalse($server->connected);
        $this->assertSame([], $server->commands);
    }

    public function test_no_encryption_is_offered_only_on_a_developer_machine(): void
    {
        $this->assertContains('none', InboundMailboxSettings::encryptions());

        $this->app['env'] = 'production';

        $this->assertSame(['ssl', 'tls'], InboundMailboxSettings::encryptions());
    }

    public function test_saving_needs_admin_settings_manage(): void
    {
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();

        $this->actingAs($staff)->put(route('admin.emails.import.update'), $this->form())->assertForbidden();
        $this->assertSame(0, DB::table('app_settings')->count());
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function form(array $overrides = []): array
    {
        return [
            'enabled' => '1',
            'address' => 'communications@example.test',
            'host' => 'imap.example.test',
            'port' => '993',
            'encryption' => 'ssl',
            'validate_cert' => '1',
            'username' => 'communications@example.test',
            'password' => self::PASSWORD,
            'folder' => 'INBOX',
            'plus_addressing' => '0',
            ...$overrides,
        ];
    }
}
