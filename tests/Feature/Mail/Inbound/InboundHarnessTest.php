<?php

namespace Tests\Feature\Mail\Inbound;

use App\Core\Accounts\User;
use App\Core\Mail\Inbound\InboundEmail;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Plugins\Tickets\Models\SupportTicketMessage;
use Tests\TestCase;

/**
 * Local harness for replies by email (#587): key, sample reply and ingest.
 */
class InboundHarnessTest extends TestCase
{
    use InboundMailTestHelpers;
    use RefreshDatabase;

    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
        Storage::fake('private');
        Notification::fake();
        $this->workDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'myapes-inbound-'.bin2hex(random_bytes(4));
        mkdir($this->workDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workDir.DIRECTORY_SEPARATOR.'{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        @rmdir($this->workDir);
        parent::tearDown();
    }

    public function test_the_key_command_writes_a_dedicated_key_without_printing_it(): void
    {
        file_put_contents($this->workDir.'/.env', "APP_NAME=Test\nINBOUND_MAIL_ENABLED=false\nINBOUND_MAIL_TOKEN_KEY=\n");
        $this->app->useEnvironmentPath($this->workDir);

        $this->artisan('inbound-mail:key', ['--enable' => true])
            ->doesntExpectOutputToContain('INBOUND_MAIL_TOKEN_KEY=')
            ->assertSuccessful();

        $env = (string) file_get_contents($this->workDir.'/.env');
        $this->assertMatchesRegularExpression('/^INBOUND_MAIL_TOKEN_KEY=[a-f0-9]{64}$/m', $env);
        $this->assertStringContainsString('INBOUND_MAIL_ENABLED=true', $env);
        $this->assertStringContainsString('APP_NAME=Test', $env);

        $this->artisan('inbound-mail:key')->expectsOutputToContain('already set')->assertSuccessful();
        $this->assertSame($env, (string) file_get_contents($this->workDir.'/.env'));
    }

    public function test_a_sample_reply_runs_end_to_end_without_a_mailbox(): void
    {
        $this->enableEmailReplies(['inbound-mail.authserv_id' => '']);
        $owner = User::factory()->create(['email' => 'owner@example.org']);
        $ticket = $this->ticketFor($owner);
        $file = $this->workDir.'/reply.eml';

        $this->artisan('tickets:inbound-sample', ['record' => 'T'.$ticket->id, 'email' => 'owner@example.org', '--body' => 'Replying from Laragon.', '--output' => $file])
            ->assertSuccessful();
        $this->assertFileExists($file);

        $this->artisan('tickets:inbound-ingest', ['file' => $file])
            ->expectsOutputToContain(__('admin.emails.inbound.statuses.posted'))
            ->assertSuccessful();

        $message = SupportTicketMessage::query()->sole();
        $this->assertTrue($message->via_email);
        $this->assertStringContainsString('Replying from Laragon.', (string) $message->message);
        $this->assertSame('file', InboundEmail::query()->sole()->source);

        $this->artisan('tickets:inbound-ingest', ['file' => $file])
            ->expectsOutputToContain('already received')
            ->assertSuccessful();
        $this->assertSame(1, SupportTicketMessage::query()->count());
    }

    public function test_ingesting_an_auto_reply_reports_it_ignored(): void
    {
        $this->enableEmailReplies();
        file_put_contents($this->workDir.'/auto.eml', $this->fixture('auto-reply'));

        $this->artisan('tickets:inbound-ingest', ['file' => $this->workDir.'/auto.eml'])
            ->expectsOutputToContain(__('admin.emails.inbound.statuses.ignored'))
            ->assertSuccessful();
    }

    public function test_the_harness_refuses_bad_input(): void
    {
        $this->artisan('tickets:inbound-ingest', ['file' => $this->workDir.'/missing.eml'])->assertFailed();
        $this->artisan('tickets:inbound-sample', ['record' => 'T1', 'email' => 'owner@example.org'])
            ->expectsOutputToContain('inbound-mail:key')
            ->assertFailed();

        $this->enableEmailReplies();
        $this->artisan('tickets:inbound-sample', ['record' => 'Z1', 'email' => 'owner@example.org'])->assertFailed();
        $this->artisan('tickets:inbound-sample', ['record' => 'T1', 'email' => 'nobody@example.org'])->assertFailed();
    }
}
