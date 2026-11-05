<?php

namespace App\Core\Accounts\ClientRecords;

use Closure;
use LogicException;

/**
 * Core registry of client record types that plugins contribute (#514), so Core
 * can find records owned by an account without importing plugin models.
 */
final class ClientRecordRegistry
{
    /** @var list<Closure(): list<ClientRecordType>> */
    private array $contributors = [];

    /** @var array<string, ClientRecordType>|null */
    private ?array $resolved = null;

    /**
     * @param  Closure(): list<ClientRecordType>  $contributor
     */
    public function contribute(Closure $contributor): void
    {
        $this->contributors[] = $contributor;
        $this->resolved = null;
    }

    /**
     * @return array<string, ClientRecordType> keyed and sorted by type key
     */
    public function all(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $types = [];

        foreach ($this->contributors as $contributor) {
            foreach ($contributor() as $type) {
                if (isset($types[$type->key])) {
                    throw new LogicException("Client record type [{$type->key}] is registered twice.");
                }

                if (! in_array(ArchivableClientRecord::class, class_uses_recursive($type->model), true)) {
                    throw new LogicException("Client record model [{$type->model}] must use ArchivableClientRecord.");
                }

                $types[$type->key] = $type;
            }
        }

        ksort($types);

        return $this->resolved = $types;
    }
}
