<?php

namespace App\Services;

use App\Core\Accounts\AuditLog;
use App\Core\Accounts\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Filtered, sanitised audit log for Admin → Security (#949).
 * Secrets never leave the service — context is scrubbed before display or export.
 */
final class SecurityAuditLogBrowser
{
    private const PER_PAGE = 40;

    /** @var list<string> */
    private const SECRET_CONTEXT_KEYS = [
        'password',
        'temporary_password',
        'pin',
        'plain_pin',
        'secret',
        'token',
        'recovery_codes',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'otp',
        'code',
        'vapid_private_key',
        'mailbox_password',
    ];

    /**
     * @return list<string>
     */
    public function eventTypes(): array
    {
        return AuditLog::query()
            ->select('event')
            ->distinct()
            ->orderBy('event')
            ->pluck('event')
            ->filter(fn (string $event): bool => $this->isSecurityRelevant($event))
            ->values()
            ->all();
    }

    /**
     * @return array{actor: ?string, subject: ?string, event: ?string, from: ?string, to: ?string}
     */
    public function filtersFrom(Request $request): array
    {
        return [
            'actor' => $this->nullableString($request->query('actor')),
            'subject' => $this->nullableString($request->query('subject')),
            'event' => $this->nullableString($request->query('event')),
            'from' => $this->nullableString($request->query('from')),
            'to' => $this->nullableString($request->query('to')),
        ];
    }

    /**
     * @param  array{actor: ?string, subject: ?string, event: ?string, from: ?string, to: ?string}  $filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        return $this->query($filters)
            ->with(['user:id,name'])
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (AuditLog $log): array => $this->present($log));
    }

    /**
     * @param  array{actor: ?string, subject: ?string, event: ?string, from: ?string, to: ?string}  $filters
     */
    public function export(array $filters): StreamedResponse
    {
        $filename = 'security-audit-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($filters): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['at', 'event', 'actor', 'subject', 'ip_address', 'context']);

            $this->query($filters)->with(['user:id,name'])->orderBy('id')->chunk(200, function ($rows) use ($out): void {
                foreach ($rows as $log) {
                    $row = $this->present($log);
                    fputcsv($out, [
                        $row['at'],
                        $row['event'],
                        $row['actor'] ?? '',
                        $row['subject'] ?? '',
                        $row['ip_address'] ?? '',
                        json_encode($row['context'], JSON_UNESCAPED_UNICODE),
                    ]);
                }
            });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @param  array{actor: ?string, subject: ?string, event: ?string, from: ?string, to: ?string}  $filters
     * @return Builder<AuditLog>
     */
    private function query(array $filters): Builder
    {
        $query = AuditLog::query()->latest('id');

        if ($filters['event']) {
            $query->where('event', $filters['event']);
        } else {
            $query->where(function (Builder $builder): void {
                foreach (SecurityOverviewAggregator::securityEventPrefixes() as $prefix) {
                    $builder->orWhere('event', 'like', $prefix.'%')
                        ->orWhere('event', $prefix);
                }
                $builder->orWhere('event', 'like', 'forms.%');
            });
        }

        if ($filters['actor']) {
            $actor = $filters['actor'];
            $query->whereHas('user', function (Builder $builder) use ($actor): void {
                $builder->where('name', 'like', '%'.$actor.'%')
                    ->orWhere('email', 'like', '%'.$actor.'%')
                    ->orWhere('username', 'like', '%'.$actor.'%');
            });
        }

        if ($filters['subject']) {
            $subject = $filters['subject'];
            $ids = User::query()
                ->where(function (Builder $builder) use ($subject): void {
                    $builder->where('name', 'like', '%'.$subject.'%')
                        ->orWhere('email', 'like', '%'.$subject.'%')
                        ->orWhere('username', 'like', '%'.$subject.'%');
                })
                ->limit(200)
                ->pluck('id');
            $query->where(function (Builder $builder) use ($ids): void {
                $builder->where(function (Builder $inner) use ($ids): void {
                    $inner->where('auditable_type', (new User)->getMorphClass())
                        ->whereIn('auditable_id', $ids);
                })->orWhereIn('user_id', $ids);
            });
        }

        if ($filters['from'] && ($from = $this->parseDate($filters['from']))) {
            $query->where('created_at', '>=', $from->startOfDay());
        }

        if ($filters['to'] && ($to = $this->parseDate($filters['to']))) {
            $query->where('created_at', '<=', $to->endOfDay());
        }

        return $query;
    }

    /**
     * @return array{at: string, event: string, actor: ?string, subject: ?string, ip_address: ?string, context: array<string, mixed>}
     */
    private function present(AuditLog $log): array
    {
        $subject = null;
        if ($log->auditable_type && $log->auditable_id) {
            $user = User::query()->find($log->auditable_id);
            if ($user !== null && $log->auditable_type === $user->getMorphClass()) {
                $subject = $user->name;
            }
        }

        return [
            'at' => $log->created_at?->timezone(config('app.timezone'))->format('j M Y H:i') ?? '',
            'event' => (string) $log->event,
            'actor' => $log->user?->name,
            'subject' => $subject,
            'ip_address' => $log->ip_address,
            'context' => $this->sanitiseContext(is_array($log->context) ? $log->context : []),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function sanitiseContext(array $context): array
    {
        $clean = [];
        foreach ($context as $key => $value) {
            $name = strtolower((string) $key);
            if (in_array($name, self::SECRET_CONTEXT_KEYS, true) || str_contains($name, 'password') || str_contains($name, 'secret') || str_contains($name, 'token')) {
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $clean[(string) $key] = $value;
            }
        }

        return $clean;
    }

    private function isSecurityRelevant(string $event): bool
    {
        if (str_starts_with($event, 'forms.')) {
            return true;
        }

        foreach (SecurityOverviewAggregator::securityEventPrefixes() as $prefix) {
            if (str_starts_with($event, $prefix) || $event === $prefix) {
                return true;
            }
        }

        return false;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function parseDate(string $value): ?Carbon
    {
        try {
            return Carbon::parse($value, config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }
}
