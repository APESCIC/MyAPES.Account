<?php

namespace Tests\Feature\Mail\Inbound;

use App\Core\Accounts\User;
use App\Core\Mail\Inbound\InboundEmail;
use App\Core\Mail\Inbound\InboundMailStore;
use App\Core\Mail\Inbound\InboundReplySetting;
use App\Jobs\ProcessInboundEmail;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugins\Tickets\Models\SupportTicketMessage;
use Plugins\Tickets\Notifications\TicketUpdatedNotification;
use Tests\TestCase;

/**
 * Admin → Emails → Inbound and the per-service switch (#585).
 */
class AdminInboundEmailTest extends TestCase
{
    use InboundMailTestHelpers;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
        Storage::fake('private');
        $this->enableEmailReplies();
        $this->admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();
    }

    /**
     * @return array<string, array{string|null, int}>
     */
    public static function viewers(): array
    {
        return [
            'guest' => [null, 302],
            'service user' => [AuthorizationProfile::ROLE_SERVICE_USER, 403],
            'staff' => [AuthorizationProfile::ROLE_STAFF, 403],
            'administrator' => [AuthorizationProfile::ROLE_ADMINISTRATOR, 200],
        ];
    }

    #[DataProvider('viewers')]
    public function test_only_email_admins_see_the_inbound_tab(?string $role, int $status): void
    {
        $email = $this->quarantined();

        if ($role !== null) {
            $this->actingAs(User::factory()->protectedRole($role)->create());
        }

        $this->get(route('admin.emails.inbound.index'))->assertStatus($status);
        $this->get(route('admin.emails.inbound.show', $email))->assertStatus($status);

        if ($role !== AuthorizationProfile::ROLE_ADMINISTRATOR) {
            $this->post(route('admin.emails.inbound.retry', $email))->assertStatus($status);
            $this->put(route('admin.emails.inbound.services.update'), ['services' => ['apes-cic|tickets' => '0']])->assertStatus($status);
        }
    }

    public function test_the_list_shows_status_and_reason_and_filters_by_status(): void
    {
        $email = $this->quarantined();

        $this->actingAs($this->admin)
            ->get(route('admin.emails.inbound.index'))
            ->assertOk()
            ->assertSee('data-inbound-email="'.$email->id.'"', false)
            ->assertSee(__('admin.emails.inbound.statuses.quarantined'))
            ->assertSee(__('admin.emails.inbound.reasons.unknown_sender'))
            ->assertSee(__('admin.emails.tabs.inbound'));

        $this->actingAs($this->admin)
            ->get(route('admin.emails.inbound.index', ['status' => 'posted']))
            ->assertOk()
            ->assertDontSee('data-inbound-email="'.$email->id.'"', false);

        $this->actingAs($this->admin)
            ->get(route('admin.emails.inbound.show', $email))
            ->assertOk()
            ->assertSee('data-inbound-actions', false);
    }

    public function test_retry_requeues_and_is_audited(): void
    {
        $email = $this->quarantined();
        Queue::fake();

        $this->actingAs($this->admin)
            ->post(route('admin.emails.inbound.retry', $email))
            ->assertRedirect(route('admin.emails.inbound.show', $email));

        $this->assertSame(InboundEmail::STATUS_RECEIVED, $email->fresh()->status);
        Queue::assertPushed(ProcessInboundEmail::class, fn (ProcessInboundEmail $job): bool => $job->inboundEmailId === $email->id);
        $this->assertDatabaseHas('audit_logs', ['event' => 'admin.emails.inbound_retried', 'user_id' => $this->admin->id]);
    }

    public function test_discard_needs_confirmation_deletes_the_message_and_is_audited(): void
    {
        $email = $this->quarantined();
        $path = (string) $email->raw_path;

        $this->actingAs($this->admin)
            ->post(route('admin.emails.inbound.discard', $email))
            ->assertSessionHasErrors('confirm_discard');

        $this->actingAs($this->admin)
            ->post(route('admin.emails.inbound.discard', $email), ['confirm_discard' => '1'])
            ->assertRedirect(route('admin.emails.inbound.show', $email));

        $email->refresh();
        $this->assertSame(InboundEmail::STATUS_DISCARDED, $email->status);
        $this->assertNull($email->raw_path);
        Storage::disk('private')->assertMissing($path);
        $this->assertDatabaseHas('audit_logs', ['event' => 'admin.emails.inbound_discarded', 'user_id' => $this->admin->id]);

        $this->actingAs($this->admin)
            ->post(route('admin.emails.inbound.retry', $email))
            ->assertSessionHasErrors('inbound');
    }

    public function test_switching_a_service_off_stops_posting_and_outgoing_tokens(): void
    {
        Notification::fake();
        $owner = User::factory()->create(['email' => 'owner@example.org']);
        $ticket = $this->ticketFor($owner);

        $this->actingAs($this->admin)
            ->get(route('admin.emails.inbound.index'))
            ->assertSee('name="services[apes-cic|tickets]"', false)
            ->assertSee('name="services[shelter-rescue|cases]"', false);

        $this->actingAs($this->admin)
            ->put(route('admin.emails.inbound.services.update'), ['services' => ['apes-cic|tickets' => '0', 'apes-cic|cases' => '1']])
            ->assertRedirect(route('admin.emails.inbound.index'));

        $this->assertFalse(InboundReplySetting::query()->where(['sub_core_key' => 'apes-cic', 'plugin' => 'tickets'])->sole()->enabled);
        $this->assertDatabaseHas('audit_logs', ['event' => 'admin.emails.inbound_service_changed', 'user_id' => $this->admin->id]);
        $this->assertDatabaseCount('inbound_reply_settings', 1);

        $email = app(InboundMailStore::class)->store($this->fixture('plain-reply', [
            'TO' => $this->replyAddressFor('T', $ticket->id, $owner),
        ]))?->fresh();
        $this->assertSame([InboundEmail::STATUS_QUARANTINED, 'service_off'], [$email?->status, $email?->reason]);
        $this->assertSame(0, SupportTicketMessage::query()->count());

        $mail = (new TicketUpdatedNotification($ticket, $this->admin, 'updated', 'apes-cic', 'apes-cic.tickets.show', 'APES CIC'))->toMail($owner);
        $this->assertArrayNotHasKey('replyByEmail', $mail->viewData);

        $this->actingAs($this->admin)
            ->put(route('admin.emails.inbound.services.update'), ['services' => ['apes-cic|tickets' => '1']]);
        $this->assertTrue(InboundReplySetting::query()->where(['sub_core_key' => 'apes-cic', 'plugin' => 'tickets'])->sole()->enabled);
    }

    private function quarantined(): InboundEmail
    {
        Queue::fake();
        $email = app(InboundMailStore::class)->store($this->fixture('plain-reply', ['FROM' => 'stranger@example.org', 'SUBJECT' => 'Re: [MYAPES-T-1]']));
        $this->assertNotNull($email);
        $email->forceFill(['status' => InboundEmail::STATUS_QUARANTINED, 'reason' => 'unknown_sender', 'processed_at' => now()])->save();

        return $email->fresh();
    }
}
