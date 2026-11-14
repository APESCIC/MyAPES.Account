<?php

namespace App\Core\Providers;

use App\Core\Mail\BrandedMailChannel;
use App\Core\Mail\CoreMailTemplates;
use App\Core\Mail\Inbound\Contracts\InboundMailbox;
use App\Core\Mail\Inbound\Diagnostics\MailboxTransport;
use App\Core\Mail\Inbound\Diagnostics\SocketTransport;
use App\Core\Mail\Inbound\Herd\HerdMailbox;
use App\Core\Mail\Inbound\Herd\HerdMailRunner;
use App\Core\Mail\Inbound\Herd\ProcessHerdMailRunner;
use App\Core\Mail\Inbound\ImapMailbox;
use App\Core\Mail\Inbound\InboundMailboxSettings;
use App\Core\Mail\Inbound\InboundMailSettings;
use App\Core\Mail\Inbound\InboundReplyRegistry;
use App\Core\Mail\Inbound\Mailpit\MailpitMailbox;
use App\Core\Mail\Inbound\ReplyToken;
use App\Core\Mail\MailCopyOverrides;
use App\Core\Mail\MailTemplateRegistry;
use App\Core\Mail\RecordOutboundMail;
use App\Core\Secrets\SecretDefinition;
use App\Core\Secrets\SecretRegistry;
use App\Core\Settings\AppSettings;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class CoreMailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MailTemplateRegistry::class);
        $this->app->singleton(MailCopyOverrides::class);
        $this->app->singleton(InboundReplyRegistry::class);
        $this->app->singleton(SecretRegistry::class, static function ($app): SecretRegistry {
            $registry = new SecretRegistry($app->make(AppSettings::class));
            $registry->register(new SecretDefinition(
                SecretRegistry::INBOUND_MAIL_IMAP_PASSWORD,
                'communications@ Mail Client app password',
                'INBOUND_MAIL_IMAP_PASSWORD',
                'IMAP password for the built-in communications@ mailbox.',
            ));

            return $registry;
        });
        $this->app->singleton(InboundMailSettings::class);
        $this->app->singleton(ReplyToken::class);
        $this->app->afterResolving(AppSettings::class, static fn (AppSettings $settings) => $settings->define(...InboundMailboxSettings::definitions()));
        $this->app->bind(HerdMailRunner::class, ProcessHerdMailRunner::class);
        $this->app->bind(InboundMailbox::class, function ($app): InboundMailbox {
            $settings = $app->make(InboundMailSettings::class);

            return match (true) {
                $settings->usesMailpit() => $app->make(MailpitMailbox::class),
                $settings->usesHerd() => $app->make(HerdMailbox::class),
                default => $app->make(ImapMailbox::class),
            };
        });
        $this->app->bind(MailboxTransport::class, SocketTransport::class);
        $this->app->bind(MailChannel::class, BrandedMailChannel::class);
    }

    public function boot(): void
    {
        $this->app->make(MailTemplateRegistry::class)
            ->contribute(static fn (): array => CoreMailTemplates::all());

        Event::listen(NotificationSent::class, [RecordOutboundMail::class, 'sent']);
        Event::listen(NotificationFailed::class, [RecordOutboundMail::class, 'failed']);

        // Plugins register their lang namespaces and templates during boot, so overrides apply once everything has booted.
        $this->app->booted(fn () => $this->app->make(MailCopyOverrides::class)->apply());
        Event::listen(JobProcessing::class, fn () => $this->app->make(MailCopyOverrides::class)->apply());
    }
}
