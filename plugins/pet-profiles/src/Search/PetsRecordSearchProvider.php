<?php

namespace Plugins\PetProfiles\Search;

use App\Core\Accounts\User;
use App\Core\Extensions\Plugins\PluginEnablement;
use App\Core\Search\RecordHit;
use App\Core\Search\RecordRanker;
use App\Core\Search\RecordSearchProvider;
use Illuminate\Support\Facades\Schema;
use Plugins\PetProfiles\Models\PetProfile;
use Plugins\PetProfiles\PetProfilesArea;

/**
 * Pet profile hits for workspace record search (#527).
 */
final class PetsRecordSearchProvider implements RecordSearchProvider
{
    /** @var array<string, string> domain => module */
    private const DOMAINS = [
        PetProfile::DOMAIN_SHELTER => 'shelter-rescue',
        PetProfile::DOMAIN_PETCARE => 'pet-care-clinic',
    ];

    public function __construct(
        private readonly PluginEnablement $plugins,
    ) {}

    public function type(): string
    {
        return 'pets';
    }

    public function label(): string
    {
        return __('workspace.palette.records.pets');
    }

    public function search(User $actor, string $q, ?string $status = null): array
    {
        // Pets have no workflow status column; a status filter yields nothing.
        if ($status !== null && $status !== '') {
            return [];
        }

        $hits = [];
        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%';
        $numericId = ctype_digit(ltrim($q, '#')) ? (int) ltrim($q, '#') : null;
        $hasMicrochip = Schema::hasColumn((new PetProfile)->getTable(), 'microchip');

        foreach (self::DOMAINS as $domain => $module) {
            if (! $this->plugins->isEnabled($module, 'pet-profiles')) {
                continue;
            }

            $area = PetProfilesArea::forModule($module);
            if (! $actor->can($area->permission('view-all')) && ! $actor->can($area->permission('view-own'))) {
                continue;
            }

            $pets = PetProfile::query()
                ->where('service_domain', $domain)
                ->visibleTo($actor, $domain)
                ->with('user')
                ->where(static function ($query) use ($like, $numericId, $hasMicrochip): void {
                    $query
                        ->where('name', 'like', $like)
                        ->orWhereHas('user', static function ($user) use ($like): void {
                            $user->where('name', 'like', $like)->orWhere('email', 'like', $like);
                        });

                    if ($hasMicrochip) {
                        $query->orWhere('microchip', 'like', $like);
                    }

                    if ($numericId !== null) {
                        $query->orWhere('id', $numericId);
                    }
                })
                ->latest('id')
                ->limit(25)
                ->get();

            foreach ($pets as $pet) {
                $reference = '#'.$pet->id;
                $microchip = $hasMicrochip ? ($pet->microchip ?? null) : null;
                $rank = RecordRanker::bestScore(
                    $q,
                    reference: $reference,
                    microchip: is_string($microchip) ? $microchip : null,
                    partialFields: [
                        (string) $pet->id,
                        $pet->name,
                        $pet->user?->name,
                        $pet->user?->email,
                        is_string($microchip) ? $microchip : null,
                    ],
                );

                if ($rank === null) {
                    continue;
                }

                $hits[] = new RecordHit(
                    type: $this->type(),
                    id: (string) $pet->id,
                    label: (string) ($pet->name ?: $reference),
                    url: route($area->showRouteName(), $pet),
                    rank: $rank,
                    subtitle: $reference.($microchip ? ' · '.$microchip : ''),
                    status: null,
                    reference: $reference,
                );
            }
        }

        return $hits;
    }
}
