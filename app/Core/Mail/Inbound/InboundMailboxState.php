<?php

namespace App\Core\Mail\Inbound;

use Illuminate\Database\Eloquent\Model;

/**
 * Where the importer got to in one mailbox folder (#777): the highest UID
 * handled and the UIDVALIDITY it belongs to.
 *
 * @property int $id
 * @property string $mailbox
 * @property string $folder
 * @property int|null $uid_validity
 * @property int $last_uid
 */
class InboundMailboxState extends Model
{
    protected $fillable = ['mailbox', 'folder', 'uid_validity', 'last_uid'];

    protected function casts(): array
    {
        return [
            'uid_validity' => 'integer',
            'last_uid' => 'integer',
        ];
    }

    public static function for(string $mailbox, string $folder): self
    {
        return self::query()->firstOrNew(['mailbox' => strtolower($mailbox), 'folder' => $folder], ['last_uid' => 0]);
    }

    /**
     * The UID to fetch above, or null to rescan the folder because the server renumbered it.
     */
    public function resumeAfter(?int $uidValidity): ?int
    {
        return $uidValidity !== null && $this->exists && $this->uid_validity === $uidValidity ? $this->last_uid : null;
    }
}
