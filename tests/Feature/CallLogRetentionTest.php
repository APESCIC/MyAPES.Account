<?php

namespace Tests\Feature;

use App\Core\Accounts\AuditLog;
use App\Core\Accounts\CallLog;
use App\Core\Accounts\User;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Call log retention (#480): logs older than 24 months are purged daily.
 */
class CallLogRetentionTest extends TestCase
{
    use RefreshDatabase;

    /** @var array{0: User, 1: User}|null */
    private ?array $people = null;

    public function test_logs_older_than_the_retention_period_are_removed(): void
    {
        $old = $this->log(now()->subMonths(25), 'Old call');
        $edge = $this->log(now()->subMonths(24)->addDay(), 'Recent enough');
        $new = $this->log(now()->subDay(), 'Yesterday');

        $this->artisan('call-logs:prune')
            ->expectsOutputToContain('Deleted 1 call log record(s) older than 24 months.')
            ->assertSuccessful();

        $this->assertFalse(CallLog::query()->whereKey($old->id)->exists());
        $this->assertTrue(CallLog::query()->whereKey($edge->id)->exists());
        $this->assertTrue(CallLog::query()->whereKey($new->id)->exists());

        $audit = AuditLog::query()->where('event', 'call_logs.pruned')->sole();
        $this->assertSame(1, $audit->context['deleted']);
        $this->assertSame(24, $audit->context['retention_months']);
    }

    public function test_the_period_follows_config_and_the_months_option(): void
    {
        $this->log(now()->subMonths(13), 'Thirteen months');
        config(['myapes.call_logs.retention_months' => 12]);

        $this->artisan('call-logs:prune')->assertSuccessful();
        $this->assertSame(0, CallLog::query()->count());

        $this->log(now()->subMonths(3), 'Three months');
        $this->artisan('call-logs:prune', ['--months' => 6])->assertSuccessful();
        $this->assertSame(1, CallLog::query()->count());

        $this->artisan('call-logs:prune', ['--months' => 0])->assertFailed();
        $this->assertSame(1, CallLog::query()->count());
    }

    public function test_the_purge_is_scheduled_daily(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(static fn (Event $event): bool => str_contains((string) $event->command, 'call-logs:prune'));

        $this->assertCount(1, $events);
        $this->assertSame('0 0 * * *', $events->first()->expression);
    }

    private function log(\DateTimeInterface $calledAt, string $summary): CallLog
    {
        $this->people ??= [
            User::factory()->accessLevel(User::ROLE_SERVICE_USER)->create(),
            User::factory()->protectedRole('staff')->create(),
        ];

        return CallLog::query()->create([
            'caller_id' => $this->people[0]->id,
            'staff_id' => $this->people[1]->id,
            'direction' => 'inbound',
            'summary' => $summary,
            'called_at' => $calledAt,
        ]);
    }
}
