<?php

namespace Tests\Feature\Mail\Inbound;

use App\Core\Mail\Inbound\Diagnostics\FailureType;
use App\Core\Mail\Inbound\Diagnostics\ImapSession;
use App\Core\Mail\Inbound\Diagnostics\MailboxTransport;
use App\Core\Mail\Inbound\Diagnostics\SocketTransport;
use App\Core\Mail\Inbound\Diagnostics\TransportFailure;
use App\Core\Mail\Inbound\ImapMailbox;
use RuntimeException;
use Tests\TestCase;

/**
 * The IMAP session and socket transport behind the connection test (#771, #781).
 */
class ImapTransportTest extends TestCase
{
    public function test_a_tagged_command_collects_untagged_lines_and_folds_literals(): void
    {
        $transport = $this->scripted([
            '* LIST (\HasNoChildren) "/" {9}',
            'Junk Mail',
            '',
            '* LIST (\HasNoChildren) "/" "INBOX"',
            'A001 OK List completed.',
        ]);

        $response = (new ImapSession($transport))->command('LIST "" "*"');

        $this->assertTrue($response->ok());
        $this->assertSame(['* LIST (\HasNoChildren) "/" "Junk Mail"', '* LIST (\HasNoChildren) "/" "INBOX"'], $response->untagged);
        $this->assertSame(['A001 LIST "" "*"'], $transport->written);
    }

    public function test_a_no_answer_and_a_continuation_are_not_ok(): void
    {
        $this->assertSame('NO', (new ImapSession($this->scripted(['A001 NO Mailbox does not exist'])))->command('SELECT "Nope"')->status);
        $this->assertSame('BAD', (new ImapSession($this->scripted(['+ go ahead'])))->command('APPEND "INBOX" {10}')->status);
    }

    public function test_quoting_escapes_quotes_and_backslashes(): void
    {
        $this->assertSame('"pa\"ss\\\\word"', ImapSession::quote('pa"ss\\word'));
    }

    public function test_the_socket_transport_reports_refused_connections_and_unknown_names(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        [, $port] = explode(':', (string) stream_socket_get_name($server, false));
        fclose($server);

        $transport = new SocketTransport;
        try {
            $transport->connect('127.0.0.1', (int) $port, 2);
            $this->fail('A closed port must refuse the connection.');
        } catch (TransportFailure $failure) {
            $this->assertContains($failure->type, [FailureType::ConnectionRefused, FailureType::Timeout]);
        }

        $this->assertFalse($transport->resolves('mailbox.invalid'));
        $this->assertFalse($transport->resolves(''));
        $this->assertTrue($transport->resolves('127.0.0.1'));
    }

    public function test_imap_library_errors_map_to_failure_types_without_their_text(): void
    {
        $this->assertSame(FailureType::Timeout, ImapMailbox::failureFor(new RuntimeException('connection timed out')));
        $this->assertSame(FailureType::ConnectionRefused, ImapMailbox::failureFor(new RuntimeException('connection setup failed')));

        $unavailable = ImapMailbox::unavailable('The inbound mailbox could not be reached', new RuntimeException('LOGIN "user" "secret" refused'));
        $this->assertStringNotContainsString('secret', $unavailable->getMessage());
        $this->assertSame(FailureType::ConnectionRefused, $unavailable->type);
    }

    /**
     * @param  list<string>  $lines
     */
    private function scripted(array $lines): MailboxTransport
    {
        return new class($lines) implements MailboxTransport
        {
            /** @var list<string> */
            public array $written = [];

            /**
             * @param  list<string>  $lines
             */
            public function __construct(private array $lines) {}

            public function resolves(string $host): bool
            {
                return true;
            }

            public function connect(string $host, int $port, int $timeout): void {}

            public function startTls(string $peerName, bool $verify): array
            {
                return ['name' => null, 'expires_at' => null];
            }

            public function readLine(): string
            {
                return array_shift($this->lines) ?? throw new TransportFailure(FailureType::Timeout);
            }

            public function readBytes(int $length): string
            {
                return (string) array_shift($this->lines);
            }

            public function writeLine(string $line): void
            {
                $this->written[] = $line;
            }

            public function close(): void {}
        };
    }
}
