<?php

namespace App\Core\Accounts\ClientRecords;

/**
 * A client record that can be archived and restored, never hard-deleted, when it
 * belongs to a staff-side account (#514). Ordinary deletes are unchanged.
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> withArchived()
 * @method static \Illuminate\Database\Eloquent\Builder<static> onlyArchived()
 */
trait ArchivableClientRecord
{
    public static function bootArchivableClientRecord(): void
    {
        static::addGlobalScope(new ClientRecordArchiveScope);
    }

    public function initializeArchivableClientRecord(): void
    {
        $this->mergeCasts([ClientRecordArchiveScope::COLUMN => 'datetime']);
    }

    public function isArchived(): bool
    {
        return $this->getAttribute(ClientRecordArchiveScope::COLUMN) !== null;
    }
}
