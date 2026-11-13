<?php

namespace Tests\Feature\Mail\Inbound;

use App\Core\Mail\Inbound\Diagnostics\DiagnosticReport;
use App\Core\Mail\Inbound\Diagnostics\DiagnosticStep;
use App\Core\Mail\Inbound\Diagnostics\FailureType;
use App\Core\Mail\Inbound\Diagnostics\MailboxConnection;
use App\Core\Mail\Inbound\Diagnostics\MailboxDiagnostics;
use App\Core\Mail\Inbound\Diagnostics\StepStatus;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fakes\FakeImapServer;
use Tests\TestCase;

/**
 * The five-step connection test (#771): DNS, connection, encryption, login
 * and folder, stopping at the first failure.
 */
class MailboxDiagnosticsTest extends TestCase
{
    private const PASSWORD = 'not-a-real-mail-app-password';

    private FakeImapServer $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = new FakeImapServer;
    }

    public function test_every_step_passes_against_a_healthy_server(): void
    {
        $report = $this->diagnose();

        $this->assertTrue($report->ok());
        $this->assertSame(
            ['dns' => 'passed', 'tcp' => 'passed', 'tls' => 'passed', 'login' => 'passed', 'folder' => 'passed'],
            $this->statuses($report),
        );
        $this->assertSame(['LOGIN', 'STATUS "INBOX" (MESSAGES UNSEEN UIDVALIDITY)', 'LOGOUT'], $this->server->commands);
        $this->assertFalse($this->server->connected);
    }

    /**
     * @return array<string, array{0: callable(FakeImapServer): void, 1: DiagnosticStep, 2: FailureType}>
     */
    public static function failures(): array
    {
        return [
            'dns' => [fn (FakeImapServer $s) => $s->resolves = false, DiagnosticStep::Dns, FailureType::DnsNotFound],
            'tcp' => [fn (FakeImapServer $s) => $s->connectFailure = FailureType::ConnectionRefused, DiagnosticStep::Tcp, FailureType::ConnectionRefused],
            'tls' => [fn (FakeImapServer $s) => $s->tlsFailure = FailureType::CertificateNameMismatch, DiagnosticStep::Tls, FailureType::CertificateNameMismatch],
            'login' => [fn (FakeImapServer $s) => $s->loginResponse = 'NO [AUTHENTICATIONFAILED] Authentication failed.', DiagnosticStep::Login, FailureType::LoginFailed],
            'folder' => [fn (FakeImapServer $s) => $s->folders = ['Archive'], DiagnosticStep::Folder, FailureType::FolderNotFound],
        ];
    }

    #[DataProvider('failures')]
    public function test_a_failing_step_stops_the_test_and_skips_the_rest(callable $break, DiagnosticStep $step, FailureType $type): void
    {
        $break($this->server);

        $report = $this->diagnose();

        $this->assertFalse($report->ok());
        $this->assertSame($step, $report->failedStep);
        $this->assertSame($type, $report->failure);

        $seen = false;
        foreach ($report->steps as $result) {
            $expected = match (true) {
                $result->step === $step => StepStatus::Failed,
                $seen => StepStatus::Skipped,
                default => StepStatus::Passed,
            };
            $seen = $seen || $result->step === $step;
            $this->assertSame($expected, $result->status, $result->step->value);
        }
        $this->assertNotContains('LOGOUT', $this->server->commands);
    }

    public function test_starttls_upgrades_the_connection_on_port_143(): void
    {
        $report = $this->diagnose(port: 143, encryption: 'tls');

        $this->assertTrue($report->ok());
        $this->assertTrue($this->server->tls);
        $this->assertSame('STARTTLS', $this->server->commands[0]);
    }

    public function test_no_encryption_skips_the_encryption_step(): void
    {
        $report = $this->diagnose(port: 143, encryption: 'none');

        $this->assertTrue($report->ok());
        $this->assertSame('skipped', $this->statuses($report)['tls']);
        $this->assertFalse($this->server->tls);
    }

    public function test_each_step_reports_its_time(): void
    {
        foreach ($this->diagnose()->steps as $result) {
            $this->assertGreaterThanOrEqual(0, $result->milliseconds);
        }
    }

    public function test_the_report_survives_a_round_trip_without_the_password(): void
    {
        $report = $this->diagnose();
        $stored = $report->toArray();

        $this->assertStringNotContainsString(self::PASSWORD, json_encode($stored));
        $this->assertEquals($report, DiagnosticReport::fromArray($stored));
    }

    public function test_a_connection_never_serialises_or_dumps_its_password(): void
    {
        $connection = $this->connection();

        $this->assertStringNotContainsString(self::PASSWORD, print_r($connection, true));
        $this->expectException(LogicException::class);
        serialize($connection);
    }

    private function diagnose(int $port = 993, string $encryption = 'ssl'): DiagnosticReport
    {
        return (new MailboxDiagnostics($this->server))->run($this->connection($port, $encryption));
    }

    private function connection(int $port = 993, string $encryption = 'ssl'): MailboxConnection
    {
        return new MailboxConnection('mail.example.test', $port, $encryption, true, 'communications@example.test', self::PASSWORD);
    }

    /**
     * @return array<string, string>
     */
    private function statuses(DiagnosticReport $report): array
    {
        $statuses = [];
        foreach ($report->steps as $result) {
            $statuses[$result->step->value] = $result->status->value;
        }

        return $statuses;
    }
}
