<?php

namespace App\Core\Accounts\ClientRecords;

use App\Core\Accounts\AccountClassifier;
use App\Core\Accounts\User;
use App\Notifications\StaffClientRecordsArchivedNotification;
use App\Services\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Finds client records owned by staff-side accounts and archives or restores
 * them (#514). Records are only ever archived, never deleted.
 */
final class StaffClientRecordArchiver
{
    public function __construct(
        private readonly ClientRecordRegistry $registry,
        private readonly AccountClassifier $classifier,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Active client records owned by staff-side accounts, one row per account and type.
     *
     * @return Collection<int, array{user: User, type: ClientRecordType, ids: list<int>}>
     */
    public function findings(): Collection
    {
        $staffIds = $this->classifier->scopeStaffSide(User::query())->select('users.id');
        $findings = collect();

        foreach ($this->registry->all() as $type) {
            $rows = $type->model::query()
                ->whereIn($type->ownerColumn, $staffIds)
                ->orderBy($type->ownerColumn)
                ->orderBy('id')
                ->get(['id', $type->ownerColumn]);

            foreach ($rows->groupBy($type->ownerColumn) as $ownerId => $records) {
                $findings->push([
                    'user_id' => (int) $ownerId,
                    'type' => $type,
                    'ids' => $records->pluck('id')->map(static fn ($id): int => (int) $id)->values()->all(),
                ]);
            }
        }

        $users = User::query()->whereKey($findings->pluck('user_id')->unique()->all())->get()->keyBy('id');

        return $findings
            ->map(static fn (array $finding): array => [
                'user' => $users[$finding['user_id']],
                'type' => $finding['type'],
                'ids' => $finding['ids'],
            ])
            ->sortBy(static fn (array $finding): string => sprintf('%010d|%s', $finding['user']->id, $finding['type']->key))
            ->values();
    }

    /**
     * Archive every finding, record it in the audit log and email each affected account.
     *
     * @param  Collection<int, array{user: User, type: ClientRecordType, ids: list<int>}>  $findings
     * @return int number of records archived
     */
    public function archive(Collection $findings, ?User $actor = null): int
    {
        $archived = 0;
        $byUser = $findings->groupBy(static fn (array $finding): int => $finding['user']->id);

        DB::transaction(function () use ($byUser, $actor, &$archived): void {
            $now = now();

            foreach ($byUser as $userFindings) {
                $user = $userFindings->first()['user'];
                $counts = [];

                foreach ($userFindings as $finding) {
                    $archived += $finding['type']->model::query()
                        ->whereKey($finding['ids'])
                        ->update([ClientRecordArchiveScope::COLUMN => $now]);
                    $counts[$finding['type']->key] = count($finding['ids']);
                }

                $user->forceFill([
                    'client_records_archived_at' => $now,
                    'client_records_notice_dismissed_at' => null,
                ])->save();

                $this->audit->record('accounts.client_records_archived', $actor, $user, [
                    'command' => 'myapes:accounts:detach-staff-client-records',
                    'classification' => $this->classifier->classify($user),
                    'records' => $counts,
                ]);
            }
        });

        foreach ($byUser as $userFindings) {
            $user = $userFindings->first()['user'];
            $user->notify(new StaffClientRecordsArchivedNotification(
                $userFindings->mapWithKeys(static fn (array $finding): array => [
                    $finding['type']->label => count($finding['ids']),
                ])->all(),
            ));
        }

        return $archived;
    }

    /**
     * Restore every archived client record owned by this account.
     *
     * @return array<string, int> restored counts keyed by type key
     */
    public function restore(User $user, ?User $actor = null): array
    {
        $restored = [];

        DB::transaction(function () use ($user, $actor, &$restored): void {
            foreach ($this->registry->all() as $type) {
                $count = $type->model::onlyArchived()
                    ->where($type->ownerColumn, $user->id)
                    ->update([ClientRecordArchiveScope::COLUMN => null]);

                if ($count > 0) {
                    $restored[$type->key] = $count;
                }
            }

            $user->forceFill(['client_records_archived_at' => null])->save();

            $this->audit->record('accounts.client_records_restored', $actor, $user, [
                'command' => 'myapes:accounts:restore-staff-client-records',
                'records' => $restored,
            ]);
        });

        return $restored;
    }
}
