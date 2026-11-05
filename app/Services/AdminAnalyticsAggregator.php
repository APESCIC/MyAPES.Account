<?php

namespace App\Services;

use App\Contracts\ModuleAnalyticsProvider;
use App\Contracts\ModuleRegistry;
use App\Core\Accounts\AccountClassifier;
use App\Core\Accounts\AuditLog;
use App\Core\Accounts\User;
use App\Core\Extensions\Models\ModuleInstallation;
use App\Modules\ModuleCodeStatus;
use App\Support\ReleaseHistoryRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class AdminAnalyticsAggregator
{
    public const RANGES = [7, 30, 90];

    public const DEFAULT_RANGE = 30;

    public const CACHE_TTL_SECONDS = 60;

    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly ModuleAdministrationCatalogue $catalogue,
        private readonly MaintenanceLifecycleManager $maintenance,
        private readonly ModuleProjectionCache $projections,
        private readonly ReleaseHistoryRepository $releases,
        private readonly AuthorizationProfile $authorization,
        private readonly AccountClassifier $accounts,
    ) {}

    public function normalizeRange(mixed $range): int
    {
        $value = is_numeric($range) ? (int) $range : self::DEFAULT_RANGE;

        return in_array($value, self::RANGES, true)
            ? $value
            : self::DEFAULT_RANGE;
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboard(int $range): array
    {
        $range = $this->normalizeRange($range);
        $timezone = (string) config('app.timezone');
        $cacheKey = $this->cacheKey($range, $timezone);

        return Cache::remember(
            $cacheKey,
            now()->addSeconds(self::CACHE_TTL_SECONDS),
            fn (): array => $this->build($range, $timezone),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function build(int $range, string $timezone): array
    {
        $to = Carbon::now($timezone)->startOfDay()->addDay();
        $from = $to->copy()->subDays($range);
        $days = [];
        for ($cursor = $from->copy(); $cursor->lt($to); $cursor->addDay()) {
            $days[] = $cursor->toDateString();
        }

        $createdPerDay = array_fill_keys($days, 0);
        $closedPerDay = array_fill_keys($days, 0);
        $byInstance = [];
        $closureMinutes = [];
        $open = 0;
        $highOrUrgent = 0;
        $unassigned = 0;

        foreach ($this->registry->shippedInstances() as $instance) {
            $providerClass = $instance->analyticsProviderClass();
            if ($providerClass === null) {
                continue;
            }

            /** @var ModuleAnalyticsProvider $provider */
            $provider = app($providerClass);
            $snapshot = $provider->snapshot($instance, $from, $to, $timezone);
            $open += $snapshot->currentlyOpen;
            $highOrUrgent += $snapshot->currentlyHighOrUrgent;
            $unassigned += $snapshot->currentlyUnassigned;
            $closureMinutes = [...$closureMinutes, ...$snapshot->closureMinutes];
            $byInstance[] = [
                'key' => $snapshot->instanceKey,
                'sub_core' => $instance->subCore->name,
                'module' => $instance->module->name,
                'open' => $snapshot->currentlyOpen,
                'high_or_urgent' => $snapshot->currentlyHighOrUrgent,
                'unassigned' => $snapshot->currentlyUnassigned,
            ];

            foreach ($snapshot->createdPerDay as $day => $count) {
                if (array_key_exists($day, $createdPerDay)) {
                    $createdPerDay[$day] += $count;
                }
            }
            foreach ($snapshot->closedPerDay as $day => $count) {
                if (array_key_exists($day, $closedPerDay)) {
                    $closedPerDay[$day] += $count;
                }
            }
        }

        $installations = ModuleInstallation::query()->get();
        $maintenance = $this->maintenance->status();
        $currentWindow = $maintenance['current'];

        return [
            'range' => $range,
            'timezone' => $timezone,
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'accounts' => $this->accountMetrics($from, $to),
            'modules' => [
                'installed' => $installations->count(),
                'enabled' => $installations->where('enabled', true)->count(),
            ],
            'workload' => [
                'open' => $open,
                'high_or_urgent' => $highOrUrgent,
                'unassigned' => $unassigned,
                'median_closure_minutes' => $this->median($closureMinutes),
                'closure_sample_size' => count($closureMinutes),
                'by_instance' => $byInstance,
                'days' => $days,
                'created_per_day' => array_values($createdPerDay),
                'closed_per_day' => array_values($closedPerDay),
            ],
            'maintenance' => [
                'active' => $maintenance['active'],
                'message' => is_object($currentWindow) ? (string) $currentWindow->message : null,
            ],
            'module_alerts' => $alerts = $this->moduleAlerts(),
            'module_alerts_action' => array_values(array_filter($alerts, static fn (array $alert): bool => $alert['severity'] === 'action')),
            'module_alerts_info' => array_values(array_filter($alerts, static fn (array $alert): bool => $alert['severity'] !== 'action')),
            'privileged_events' => $this->privilegedEvents(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function accountMetrics(Carbon $from, Carbon $to): array
    {
        $identityTypes = [
            User::IDENTITY_LOCAL => 0,
            User::IDENTITY_CLOUDRON_OIDC => 0,
            User::IDENTITY_HYBRID => 0,
        ];
        foreach (User::query()->select('identity_type')->get() as $user) {
            $type = (string) $user->identity_type;
            if (array_key_exists($type, $identityTypes)) {
                $identityTypes[$type]++;
            }
        }

        $accessClasses = [];
        foreach ($this->authorization->protectedRolesByPrecedence() as $role) {
            $accessClasses[$role] = 0;
        }
        foreach (User::query()->with('roles')->get() as $user) {
            $role = $this->authorization->effectiveProtectedRole($user);
            if ($role !== null && array_key_exists($role, $accessClasses)) {
                $accessClasses[$role]++;
            }
        }

        return [
            'total' => User::query()->count(),
            'created_in_range' => User::query()
                ->where('created_at', '>=', $from)
                ->where('created_at', '<', $to)
                ->count(),
            'suspended' => User::query()->whereNotNull('suspended_at')->count(),
            'public_users' => $this->accounts->scopePublic(User::query())->count(),
            'staff_users' => $this->accounts->scopeStaffSide(User::query())->count(),
            'by_identity_type' => $identityTypes,
            'by_access_class' => $accessClasses,
        ];
    }

    /**
     * Plugin alerts (#474). Only "action" alerts count on the Admin overview;
     * "info" rows explain states that need nothing doing, such as a plugin
     * that is not made for a module or a plugin already holding records.
     *
     * @return list<array{key: string, label: string, kind: string, severity: string, reason: string, fix_url: string, fix_label: string}>
     */
    private function moduleAlerts(): array
    {
        $alerts = [];
        $matrix = $this->catalogue->matrix();
        $pluginsUrl = route('admin.modules.index');

        foreach ($matrix['cells'] as $cell) {
            $definition = $cell['definition'];
            $installation = $cell['installation'];
            $label = $definition->subCore->name.' / '.$definition->module->name;
            $alert = static fn (string $kind, string $severity, string $reason, string $fixLabel): array => [
                'key' => $definition->key(),
                'label' => $label,
                'kind' => $kind,
                'severity' => $severity,
                'reason' => $reason,
                'fix_url' => $pluginsUrl,
                'fix_label' => $fixLabel,
            ];

            if ($definition->codeStatus === ModuleCodeStatus::Incompatible) {
                $alerts[] = $alert('incompatible', 'info', __('admin.overview.alert_reason.incompatible'), __('admin.overview.alert_fix.view'));
            }

            if ($definition->codeStatus === ModuleCodeStatus::CodeNotShipped) {
                $alerts[] = $alert('code_not_shipped', 'action', __('admin.overview.alert_reason.code_not_shipped'), __('admin.overview.alert_fix.review'));
            }

            if ($installation instanceof ModuleInstallation && ! $installation->enabled) {
                $alerts[] = $alert('disabled', 'action', __('admin.overview.alert_reason.disabled'), __('admin.overview.alert_fix.enable'));
            }

            if ($installation instanceof ModuleInstallation
                && $installation->enabled
                && (int) $cell['active_record_count'] > 0) {
                $alerts[] = $alert('active-records', 'info', __('admin.overview.alert_reason.active_records', ['count' => (int) $cell['active_record_count']]), __('admin.overview.alert_fix.view'));
            }
        }

        return $alerts;
    }
    /**
     * @return array<int, array{event: string, actor: string, occurred_at: string}>
     */
    private function privilegedEvents(): array
    {
        return AuditLog::query()
            ->with('user:id,name')
            ->where(function ($query): void {
                $query->where('event', 'like', 'authorization.%')
                    ->orWhere('event', 'like', 'maintenance.%')
                    ->orWhere('event', 'like', 'module.%');
            })
            ->latest('id')
            ->limit(8)
            ->get()
            ->map(static fn (AuditLog $event): array => [
                'event' => $event->event,
                'actor' => $event->user?->name ?? 'Unknown',
                'occurred_at' => optional($event->created_at)?->toIso8601String() ?? '',
            ])
            ->all();
    }

    /**
     * @param  array<int, float>  $values
     */
    private function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values, SORT_NUMERIC);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 1
            ? (float) $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    private function cacheKey(int $range, string $timezone): string
    {
        return implode(':', [
            'admin-analytics',
            $this->releases->version(),
            (string) $this->projections->version(),
            (string) $range,
            $timezone,
        ]);
    }
}
