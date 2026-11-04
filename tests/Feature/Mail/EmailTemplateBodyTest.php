<?php

namespace Tests\Feature\Mail;

use App\Core\Accounts\User;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Plugins\Tickets\Models\SupportTicket;
use Plugins\Tickets\Notifications\TicketUpdatedNotification;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * Editable email template bodies with merge fields, preview and reset (#486).
 */
class EmailTemplateBodyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
        config(['mail.default' => 'array']);
        $this->admin = User::factory()->accessLevel(User::ROLE_ADMIN)->create();
    }

    public function test_admin_edits_a_body_and_the_sent_email_uses_it(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.emails.show', 'tickets.ticket_updated'))
            ->assertOk()
            ->assertSee('name="body"', false)
            ->assertSee('<code>:id</code>', false)
            ->assertSee('<code>:name</code>', false);

        $this->saveBody('<h2>Ticket :id update</h2><p>Hi :name, your ticket about <em>:subject</em> was :event.</p><script>alert(1)</script>')
            ->assertRedirect(route('admin.emails.show', 'tickets.ticket_updated'));

        $owner = User::factory()->create(['name' => 'Robin Reader']);
        $ticket = $this->ticket($owner);
        $owner->notify(new TicketUpdatedNotification($ticket, $this->admin, 'updated', 'apes-cic', 'apes-cic.tickets.show', 'APES CIC'));

        $email = $this->lastEmail();
        $html = (string) $email->getHtmlBody();
        $this->assertMatchesRegularExpression('/<h2[^>]*>Ticket '.$ticket->id.' update<\/h2>/', $html);
        $this->assertMatchesRegularExpression('/Hi Robin Reader, your ticket about <em[^>]*>Printer jam<\/em> was updated\./', $html);
        $this->assertStringNotContainsString('alert(1)', $html);
        $this->assertStringContainsString('Log in to reply', $html);
        $this->assertStringNotContainsString('Status:', $html);

        $text = (string) $email->getTextBody();
        $this->assertStringContainsString('Hi Robin Reader, your ticket about Printer jam was updated.', $text);
        $this->assertStringNotContainsString('<em>', $text);
    }

    public function test_unknown_merge_fields_are_rejected(): void
    {
        $this->saveBody('<p>Your password is :password</p>')
            ->assertSessionHasErrors('body');
    }

    public function test_preview_shows_the_edited_body_and_reset_restores_the_standard_email(): void
    {
        $this->saveBody('<p>Custom preview body for :name</p>');

        $this->actingAs($this->admin)
            ->get(route('admin.emails.preview', 'tickets.ticket_updated'))
            ->assertOk()
            ->assertSee('Custom preview body for '.e($this->admin->name), false);

        $this->actingAs($this->admin)
            ->delete(route('admin.emails.copy.reset', 'tickets.ticket_updated'), ['confirm_reset' => '1'])
            ->assertRedirect();

        $owner = User::factory()->create();
        $owner->notify(new TicketUpdatedNotification($this->ticket($owner), $this->admin, 'updated', 'apes-cic', 'apes-cic.tickets.show', 'APES CIC'));

        $html = (string) $this->lastEmail()->getHtmlBody();
        $this->assertStringNotContainsString('Custom preview body', $html);
        $this->assertStringContainsString('Status:', $html);
    }

    public function test_templates_without_a_body_are_sent_unchanged(): void
    {
        $owner = User::factory()->create();
        $owner->notify(new TicketUpdatedNotification($this->ticket($owner), $this->admin, 'updated', 'apes-cic', 'apes-cic.tickets.show', 'APES CIC'));

        $this->assertStringContainsString('Status:', (string) $this->lastEmail()->getHtmlBody());
    }

    private function saveBody(string $body): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)
            ->from(route('admin.emails.show', 'tickets.ticket_updated'))
            ->put(route('admin.emails.copy.update', 'tickets.ticket_updated'), [
                'lock_version' => (int) \App\Core\Mail\MailTemplateOverride::query()->where('template_key', 'tickets.ticket_updated')->value('lock_version'),
                'body' => $body,
            ]);
    }

    private function ticket(User $owner): SupportTicket
    {
        return SupportTicket::query()->create([
            'sub_core_key' => 'apes-cic',
            'user_id' => $owner->id,
            'service_area' => 'it_systems',
            'sub_category' => 'device_software',
            'subject' => 'Printer jam',
            'priority' => 'medium',
            'status' => 'open',
            'description' => 'The printer is jammed.',
        ]);
    }

    private function lastEmail(): Email
    {
        $message = app('mailer')->getSymfonyTransport()->messages()->last();
        $this->assertNotNull($message);

        return $message->getOriginalMessage();
    }
}
