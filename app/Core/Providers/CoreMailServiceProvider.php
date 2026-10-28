<?php

namespace App\Core\Providers;

use App\Core\Mail\CoreMailTemplates;
use App\Core\Mail\MailCopyOverrides;
use App\Core\Mail\MailTemplateRegistry;
use App\Core\Mail\RecordOutboundMail;
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
