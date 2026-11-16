<?php

namespace App\Core\Search;

use App\Core\Accounts\User;

/**
 * Plugin / domain contributor for live record search (#527).
 */
interface RecordSearchProvider
{
    public function type(): string;

    public function label(): string;

    /**
     * @return list<RecordHit>
     */
    public function search(User $actor, string $q, ?string $status = null): array;
}
