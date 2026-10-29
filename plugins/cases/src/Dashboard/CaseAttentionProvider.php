<?php

namespace Plugins\Cases\Dashboard;

use App\Contracts\ModuleAttentionProvider;
use App\Core\Accounts\User;
use Plugins\Cases\Models\ShelterCase;
use App\Modules\ModuleAttentionItem;
use App\Modules\ModuleInstanceDefinition;
use App\Support\WorkflowLabels;
use Plugins\PetProfiles\Models\PetProfile;

class CaseAttentionProvider implements ModuleAttentionProvider
{
    public function attention(
        ModuleInstanceDefinition $instance,
        User $user,
        int $limit = 6,
    ): array {
        $isApesCic = $instance->subCore->key
            === ShelterCase::SUB_CORE_APES_CIC;
        $query = ShelterCase::query()
            ->forSubCore($instance->subCore->key)
            ->visibleTo($user, $instance->subCore->key);

        if ($isApesCic) {
            $query->whereNotIn('status', ['resolved', 'closed']);
        } else {
            $query
                ->where('status', '<>', 'closed')
                ->whereHas(
                    'petProfile',
                    static fn ($pets) => $pets->where(
                        'service_domain',
                        PetProfile::DOMAIN_SHELTER,
                    ),
                );
        }

        return $query
            ->with(['petProfile', 'user'])
            ->latest('updated_at')
            ->limit(max(0, $limit))
            ->get()
            ->map(fn (ShelterCase $case): ModuleAttentionItem => new ModuleAttentionItem(
                $instance->key(),
                $isApesCic ? 'ticket' : 'shelter',
                $isApesCic ? 'briefcase-business' : 'house',
                $isApesCic ? 'APES CIC' : 'APES Shelter',
                'Case',
                $case->title,
                $case->status,
                $isApesCic ? $case->priority : null,
                $isApesCic
                    ? str($case->category)->replace('_', ' ')->title()->toString()
                    : WorkflowLabels::label('case_type', $case->case_type),
                $isApesCic
                    ? ($case->user ? "For: {$case->user->name}" : null)
                    : ($case->petProfile
                        ? "Pet: {$case->petProfile->name}"
                        : null),
                $case->updated_at,
                $isApesCic
                    ? 'apes-cic.cases.show'
                    : 'shelter.cases.show',
                $case->id,
            ))
            ->all();
    }
}
