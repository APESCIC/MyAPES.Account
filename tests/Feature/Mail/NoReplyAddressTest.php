<?php

namespace Tests\Feature\Mail;

use App\Core\Accounts\User;
use App\Notifications\Auth\PasswordChangedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * Outbound mail carries a no-reply Reply-To and explains how to reply (#467).
 */
class NoReplyAddressTest extends TestCase
{
    use RefreshDatabase;

    public function test_outbound_mail_carries_the_configured_no_reply_address(): void
    {
        config([
            'mail.reply_to.address' => 'no-reply@example.test',
            'mail.reply_to.name' => 'MyAPES Account (do not reply)',
        ]);
        $this->app->forgetInstance('mail.manager');
        $this->app->forgetInstance('mailer');

        $email = $this->sendPasswordChangedMail();

        $this->assertCount(1, $email->getReplyTo());
        $this->assertSame('no-reply@example.test', $email->getReplyTo()[0]->getAddress());
        $this->assertSame('MyAPES Account (do not reply)', $email->getReplyTo()[0]->getName());
    }

    public function test_reply_to_defaults_to_a_no_reply_address(): void
    {
        $this->assertSame('no-reply@apes.org.uk', config('mail.reply_to.address'));
        $this->assertNotSame('', (string) config('mail.reply_to.name'));
    }

    public function test_every_branded_email_footer_explains_how_to_reply(): void
    {
        $email = $this->sendPasswordChangedMail();

        $html = (string) $email->getHtmlBody();
        $this->assertStringContainsString('Replies to this email are not read', $html);
        $this->assertStringContainsString('Log in to reply', $html);
    }

    private function sendPasswordChangedMail(): Email
    {
        config(['mail.default' => 'array']);
        $user = User::factory()->accessLevel(User::ROLE_SERVICE_USER)->create();

        $user->notify(new PasswordChangedNotification);

        /** @var \Illuminate\Support\Collection<int, SentMessage> $messages */
        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);

        $email = $messages->first()->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $email);

        return $email;
    }
}
