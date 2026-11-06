<?php

namespace Plugins\Consultations\Support;

use App\Core\Accounts\AccountClassifier;
use App\Core\Accounts\AuditLog;
use App\Core\Accounts\User;
use App\Services\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Plugins\Consultations\Models\PetCareConsultation;

/**
 * Finds consultations created by users without the clinical create ability and flags
 * them for vet review (#539). Nothing is archived or deleted.
 */
final class NonClinicalConsultationFlagger
{
    public const SOURCE_COLUMN = 'created_by';

    public const SOURCE_AUDIT = 'audit_log';

    public const SOURCE_UNKNOWN = 'unknown';

    public function __construct(
        private readonly AccountClassifier $classifier,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Unflagged consultations with their creator; `flag` is false when the creator is clinical or unknown.
     *
     * @return Collection<int, array{consultation: PetCareConsultation, creator: ?User, account: string, source: string, flag: bool}>
     */
    public function findings(): Collection
    {
        $consultations = PetCareConsultation::query()
            ->forPetCareDomain()
            ->whereNull('vet_review_flagged_at')
            ->with('createdBy')
            ->orderBy('id')
            ->get();
        $auditCreators = $this->auditCreators($consultations->whereNull('created_by')->modelKeys());

        return $consultations
            ->map(function (PetCareConsultation $consultation) use ($auditCreators): array {
                [$creator, $source] = match (true) {
                    $consultation->createdBy !== null => [$consultation->createdBy, self::SOURCE_COLUMN],
                    $auditCreators->has($consultation->id) => [$auditCreators->get($consultation->id), self::SOURCE_AUDIT],
                    default => [null, self::SOURCE_UNKNOWN],
                };

                return [
                    'consultation' => $consultation,
                    'creator' => $creator,
                    'account' => $creator === null ? self::SOURCE_UNKNOWN : $this->classifier->classify($creator),
                    'source' => $source,
                    'flag' => $creator !== null && ! $this->isClinical($creator),
                ];
            })
            ->reject(static fn (array $finding): bool => $finding['creator'] !== null && ! $finding['flag'])
            ->values();
    }

    /**
     * @param  Collection<int, array{consultation: PetCareConsultation, creator: ?User, account: string, source: string, flag: bool}>  $findings
     */
    public function flag(Collection $findings): int
    {
        $toFlag = $findings->where('flag', true);

        DB::transaction(function () use ($toFlag): void {
            $flaggedAt = now();

            foreach ($toFlag as $finding) {
                $consultation = $finding['consultation'];
                $consultation->forceFill(['vet_review_flagged_at' => $flaggedAt])->saveQuietly();
                $this->auditLogger->record('petcare.consultation.flagged_for_vet_review', null, $consultation, [
                    'creator_id' => $finding['creator']?->id,
                    'creator_account' => $finding['account'],
                    'source' => $finding['source'],
                    'sub_core_key' => 'pet-care-clinic',
                    'module_key' => 'consultations',
                ]);
            }
        });

        return $toFlag->count();
    }

    public function isClinical(User $user): bool
    {
        return $user->getAllPermissions()
            ->pluck('name')
            ->contains(PetCareConsultation::PERMISSION_CREATE);
    }

    /**
     * @param  array<int, int>  $consultationIds
     * @return Collection<int, User>
     */
    private function auditCreators(array $consultationIds): Collection
    {
        if ($consultationIds === []) {
            return collect();
        }

        $creatorIds = AuditLog::query()
            ->where('event', 'petcare.consultation.created')
            ->where('auditable_type', (new PetCareConsultation)->getMorphClass())
            ->whereIn('auditable_id', $consultationIds)
            ->whereNotNull('user_id')
            ->orderBy('id')
            ->get(['auditable_id', 'user_id'])
            ->unique('auditable_id')
            ->mapWithKeys(static fn (AuditLog $log): array => [(int) $log->auditable_id => (int) $log->user_id]);
        $users = User::query()->whereKey($creatorIds->values()->unique()->all())->get()->keyBy('id');

        return $creatorIds
            ->map(static fn (int $userId): ?User => $users->get($userId))
            ->filter();
    }
}
