<?php

namespace Tests\Feature\Mail;

use App\Core\Accounts\User;
use App\Core\Mail\MailTemplateRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BrandedDomainMailTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string}>
     */
    public static function domainTemplateKeys(): array
    {
        return [
            'staff login reminder' => ['accounts.pending_first_login_chase'],
            'ticket updated' => ['tickets.ticket_updated'],
            'apes cic case updated' => ['cases.apes_cic_case_updated'],
            'shelter case updated' => ['cases.shelter_case_updated'],
            'consultation updated' => ['consultations.consultation_updated'],
        ];
    }

    #[DataProvider('domainTemplateKeys')]
    public function test_domain_and_chase_mail_render_on_the_shared_brand_layout(string $key): void
    {
        $recipient = User::factory()->create(['name' => 'Brand Recipient']);
        $template = app(MailTemplateRegistry::class)->find($key);
        $this->assertNotNull($template, "Template {$key} must be registered.");

        $mail = $template->sampleNotification($recipient)->toMail($recipient);
        $html = $mail->render();

        $this->assertSame('mail.brand.notification', $mail->markdown);
        $this->assertSame(__('terms.app_name'), $mail->from[1] ?? null);
        $this->assertStringContainsString(__('mail.brand.footer.help'), $html);
        $this->assertStringContainsString(url('/logos/myapes-header-light-600x128.png'), $html);
        $this->assertStringContainsString(route('help'), $html);
        $this->assertStringContainsString('Brand Recipient', $html);
        $this->assertStringNotContainsString('Regards,', $html);
        $this->assertStringNotContainsString('Hello!', $html);
        $this->assertNotNull($mail->actionUrl);
        $this->assertStringContainsString(e($mail->actionUrl), $html);
    }

    public function test_domain_mail_keeps_internal_staff_notes_out_of_the_message(): void
    {
        $recipient = User::factory()->create();
        $template = app(MailTemplateRegistry::class)->find('tickets.ticket_updated');

        $mail = $template->sampleNotification($recipient)->toMail($recipient);
        $text = implode("\n", [$mail->subject, ...$mail->introLines, ...$mail->outroLines]);

        $this->assertStringNotContainsString('staff_notes', $text);
        $this->assertStringNotContainsString('internal', strtolower($text));
    }
}
