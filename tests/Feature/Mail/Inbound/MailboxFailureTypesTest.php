<?php

namespace Tests\Feature\Mail\Inbound;

use App\Core\Accounts\User;
use App\Core\Mail\Inbound\Contracts\InboundMailbox;
use App\Core\Mail\Inbound\Diagnostics\FailureType;
use App\Core\Mail\Inbound\Diagnostics\MailboxConnection;
use App\Core\Mail\Inbound\Diagnostics\MailboxDiagnostics;
use App\Core\Mail\Inbound\Diagnostics\MailboxTransport;
use App\Core\Mail\Inbound\Diagnostics\SocketTransport;
use App\Services\AuthorizationProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fakes\FakeImapServer;
use Tests\TestCase;

/**
 * Plain-words error types and the result panel (#772).
 */
class MailboxFailureTypesTest extends TestCase
{
    use InboundMailTestHelpers;
    use RefreshDatabase;

    private const USERNAME = 'communications@example.test';

    private const PASSWORD = 'not-a-real-mail-app-password';

    private FakeImapServer $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableEmailReplies();
        $this->server = new FakeImapServer;
        $this->app->instance(MailboxTransport::class, $this->server);
        $this->app->instance(InboundMailbox::class, new FakeMailbox);
    }

    /**
     * @return array<string, array{0: FailureType, 1: callable(FakeImapServer): void, 2?: int, 3?: string}>
     */
    public static function types(): array
    {
        return [
            'server name not found' => [FailureType::DnsNotFound, fn (FakeImapServer $s) => $s->resolves = false],
            'connection refused' => [FailureType::ConnectionRefused, fn (FakeImapServer $s) => $s->connectFailure = FailureType::ConnectionRefused],
            'no answer' => [FailureType::Timeout, fn (FakeImapServer $s) => $s->connectFailure = FailureType::Timeout],
            'wrong encryption for this port' => [FailureType::WrongEncryption, fn () => null, 993, 'tls'],
            'certificate name does not match' => [FailureType::CertificateNameMismatch, fn (FakeImapServer $s) => $s->tlsFailure = FailureType::CertificateNameMismatch],
            'certificate expired or not trusted' => [FailureType::CertificateUntrusted, fn (FakeImapServer $s) => $s->tlsFailure = FailureType::CertificateUntrusted],
            'handshake failed' => [FailureType::HandshakeFailed, fn (FakeImapServer $s) => $s->tlsFailure = FailureType::HandshakeFailed],
            'login failed' => [FailureType::LoginFailed, fn (FakeImapServer $s) => $s->loginResponse = 'NO [AUTHENTICATIONFAILED] Authentication failed.'],
            'too many attempts' => [FailureType::TooManyAttempts, fn (FakeImapServer $s) => $s->loginResponse = 'NO [LIMIT] Too many invalid IMAP connections.'],
            'folder not found' => [FailureType::FolderNotFound, fn (FakeImapServer $s) => $s->folders = ['Archive']],
        ];
    }

    #[DataProvider('types')]
    public function test_each_failure_has_its_type_and_wording(FailureType $type, callable $break, int $port = 993, string $encryption = 'ssl'): void
    {
        $break($this->server);

        $report = (new MailboxDiagnostics($this->server))->run($this->connection($port, $encryption));

        $this->assertSame($type, $report->failure);
        $this->assertSame(trans('admin.emails.import.errors.'.$type->value.'.message'), $type->message());
        $this->assertStringNotContainsString('admin.emails', $type->message().$type->hint());
        $this->assertNotSame('', $type->hint());
    }

    public function test_a_missing_feature_is_a_warning_on_a_passing_test(): void
    {
        $this->server->loginResponse = 'OK [CAPABILITY IMAP4rev1 IDLE] Logged in';

        $report = (new MailboxDiagnostics($this->server))->run($this->connection());

        $this->assertTrue($report->ok());
        $this->assertSame([FailureType::FeatureMissing], $report->warnings());
        $this->assertSame(['MOVE', 'UIDPLUS'], $report->details['missing_features']);
        $this->assertTrue(FailureType::FeatureMissing->isWarning());

        $this->submitTestForm()
            ->assertSee('data-warning="feature_missing"', false)
            ->assertSee(__('admin.emails.import.errors.feature_missing.hint', ['features' => 'MOVE, UIDPLUS']));
    }

    public function test_port_993_with_starttls_is_wrong_encryption_not_a_generic_failure(): void
    {
        $this->server->plainGreetingTimesOut = true;

        $report = (new MailboxDiagnostics($this->server))->run($this->connection(993, 'tls'));
        $this->assertSame(FailureType::WrongEncryption, $report->failure);

        $report = (new MailboxDiagnostics($this->server))->run($this->connection(1993, 'tls'));
        $this->assertSame(FailureType::WrongEncryption, $report->failure);
    }

    public function test_tls_and_connection_errors_from_php_are_classified(): void
    {
        $this->assertSame(FailureType::CertificateNameMismatch, SocketTransport::tlsFailure("stream_socket_enable_crypto(): Peer certificate CN=`my.example.test' did not match expected CN=`mail.example.test'"));
        $this->assertSame(FailureType::CertificateUntrusted, SocketTransport::tlsFailure('SSL operation failed with code 1. OpenSSL Error messages: error:0A000086:SSL routines::certificate verify failed'));
        $this->assertSame(FailureType::WrongEncryption, SocketTransport::tlsFailure('error:0A00010B:SSL routines::wrong version number'));
        $this->assertSame(FailureType::HandshakeFailed, SocketTransport::tlsFailure('Failed to enable crypto'));
        $this->assertSame(FailureType::ConnectionRefused, SocketTransport::connectFailure(111, 'Connection refused'));
        $this->assertSame(FailureType::Timeout, SocketTransport::connectFailure(110, 'Connection timed out'));
        $this->assertSame(FailureType::DnsNotFound, SocketTransport::connectFailure(0, 'php_network_getaddresses: getaddrinfo for nowhere.test failed'));
    }

    public function test_the_panel_explains_a_failure_without_server_text_or_credentials(): void
    {
        $this->server->loginResponse = 'NO [AUTHENTICATIONFAILED] Authentication failed for '.self::USERNAME;

        $this->submitTestForm()
            ->assertSee('role="status"', false)
            ->assertSee(__('admin.emails.import.errors.login_failed.message'))
            ->assertSee(__('admin.emails.import.errors.login_failed.hint'))
            ->assertDontSee('AUTHENTICATIONFAILED')
            ->assertDontSee('Authentication failed for')
            ->assertDontSee(self::PASSWORD);
    }

    public function test_folder_not_found_lists_the_folders_on_the_server(): void
    {
        $this->server->folders = ['Archive', 'Sent'];

        $this->submitTestForm()
            ->assertSee(__('admin.emails.import.errors.folder_not_found.message'))
            ->assertSee(__('admin.emails.import.server_folders', ['folders' => 'Archive, Sent']));
    }

    public function test_a_successful_test_shows_counts_capabilities_and_certificate_expiry(): void
    {
        $this->submitTestForm()
            ->assertSee(__('admin.emails.import.details.messages_value', ['count' => 3, 'unseen' => 1]))
            ->assertSee('IDLE: yes, MOVE: yes, UIDPLUS: yes')
            ->assertSee(__('admin.emails.import.details.certificate_value', ['name' => 'mail.example.test', 'date' => '2030-01-01']));
    }

    private function submitTestForm(): TestResponse
    {
        $admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();

        return $this->actingAs($admin)->followingRedirects()->post(route('admin.settings.reply-mailbox.test'), [
            '_method' => 'PUT',
            'host' => 'mail.example.test',
            'port' => '993',
            'encryption' => 'ssl',
            'validate_cert' => '1',
            'username' => self::USERNAME,
            'password' => self::PASSWORD,
            'folder' => 'INBOX',
        ]);
    }

    private function connection(int $port = 993, string $encryption = 'ssl'): MailboxConnection
    {
        return new MailboxConnection('mail.example.test', $port, $encryption, true, self::USERNAME, self::PASSWORD);
    }
}
