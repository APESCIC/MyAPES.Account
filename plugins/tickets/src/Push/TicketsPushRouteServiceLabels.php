<?php

namespace Plugins\Tickets\Push;

use App\Core\Push\PushRouteServiceLabelRegistry;
use Illuminate\Support\Str;
use Plugins\Tickets\TicketServiceConfiguration;

final class TicketsPushRouteServiceLabels
{
    public static function register(PushRouteServiceLabelRegistry $registry): void
    {
        $registry->register(static function (string $routeName, array $parameters): ?string {
            if (Str::startsWith($routeName, 'petcare.')) {
                return app(TicketServiceConfiguration::class)->for('pet-care-clinic')->serviceName;
            }

            if (Str::startsWith($routeName, 'apes-cic.') && isset($parameters['ticket'])) {
                $ticket = $parameters['ticket'];
                $module = is_object($ticket) ? (string) $ticket->sub_core_key : 'apes-cic';

                return app(TicketServiceConfiguration::class)->for($module)->serviceName;
            }

            return null;
        });
    }
}
