<?php

namespace App\Services;

use App\Core\Accounts\User;

use App\Core\Extensions\Plugins\PluginEnablement;
use Illuminate\Support\Facades\Route;
use Plugins\Cases\CasesArea;
use Plugins\Tickets\TicketsArea;

/**
 * Module/plugin open links and "new for this client" quick actions (#520).
 */
final class UserProfileHubActions
{
    /** @var list<array{module: string, plugin: string, area: string}> */
    private const CREATE_TARGETS = [
        ['module' => 'apes-cic', 'plugin' => 'tickets', 'area' => 'tickets'],
        ['module' => 'shelter-rescue', 'plugin' => 'tickets', 'area' => 'tickets'],
        ['module' => 'pet-care-clinic', 'plugin' => 'tickets', 'area' => 'tickets'],
        ['module' => 'apes-cic', 'plugin' => 'cases', 'area' => 'cases'],
    ];

    public function __construct(
        private readonly PluginEnablement $plugins,
    ) {}

    /**
     * @return list<array{key: string, label: string, url: string, kind: string, module: string, plugin: string}>
     */
    public function forClient(User $client, User $viewer): array
    {
        $actions = [];

        foreach (self::CREATE_TARGETS as $target) {
            if (! $this->plugins->isEnabled($target['module'], $target['plugin'])) {
                continue;
            }

            $viewAll = "{$target['module']}.{$target['plugin']}.view-all";
            if (! $viewer->can($viewAll)) {
                continue;
            }

            $indexRoute = $this->indexRouteName($target['module'], $target['area']);
            if ($indexRoute === null || ! Route::has($indexRoute)) {
                continue;
            }

            $actions[] = [
                'key' => "create.{$target['module']}.{$target['plugin']}",
                'label' => $this->createLabel($target['plugin'], $target['module']),
                'url' => '#hub-create-'.$target['plugin'].'-'.$target['module'],
                'kind' => 'create',
                'module' => $target['module'],
                'plugin' => $target['plugin'],
                'open_url' => route($indexRoute),
            ];
        }

        return $actions;
    }

    private function indexRouteName(string $module, string $area): ?string
    {
        return match ($area) {
            'tickets' => TicketsArea::forModule($module)->indexRouteName(),
            'cases' => CasesArea::forModule($module)->indexRouteName(),
            default => null,
        };
    }

    private function createLabel(string $plugin, string $module): string
    {
        $moduleLabel = match ($module) {
            'apes-cic' => 'APES CIC',
            'shelter-rescue' => 'Shelter',
            'pet-care-clinic' => 'Pet Care',
            default => $module,
        };

        return match ($plugin) {
            'tickets' => __('admin.users.hub.actions.new_ticket', ['module' => $moduleLabel]),
            'cases' => __('admin.users.hub.actions.new_case', ['module' => $moduleLabel]),
            default => __('admin.users.hub.actions.new_record', ['module' => $moduleLabel]),
        };
    }
}
