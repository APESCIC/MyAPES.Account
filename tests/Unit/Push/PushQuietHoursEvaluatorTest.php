<?php

namespace Tests\Unit\Push;

use App\Core\Accounts\User;
use App\Core\Push\PushQuietHoursEvaluator;
use App\Core\Push\UserNotificationSetting;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PushQuietHoursEvaluatorTest extends TestCase
{
    use RefreshDatabase;

    private PushQuietHoursEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->evaluator = app(PushQuietHoursEvaluator::class);
    }

    public function test_quiet_hours_span_midnight_in_winter_gmt(): void
    {
        $user = User::factory()->create();
        UserNotificationSetting::query()->create([
            'user_id' => $user->id,
            'quiet_hours_enabled' => true,
            'quiet_hours_start' => '22:00:00',
            'quiet_hours_end' => '07:00:00',
            'quiet_hours_timezone' => 'Europe/London',
        ]);

        $user = $user->fresh(['notificationSetting']);

        $this->assertTrue($this->evaluator->isQuietForUser(
            $user,
            CarbonImmutable::parse('2026-01-15 23:30:00', 'Europe/London'),
        ));
        $this->assertTrue($this->evaluator->isQuietForUser(
            $user,
            CarbonImmutable::parse('2026-01-16 06:30:00', 'Europe/London'),
        ));
        $this->assertFalse($this->evaluator->isQuietForUser(
            $user,
            CarbonImmutable::parse('2026-01-16 12:00:00', 'Europe/London'),
        ));
    }

    public function test_quiet_hours_use_bst_wall_clock_times(): void
    {
        $user = User::factory()->create();
        UserNotificationSetting::query()->create([
            'user_id' => $user->id,
            'quiet_hours_enabled' => true,
            'quiet_hours_start' => '22:00:00',
            'quiet_hours_end' => '07:00:00',
            'quiet_hours_timezone' => 'Europe/London',
        ]);

        $user = $user->fresh(['notificationSetting']);

        $summerEvening = CarbonImmutable::parse('2026-07-15 23:15:00', 'Europe/London');
        $this->assertTrue($this->evaluator->isQuietForUser($user, $summerEvening));
        $this->assertSame('BST', $summerEvening->format('T'));
    }

    public function test_quiet_hours_off_when_disabled(): void
    {
        $user = User::factory()->create();
        UserNotificationSetting::query()->create([
            'user_id' => $user->id,
            'quiet_hours_enabled' => false,
            'quiet_hours_start' => '22:00:00',
            'quiet_hours_end' => '07:00:00',
        ]);

        $user = $user->fresh(['notificationSetting']);

        $this->assertFalse($this->evaluator->isQuietForUser(
            $user,
            CarbonImmutable::parse('2026-01-15 23:30:00', 'Europe/London'),
        ));
    }
}
