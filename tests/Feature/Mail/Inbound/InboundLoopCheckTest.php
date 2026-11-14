<?php

namespace Tests\Feature\Mail\Inbound;

use App\Core\Accounts\User;
use App\Core\Mail\Inbound\Herd\HerdMailRunner;
use App\Core\Mail\Inbound\InboundEmail;
use App\Core\Mail\Inbound\InboundMailPoller;
use App\Core\Mail\Inbound\Local\ClientReplyBuilder;
use App\Core\Mail\Inbound\Local\OriginalEmail;
use App\Jobs\ProcessInboundEmail;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Plugins\Tickets\Models\SupportTicketMessage;
use Plugins\Tickets\Notifications\TicketUpdatedNotification;
use Tests\Support\FakeHerdMailRunner;
use Tests\TestCase;

/**
 * End-to-end inbound loop check (#818) with a fake Herd runner.
 */
class InboundLoopCheckTest extends TestCase
{
    use InboundMailTestHelpers;
    use RefreshDatabase;

    private FakeHerdMailRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
        Storage::fake('private');
        Notification::fake();
        $this->runner = new FakeHerdMailRunner;
        $this->app->instance(HerdMailRunner::class, $this->runner);
        $this->enableEmailReplies(['inbound-mail.driver' => 'herd']);
    }

    public function test_the_command_refuses_unless_app_env_is_local(): void
    {
        $this->artisan('tickets:inbound-loop-check')
            ->expectsOutputToContain('local testing only')
            ->assertFailed();
    }

    public function test_a_reply_to_one_ticket_is_never_posted_to_another(): void
    {
        $this->app['env'] = 'local';
        $owner = User::factory()->create(['email' => 'owner@example.org']);
        $ticketA = $this->ticketFor($owner);
        $ticketB = $this->ticketFor($owner, ['subject' => 'Other ticket']);

        $original = new OriginalEmail(
            messageId: 'loop-a@example.test',
            subject: 'Update [MYAPES-T-'.$ticketA->id.']',
            replyTo: $this->replyAddressFor('T', $ticketA->id, $owner),
            recipient: 'owner@example.org',
            text: 'Staff update',
        );
        $raw = app(ClientReplyBuilder::class)->raw(
            app(ClientReplyBuilder::class)->build($original, $owner, 'Only for ticket A.'),
        );
        $this->runner->put('reply-a', $original->replyTo, now()->toIso8601String(), $raw);

        app(InboundMailPoller::class)->poll(force: true);
        ProcessInboundEmail::dispatchSync((int) InboundEmail::query()->sole()->id);

        $this->assertSame(1, SupportTicketMessage::query()->where('via_email', true)->count());
        $this->assertSame($ticketA->id, SupportTicketMessage::query()->where('via_email', true)->sole()->support_ticket_id);
        $this->assertSame(0, SupportTicketMessage::query()->where('support_ticket_id', $ticketB->id)->where('via_email', true)->count());
    }

    public function test_the_staff_notification_email_has_the_ticket_reference(): void
    {
        $owner = User::factory()->create(['email' => 'owner@example.org']);
        $ticket = $this->ticketFor($owner);
        $mail = (new TicketUpdatedNotification(
            $ticket,
            $owner,
            'updated',
            'apes-cic',
            'apes-cic.tickets.show',
            'APES CIC',
        ))->toMail(User::factory()->make(['email' => 'staff@example.test']));

        $blob = strtolower($mail->subject.' '.implode(' ', $mail->introLines));
        $this->assertStringContainsString((string) $ticket->id, $blob);
    }
}
