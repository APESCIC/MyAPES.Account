<?php

namespace App\Services;

use App\Core\Accounts\AuditLog;
use App\Core\Accounts\User;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Plugins\Cases\Models\ShelterCase;
use Plugins\Consultations\Models\PetCareConsultation;
use Plugins\PetProfiles\Models\PetProfile;
use Plugins\Recruitment\Models\RecruitmentApplication;
use Plugins\Tickets\Models\SupportTicket;

/**
 * Sanitised, paginated activity for a managed user (#524).
 */
final class UserActivityTimeline
{
    private const PER_PAGE = 25;

    /** @var list<string> */
    private const AUDIT_CONTEXT_KEYS = [
        'actor_id',
        'target_user_id',
        'role_id',
        'role_ids',
        'role_count',
        'permission_count',
        'affected_user_count',
        'group_id',
        'mapping_id',
        'matched_user_count',
        'changed_user_count',
        'action',
        'source_key',
        'route_name',
        'method',
        'reason_code',
        'reason_length',
        'granted_count',
        'revoked_count',
        'was_confirmed',
        'channel',
        'note_id',
        'avatar_updated',
        'photo_updated',
        'sub_core_key',
        'module_key',
        'service_area',
        'priority',
        'status',
        'category',
    ];

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
    ];

    /**
     * @param  array{module?: string, from?: ?Carbon, to?: ?Carbon}  $filters
     */
    public function paginate(User $subject, int $page = 1, array $filters = []): LengthAwarePaginator
    {
        $entries = collect($this->auditEntries($subject, $filters)->all())
            ->merge($this->recordEntries($subject, $filters))
            ->sortByDesc(static fn (array $entry): int => $entry['sort_at'])
            ->values();

        $page = max(1, $page);
        $total = $entries->count();
        $slice = $entries->forPage($page, self::PER_PAGE)->values();

        return new Paginator(
            $slice,
            $total,
            self::PER_PAGE,
            $page,
            [
                'path' => Paginator::resolveCurrentPath(),
                'pageName' => 'activity_page',
            ],
        );
    }

    /**
     * @param  array{module?: string, from?: ?Carbon, to?: ?Carbon}  $filters
     * @return Collection<int, array{event: string, actor_id: ?int, created_at: mixed, context: array<string, mixed>, sort_at: int, source: string}>
     */
    private function auditEntries(User $subject, array $filters): Collection
    {
        $query = AuditLog::query()
            ->where('auditable_type', $subject->getMorphClass())
            ->where('auditable_id', $subject->id);
        $this->constrainDates($query, $filters);

        return $query
            ->latest('id')
            ->limit(200)
            ->get()
            ->map(function (AuditLog $audit): array {
                $createdAt = $audit->created_at;
                $context = is_array($audit->context) ? $audit->context : [];

                return [
                    'event' => (string) $audit->event,
                    'actor_id' => $audit->user_id === null ? null : (int) $audit->user_id,
                    'created_at' => $createdAt,
                    'context' => $this->sanitiseContext($context),
                    'sort_at' => $this->sortKey($createdAt, (int) $audit->id),
                    'source' => 'audit',
                ];
            });
    }

    /**
     * @param  array{module?: string, from?: ?Carbon, to?: ?Carbon}  $filters
     * @return Collection<int, array{event: string, actor_id: ?int, created_at: mixed, context: array<string, mixed>, sort_at: int, source: string}>
     */
    private function recordEntries(User $subject, array $filters): Collection
    {
        $entries = collect();
        $module = (string) ($filters['module'] ?? '');

        foreach (
            [
                [SupportTicket::class, 'ticket.created', 'subject'],
                [ShelterCase::class, 'case.created', 'title'],
                [PetProfile::class, 'pet.created', 'name'],
                [PetCareConsultation::class, 'consultation.created', 'subject'],
                [RecruitmentApplication::class, 'application.created', 'status'],
            ] as [$model, $event, $labelField]
        ) {
            $query = $model::query()->where('user_id', $subject->id);
            $this->constrainDates($query, $filters);
            $rows = $query->latest('id')->limit(50)->get();

            foreach ($rows as $row) {
                $rowModule = $this->recordModule($row);
                if ($module !== '' && $rowModule !== $module) {
                    continue;
                }

                $createdAt = $row->created_at ?? $row->submitted_at ?? null;
                $entries->push([
                    'event' => $event,
                    'actor_id' => null,
                    'created_at' => $createdAt,
                    'context' => $this->sanitiseContext([
                        'reference' => '#'.$row->id,
                        'status' => (string) ($row->status ?? '—'),
                        'label' => (string) ($row->{$labelField} ?? ''),
                    ]),
                    'sort_at' => $this->sortKey($createdAt, (int) $row->id),
                    'source' => 'record',
                ]);
            }
        }

        return $entries;
    }

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  array{from?: ?Carbon, to?: ?Carbon}  $filters
     */
    private function constrainDates($query, array $filters): void
    {
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;

        if ($from instanceof Carbon) {
            $query->whereDate('created_at', '>=', $from->toDateString());
        }

        if ($to instanceof Carbon) {
            $query->whereDate('created_at', '<=', $to->toDateString());
        }
    }

    private function recordModule(object $row): string
    {
        if ($row instanceof SupportTicket || $row instanceof ShelterCase) {
            return (string) $row->sub_core_key;
        }

        if ($row instanceof PetProfile) {
            return $row->service_domain === PetProfile::DOMAIN_SHELTER
                ? 'shelter-rescue'
                : 'pet-care-clinic';
        }

        if ($row instanceof PetCareConsultation) {
            return 'pet-care-clinic';
        }

        if ($row instanceof RecruitmentApplication) {
            return 'apes-cic';
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function sanitiseContext(array $context): array
    {
        $safe = [];

        foreach ($context as $key => $value) {
            $key = (string) $key;
            $lower = strtolower($key);

            if (in_array($lower, self::SECRET_CONTEXT_KEYS, true)) {
                continue;
            }

            // Substring match only for unambiguous secret fragments — bare
            // "code"/"otp" would strip allow-listed keys such as reason_code.
            foreach (['password', 'secret', 'token', 'pin', 'recovery'] as $secretFragment) {
                if (str_contains($lower, $secretFragment)) {
                    continue 2;
                }
            }

            if (! in_array($key, self::AUDIT_CONTEXT_KEYS, true)
                && ! in_array($key, ['reference', 'status', 'label'], true)) {
                // Keep only known allow-listed keys for audit payloads.
                if (! Arr::accessible($value) && ! is_scalar($value) && $value !== null) {
                    continue;
                }
            }

            if (is_array($value)) {
                $safe[$key] = array_values(array_filter(
                    $value,
                    static fn (mixed $item): bool => is_scalar($item) || $item === null,
                ));
                continue;
            }

            if (is_scalar($value) || $value === null) {
                $safe[$key] = $value;
            }
        }

        return Arr::only(
            $safe,
            array_merge(self::AUDIT_CONTEXT_KEYS, ['reference', 'status', 'label']),
        );
    }

    private function sortKey(mixed $createdAt, int $id): int
    {
        if ($createdAt instanceof Carbon) {
            return ($createdAt->getTimestamp() * 1000) + $id;
        }

        if (is_string($createdAt) && $createdAt !== '') {
            return (Carbon::parse($createdAt)->getTimestamp() * 1000) + $id;
        }

        return $id;
    }
}
