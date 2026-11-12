<?php

namespace App\Core\Mail\Inbound\Diagnostics;

/**
 * The real network: DNS, a TCP socket and TLS through PHP streams. Errors are
 * reduced to a FailureType; the server's own words are never passed on.
 */
final class SocketTransport implements MailboxTransport
{
    /** @var resource|null */
    private $stream = null;

    public function resolves(string $host): bool
    {
        if ($host === '') {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        $addresses = @gethostbynamel($host);

        return is_array($addresses) && $addresses !== [];
    }

    public function connect(string $host, int $port, int $timeout): void
    {
        $this->close();

        $errno = 0;
        $error = '';
        $context = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'SNI_enabled' => true]]);
        $stream = @stream_socket_client('tcp://'.$host.':'.$port, $errno, $error, $timeout, STREAM_CLIENT_CONNECT, $context);

        if ($stream === false) {
            throw new TransportFailure(self::connectFailure($errno, $error));
        }

        stream_set_timeout($stream, $timeout);
        $this->stream = $stream;
    }

    public function startTls(string $peerName, bool $verify): array
    {
        $stream = $this->stream ?? throw new TransportFailure(FailureType::ConnectionRefused);

        stream_context_set_option($stream, 'ssl', 'peer_name', $peerName);
        stream_context_set_option($stream, 'ssl', 'verify_peer', $verify);
        stream_context_set_option($stream, 'ssl', 'verify_peer_name', $verify);
        stream_context_set_option($stream, 'ssl', 'allow_self_signed', ! $verify);

        $warning = '';
        set_error_handler(static function (int $level, string $message) use (&$warning): bool {
            $warning .= ' '.$message;

            return true;
        });

        try {
            $enabled = stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        } finally {
            restore_error_handler();
        }

        if ($enabled !== true) {
            throw new TransportFailure($this->timedOut() ? FailureType::Timeout : self::tlsFailure($warning));
        }

        return self::certificate(stream_context_get_params($stream)['options']['ssl']['peer_certificate'] ?? null);
    }

    public function readLine(): string
    {
        $stream = $this->stream ?? throw new TransportFailure(FailureType::ConnectionRefused);
        $line = fgets($stream, 65536);

        if ($line === false) {
            throw new TransportFailure($this->timedOut() ? FailureType::Timeout : FailureType::ConnectionRefused);
        }

        return rtrim($line, "\r\n");
    }

    public function readBytes(int $length): string
    {
        $stream = $this->stream ?? throw new TransportFailure(FailureType::ConnectionRefused);
        $data = '';

        while (strlen($data) < $length) {
            $chunk = fread($stream, min(65536, $length - strlen($data)));
            if ($chunk === false || $chunk === '') {
                throw new TransportFailure($this->timedOut() ? FailureType::Timeout : FailureType::ConnectionRefused);
            }
            $data .= $chunk;
        }

        return $data;
    }

    public function writeLine(string $line): void
    {
        $stream = $this->stream ?? throw new TransportFailure(FailureType::ConnectionRefused);

        if (@fwrite($stream, $line."\r\n") === false) {
            throw new TransportFailure(FailureType::ConnectionRefused);
        }
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            @fclose($this->stream);
        }

        $this->stream = null;
    }

    public static function connectFailure(int $errno, string $error): FailureType
    {
        $error = strtolower($error);

        return match (true) {
            str_contains($error, 'getaddrinfo'), str_contains($error, 'name or service not known'), str_contains($error, 'no such host') => FailureType::DnsNotFound,
            in_array($errno, [110, 10060], true), str_contains($error, 'timed out') => FailureType::Timeout,
            default => FailureType::ConnectionRefused,
        };
    }

    public static function tlsFailure(string $warning): FailureType
    {
        $warning = strtolower($warning);

        return match (true) {
            str_contains($warning, 'did not match expected'), str_contains($warning, 'hostname mismatch') => FailureType::CertificateNameMismatch,
            str_contains($warning, 'certificate verify failed'), str_contains($warning, 'certificate has expired'), str_contains($warning, 'self signed'), str_contains($warning, 'self-signed') => FailureType::CertificateUntrusted,
            str_contains($warning, 'wrong version number'), str_contains($warning, 'unknown protocol'), str_contains($warning, 'packet length too long'), str_contains($warning, 'record layer failure') => FailureType::WrongEncryption,
            default => FailureType::HandshakeFailed,
        };
    }

    /**
     * @return array{name: ?string, expires_at: ?int}
     */
    private static function certificate(mixed $certificate): array
    {
        $parsed = $certificate !== null && function_exists('openssl_x509_parse') ? openssl_x509_parse($certificate) : false;

        if (! is_array($parsed)) {
            return ['name' => null, 'expires_at' => null];
        }

        $name = $parsed['subject']['CN'] ?? null;

        return [
            'name' => is_array($name) ? (string) reset($name) : ($name !== null ? (string) $name : null),
            'expires_at' => isset($parsed['validTo_time_t']) ? (int) $parsed['validTo_time_t'] : null,
        ];
    }

    private function timedOut(): bool
    {
        return is_resource($this->stream) && (stream_get_meta_data($this->stream)['timed_out'] ?? false);
    }
}
