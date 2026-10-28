<?php

namespace Tests\Feature\Mail;

use App\Core\Accounts\User;
use App\Core\Mail\MailCategory;
use App\Core\Mail\MailTemplate;
use App\Core\Mail\MailTemplateRegistry;
use App\Notifications\Auth\VerifyEmailNotification;
use App\Notifications\PendingFirstLoginChaseNotification;
use App\Notifications\TicketUpdatedNotification as LegacyTicketUpdatedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use LogicException;
use Plugins\Tickets\Notifications\TicketUpdatedNotification;
use Tests\TestCase;

class MailTemplateRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_registry_lists_core_and_plugin_templates_with_stable_keys(): void
    {
        $keys = array_keys(app(MailTemplateRegistry::class)->all());

        $this->assertSame([
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
            'tickets.ticket_updated',
        ], $keys);
    }

    public function test_every_template_has_translated_labels_and_a_category(): void
    {
        foreach (app(MailTemplateRegistry::class)->all() as $template) {
            $this->assertNotSame($template->labelKey, $template->label(), "Missing label for {$template->key}");
            $this->assertNotSame($template->descriptionKey, $template->description(), "Missing description for {$template->key}");
            $this->assertNotSame('mail.categories.'.$template->category->value, $template->category->label());
        }
    }

    public function test_every_sample_renders_without_sending_mail(): void
    {
        Notification::fake();
        $recipient = User::factory()->create(['name' => 'Preview Admin']);

        foreach (app(MailTemplateRegistry::class)->all() as $template) {
            $notification = $template->sampleNotification($recipient);

            $this->assertInstanceOf($template->notification, $notification);

            $mail = $notification->toMail($recipient);
            $this->assertInstanceOf(MailMessage::class, $mail);
            $this->assertNotSame('', (string) $mail->subject, "Empty subject for {$template->key}");
            $this->assertNotSame('', (string) $mail->render(), "Empty render for {$template->key}");
        }

        Notification::assertNothingSent();
    }

    public function test_lookup_by_notification_class_and_compatibility_subclass(): void
    {
        $registry = app(MailTemplateRegistry::class);

        $this->assertSame('auth.verify_email', $registry->forNotification(new VerifyEmailNotification)?->key);
        $this->assertSame('accounts.pending_first_login_chase', $registry->forNotification(PendingFirstLoginChaseNotification::class)?->key);
        $this->assertSame('tickets.ticket_updated', $registry->forNotification(TicketUpdatedNotification::class)?->key);
        $this->assertSame('tickets.ticket_updated', $registry->forNotification(LegacyTicketUpdatedNotification::class)?->key);
        $this->assertNull($registry->forNotification(self::class));
    }

    public function test_auth_and_chase_categories_are_mandatory(): void
    {
        $registry = app(MailTemplateRegistry::class);

        $this->assertSame(MailCategory::Security, $registry->find('auth.verify_email')?->category);
        $this->assertSame(MailCategory::Service, $registry->find('accounts.pending_first_login_chase')?->category);
        $this->assertTrue(MailCategory::Security->isMandatory());
        $this->assertTrue(MailCategory::Service->isMandatory());
        $this->assertFalse(MailCategory::Optional->isMandatory());
    }

    public function test_registered_auth_templates_send_as_myapes_account(): void
    {
        $recipient = User::factory()->create();
        $registry = app(MailTemplateRegistry::class);

        foreach ($registry->all() as $template) {
            if (! str_starts_with($template->key, 'auth.')) {
                continue;
            }

            $mail = $template->sampleNotification($recipient)->toMail($recipient);
            $this->assertSame(__('terms.app_name'), $mail->from[1] ?? null, $template->key);
        }
    }

    public function test_duplicate_keys_are_rejected(): void
    {
        $registry = new MailTemplateRegistry;
        $template = $this->template('demo.duplicate');
        $registry->contribute(static fn (): array => [$template]);
        $registry->contribute(static fn (): array => [$template]);

        $this->expectException(LogicException::class);
        $registry->all();
    }

    public function test_invalid_keys_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->template('Not A Key');
    }

    public function test_shared_brand_layout_is_not_owned_by_the_auth_kit(): void
    {
        $this->assertFileExists(resource_path('views/mail/brand/layout.blade.php'));
        $this->assertFileExists(resource_path('views/mail/brand/message.blade.php'));
        $this->assertFileDoesNotExist(resource_path('views/mail/auth/layout.blade.php'));
    }

    private function template(string $key): MailTemplate
    {
        return new MailTemplate(
            key: $key,
            notification: VerifyEmailNotification::class,
            category: MailCategory::Security,
            labelKey: 'mail.templates.auth.verify_email.name',
            descriptionKey: 'mail.templates.auth.verify_email.description',
            sample: static fn (): VerifyEmailNotification => new VerifyEmailNotification,
        );
    }
}
