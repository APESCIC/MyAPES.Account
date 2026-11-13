<?php

namespace App\Core\Mail\Inbound\Contracts;

use App\Core\Mail\Inbound\MailboxUnavailable;

/**
 * The mailbox replies arrive in (#579).
 */
interface InboundMailbox
{
    /**
     * Oldest messages first; with $afterUid, only messages whose UID is higher (#777).
     *
     * @return iterable<int, MailboxMessage>
     *
     * @throws MailboxUnavailable
     */
    public function messages(int $limit, ?int $afterUid = null): iterable;

    /**
     * The folder's UIDVALIDITY (#777), or null when the mailbox has no UIDs.
     *
     * @throws MailboxUnavailable
     */
    public function uidValidity(): ?int;

    /**
     * Creates any of these folders that are missing (#776).
     *
     * @param  list<string>  $folders
     *
     * @throws MailboxUnavailable
     */
    public function ensureFolders(array $folders): void;

    /**
     * Deletes messages older than the given number of days from a folder and returns how many (#776).
     *
     * @throws MailboxUnavailable
     */
    public function purge(string $folder, int $olderThanDays): int;

    /**
     * Completes moves and deletions, then disconnects.
     *
     * @throws MailboxUnavailable
     */
    public function expunge(): void;

    /**
     * Connects and opens the folder without reading or changing any mail (#736).
     *
     * @throws MailboxUnavailable
     */
    public function test(): void;
}
