<?php

namespace Tests\Feature\Mail\Inbound;

use App\Core\Accounts\User;
use App\Core\Mail\Inbound\InboundEmail;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Local test replies on Admin â†’ Emails â†’ Inbound (#758).
 */
class LocalTestRepliesTest extends TestCase
{
    use InboundMailTestHelpers;
    use RefreshDatabase;

    private User $admin;

    private string $replyRaw = '';

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
        Storage::fake('private');
        Queue::fake();
        // Switching the app to local turns off Laravel's test-only CSRF bypass.
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->enableEmailReplies(['inbound-mail.driver' => 'mailpit', 'inbound-mail.mailpit.url' => 'http://127.0.0.1:8025']);
        $this->admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();
        User::factory()->create(['email' => 'owner@example.org']);
        $this->fakeMailpit();
    }

    public function test_the_panel_and_routes_are_not_found_outside_local(): void
    {
        $this->actingAs($this->admin);

        $this->get(route('admin.emails.inbound.index'))->assertOk()->assertDontSee('data-local-replies', false);
        $this->post(route('admin.emails.inbound.local.reply'), ['message' => 'orig-id', 'body' => 'Hi'])->assertNotFound();
        $this->post(route('admin.emails.inbound.local.fetch'))->assertNotFound();
    }

    public function test_the_panel_and_routes_are_not_found_with_the_imap_driver(): void
    {
        $this->app['env'] = 'local';
        config(['inbound-mail.driver' => 'imap']);
        $this->actingAs($this->admin);

        $this->get(route('admin.emails.inbound.index'))->assertOk()->assertDontSee('data-local-replies', false);
        $this->post(route('admin.emails.inbound.local.fetch'))->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_people_without_admin_emails_manage_are_forbidden(): void
    {
        $this->app['env'] = 'local';
        $this->actingAs(User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create());

        $this->post(route('admin.emails.inbound.local.reply'), ['message' => 'orig-id', 'body' => 'Hi'])->assertForbidden();
        $this->post(route('admin.emails.inbound.local.fetch'))->assertForbidden();
    }

    public function test_the_panel_lists_the_latest_ticket_and_case_emails_in_mailpit(): void
    {
        $this->app['env'] = 'local';

        $this->actingAs($this->admin)
            ->get(route('admin.emails.inbound.index'))
            ->assertOk()
            ->assertSee('data-local-replies', false)
            ->assertSee('Boiler is broken [MYAPES-T-12]')
            ->assertSee('http://127.0.0.1:8025/view/orig-id', false)
            ->assertDontSee('Re: Boiler is broken [MYAPES-T-12]')
            ->assertSee(__('admin.emails.inbound.local.variant.no-references'));
    }

    public function test_reply_as_client_sends_the_reply_through_the_mail_settings(): void
    {
        $this->app['env'] = 'local';

        $this->actingAs($this->admin)
            ->post(route('admin.emails.inbound.local.reply'), [
                'message' => 'orig-id',
                'body' => 'Replying from the panel.',
                'variants' => ['no-tag', 'attachment'],
            ])
            ->assertRedirect(route('admin.emails.inbound.index').'#local-replies')
            ->assertSessionHas('status');

        $sent = $this->sentEmails();
        $this->assertCount(1, $sent);
        $this->assertSame('Re: Boiler is broken', $sent[0]->getSubject());
        $this->assertSame('owner@example.org', $sent[0]->getFrom()[0]->getAddress());
        $this->assertCount(1, $sent[0]->getAttachments());
    }

    public function test_reply_as_client_refuses_unknown_variants_and_senders(): void
    {
        $this->app['env'] = 'local';
        $this->actingAs($this->admin);

        $this->post(route('admin.emails.inbound.local.reply'), ['message' => 'orig-id', 'body' => 'Hi', 'variants' => ['bogus']])
            ->assertSessionHasErrors('variants.0');
        $this->post(route('admin.emails.inbound.local.reply'), ['message' => 'orig-id', 'body' => 'Hi', 'as' => 'nobody@example.org'])
            ->assertSessionHasErrors('local_reply');
        $this->assertSame([], $this->sentEmails());
    }

    public function test_fetch_replies_now_runs_the_poller_once_and_shows_the_counts(): void
    {
        $this->app['env'] = 'local';
        $this->replyRaw = $this->fixture('plain-reply', ['MESSAGE_ID' => 'panel@mail.example.org', 'SUBJECT' => 'Re: Boiler [MYAPES-T-12]']);

        $this->actingAs($this->admin)
            ->post(route('admin.emails.inbound.local.fetch'))
            ->assertRedirect()
            ->assertSessionHas('status', __('admin.emails.inbound.local.fetched', ['fetched' => 1, 'stored' => 1, 'duplicates' => 0, 'failed' => 0]))
            ->assertSessionHas('local_fetched', fn (array $fetched): bool => count($fetched) === 1
                && $fetched[0]['url'] === route('admin.emails.inbound.show', InboundEmail::query()->sole()));

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE');
    }

    private function fakeMailpit(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, '/api/v1/search') && $request['query'] === 'to:communications') {
                return Http::response($this->replyRaw === '' ? ['messages' => [], 'messages_count' => 0] : ['messages' => [
                    ['ID' => 'reply-id', 'To' => [['Address' => 'communications@example.test']], 'Created' => '2026-11-11T10:00:00Z'],
                ], 'messages_count' => 1]);
            }
            if (str_contains($url, '/api/v1/search')) {
                return Http::response(['messages' => [
                    ['ID' => 'reply-id', 'Subject' => 'Re: Boiler is broken [MYAPES-T-12]', 'To' => [['Address' => 'communications@example.test']], 'Created' => '2026-11-11T10:05:00Z'],
                    ['ID' => 'orig-id', 'Subject' => 'Boiler is broken [MYAPES-T-12]', 'To' => [['Address' => 'owner@example.org']], 'Created' => '2026-11-11T10:00:00Z'],
                ], 'messages_count' => 2]);
            }
            if (str_ends_with($url, '/api/v1/message/reply-id/raw')) {
                return Http::response($this->replyRaw);
            }
            if (str_ends_with($url, '/api/v1/message/orig-id')) {
                return Http::response([
                    'MessageID' => 'original-1@example.test',
                    'Subject' => 'Boiler is broken [MYAPES-T-12]',
                    'From' => ['Address' => 'no-reply@example.test'],
                    'To' => [['Address' => 'owner@example.org']],
                    'ReplyTo' => [['Address' => 'communications+t12.abc@example.test']],
                    'Text' => 'Your ticket was updated.',
                ]);
            }
            if ($request->method() === 'DELETE') {
                return Http::response('ok');
            }

            return Http::response([], 404);
        });
    }
}
