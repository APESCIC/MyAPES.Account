<?php

namespace App\Core\Mail\Inbound\Diagnostics;

/**
 * A line-based connection to an IMAP server, split into the steps a
 * connection test reports on (#771). Failures are TransportFailure only.
 */
interface MailboxTransport
{
    public function resolves(string $host): bool;

    /**
     * @throws TransportFailure
     */
    public function connect(string $host, int $port, int $timeout): void;

    /**
     * Starts TLS on the open connection and returns the server certificate's name and expiry (Unix time).
     *
     * @return array{name: ?string, expires_at: ?int}
     *
     * @throws TransportFailure
     */
    public function startTls(string $peerName, bool $verify): array;

    /**
     * @throws TransportFailure
     */
    public function readLine(): string;

    /**
     * @throws TransportFailure
     */
    public function readBytes(int $length): string;

    /**
     * @throws TransportFailure
     */
    public function writeLine(string $line): void;

    public function close(): void;
}
