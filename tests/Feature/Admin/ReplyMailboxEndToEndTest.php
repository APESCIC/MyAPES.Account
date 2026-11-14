<?php

namespace Tests\Feature\Admin;

use App\Core\Mail\Inbound\Contracts\InboundMailbox;
use App\Core\Mail\Inbound\ImapMailbox;
use App\Core\Mail\Inbound\InboundEmail;
use App\Core\Mail\Inbound\InboundMailPoller;
use App\Core\Mail\Inbound\InboundMailSettings;
use App\Core\Secrets\SecretRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Mail\Inbound\FakeMailbox;
use Tests\Feature\Mail\Inbound\InboundMailTestHelpers;
use Tests\TestCase;

/**
 * A Keys and secrets password turns polling on against the built-in connection (#808, #809).
 */
class ReplyMailboxEndToEndTest extends TestCase
{
    use InboundMailTestHelpers;
    use RefreshDatabase;

    public function test_a_keys_and_secrets_password_turns_polling_on_and_enabled_false_turns_it_off(): void
    {
        Storage::fake('private');
        Queue::fake();
        config([
            'app-settings.env' => array_fill_keys(array_keys((array) config('app-settings.env')), null),
            'inbound-mail.enabled' => null,
            'inbound-mail.token_key' => 'inbound-test-key',
            'inbound-mail.address' => 'communications@example.test',
            'inbound-mail.imap.host' => 'mail.cloudron.apes.org.uk',
            'inbound-mail.imap.port' => 993,
            'inbound-mail.imap.encryption' => 'ssl',
            'inbound-mail.imap.password' => null,
        ]);
        $settings = app(InboundMailSettings::class);
        $this->assertFalse($settings->pollingConfigured());
        $this->assertInstanceOf(ImapMailbox::class, app(InboundMailbox::class));

        app(SecretRegistry::class)->set(SecretRegistry::INBOUND_MAIL_IMAP_PASSWORD, 'not-a-real-password');
        $this->assertTrue($settings->pollingConfigured());
        $this->assertSame(InboundMailSettings::PASSWORD_SOURCE_KEYS, $settings->passwordSource());
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
