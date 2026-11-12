<?php

namespace Tests\Feature\Admin;

use App\Core\Accounts\User;
use App\Core\Mail\Inbound\InboundMailboxSettings;
use App\Core\Settings\AppSettings;
use App\Services\AuthorizationProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
            ->put(route('admin.settings.reply-mailbox.update'), $this->form())
            ->assertRedirect(route('admin.settings.show', 'reply-mailbox'))
            ->assertSessionHas('status', __('admin.settings.reply_mailbox.saved'));

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
        $this->actingAs($this->admin)->put(route('admin.settings.reply-mailbox.update'), $this->form());

        $this->actingAs($this->admin)
            ->get(route('admin.settings.show', 'reply-mailbox'))
            ->assertOk()
            ->assertSee(__('admin.settings.reply_mailbox.password_saved'))
            ->assertSee('value="imap.example.test"', false)
            ->assertDontSee(self::PASSWORD);
    }

    public function test_a_blank_password_keeps_the_saved_one(): void
    {
        $this->actingAs($this->admin)->put(route('admin.settings.reply-mailbox.update'), $this->form());

        $this->actingAs($this->admin)->put(route('admin.settings.reply-mailbox.update'), $this->form(['password' => '', 'folder' => 'Replies']));

        $this->assertSame(self::PASSWORD, app(AppSettings::class)->get(self::GROUP, 'password'));
        $this->assertSame('Replies', app(AppSettings::class)->get(self::GROUP, 'folder'));
    }

    public function test_env_values_win_and_are_shown_as_set_by_env(): void
    {
        config([
            'app-settings.env.INBOUND_MAIL_IMAP_HOST' => 'env-imap.example.test',
            'app-settings.env.INBOUND_MAIL_PLUS_ADDRESSING' => true,
        ]);

        $this->actingAs($this->admin)->put(route('admin.settings.reply-mailbox.update'), $this->form());

        $settings = app(AppSettings::class);
        $this->assertSame('env-imap.example.test', $settings->get(self::GROUP, 'host'));
        $this->assertFalse($settings->stored(self::GROUP, 'host'));
        $this->assertTrue($settings->get(self::GROUP, 'plus_addressing'));
        $this->assertFalse($settings->stored(self::GROUP, 'plus_addressing'));

        $this->actingAs($this->admin)
            ->get(route('admin.settings.show', 'reply-mailbox'))
            ->assertSee('data-env-badge', false)
            ->assertSee(__('admin.settings.reply_mailbox.env_badge'));
    }

    public function test_invalid_values_are_refused(): void
    {
        $this->actingAs($this->admin)
            ->from(route('admin.settings.show', 'reply-mailbox'))
            ->put(route('admin.settings.reply-mailbox.update'), $this->form(['port' => '0', 'encryption' => 'starttls', 'address' => 'not-an-address']))
            ->assertSessionHasErrors(['port', 'encryption', 'address']);

        $this->assertFalse(app(AppSettings::class)->stored(self::GROUP, 'host'));
    }

    public function test_saving_needs_admin_settings_manage(): void
    {
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();

        $this->actingAs($staff)->put(route('admin.settings.reply-mailbox.update'), $this->form())->assertForbidden();
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
