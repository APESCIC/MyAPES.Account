<?php

namespace Plugins\Consultations\Search;

use App\Core\Accounts\User;
use App\Core\Extensions\Plugins\PluginEnablement;
use App\Core\Search\RecordHit;
use App\Core\Search\RecordRanker;
use App\Core\Search\RecordSearchProvider;
use Plugins\Consultations\Models\PetCareConsultation;

/**
 * Consultation hits for workspace record search (#527).
 */
final class ConsultationsRecordSearchProvider implements RecordSearchProvider
{
    public function __construct(
        private readonly PluginEnablement $plugins,
    ) {}

    public function type(): string
    {
        return 'consultations';
    }

    public function label(): string
    {
        return __('workspace.palette.records.consultations');
    }

    public function search(User $actor, string $q, ?string $status = null): array
    {
        if (! $this->plugins->isEnabled('pet-care-clinic', 'consultations')) {
            return [];
        }

        if (! $actor->can(PetCareConsultation::PERMISSION_VIEW_ALL)
            && ! $actor->can(PetCareConsultation::PERMISSION_VIEW_OWN)) {
            return [];
        }

        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%';
        $numericId = ctype_digit(ltrim($q, '#')) ? (int) ltrim($q, '#') : null;

        $consultations = PetCareConsultation::query()
            ->forPetCareDomain()
            ->visibleTo($actor)
            ->with(['user', 'petProfile'])
            ->when(
                $status !== null && $status !== '',
                static fn ($query) => $query->where('status', $status),
            )
            ->where(static function ($query) use ($like, $numericId): void {
                $query
                    ->where('subject', 'like', $like)
                    ->orWhere('status', 'like', $like)
                    ->orWhereHas('user', static function ($user) use ($like): void {
                        $user->where('name', 'like', $like)->orWhere('email', 'like', $like);
                    })
                    ->orWhereHas('petProfile', static function ($pet) use ($like): void {
                        $pet->where('name', 'like', $like);
                    });

                if ($numericId !== null) {
                    $query->orWhere('id', $numericId);
                }
            })
            ->latest('id')
            ->limit(25)
            ->get();

        $hits = [];

        foreach ($consultations as $consultation) {
            $reference = '#'.$consultation->id;
            $rank = RecordRanker::bestScore(
                $q,
                reference: $reference,
                partialFields: [
                    (string) $consultation->id,
                    $consultation->subject,
                    $consultation->user?->name,
                    $consultation->user?->email,
                    $consultation->petProfile?->name,
                    $consultation->status,
                ],
                recordStatus: $consultation->status,
                statusFilter: $status,
            );

            if ($rank === null) {
                continue;
            }

            $hits[] = new RecordHit(
                type: $this->type(),
                id: (string) $consultation->id,
                label: (string) ($consultation->subject ?: $reference),
                url: route('petcare.consultations.show', $consultation),
                rank: $rank,
                subtitle: $reference,
                status: (string) $consultation->status,
                reference: $reference,
            );
        }

        return $hits;
    }
}
