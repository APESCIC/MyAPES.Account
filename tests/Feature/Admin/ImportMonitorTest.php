<?php

namespace Tests\Feature\Admin;

use App\Core\Accounts\User;
use App\Core\Mail\Inbound\ImportMonitor;
use App\Core\Mail\Inbound\InboundEmail;
use App\Core\Mail\Inbound\InboundMailboxLogEntry;
use App\Core\Mail\Inbound\InboundMailPoller;
use App\Core\Mail\Inbound\ReplyMailboxStatus;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Plugins\Tickets\Models\SupportTicket;
use Tests\Feature\Mail\Inbound\InboundMailTestHelpers;
use Tests\TestCase;

/**
 * Import monitor on Admin → Emails → Import (#813).
 */
class ImportMonitorTest extends TestCase
{
    use InboundMailTestHelpers;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
        $this->enableEmailReplies();
        $this->admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();
    }

    public function test_health_strip_shows_each_of_the_four_states(): void
    {
        $status = app(ReplyMailboxStatus::class);

        config(['inbound-mail.enabled' => false]);
        $this->actingAs($this->admin)->get(route('admin.emails.import.index'))
            ->assertOk()
            ->assertSee('data-import-health="off"', false)
            ->assertSee(__('admin.emails.import.monitor.health.off'));

        config(['inbound-mail.enabled' => true]);
        $status->recordPoll([
            'result' => InboundMailPoller::RESULT_POLLED,
            'fetched' => 0,
            'stored' => 0,
            'duplicates' => 0,
            'failed' => 0,
        ]);
        $this->actingAs($this->admin)->get(route('admin.emails.import.index'))
            ->assertSee('data-import-health="working"', false)
            ->assertSee(__('admin.emails.import.monitor.health.working'));

        $status->recordPoll([
            'result' => InboundMailPoller::RESULT_UNAVAILABLE,
            'failure' => 'login_failed',
            'fetched' => 0,
            'stored' => 0,
            'duplicates' => 0,
            'failed' => 0,
        ]);
        $this->actingAs($this->admin)->get(route('admin.emails.import.index'))
            ->assertSee('data-import-health="backing_off"', false)
            ->assertSee(__('admin.emails.import.monitor.health.backing_off'))
            ->assertSee('data-health-failures', false)
            ->assertSee('>1</dd>', false);

        $this->assertSame(1, $status->consecutiveFailures());

        $this->travel(2)->minutes();
        $this->actingAs($this->admin)->get(route('admin.emails.import.index'))
            ->assertSee('data-import-health="failing"', false)
            ->assertSee(__('admin.emails.import.monitor.health.failing'));
    }

    public function test_counts_match_seeded_inbound_emails_for_both_windows(): void
    {
        $this->seedEmail(InboundEmail::STATUS_POSTED, now()->subHours(2));
        $this->seedEmail(InboundEmail::STATUS_QUARANTINED, now()->subHours(3));
        $this->seedEmail(InboundEmail::STATUS_REJECTED, now()->subHours(4));
        $this->seedEmail(InboundEmail::STATUS_IGNORED, now()->subHours(5));
        $this->seedEmail(InboundEmail::STATUS_FAILED, now()->subHours(6));
        $this->seedEmail(InboundEmail::STATUS_POSTED, now()->subDays(3));
        $this->seedEmail(InboundEmail::STATUS_QUARANTINED, now()->subDays(4));
        $this->seedEmail(InboundEmail::STATUS_POSTED, now()->subDays(10));

        $now = now();
        InboundMailboxLogEntry::query()->insert([
            [
                'kind' => InboundMailboxLogEntry::KIND_POLL,
                'result' => 'polled',
                'duration_ms' => 10,
                'fetched' => 2,
                'stored' => 1,
                'duplicates' => 3,
                'failed' => 0,
                'created_at' => $now->copy()->subHours(1)->toDateTimeString(),
            ],
            [
                'kind' => InboundMailboxLogEntry::KIND_POLL,
                'result' => 'polled',
                'duration_ms' => 10,
                'fetched' => 1,
                'stored' => 1,
                'duplicates' => 2,
                'failed' => 0,
                'created_at' => $now->copy()->subDays(3)->toDateTimeString(),
            ],
        ]);

        $page = $this->actingAs($this->admin)->get(route('admin.emails.import.index'))->assertOk();

        $page->assertSee('data-count-window="hours_24"', false)
            ->assertSee('data-count-window="days_7"', false);

        $monitor = app(ImportMonitor::class);
        $this->assertSame([
            'fetched' => 5,
            'posted' => 1,
            'held' => 1,
            'rejected' => 1,
            'ignored' => 1,
            'failed' => 1,
            'duplicates' => 3,
        ], $monitor->countsSince(now()->subDay()));
        $this->assertSame([
            'fetched' => 7,
            'posted' => 2,
            'held' => 2,
            'rejected' => 1,
            'ignored' => 1,
            'failed' => 1,
            'duplicates' => 5,
        ], $monitor->countsSince(now()->subDays(7)));
    }

    public function test_recent_imports_link_to_the_record_and_never_show_a_body(): void
    {
        $owner = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create([
            'name' => 'Client Owner',
            'email' => 'client-owner@example.test',
        ]);
        $ticket = $this->ticketFor($owner);
        $kept = $this->seedEmail(InboundEmail::STATUS_POSTED, now()->subMinute(), [
            'user_id' => $owner->id,
            'from_address' => $owner->email,
            'from_domain' => 'example.test',
            'subject' => 'Kept subject line',
            'raw_path' => 'inbound-mail/kept.eml',
            'raw_pruned_at' => null,
            'record_prefix' => 'T',
            'record_id' => $ticket->id,
            'posted_type' => SupportTicket::class,
            'posted_id' => $ticket->id,
        ]);
        $pruned = $this->seedEmail(InboundEmail::STATUS_POSTED, now()->subMinutes(2), [
            'subject' => 'Pruned subject must hide',
            'raw_path' => null,
            'raw_pruned_at' => now()->subDay(),
            'from_domain' => 'pruned.example.test',
        ]);

        $response = $this->actingAs($this->admin)->get(route('admin.emails.import.index'))->assertOk();
        $html = (string) $response->getContent();

        $this->assertStringContainsString('data-import-row="'.$kept->id.'"', $html);
        $this->assertStringContainsString('data-import-record-link', $html);
        $this->assertStringContainsString('Kept subject line', $html);
        $this->assertStringContainsString((string) route('apes-cic.tickets.show', $ticket), $html);
        $this->assertStringContainsString('data-import-row="'.$pruned->id.'"', $html);
        $this->assertStringNotContainsString('Pruned subject must hide', $html);
        $this->assertStringNotContainsString('The boiler in the shelter office is broken.', $html);
        $this->assertDoesNotMatchRegularExpression('/data-import-body|message body|raw message/i', $html);
    }

    public function test_the_monitor_page_stays_lean_with_ten_thousand_rows(): void
    {
        $now = now();
        $rows = [];
        for ($i = 0; $i < 10000; $i++) {
            $rows[] = [
                'message_id_hash' => hash('sha256', 'monitor-bulk-'.$i),
                'message_id' => '<monitor-'.$i.'@example.test>',
                'source' => 'imap',
                'from_domain' => 'example.test',
                'subject' => 'Bulk '.$i,
                'status' => $i % 3 === 0
                    ? InboundEmail::STATUS_POSTED
                    : ($i % 3 === 1 ? InboundEmail::STATUS_QUARANTINED : InboundEmail::STATUS_FAILED),
                'raw_size' => 0,
                'attachments_stored' => 0,
                'attachments_rejected' => 0,
                'attempts' => 0,
                'notice_sent' => false,
                'received_at' => $now->copy()->subMinutes($i % 200)->toDateTimeString(),
                'created_at' => $now->toDateTimeString(),
                'updated_at' => $now->toDateTimeString(),
            ];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            InboundEmail::query()->insert($chunk);
        }

        // Warm caches and compiled views so the timed request reflects query work.
        $this->actingAs($this->admin)->get(route('admin.emails.import.index'))->assertOk();

        $queries = 0;
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            if (Str::contains($query->sql, ['inbound_emails', 'inbound_mailbox_log'], ignoreCase: true)) {
                $queries++;
            }
        });

        $started = hrtime(true);
        $this->actingAs($this->admin)->get(route('admin.emails.import.index'))->assertOk()
            ->assertSee('data-import-monitor', false);
        $elapsedMs = (hrtime(true) - $started) / 1_000_000;

        $this->assertLessThanOrEqual(15, $queries, "Expected a lean query count, got {$queries}");
        // Wall-clock varies by host; the durable AC check is the query budget above.
        $this->assertLessThan(1000, $elapsedMs, "Expected a warm page load under 1s, got {$elapsedMs} ms");
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seedEmail(string $status, $receivedAt, array $overrides = []): InboundEmail
    {
        return InboundEmail::query()->create([
            'message_id_hash' => hash('sha256', Str::uuid()->toString()),
            'message_id' => '<'.Str::uuid().'@example.test>',
            'source' => 'imap',
            'from_address' => 'sender@example.test',
            'from_domain' => 'example.test',
            'subject' => 'Monitor seed',
            'status' => $status,
            'reason' => $status === InboundEmail::STATUS_QUARANTINED ? 'unmatched' : null,
            'received_at' => $receivedAt,
            ...$overrides,
        ]);
    }
}
