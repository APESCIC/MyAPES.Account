<?php

namespace App\Core\Push;

use App\Core\Accounts\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use NotificationChannels\WebPush\PushSubscription;

/**
 * Count-only push stats for Admin → Settings → Push notifications (#803).
 */
final class PushAdminStats
{
    public function __construct(private readonly PushRoleResolver $roles) {}

    /**
     * @return array<string, int>
     */
    public function activeDevicesByRole(): array
    {
        $counts = array_fill_keys(PushRoleResolver::catalogueRoles(), 0);

        $subscriptionCounts = PushSubscription::query()
            ->select('subscribable_id', DB::raw('count(*) as total'))
            ->where('subscribable_type', (new User)->getMorphClass())
            ->groupBy('subscribable_id')
            ->pluck('total', 'subscribable_id');

        if ($subscriptionCounts->isEmpty()) {
            return $counts;
        }

        $users = User::query()->whereIn('id', $subscriptionCounts->keys())->get();

        foreach ($users as $user) {
            $role = $this->roles->forUser($user);
            if ($role === null) {
                continue;
            }

            $counts[$role] += (int) ($subscriptionCounts[$user->id] ?? 0);
        }

        return $counts;
    }

    /**
     * @return array<string, array{sent: int, failed: int, expired: int}>
     */
    public function deliveryTotalsLast7Days(): array
    {
        $since = Carbon::now()->subDays(7);

        $rows = PushDelivery::query()
            ->selectRaw('notification_type, status, count(*) as total')
            ->where('created_at', '>=', $since)
            ->groupBy('notification_type', 'status')
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $type = (string) $row->notification_type;
            $totals[$type] ??= [
                PushDelivery::STATUS_SENT => 0,
                PushDelivery::STATUS_FAILED => 0,
                PushDelivery::STATUS_EXPIRED => 0,
            ];
            $status = (string) $row->status;
            if (isset($totals[$type][$status])) {
                $totals[$type][$status] = (int) $row->total;
            }
        }

        $formatted = [];
        foreach ($totals as $type => $statuses) {
            $formatted[$type] = [
                'sent' => $statuses[PushDelivery::STATUS_SENT],
                'failed' => $statuses[PushDelivery::STATUS_FAILED],
                'expired' => $statuses[PushDelivery::STATUS_EXPIRED],
            ];
        }

        ksort($formatted);

        return $formatted;
    }
}