<?php

namespace App\Core\Search;

use Closure;
use LogicException;

/**
 * Registry of record-search providers contributed by Core and plugins (#527).
 */
final class RecordSearchRegistry
{
    /** @var list<Closure(): list<RecordSearchProvider>> */
    private array $contributors = [];

    /** @var list<RecordSearchProvider>|null */
    private ?array $resolved = null;

    /**
     * @param  Closure(): list<RecordSearchProvider>  $contributor
     */
    public function contribute(Closure $contributor): void
    {
        $this->contributors[] = $contributor;
        $this->resolved = null;
    }

    /**
     * @return list<RecordSearchProvider>
     */
    public function providers(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $providers = [];
        $seen = [];

        foreach ($this->contributors as $contributor) {
            foreach ($contributor() as $provider) {
                $type = $provider->type();
                if (isset($seen[$type])) {
                    throw new LogicException("Record search provider type [{$type}] is registered twice.");
                }

                $seen[$type] = true;
                $providers[] = $provider;
            }
        }

        return $this->resolved = $providers;
    }

    public function find(string $type): ?RecordSearchProvider
    {
        foreach ($this->providers() as $provider) {
            if ($provider->type() === $type) {
                return $provider;
            }
        }

        return null;
    }
}
