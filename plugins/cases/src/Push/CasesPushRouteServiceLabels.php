<?php

namespace Plugins\Cases\Push;

use App\Core\Push\PushRouteServiceLabelRegistry;
use Illuminate\Support\Str;
use Plugins\Cases\CasesArea;

final class CasesPushRouteServiceLabels
{
    public static function register(PushRouteServiceLabelRegistry $registry): void
    {
        $registry->register(static function (string $routeName, array $parameters): ?string {
            if (Str::startsWith($routeName, 'shelter.')) {
                return CasesArea::forModule('shelter-rescue')->serviceLabel;
            }

            if (Str::startsWith($routeName, 'apes-cic.') && ! isset($parameters['ticket'])) {
                return CasesArea::forModule('apes-cic')->serviceLabel;
            }

            return null;
        });
    }
}
