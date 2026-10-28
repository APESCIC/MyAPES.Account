<?php

namespace Plugins\Tickets;

use App\Core\Accounts\User;
use App\Core\Extensions\Plugins\PluginAbilityFactory;
use App\Core\Extensions\Plugins\PluginManifest;
use App\Core\Extensions\Plugins\PluginNavigationItem;
use App\Core\Extensions\Plugins\PluginServiceProvider;
use App\Core\Extensions\Plugins\PluginSettingsSchema;
use App\Core\Mail\MailCategory;
use App\Core\Mail\MailTemplate;
use Illuminate\Support\Facades\Gate;
use Plugins\Tickets\Dashboard\SupportTicketActiveRecordDetector;
use Plugins\Tickets\Dashboard\SupportTicketAnalyticsProvider;
use Plugins\Tickets\Dashboard\SupportTicketAttentionProvider;
use Plugins\Tickets\Dashboard\SupportTicketRecentActivityProvider;
use Plugins\Tickets\Dashboard\SupportTicketSummaryProvider;
use Plugins\Tickets\Models\SupportTicket;
use Plugins\Tickets\Notifications\TicketUpdatedNotification;
use Plugins\Tickets\Policies\SupportTicketPolicy;

class TicketsServiceProvider extends PluginServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'tickets');

        Gate::policy(SupportTicket::class, SupportTicketPolicy::class);
    }

    protected function mailTemplates(): array
    {
        return [
            new MailTemplate(
                key: 'tickets.ticket_updated',
                notification: TicketUpdatedNotification::class,
                category: MailCategory::Service,
                labelKey: 'tickets::mail.templates.ticket_updated.name',
                descriptionKey: 'tickets::mail.templates.ticket_updated.description',
                sample: static fn (User $recipient): TicketUpdatedNotification => new TicketUpdatedNotification(
                    (new SupportTicket)->forceFill([
                        'id' => 1024,
                        'subject' => 'Sample support request',
                        'status' => 'open',
                        'priority' => 'medium',
                    ]),
                    $recipient,
                    'updated',
                    'apes-cic',
                    'apes-cic.tickets.show',
                    'APES CIC',
                ),
                owner: 'tickets',
            ),
        ];
    }

    protected function manifest(): PluginManifest
    {
        $a = PluginAbilityFactory::class;

        return PluginManifest::make(
            slug: 'tickets',
            name: 'Tickets',
            description: 'Support requests and threaded responses.',
            version: '1.0.0',
            requiresCore: '^0.37.0',
            compatibleModules: ['apes-cic', 'shelter-rescue', 'pet-care-clinic'],
            shippedModules: ['apes-cic', 'shelter-rescue', 'pet-care-clinic'],
            permissions: [
                $a::public('view-own', 'View own tickets'),
                $a::public('create', 'Create tickets'),
                $a::public('comment-own', 'Comment on own tickets'),
                $a::staff('view-all', 'View all tickets'),
                $a::staff('update-all', 'Update all tickets'),
                $a::staff('assign', 'Assign tickets'),
                $a::staff('close', 'Close tickets'),
                $a::staffDelete('delete', 'Delete tickets'),
            ],
            navigation: [
                new PluginNavigationItem('Tickets', 'apes-cic.tickets.index', 'ticket', 10, moduleSlug: 'apes-cic'),
                new PluginNavigationItem('Tickets', 'shelter.tickets.index', 'ticket', 20, moduleSlug: 'shelter-rescue'),
                new PluginNavigationItem('Tickets', 'petcare.tickets.index', 'ticket', 20, moduleSlug: 'pet-care-clinic'),
            ],
            settingsByModule: [
                'apes-cic' => new PluginSettingsSchema(
                    supportsSettings: true,
                    schema: PluginSettingsSchema::SCHEMA_WEBSITES_CATEGORIES,
                    groupKey: 'service_areas',
                    groupLabel: 'Service areas',
                ),
                'shelter-rescue' => new PluginSettingsSchema(supportsSettings: false),
                'pet-care-clinic' => new PluginSettingsSchema(supportsSettings: false),
            ],
            translationNamespace: 'tickets',
            searchKeywordsKey: 'tickets::plugin.keywords',
            activeRecordDetector: SupportTicketActiveRecordDetector::class,
            summaryProvider: SupportTicketSummaryProvider::class,
            recentActivityProvider: SupportTicketRecentActivityProvider::class,
            analyticsProvider: SupportTicketAnalyticsProvider::class,
            attentionProvider: SupportTicketAttentionProvider::class,
            staffRouteFiles: [
                dirname(__DIR__).'/routes/apes-cic.php',
                dirname(__DIR__).'/routes/shelter.php',
                dirname(__DIR__).'/routes/petcare.php',
            ],
        );
    }
}
