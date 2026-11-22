<?php

namespace App\Core\Push;

use App\Core\Accounts\User;
use Carbon\CarbonImmutable;
use DateTimeZone;

/**
 * Decides whether push should be skipped for quiet hours (#795).
 */
final class PushQuietHoursEvaluator
{
    public function isQuietForUser(User $user, ?CarbonImmutable $instant = null): bool
    {
        $settings = $user->notificationSetting;
        if ($settings === null || ! $settings->quiet_hours_enabled) {
            return false;
        }

        if ($settings->quiet_hours_start === null || $settings->quiet_hours_end === null) {
            return false;
        }

        $timezone = $settings->quiet_hours_timezone !== ''
            ? $settings->quiet_hours_timezone
            : 'Europe/London';

        try {
            $tz = new DateTimeZone($timezone);
        } catch (\Exception) {
            $tz = new DateTimeZone('Europe/London');
        }

        $now = ($instant ?? CarbonImmutable::now())->setTimezone($tz);
        $start = $this->timeOnDate($settings->quiet_hours_start, $now, $tz);
        $end = $this->timeOnDate($settings->quiet_hours_end, $now, $tz);

        if ($start->equalTo($end)) {
            return false;
        }

        if ($start->lessThan($end)) {
            return $now->greaterThanOrEqualTo($start) && $now->lessThan($end);
        }

        return $now->greaterThanOrEqualTo($start) || $now->lessThan($end);
    }

    private function timeOnDate(string $time, CarbonImmutable $date, DateTimeZone $tz): CarbonImmutable
    {
        $parts = explode(':', $time);
        $hour = (int) ($parts[0] ?? 0);
        $minute = (int) ($parts[1] ?? 0);
        $second = (int) ($parts[2] ?? 0);

        return CarbonImmutable::create(
            $date->year,
            $date->month,
            $date->day,
            $hour,
            $minute,
            $second,
            $tz,
        );
    }
}
