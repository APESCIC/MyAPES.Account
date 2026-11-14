<?php

namespace Tests\Feature\Admin;

use App\Core\Accounts\AuditLog;
use App\Core\Accounts\Permission;
use App\Core\Accounts\Role;
use App\Core\Accounts\RoleSource;
use App\Core\Accounts\User;
use App\Core\Mail\Inbound\InboundEmail;
use App\Jobs\ProcessInboundEmail;
use App\Services\AuthorizationProfile;
use App\Services\AuthorizationRoleMaterializer;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Mail\Inbound\InboundMailTestHelpers;
use Tests\TestCase;

/**
 * Held/failed import queue, retry-all and menu badge (#814).
 */
class ImportQueueTest extends TestCase
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

    public function test_held_unmatched_and_failed_items_appear_with_reason_and_age(): void
    {
        $held = $this->seedQueue(InboundEmail::STATUS_QUARANTINED, 'unmatched', now()->subHours(3));
        $failed = $this->seedQueue(InboundEmail::STATUS_FAILED, 'unparseable', now()->subDay());
        $this->seedQueue(InboundEmail::STATUS_POSTED, null, now()->subMinutes(5));

        $this->actingAs($this->admin)->get(route('admin.emails.import.index'))
            ->assertOk()
            ->assertSee('data-import-queue', false)
            ->assertSee('data-import-queue-row="'.$held->id.'"', false)
            ->assertSee('data-import-queue-row="'.$failed->id.'"', false)
            ->assertSee(__('admin.emails.inbound.reasons.unmatched'))
            ->assertSee(__('admin.emails.inbound.reasons.unparseable'))
            ->assertSee('data-import-queue-age', false)
            ->assertDontSee('data-import-queue-row="'.($held->id + 2).'"', false);
    }

    public function test_retry_all_failed_retries_only_failed_items_and_is_audited_once(): void
    {
        Queue::fake();

        $failedA = $this->seedQueue(InboundEmail::STATUS_FAILED, 'unparseable', now()->subHour());
        $failedB = $this->seedQueue(InboundEmail::STATUS_FAILED, 'raw_missing', now()->subHour(), raw: false);
        $held = $this->seedQueue(InboundEmail::STATUS_QUARANTINED, 'unmatched', now()->subHour());
        $failedReady = $this->seedQueue(InboundEmail::STATUS_FAILED, 'unparseable', now()->subMinutes(30));

        $this->actingAs($this->admin)
            ->post(route('admin.emails.import.retry-failed'), ['confirm_retry_failed' => '1'])
            ->assertRedirect(route('admin.emails.import.index'))
            ->assertSessionHas('status', __('admin.emails.import.queue.retry_failed_done', ['count' => 2]));

        $this->assertSame(InboundEmail::STATUS_RECEIVED, $failedA->fresh()->status);
        $this->assertSame(InboundEmail::STATUS_RECEIVED, $failedReady->fresh()->status);
        $this->assertSame(InboundEmail::STATUS_FAILED, $failedB->fresh()->status);
        $this->assertSame(InboundEmail::STATUS_QUARANTINED, $held->fresh()->status);

        Queue::assertPushed(ProcessInboundEmail::class, 2);

        $audit = AuditLog::query()->where('event', 'admin.emails.import_retry_failed')->sole();
        $this->assertSame(['count' => 2], $audit->context);
        $this->assertSame($this->admin->id, $audit->user_id);
        $this->assertSame(1, AuditLog::query()->where('event', 'admin.emails.import_retry_failed')->count());
    }

    public function test_the_menu_badge_shows_held_and_failed_and_hides_at_zero(): void
    {
        $this->actingAs($this->admin)->get(route('admin.index'))
            ->assertOk()
            ->assertDontSee('data-nav-badge="admin.emails.import"', false);

        $this->seedQueue(InboundEmail::STATUS_QUARANTINED, 'unmatched', now()->subHour());
        $this->seedQueue(InboundEmail::STATUS_FAILED, 'unparseable', now()->subHour());

        $this->actingAs($this->admin)->get(route('admin.index'))
            ->assertOk()
            ->assertSee('data-nav-badge="admin.emails.import"', false)
            ->assertSee('>2</span>', false);

        $this->actingAs($this->admin)->get(route('admin.emails.inbound.index'))
            ->assertSee('data-import-tab-badge', false)
            ->assertSee('>2</span>', false);
    }

    public function test_actions_need_manage_while_viewers_see_the_queue_read_only(): void
    {
        $viewer = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();
        $role = Role::query()->create(['name' => 'import-queue-viewer', 'guard_name' => 'web']);
        $role->permissions()->attach(
            Permission::query()
                ->whereIn('name', ['admin.access', 'admin.emails.view'])
                ->where('guard_name', 'web')
                ->pluck('id'),
        );
        app(AuthorizationRoleMaterializer::class)->grant($viewer, $role, RoleSource::SOURCE_LOCAL, actor: $this->admin);

        $held = $this->seedQueue(InboundEmail::STATUS_QUARANTINED, 'unmatched', now()->subHour());
        $failed = $this->seedQueue(InboundEmail::STATUS_FAILED, 'unparseable', now()->subHour());

        $this->actingAs($viewer->fresh())->get(route('admin.emails.import.index'))
            ->assertOk()
            ->assertSee('data-import-queue-row="'.$held->id.'"', false)
            ->assertSee('data-import-queue-readonly', false)
            ->assertDontSee('data-import-retry-failed', false)
            ->assertDontSee(route('admin.emails.inbound.retry', $held), false);

        $this->actingAs($viewer->fresh())
            ->post(route('admin.emails.import.retry-failed'), ['confirm_retry_failed' => '1'])
            ->assertForbidden();

        $this->actingAs($viewer->fresh())
            ->post(route('admin.emails.inbound.retry', $failed))
            ->assertForbidden();

        $this->actingAs($this->admin)->get(route('admin.emails.import.index'))
            ->assertOk()
            ->assertSee('data-import-retry-failed', false)
            ->assertDontSee('data-import-queue-readonly', false);
    }

    private function seedQueue(string $status, ?string $reason, $receivedAt, bool $raw = true): InboundEmail
    {
        return InboundEmail::query()->create([
            'message_id_hash' => hash('sha256', Str::uuid()->toString()),
            'message_id' => '<'.Str::uuid().'@example.test>',
            'source' => 'imap',
            'from_address' => 'queue@example.test',
            'from_domain' => 'example.test',
            'subject' => 'Queue seed '.$status,
            'status' => $status,
            'reason' => $reason,
            'raw_path' => $raw ? 'inbound-mail/queue-'.Str::uuid().'.eml' : null,
            'raw_pruned_at' => $raw ? null : now()->subDay(),
            'received_at' => $receivedAt,
        ]);
    }
}
