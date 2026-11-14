<?php

namespace Tests\Feature\Mail\Inbound;

use App\Core\Accounts\User;
use App\Core\Mail\Inbound\Herd\HerdMailRunner;
use App\Core\Mail\Inbound\InboundEmail;
use App\Core\Mail\Inbound\InboundMailPoller;
use App\Core\Mail\Inbound\Local\ClientReplyBuilder;
use App\Core\Mail\Inbound\Local\LocalReplies;
use App\Jobs\ProcessInboundEmail;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugins\Tickets\Models\SupportTicketMessage;
use Tests\Support\FakeHerdMailRunner;
use Tests\TestCase;

/**
 * Local test replies through Herd (#817).
 */
class HerdLocalRepliesTest extends TestCase
{
    use InboundMailTestHelpers;
    use RefreshDatabase;

    private User $admin;

    private User $owner;

    private FakeHerdMailRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
        Storage::fake('private');
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->runner = new FakeHerdMailRunner;
        $this->app->instance(HerdMailRunner::class, $this->runner);
        $this->enableEmailReplies(['inbound-mail.driver' => 'herd']);
        $this->admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();
        $this->owner = User::factory()->create(['email' => 'owner@example.org']);
        $this->seedHerdOutbound();
    }

    public function test_the_panel_lists_the_latest_ticket_and_case_emails_from_herd(): void
    {
        $this->app['env'] = 'local';

        $this->actingAs($this->admin)
            ->get(route('admin.emails.inbound.index'))
            ->assertOk()
            ->assertSee('data-local-replies', false)
            ->assertSee('Boiler is broken [MYAPES-T-12]')
            ->assertSee('herd://mail/orig-id', false)
            ->assertDontSee('Re: Boiler is broken [MYAPES-T-12]')
            ->assertSee(__('admin.emails.inbound.local.variant.no-references'));
    }

    public function test_a_reply_from_the_panel_is_imported_by_fetch_now_and_posted(): void
    {
        $this->app['env'] = 'local';
        $ticket = $this->ticketFor($this->owner);
        $to = $this->replyAddressFor('T', $ticket->id, $this->owner);
        $this->runner->mail = [];
        $this->runner->jsonMessages = [];
        $this->runner->listSubjects = [];
        $this->runner->put('orig-panel', 'owner@example.org', now()->toIso8601String(), $this->fixture('plain-reply', [
            'SUBJECT' => 'Boiler is broken [MYAPES-T-'.$ticket->id.']',
            'MESSAGE_ID' => 'orig-panel@example.test',
        ]));
        $this->runner->jsonMessages['orig-panel'] = [
            'id' => 'orig-panel',
            'messageId' => '<orig-panel@example.test>',
            'subject' => 'Boiler is broken [MYAPES-T-'.$ticket->id.']',
            'from' => [['address' => 'no-reply@example.test', 'name' => '']],
            'to' => [['address' => 'owner@example.org', 'name' => '']],
            'replyTo' => [['address' => $to, 'name' => '']],
            'text' => 'Your ticket was updated.',
            'headers' => [],
        ];
        $this->runner->listSubjects['orig-panel'] = 'Boiler is broken [MYAPES-T-'.$ticket->id.']';

        $this->actingAs($this->admin)
            ->post(route('admin.emails.inbound.local.reply'), [
                'message' => 'orig-panel',
                'body' => 'Replying from the Herd panel.',
            ])
            ->assertRedirect(route('admin.emails.inbound.index').'#local-replies');

        $sent = $this->sentEmails();
        $this->assertCount(1, $sent);
        $raw = app(ClientReplyBuilder::class)->raw($sent[0]);
        $this->runner->mail = [];
        $this->runner->put('reply-1', $to, now()->toIso8601String(), $raw);

        $this->actingAs($this->admin)
            ->post(route('admin.emails.inbound.local.fetch'))
            ->assertRedirect()
            ->assertSessionHas('status');

        $email = InboundEmail::query()->latest('id')->first();
        $this->assertNotNull($email);
        $this->assertSame(InboundEmail::STATUS_POSTED, $email->fresh()->status);
        $this->assertStringContainsString('Replying from the Herd panel.', (string) SupportTicketMessage::query()->sole()->message);
    }

    /**
     * @return array<string, array{list<string>, string}>
     */
    public static function variants(): array
    {
        return [
            'no tag' => [[ClientReplyBuilder::NO_TAG], InboundEmail::STATUS_POSTED],
            'no references' => [[ClientReplyBuilder::NO_REFERENCES], InboundEmail::STATUS_POSTED],
            'no tag and no references' => [[ClientReplyBuilder::NO_TAG, ClientReplyBuilder::NO_REFERENCES], InboundEmail::STATUS_POSTED],
            'edited subject' => [[ClientReplyBuilder::EDITED_SUBJECT], InboundEmail::STATUS_POSTED],
            'html only' => [[ClientReplyBuilder::HTML_ONLY], InboundEmail::STATUS_POSTED],
            'attachment' => [[ClientReplyBuilder::ATTACHMENT], InboundEmail::STATUS_POSTED],
        ];
    }

    /**
     * @param  list<string>  $variants
     */
    #[DataProvider('variants')]
    public function test_each_variant_is_matched_or_held_as_with_mailpit(array $variants, string $expectedStatus): void
    {
        $this->app['env'] = 'local';
        $ticket = $this->ticketFor($this->owner);
        $to = $this->replyAddressFor('T', $ticket->id, $this->owner);

        $this->runner->mail = [];
        $this->runner->put('orig-variant', 'owner@example.org', now()->toIso8601String(), $this->fixture('plain-reply', [
            'FROM' => 'no-reply@example.test',
            'TO' => 'owner@example.org',
            'SUBJECT' => 'Boiler is broken [MYAPES-T-'.$ticket->id.']',
            'MESSAGE_ID' => 'orig-variant@example.test',
            'EXTRA' => "\nReply-To: ".$to,
        ]));
        // fromHerd needs replyTo in json show — store via show format
        $this->runner->jsonMessages['orig-variant'] = [
            'id' => 'orig-variant',
            'messageId' => '<orig-variant@example.test>',
            'subject' => 'Boiler is broken [MYAPES-T-'.$ticket->id.']',
            'from' => [['address' => 'no-reply@example.test', 'name' => '']],
            'to' => [['address' => 'owner@example.org', 'name' => '']],
            'replyTo' => [['address' => $to, 'name' => '']],
            'text' => 'Your ticket was updated.',
            'headers' => [],
        ];

        app(LocalReplies::class)->reply('orig-variant', 'Variant body', null, $variants);
        $raw = $this->sentEmails()[0]->toString();
        $this->runner->put('variant-reply', $to, now()->toIso8601String(), $raw);

        app(InboundMailPoller::class)->poll();
        ProcessInboundEmail::dispatchSync((int) InboundEmail::query()->latest('id')->first()->id);

        $this->assertSame($expectedStatus, InboundEmail::query()->latest('id')->first()->status);
    }

    private function seedHerdOutbound(): void
    {
        $this->runner->put('reply-id', 'communications@example.test', '2026-11-11T10:05:00Z', $this->fixture('plain-reply', [
            'SUBJECT' => 'Re: Boiler is broken [MYAPES-T-12]',
            'MESSAGE_ID' => 'reply-id@example.test',
        ]));
        $this->runner->put('orig-id', 'owner@example.org', '2026-11-11T10:00:00Z', $this->fixture('plain-reply', [
            'FROM' => 'no-reply@example.test',
            'TO' => 'owner@example.org',
            'SUBJECT' => 'Boiler is broken [MYAPES-T-12]',
            'MESSAGE_ID' => 'orig-id@example.test',
            'EXTRA' => "\nReply-To: communications+t12.abc@example.test",
        ]));
        $this->runner->jsonMessages['orig-id'] = [
            'id' => 'orig-id',
            'messageId' => '<orig-id@example.test>',
            'subject' => 'Boiler is broken [MYAPES-T-12]',
            'from' => [['address' => 'no-reply@example.test', 'name' => 'MyAPES']],
            'to' => [['address' => 'owner@example.org', 'name' => 'Olive Owner']],
            'replyTo' => [['address' => 'communications+t12.abc@example.test', 'name' => '']],
            'text' => 'Your ticket was updated.',
            'headers' => [],
        ];
        // Override list subjects for recentRecordEmails filtering
        $this->runner->listSubjects = [
            'reply-id' => 'Re: Boiler is broken [MYAPES-T-12]',
            'orig-id' => 'Boiler is broken [MYAPES-T-12]',
        ];
    }
}
