<?php

namespace Tests\Feature\Admin;

use App\Core\Accounts\User;
use App\Core\Mail\Inbound\Diagnostics\DiagnosticReport;
use App\Core\Mail\Inbound\Diagnostics\DiagnosticStep;
use App\Core\Mail\Inbound\Diagnostics\FailureType;
use App\Core\Mail\Inbound\Diagnostics\StepResult;
use App\Core\Mail\Inbound\Diagnostics\StepStatus;
use App\Core\Mail\Inbound\ReplyMailboxStatus;
use App\Core\Secrets\SecretRegistry;
use App\Services\AuthorizationProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The readiness checklist on Admin → Emails → Import (#775, #811).
 */
class ImportReadinessTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'not-a-real-mail-app-password';

    private const TOKEN_KEY = 'not-a-real-inbound-token-key';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app-settings.env' => array_fill_keys(array_keys((array) config('app-settings.env')), null),
            'inbound-mail.token_key' => self::TOKEN_KEY,
            'inbound-mail.imap.password' => null,
        ]);
        $this->admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();
    }

    public function test_the_token_key_item(): void
    {
        $this->assertItem('token_key', 'ok');

        config(['inbound-mail.token_key' => null]);
        $this->page()
            ->assertSee(__('admin.emails.import.readiness.items.token_key.missing'))
            ->assertSee(__('admin.emails.import.readiness.fixed_by.it'));
        $this->assertItem('token_key', 'missing', 'it');
    }

    public function test_the_password_item(): void
    {
        $this->assertItem('password', 'missing', 'admin');

        app(SecretRegistry::class)->set(SecretRegistry::INBOUND_MAIL_IMAP_PASSWORD, self::PASSWORD);
        $this->assertItem('password', 'ok');
    }

    public function test_the_last_test_item(): void
    {
        $this->assertItem('test', 'missing', 'admin');

        app(ReplyMailboxStatus::class)->recordTest(new DiagnosticReport(
            [new StepResult(DiagnosticStep::Dns, StepStatus::Failed)],
            FailureType::DnsNotFound,
            DiagnosticStep::Dns,
        ));
        $this->assertItem('test', 'missing', 'admin');

        app(ReplyMailboxStatus::class)->recordTest(new DiagnosticReport([new StepResult(DiagnosticStep::Dns, StepStatus::Passed)]));
        $this->assertItem('test', 'ok');
    }

    public function test_the_heartbeat_item_turns_to_a_cross_after_five_minutes(): void
    {
        $this->page()->assertSee(__('admin.emails.import.readiness.items.heartbeat.not_recorded'));
        $this->assertItem('heartbeat', 'missing', 'it');

        $this->artisan('tickets:inbound-poll')->assertSuccessful();
        $this->assertItem('heartbeat', 'ok');

        $this->travel(6)->minutes();
        $this->page()->assertSee(__('admin.emails.import.readiness.items.heartbeat.missing', ['minutes' => 5]));
        $this->assertItem('heartbeat', 'missing', 'it');
    }

    public function test_the_probe_item(): void
    {
        $this->assertItem('probe', 'missing', 'admin');

        $status = app(ReplyMailboxStatus::class);
        $status->acceptProbe([$status->startProbe()]);
        $this->assertItem('probe', 'ok');
    }

    public function test_no_secret_value_is_rendered(): void
    {
        app(SecretRegistry::class)->set(SecretRegistry::INBOUND_MAIL_IMAP_PASSWORD, self::PASSWORD);

        $this->page()
            ->assertSee(__('admin.emails.import.readiness.items.password.ok'))
            ->assertSee(__('admin.emails.import.readiness.items.token_key.ok'))
            ->assertDontSee(self::PASSWORD)
            ->assertDontSee(self::TOKEN_KEY);
    }

    public function test_the_connection_panel_shows_sources_without_secrets(): void
    {
        app(SecretRegistry::class)->set(SecretRegistry::INBOUND_MAIL_IMAP_PASSWORD, self::PASSWORD);

        $this->page()
            ->assertSee('data-connection-panel', false)
            ->assertSee('data-connection-row="host" data-source="built_in"', false)
            ->assertSee('data-connection-row="password" data-source="keys_and_secrets"', false)
            ->assertSee(__('admin.emails.import.sources.built_in'), false)
            ->assertDontSee(self::PASSWORD)
            ->assertDontSee(self::TOKEN_KEY);
    }

    private function assertItem(string $key, string $state, ?string $fixedBy = null): void
    {
        $html = (string) $this->page()->getContent();

        $this->assertMatchesRegularExpression('/data-readiness="'.$key.'" data-readiness-state="'.$state.'"/', $html);

        preg_match('/<li data-readiness="'.$key.'".*?<\/li>/s', $html, $item);
        if ($fixedBy === null) {
            $this->assertStringNotContainsString('data-fixed-by', $item[0]);
        } else {
            $this->assertStringContainsString('data-fixed-by="'.$fixedBy.'"', $item[0]);
        }
    }

    private function page(): TestResponse
    {
        return $this->actingAs($this->admin)->get(route('admin.emails.import.index'))->assertOk();
    }
}
