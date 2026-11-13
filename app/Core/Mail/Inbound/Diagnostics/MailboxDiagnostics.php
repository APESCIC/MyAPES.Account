<?php

namespace App\Core\Mail\Inbound\Diagnostics;

use App\Core\Mail\Inbound\Contracts\InboundMailbox;
use App\Core\Mail\Inbound\MailboxUnavailable;
use Closure;
use Throwable;

/**
 * Tests a mailbox step by step (#771): server name, connection, encryption,
 * login, then folder, stopping at the first failure. Each step is timed and
 * a failure is reported by type only (#772).
 */
final class MailboxDiagnostics
{
    /** Features the importer relies on; without them it still works, more slowly. */
    private const WANTED_FEATURES = ['MOVE', 'UIDPLUS'];

    private bool $greeted = false;

    /** @var array<string, mixed> */
    private array $details = [];

    public function __construct(private readonly MailboxTransport $transport) {}

    /**
     * Ports and encryption that can never work together on Cloudron (#773).
     */
    public static function mismatched(int $port, string $encryption): bool
    {
        return ($port === 993 && $encryption === 'tls') || ($port === 143 && $encryption === 'ssl');
    }

    public function run(MailboxConnection $connection): DiagnosticReport
    {
        $this->greeted = false;
        $this->details = ['capabilities' => []];
        $session = new ImapSession($this->transport);

        try {
            $report = $this->steps([
                [DiagnosticStep::Dns, fn (): bool => $this->dns($connection)],
                [DiagnosticStep::Tcp, fn (): bool => $this->tcp($connection)],
                [DiagnosticStep::Tls, fn (): bool => $this->tls($connection, $session)],
                [DiagnosticStep::Login, fn (): bool => $this->login($connection, $session)],
                [DiagnosticStep::Folder, fn (): bool => $this->folder($connection, $session)],
            ]);

            if ($report->ok()) {
                $this->logout($session);
            }

            return $report;
        } finally {
            $this->transport->close();
        }
    }

    /**
     * The local Mailpit driver (#756) has no IMAP: only its connection is checked.
     */
    public function runMailpit(InboundMailbox $mailbox): DiagnosticReport
    {
        $this->details = [];

        return $this->steps([
            [DiagnosticStep::Dns, fn (): bool => false],
            [DiagnosticStep::Tcp, function () use ($mailbox): bool {
                try {
                    $mailbox->test();
                } catch (MailboxUnavailable) {
                    throw new TransportFailure(FailureType::ConnectionRefused);
                }

                return true;
            }],
            [DiagnosticStep::Tls, fn (): bool => false],
            [DiagnosticStep::Login, fn (): bool => false],
            [DiagnosticStep::Folder, fn (): bool => false],
        ]);
    }

    /**
     * @param  list<array{0: DiagnosticStep, 1: Closure(): bool}>  $plan  each check returns false when its step does not apply
     */
    private function steps(array $plan): DiagnosticReport
    {
        $results = [];
        $failure = null;
        $failedStep = null;

        foreach ($plan as [$step, $check]) {
            if ($failure !== null) {
                $results[] = new StepResult($step, StepStatus::Skipped);

                continue;
            }

            $started = hrtime(true);
            try {
                $ran = $check();
                $results[] = new StepResult($step, $ran ? StepStatus::Passed : StepStatus::Skipped, self::since($started));
            } catch (TransportFailure $exception) {
                [$failure, $failedStep] = [$exception->type, $step];
                $results[] = new StepResult($step, StepStatus::Failed, self::since($started));
            } catch (Throwable) {
                [$failure, $failedStep] = [$step->defaultFailure(), $step];
                $results[] = new StepResult($step, StepStatus::Failed, self::since($started));
            }
        }

        return new DiagnosticReport($results, $failure, $failedStep, $this->details);
    }

    private function dns(MailboxConnection $connection): bool
    {
        if (! $this->transport->resolves($connection->host)) {
            throw new TransportFailure(FailureType::DnsNotFound);
        }

        return true;
    }

    private function tcp(MailboxConnection $connection): bool
    {
        $this->transport->connect($connection->host, $connection->port, $connection->timeout);

        return true;
    }

