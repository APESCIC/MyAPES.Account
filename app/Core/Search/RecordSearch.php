<?php

namespace App\Core\Search;

use App\Core\Accounts\User;

/**
 * Orchestrates per-plugin record search providers (#527).
 */
final class RecordSearch
{
    public function __construct(
        private readonly RecordSearchRegistry $registry,
    ) {}

    /**
     * @return array{groups: list<array{type: string, label: string, hits: list<array<string, mixed>>}>}
     */
    public function search(User $actor, string $q, ?string $status = null, int $perType = 5): array
    {
        $query = trim($q);
        if ($query === '') {
            return ['groups' => []];
        }

        $groups = [];

        foreach ($this->registry->providers() as $provider) {
            $hits = $provider->search($actor, $query, $status);
            usort(
                $hits,
                static function (RecordHit $left, RecordHit $right): int {
                    return $left->rank <=> $right->rank
                        ?: strcasecmp($left->label, $right->label)
                        ?: strcmp($left->id, $right->id);
                },
            );

            $hits = array_slice($hits, 0, max(0, $perType));
            if ($hits === []) {
                continue;
            }

            $groups[] = [
                'type' => $provider->type(),
                'label' => $provider->label(),
                'hits' => array_map(
                    static fn (RecordHit $hit): array => $hit->toArray(),
                    $hits,
                ),
            ];
        }

        return ['groups' => $groups];
    }
}
