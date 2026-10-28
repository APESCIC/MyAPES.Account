<?php

namespace Tests\Feature\Mail;

use App\Core\Accounts\User;
use App\Core\Mail\MailCategory;
use App\Core\Mail\MailTemplateRegistry;
use App\Core\Mail\OutboundMailLog;
use App\Services\AuthorizationProfile;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Mail matrix for the email templates epic (#351, #362).
 *
 * Templates: every key in the Core registry (asserted against the list below, so a new
 * template fails here until it is added to the matrix).
 *
 * Per template: registered with labels; sample renders on the shared brand layout with the
 * "MyAPES Account" from-name, logo and Help link; queued; subject free of secrets; sending
 * leaves one metadata-only log row; Email preference gates Optional mail only.
 *
 * Admin Emails personas and abilities:
 * | Persona       | admin.emails.view | admin.emails.manage | List/preview/log | Send test / edit wording |
 * | Guest         | -                 | -                   | sign-in redirect | sign-in redirect         |
 * | Service user  | no                | no                  | 403              | 403                      |
 * | Student       | no                | no                  | 403              | 403                      |
 * | Volunteer     | no                | no                  | 403              | 403                      |
 * | Staff         | no                | no                  | 403              | 403                      |
 * | Administrator | yes               | no                  | 200              | 403                      |
 * | Super Admin   | yes               | yes                 | 200              | allowed                  |
 */
class MailTemplateMatrixTest extends TestCase
{
    use RefreshDatabase;

    private const TEMPLATE_KEYS = [
        'accounts.pending_first_login_chase',
        'auth.email_change_completed',
        'auth.email_change_confirm',
        'auth.email_change_started',
        'auth.passkey_added',
        'auth.passkey_removed',
        'auth.password_changed',
        'auth.reset_password',
        'auth.two_factor_disabled',
        'auth.two_factor_enabled',
        'auth.verify_email',
        'cases.apes_cic_case_updated',
        'cases.shelter_case_updated',
        'consultations.consultation_updated',
        'recruitment.application_received',
        'recruitment.application_status',
        'tickets.ticket_updated',
    ];

    /**
     * @return array<string, array{string}>
     */
    public static function templateKeys(): array
    {
        return array_combine(self::TEMPLATE_KEYS, array_map(static fn (string $key): array => [$key], self::TEMPLATE_KEYS));
    }

    public function test_matrix_covers_every_registered_template(): void
    {
        $this->assertSame(self::TEMPLATE_KEYS, array_keys(app(MailTemplateRegistry::class)->all()));
    }

    #[DataProvider('templateKeys')]
    public function test_template_renders_branded_queued_and_secret_free(string $key): void
    {
        $template = app(MailTemplateRegistry::class)->find($key);
        $recipient = $this->recipient(emailPreference: true);
        $notification = $template->sampleNotification($recipient);
        $mail = $notification->toMail($recipient);
        $html = $mail->render();
        $subject = strtolower((string) $mail->subject);

        $this->assertNotSame($template->labelKey, $template->label());
        $this->assertInstanceOf(ShouldQueue::class, $notification);
        $this->assertSame(__('terms.app_name'), $mail->from[1] ?? null);
        $this->assertStringContainsString(url('/logos/myapes-header-light-600x128.png'), $html);
        $this->assertStringContainsString(__('mail.brand.footer.help'), $html);
        $this->assertStringContainsString(route('help'), $html);
        $this->assertStringNotContainsString('Regards,', $html);

        foreach (['sample-preview-token', 'token=', 'password:', 'secret', 'totp', 'signature='] as $needle) {
            $this->assertStringNotContainsString($needle, $subject, "{$key} subject contains {$needle}");
        }
    }

    #[DataProvider('templateKeys')]
    public function test_sending_logs_metadata_only(string $key): void
    {
        $template = app(MailTemplateRegistry::class)->find($key);
        $recipient = $this->recipient(emailPreference: true, email: 'matrix.person@example.com');

        Notification::sendNow($recipient, $template->sampleNotification($recipient), ['mail']);

        $entry = OutboundMailLog::query()->sole();
        $this->assertSame($key, $entry->template_key);
        $this->assertSame('example.com', $entry->recipient_domain);
        $stored = json_encode($entry->toArray(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('matrix.person', $stored);
        $this->assertStringNotContainsString('sample-preview-token', $stored);
        $this->assertStringNotContainsString('http', $stored);
    }

    #[DataProvider('templateKeys')]
    public function test_email_preference_gates_optional_mail_only(string $key): void
    {
        $template = app(MailTemplateRegistry::class)->find($key);
        $off = $this->recipient(emailPreference: false);
        $on = $this->recipient(emailPreference: true);

        $this->assertContains('mail', $template->sampleNotification($on)->via($on));
        $this->assertSame(
            $template->category !== MailCategory::Optional,
            in_array('mail', $template->sampleNotification($off)->via($off), true),
        );
    }

    /**
     * @return array<string, array{string|null, int, int}>
     */
    public static function personas(): array
    {
        return [
            'guest' => [null, 302, 302],
            'service user' => [AuthorizationProfile::ROLE_SERVICE_USER, 403, 403],
            'student' => [AuthorizationProfile::ROLE_STUDENT, 403, 403],
            'volunteer' => [AuthorizationProfile::ROLE_VOLUNTEER, 403, 403],
            'staff' => [AuthorizationProfile::ROLE_STAFF, 403, 403],
            'administrator' => [AuthorizationProfile::ROLE_ADMINISTRATOR, 200, 403],
            'super admin' => [AuthorizationProfile::ROLE_SUPER_ADMIN, 200, 302],
        ];
    }

    #[DataProvider('personas')]
    public function test_admin_emails_persona_gates(?string $role, int $viewStatus, int $manageStatus): void
    {
        Notification::fake();

        if ($role !== null) {
            $this->actingAs(User::factory()->protectedRole($role)->create());
        }

        foreach ([
            route('admin.emails.index'),
            route('admin.emails.show', 'auth.verify_email'),
            route('admin.emails.preview', 'auth.verify_email'),
            route('admin.emails.log'),
        ] as $url) {
            $this->get($url)->assertStatus($viewStatus);
        }

        $this->post(route('admin.emails.test', 'auth.password_changed'))->assertStatus($manageStatus);
        $this->put(route('admin.emails.copy.update', 'auth.password_changed'), ['subject' => 'Matrix', 'lock_version' => 0])
            ->assertStatus($manageStatus);
        $this->delete(route('admin.emails.copy.reset', 'auth.password_changed'), ['confirm_reset' => '1'])
            ->assertStatus($manageStatus);
    }

    private function recipient(bool $emailPreference, ?string $email = null): User
    {
        $user = User::factory()->create($email === null ? [] : ['email' => $email]);
        $user->contactPreference()->updateOrCreate([], ['email' => $emailPreference, 'policy_version' => 'test', 'confirmed_at' => now()]);

        return $user->fresh();
    }
}
