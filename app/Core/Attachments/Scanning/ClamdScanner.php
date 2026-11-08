<?php

namespace App\Core\Attachments\Scanning;

/**
 * Streams a file to a clamd daemon over TCP with the INSTREAM command.
 *
 * clamd replies "stream: OK" when clean and "stream: <signature> FOUND" when
 * infected. The stream is sent in length-prefixed chunks and ended with a
 * zero-length chunk; clamd refuses streams larger than its StreamMaxLength.
 */
final class ClamdScanner implements VirusScanner
{
    private const CHUNK_BYTES = 8192;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly int $timeoutSeconds = 30,
    ) {}

    public function enabled(): bool
    {
        return $this->host !== '';
    }

    public function scan($stream): ScanResult
    {
        $errorCode = 0;
        $errorMessage = '';
        $socket = @stream_socket_client(
            "tcp://{$this->host}:{$this->port}",
            $errorCode,
            $errorMessage,
            $this->timeoutSeconds,
        );

        if ($socket === false) {
            throw new ScannerUnavailable("Could not reach clamd: {$errorMessage}");
        }

        try {
            stream_set_timeout($socket, $this->timeoutSeconds);
            $this->write($socket, "zINSTREAM\0");

            while (! feof($stream)) {
                $chunk = fread($stream, self::CHUNK_BYTES);
                if ($chunk === false) {
                    throw new ScannerUnavailable('Could not read the file to scan.');
                }
                if ($chunk === '') {
                    continue;
                }
                $this->write($socket, pack('N', strlen($chunk)).$chunk);
            }
            $this->write($socket, pack('N', 0));

            $reply = trim((string) stream_get_contents($socket), "\0\r\n ");
        } finally {
            fclose($socket);
        }

        return $this->parse($reply);
    }

    public function parse(string $reply): ScanResult
    {
        if (str_ends_with($reply, 'OK')) {
            return ScanResult::clean();
        }

        if (preg_match('/^stream: (.+) FOUND$/', $reply, $matches) === 1) {
            return ScanResult::infected($matches[1]);
        }

        throw new ScannerUnavailable('Unexpected clamd reply.');
    }

    /**
     * @param  resource  $socket
     */
    private function write($socket, string $bytes): void
    {
        $written = 0;
        $length = strlen($bytes);
        while ($written < $length) {
            $result = fwrite($socket, substr($bytes, $written));
            if ($result === false || $result === 0) {
                throw new ScannerUnavailable('Lost the connection to clamd.');
            }
            $written += $result;
        }
    }
}