    private function tls(MailboxConnection $connection, ImapSession $session): bool
    {
        if ($connection->encryption === 'none') {
            return false;
        }

        if (self::mismatched($connection->port, $connection->encryption)) {
            throw new TransportFailure(FailureType::WrongEncryption);
        }

        if ($connection->encryption === 'ssl') {
            $this->certificate($this->transport->startTls($connection->host, $connection->validateCert));
            $this->greet($session);

            return true;
        }

        try {
            $this->greet($session);
        } catch (TransportFailure $exception) {
            // A server that says nothing in plain text is waiting for SSL/TLS.
            throw $exception->type === FailureType::Timeout ? new TransportFailure(FailureType::WrongEncryption) : $exception;
        }

        if (! $session->command('STARTTLS')->ok()) {
            throw new TransportFailure(FailureType::WrongEncryption);
        }

        $this->certificate($this->transport->startTls($connection->host, $connection->validateCert));
        $this->details['capabilities'] = [];

        return true;
    }

    private function login(MailboxConnection $connection, ImapSession $session): bool
    {
        if (! $this->greeted) {
            $this->greet($session);
        }

        $password = $connection->password();
        if ($connection->username === '' || $password === '' || preg_match('/[\r\n\0]/', $connection->username.$password) === 1) {
            throw new TransportFailure(FailureType::LoginFailed);
        }

        $response = $session->command('LOGIN '.ImapSession::quote($connection->username).' '.ImapSession::quote($password));
        if (! $response->ok()) {
            throw new TransportFailure(preg_match('/\[(LIMIT|UNAVAILABLE)\]|too many/i', $response->text) === 1
                ? FailureType::TooManyAttempts
                : FailureType::LoginFailed);
        }

        $capabilities = self::capabilities($response->text);
        if ($capabilities === []) {
            foreach ($session->command('CAPABILITY')->untagged as $line) {
                if (preg_match('/^\* CAPABILITY (.+)$/i', $line, $match) === 1) {
                    $capabilities = self::capabilityList($match[1]);
                }
            }
        }
        $this->details['capabilities'] = $capabilities;
        $this->details['missing_features'] = $capabilities === [] ? [] : array_values(array_diff(self::WANTED_FEATURES, $capabilities));

        return true;
    }

    private function folder(MailboxConnection $connection, ImapSession $session): bool
    {
        $response = $session->command('STATUS '.ImapSession::quote($connection->folder).' (MESSAGES UNSEEN UIDVALIDITY)');

        if (! $response->ok()) {
            $this->details['folders'] = $this->folders($session);

            throw new TransportFailure(FailureType::FolderNotFound);
        }

        foreach ($response->untagged as $line) {
            foreach (['messages' => 'MESSAGES', 'unseen' => 'UNSEEN', 'uid_validity' => 'UIDVALIDITY'] as $key => $item) {
                if (preg_match('/\b'.$item.' (\d+)/i', $line, $match) === 1) {
                    $this->details[$key] = (int) $match[1];
                }
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function folders(ImapSession $session): array
    {
        try {
            $response = $session->command('LIST "" "*"');
        } catch (TransportFailure) {
            return [];
        }

        $folders = [];
        foreach ($response->untagged as $line) {
            if (preg_match('/^\* LIST \([^)]*\) (?:"(?:[^"\\\\]|\\\\.)*"|NIL) (.+)$/i', $line, $match) === 1) {
                $name = trim($match[1]);
                $folders[] = str_starts_with($name, '"') ? stripcslashes(substr($name, 1, -1)) : $name;
            }
        }

        return array_slice($folders, 0, 50);
    }

    private function greet(ImapSession $session): void
    {
        $greeting = $session->greeting();

        if (preg_match('/^\* (OK|PREAUTH)\b/i', $greeting) !== 1) {
            throw new TransportFailure(stripos($greeting, 'too many') !== false ? FailureType::TooManyAttempts : FailureType::ConnectionRefused);
        }

        $this->greeted = true;
        $this->details['capabilities'] = self::capabilities($greeting);
    }

    private function logout(ImapSession $session): void
    {
        try {
            $session->command('LOGOUT');
        } catch (TransportFailure) {
            // The test already passed; a server that drops the line on LOGOUT changes nothing.
        }
    }

    /**
     * @param  array{name: ?string, expires_at: ?int}  $certificate
     */
    private function certificate(array $certificate): void
    {
        $this->details['certificate_name'] = $certificate['name'];
        $this->details['certificate_expires_at'] = $certificate['expires_at'];
    }

    /**
     * @return list<string>
     */
    private static function capabilities(string $text): array
    {
        return preg_match('/\[CAPABILITY ([^\]]+)\]/i', $text, $match) === 1 ? self::capabilityList($match[1]) : [];
    }

    /**
     * @return list<string>
     */
    private static function capabilityList(string $list): array
    {
        return array_values(array_unique(array_map('strtoupper', preg_split('/\s+/', trim($list)) ?: [])));
    }

    private static function since(int|float $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
