<?php

namespace Tests\Feature\Admin;

use App\Core\Accounts\User;
use App\Core\Mail\Inbound\Contracts\InboundMailbox;
use App\Core\Mail\Inbound\ImapMailbox;
use App\Core\Mail\Inbound\InboundEmail;
use App\Core\Mail\Inbound\InboundMailPoller;
use App\Core\Mail\Inbound\InboundMailSettings;
use App\Services\AuthorizationProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Mail\Inbound\FakeMailbox;
use Tests\Feature\Mail\Inbound\InboundMailTestHelpers;
use Tests\TestCase;

/**
 * Settings saved in Admin → Settings → Reply mailbox switch polling on (#742),
 * with no real IMAP server: a fake mailbox stands in, as on a local Herd install.
 */
class ReplyMailboxEndToEndTest extends TestCase
{
    use InboundMailTestHelpers;
    use RefreshDatabase;

    public function test_details_saved_by_an_admin_turn_polling_on_and_env_still_wins(): void
    {
        Storage::fake('private');
        Queue::fake();
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
        $settings = app(InboundMailSettings::class);
        $this->assertFalse($settings->pollingConfigured());
        $this->assertInstanceOf(ImapMailbox::class, app(InboundMailbox::class));

        $admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();
        $this->actingAs($admin)->put(route('admin.emails.import.update'), [
            'enabled' => '1',
            'address' => 'communications@example.test',
            'host' => 'imap.example.test',
            'port' => '993',
            'encryption' => 'ssl',
            'validate_cert' => '1',
            'username' => 'communications@example.test',
            'password' => 'not-a-real-password',
            'folder' => 'INBOX',
            'plus_addressing' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertTrue($settings->pollingConfigured());
        $this->assertSame(InboundMailSettings::PASSWORD_SOURCE_LEGACY, $settings->passwordSource());
        $this->assertSame('communications@example.test', $settings->address());

        $mailbox = new FakeMailbox;
        $mailbox->add($this->fixture('plain-reply', ['TO' => 'communications@example.test']));
        $this->app->instance(InboundMailbox::class, $mailbox);
        $this->assertSame(1, app(InboundMailPoller::class)->poll()['stored']);
        $this->assertSame(1, InboundEmail::query()->count());

        config(['app-settings.env.INBOUND_MAIL_ENABLED' => false, 'inbound-mail.enabled' => false]);
        $this->assertFalse($settings->repliesEnabled());
    }
}
