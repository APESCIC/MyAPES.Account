<?php

namespace App\Core\Mail\Inbound\Contracts;

use App\Core\Accounts\User;
use App\Core\Mail\Inbound\InboundAttachmentSet;
use Illuminate\Database\Eloquent\Model;

/**
 * A plugin's side of replies by email (#589): finds the record a reply is
 * for, checks who may reply, and posts the reply as a public message.
 */
interface InboundReplyHandler
{
    /** One upper-case letter used in reply tokens and subject tags, e.g. "T". */
    public function prefix(): string;

    /** The plugin slug that owns the records, e.g. "tickets". */
    public function plugin(): string;

    public function find(int $id): ?Model;

    /** The module the record belongs to, for the per-service toggle. */
    public function moduleSlug(Model $record): string;

    public function canReply(Model $record, User $sender): bool;

    public function isClosed(Model $record): bool;

    public function attachmentsAllowed(Model $record): bool;

    /** A short label for notices, such as "Ticket #12". */
    public function reference(Model $record): string;

    public function url(Model $record): string;

    /**
     * Post the reply as a public message by the sender and notify as the app does.
     *
     * @return Model The created message or update.
     */
    public function post(Model $record, User $sender, string $body, InboundAttachmentSet $attachments): Model;
}
