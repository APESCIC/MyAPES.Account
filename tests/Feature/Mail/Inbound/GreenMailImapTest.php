<?php

namespace Tests\Feature\Mail\Inbound;

use App\Core\Accounts\User;
use App\Core\Mail\Inbound\InboundEmail;
use App\Core\Mail\Inbound\InboundMailPoller;
use App\Core\Mail\Inbound\Local\ClientReplyBuilder;
use App\Core\Mail\Inbound\Local\OriginalEmail;
use App\Jobs\ProcessInboundEmail;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Plugins\Tickets\Models\SupportTicketMessage;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Tests\TestCase;
use Webklex\PHPIMAP\ClientManager;

/**
 * Real IMAPS against GreenMail in CI (#819). Skips unless RUN_GREENMAIL=1.
 */
#[Group('greenmail')]
class GreenMailImapTest extends TestCase
{
    use InboundMailTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('RUN_GREENMAIL') !== '1') {
            $this->markTestSkipped('GreenMail CI only (RUN_GREENMAIL=1).');
        }

        app(ModuleInstallationSynchronizer::class)->synchronize();
        Storage::fake('private');

        $host = getenv('GREENMAIL_HOST') ?: '127.0.0.1';
        $this->enableEmailReplies([
            'inbound-mail.driver' => 'imap',
            'inbound-mail.address' => 'communications@example.test',
            'inbound-mail.imap.host' => $host,
            'inbound-mail.imap.port' => (int) (getenv('GREENMAIL_IMAPS_PORT') ?: 3993),
            'inbound-mail.imap.encryption' => 'ssl',
            'inbound-mail.imap.validate_cert' => false,
            'inbound-mail.imap.username' => 'communications@example.test',
            'inbound-mail.imap.password' => 'communications@example.test',
            'inbound-mail.imap.folder' => 'INBOX',
        ]);
    }

    public function test_a_client_reply_is_polled_over_imaps_posted_and_moved_to_processed(): void
    {
        $owner = User::factory()->create(['email' => 'owner@example.org']);
        $ticket = $this->ticketFor($owner);
        $replyTo = $this->replyAddressFor('T', $ticket->id, $owner);

        $original = new OriginalEmail(
            messageId: 'greenmail-orig@example.test',
            subject: 'Boiler update [MYAPES-T-'.$ticket->id.']',
            replyTo: $replyTo,
            recipient: 'owner@example.org',
            text: 'Staff update for GreenMail.',
        );
        $reply = app(ClientReplyBuilder::class)->build($original, $owner, 'GreenMail loop reply.');
        $this->deliverOverSmtp($reply);

        $result = app(InboundMailPoller::class)->poll(force: true);
        $this->assertSame(InboundMailPoller::RESULT_POLLED, $result['result']);
        $this->assertGreaterThanOrEqual(1, $result['stored']);

        $email = InboundEmail::query()->latest('id')->first();
        $this->assertNotNull($email);
        ProcessInboundEmail::dispatchSync((int) $email->id);

        $this->assertSame(InboundEmail::STATUS_POSTED, $email->fresh()->status);
        $this->assertSame($ticket->id, SupportTicketMessage::query()->where('via_email', true)->sole()->support_ticket_id);
        $this->assertSame(0, $this->folderMessageCount('INBOX'));
        $this->assertGreaterThanOrEqual(1, $this->folderMessageCount('Processed'));
    }

    private function deliverOverSmtp(Email $email): void
    {
        $host = getenv('GREENMAIL_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('GREENMAIL_SMTP_PORT') ?: 3025);
        $mailbox = (string) config('inbound-mail.address');
        $transport = new EsmtpTransport($host, $port, false);
        // GreenMail creates one mailbox per RCPT TO; a plus-address reply would
        // land in a different inbox than the poller reads. Keep the plus address
        // in the MIME To header for matching, and deliver into the plain mailbox
        // over the SMTP envelope.
        $sender = new Address('owner@example.org', 'Olive Owner');
        $email->from($sender);
        $transport->send($email, new Envelope($sender, [new Address($mailbox)]));
    }

    private function folderMessageCount(string $folder): int
    {
        $client = (new ClientManager)->make([
            'host' => getenv('GREENMAIL_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('GREENMAIL_IMAPS_PORT') ?: 3993),
            'encryption' => 'ssl',
            'validate_cert' => false,
            'username' => 'communications@example.test',
            'password' => 'communications@example.test',
            'protocol' => 'imap',
        ]);
        $client->connect();
        try {
            return $client->getFolder($folder)?->messages()->all()->count() ?? 0;
        } finally {
            $client->disconnect();
        }
    }
}
