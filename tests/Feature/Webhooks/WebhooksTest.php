<?php

namespace Tests\Feature\Webhooks;

use App\Core\Accounts\AuditLog;
use App\Core\Accounts\User;
use App\Core\Accounts\UserServiceSelection;
use App\Core\Webhooks\WebhookDelivery;
use App\Core\Webhooks\WebhookEndpoint;
use App\Core\Webhooks\WebhookEvents;
use App\Core\Webhooks\WebhookSignature;
use App\Http\Controllers\Admin\AdminWebhookController;
use App\Jobs\DeliverWebhook;
use App\Services\AuthorizationProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Plugins\Tickets\Models\SupportTicket;
use Tests\TestCase;

/**
 * Signed outgoing webhooks (#641) and their delivery queue, retries, log and replay (#642).
 */
class WebhooksTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://easm.example.org/hooks/myapes';

    private const SECRET = 'whsec_not-a-real-signing-secret-for-tests';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();
    }

    public function test_an_administrator_adds_an_endpoint_and_sees_the_secret_once(): void
    {
        $this->actingAs($this->admin)->post(route('admin.settings.webhooks.store'), [
            'name' => 'EASM',
            'url' => self::URL,
            'organisation' => 'shelter-rescue',
            'events' => [WebhookEvents::TICKET_CREATED, WebhookEvents::CASE_UPDATED],
        ])->assertSessionHasNoErrors()->assertSessionHas(AdminWebhookController::SECRET_FLASH_KEY);

        $secret = session(AdminWebhookController::SECRET_FLASH_KEY)['secret'];
        $endpoint = WebhookEndpoint::query()->sole();
        $this->assertSame($secret, $endpoint->secret);
        $this->assertStringNotContainsString($secret, (string) DB::table('webhook_endpoints')->value('secret'));

        $this->actingAs($this->admin)->get(route('admin.settings.show', 'webhooks'))->assertOk()->assertSee($secret);
        $this->actingAs($this->admin)->get(route('admin.settings.show', 'webhooks'))->assertOk()->assertDontSee($secret)->assertSee('data-webhook-endpoint="'.$endpoint->id.'"', false);
        $this->assertStringNotContainsString($secret, (string) json_encode(AuditLog::query()->get()->toArray()));
    }

    public function test_https_endpoints_only(): void
    {
        foreach (['http://easm.example.org/hook', 'https://localhost/hook', 'https://10.0.0.5/hook', 'https://192.168.1.2/hook', 'ftp://easm.example.org'] as $url) {
            $this->actingAs($this->admin)->post(route('admin.settings.webhooks.store'), [
                'name' => 'Bad', 'url' => $url, 'organisation' => 'shelter-rescue', 'events' => [WebhookEvents::TICKET_CREATED],
            ])->assertSessionHasErrors('url');
        }

        $this->assertSame(0, WebhookEndpoint::query()->count());
    }

    public function test_non_administrators_cannot_manage_webhooks(): void
    {
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();

        $this->actingAs($staff)->get(route('admin.settings.show', 'webhooks'))->assertForbidden();
        $this->actingAs($staff)->post(route('admin.settings.webhooks.store'), ['name' => 'x', 'url' => self::URL, 'organisation' => 'apes-cic', 'events' => [WebhookEvents::USER_CREATED]])->assertForbidden();
    }

    public function test_the_payload_signature_verifies_with_the_secret(): void
    {
        Http::fake(['easm.example.org/*' => Http::response('', 204)]);
        $this->endpoint('shelter-rescue', [WebhookEvents::TICKET_CREATED]);
        $this->endpoint('apes-cic', [WebhookEvents::TICKET_CREATED], 'https://newsroom.example.org/hook');

        $ticket = $this->ticket('shelter-rescue');

        Http::assertSentCount(1);
        Http::assertSent(function (HttpRequest $request) use ($ticket): bool {
            $body = $request->body();
            $payload = json_decode($body, true);

            return $request->url() === self::URL
                && WebhookSignature::verify($body, $request->header(WebhookSignature::HEADER)[0], self::SECRET)
                && ! WebhookSignature::verify($body, $request->header(WebhookSignature::HEADER)[0], 'whsec_wrong-secret')
                && $request->header(WebhookSignature::EVENT_HEADER)[0] === WebhookEvents::TICKET_CREATED
                && $payload['type'] === WebhookEvents::TICKET_CREATED
                && $payload['organisation'] === 'shelter-rescue'
                && $payload['data'] === ['ticket_id' => $ticket->id]
                && ! str_contains($body, $ticket->subject);
        });
        $this->assertSame(WebhookDelivery::SUCCEEDED, WebhookDelivery::query()->sole()->status);
    }

    public function test_old_timestamps_are_rejected_by_the_sample_verifier(): void
    {
        $body = '{"type":"ticket.created"}';
        $old = WebhookSignature::sign($body, self::SECRET, time() - 600);
        $fresh = WebhookSignature::sign($body, self::SECRET, time());

        $this->assertFalse(WebhookSignature::verify($body, $old, self::SECRET));
        $this->assertTrue(WebhookSignature::verify($body, $fresh, self::SECRET));
        $this->assertFalse(WebhookSignature::verify($body.' ', $fresh, self::SECRET));
        $this->assertFalse(WebhookSignature::verify($body, 'v1=abc', self::SECRET));
    }

    public function test_people_and_consent_events_go_to_the_organisations_they_chose(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        $this->endpoint('apes-cic', [WebhookEvents::USER_UPDATED, WebhookEvents::CONSENT_CHANGED], 'https://newsroom.example.org/hook');
        $person = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create();
        $person->serviceSelections()->delete();
        UserServiceSelection::query()->create(['user_id' => $person->id, 'sub_core_key' => 'apes-cic']);

        $person->update(['name' => 'New Name']);
        $person->contactConsentEvents()->create(['channel' => 'email', 'granted' => true, 'policy_version' => '2026-08', 'source' => 'preferences', 'recorded_at' => now()]);

        $this->assertSame(
            [WebhookEvents::USER_UPDATED, WebhookEvents::CONSENT_CHANGED],
            WebhookDelivery::query()->orderBy('id')->pluck('event')->all(),
        );
        $this->assertSame(['user_id' => $person->id, 'changes' => ['name']], WebhookDelivery::query()->orderBy('id')->first()->payload['data']);
    }

    public function test_a_failed_delivery_retries_and_the_log_shows_each_try(): void
    {
        Http::fake(['easm.example.org/*' => Http::sequence()->push('', 500)->push('', 503)->push('', 200)]);
        $this->endpoint('shelter-rescue', [WebhookEvents::TICKET_CREATED]);

        $this->ticket('shelter-rescue');
        $delivery = WebhookDelivery::query()->sole();
        $this->assertSame(WebhookDelivery::RETRYING, $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertNotNull($delivery->next_attempt_at);

        (new DeliverWebhook($delivery->id))->handle();
        (new DeliverWebhook($delivery->id))->handle();

        $delivery->refresh();
        $this->assertSame(WebhookDelivery::SUCCEEDED, $delivery->status);
        $this->assertSame([500, 503, 200], $delivery->attemptLog->pluck('status_code')->all());
        $this->assertSame([1, 2, 3], $delivery->attemptLog->pluck('attempt')->all());

        $this->actingAs($this->admin)->get(route('admin.settings.show', 'webhooks'))
            ->assertOk()
            ->assertSee('data-webhook-delivery-status="succeeded"', false)
            ->assertSee(__('admin.settings.webhooks.attempt_line', ['at' => $delivery->attemptLog[0]->created_at->format('H:i:s'), 'status' => 500, 'ms' => $delivery->attemptLog[0]->duration_ms]));
    }

    public function test_a_delivery_fails_after_eight_tries_and_a_failing_endpoint_is_turned_off(): void
    {
        Http::fake(['easm.example.org/*' => Http::response('', 500)]);
        $endpoint = $this->endpoint('shelter-rescue', [WebhookEvents::TICKET_CREATED]);

        foreach (range(1, DeliverWebhook::DISABLE_AFTER_FAILED_DELIVERIES) as $number) {
            $this->ticket('shelter-rescue');
            $delivery = WebhookDelivery::query()->latest('id')->first();
            while ($delivery->refresh()->status === WebhookDelivery::RETRYING) {
                (new DeliverWebhook($delivery->id))->handle();
            }
            $this->assertSame(WebhookDelivery::FAILED, $delivery->status);
            $this->assertSame(DeliverWebhook::MAX_ATTEMPTS, $delivery->attemptLog()->count());
        }

        $endpoint->refresh();
        $this->assertFalse($endpoint->active);
        $this->assertNotNull($endpoint->disabled_at);

        $this->ticket('shelter-rescue');
        $this->assertSame(DeliverWebhook::DISABLE_AFTER_FAILED_DELIVERIES, WebhookDelivery::query()->count());
    }

    public function test_replay_sends_the_same_event_id(): void
    {
        Http::fake(['easm.example.org/*' => Http::response('', 200)]);
        $this->endpoint('shelter-rescue', [WebhookEvents::TICKET_CREATED]);
        $this->ticket('shelter-rescue');
        $original = WebhookDelivery::query()->sole();

        $this->actingAs($this->admin)->post(route('admin.settings.webhooks.replay', $original))->assertRedirect(route('admin.settings.show', 'webhooks'));

        $replay = WebhookDelivery::query()->latest('id')->first();
        $this->assertNotSame($original->id, $replay->id);
        $this->assertSame($original->event_id, $replay->event_id);
        $this->assertSame($original->payload, $replay->payload);
        $this->assertSame(WebhookDelivery::SUCCEEDED, $replay->status);

        $sent = Http::recorded()->map(fn (array $pair) => $pair[0]->header(WebhookSignature::DELIVERY_HEADER)[0])->all();
        $this->assertSame([$original->event_id, $original->event_id], $sent);
        $this->assertDatabaseHas('audit_logs', ['event' => 'admin.webhooks.replayed']);
    }

    /**
     * @param  list<string>  $events
     */
    private function endpoint(string $organisation, array $events, string $url = self::URL): WebhookEndpoint
    {
        return WebhookEndpoint::query()->create(['name' => 'Test', 'url' => $url, 'organisation' => $organisation, 'events' => $events, 'secret' => self::SECRET, 'active' => true]);
    }

    private function ticket(string $organisation): SupportTicket
    {
        $owner = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create();

        return SupportTicket::query()->create([
            'sub_core_key' => $organisation,
            'user_id' => $owner->id,
            'service_area' => 'general',
            'subject' => 'Private subject line',
            'priority' => 'medium',
            'status' => 'open',
            'description' => '<p>Details</p>',
        ]);
    }
}
