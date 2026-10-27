<?php

namespace App\Core\Extensions\Navigation;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Lazily evaluates public navigation contributions (#282).
 */
final class PublicNavigationRegistry
{
    /** @var list<\Closure(): list<PublicNavigationItem>> */
    private array $contributors = [];

    public function contribute(\Closure $contributor): void
    {
        $this->contributors[] = $contributor;
    }

    /**
     * @return list<PublicNavigationItem>
     */
    public function enabledItems(): array
    {
        $items = [];

        foreach ($this->contributors as $contributor) {
            foreach ($contributor() as $item) {
                if ($item->enabled) {
                    $items[] = $item;
                }
            }
        }

        usort(
            $items,
            static fn (PublicNavigationItem $a, PublicNavigationItem $b): int => $a->order <=> $b->order,
        );

        return $items;
    }

    /**
     * Enabled items, minus those hidden from this signed-in user.
     *
     * @return list<PublicNavigationItem>
     */
    public function visibleTo(?Authenticatable $user): array
    {
        if ($user === null) {
            return $this->enabledItems();
        }

        return array_values(array_filter(
            $this->enabledItems(),
            static fn (PublicNavigationItem $item): bool => $item->visibleToSignedIn === null
                || ($item->visibleToSignedIn)($user),
        ));
    }
}
