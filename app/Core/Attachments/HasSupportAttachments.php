<?php

namespace App\Core\Attachments;

use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A record that support attachments hang off (#549), such as a ticket or a case.
 */
interface HasSupportAttachments
{
    /**
     * Permission prefix for the record's module and plugin, for example "apes-cic.tickets.".
     */
    public function attachmentPermissionPrefix(): string;

    public function attachmentOwnerId(): ?int;

    /**
     * Page where the record and its attachments are shown.
     */
    public function attachmentRecordUrl(): ?string;

    public function attachments(): MorphMany;
}
