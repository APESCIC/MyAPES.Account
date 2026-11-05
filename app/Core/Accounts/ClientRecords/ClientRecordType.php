<?php

namespace App\Core\Accounts\ClientRecords;

use Illuminate\Database\Eloquent\Model;

/**
 * A kind of client record that a plugin owns, such as tickets or pet profiles (#514).
 */
final readonly class ClientRecordType
{
    /**
     * @param  class-string<Model>  $model  must use {@see ArchivableClientRecord}
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $model,
        public string $ownerColumn = 'user_id',
    ) {}
}
