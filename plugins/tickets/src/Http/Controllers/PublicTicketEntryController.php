<?php

namespace Plugins\Tickets\Http\Controllers;

use App\Contracts\ModuleRegistry;
use App\Core\Extensions\Modules\ModulePackageRegistry;
use App\Core\Extensions\Plugins\PluginEnablement;
use App\Http\Controllers\Controller;
use App\Support\WorkflowLabels;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Plugins\Tickets\TicketCategoryResolver;
use Plugins\Tickets\TicketServiceConfiguration;

/**
 * Public "Open a ticket" page (#466): where visitors can raise a ticket, and
 * a sign-in step that returns them to the prefilled form.
 */
class PublicTicketEntryController extends Controller
{
    private const SERVICES = ['apes-cic', 'shelter-rescue', 'pet-care-clinic'];

    public function __construct(
        private readonly TicketServiceConfiguration $ticketServices,
        private readonly TicketCategoryResolver $categories,
        private readonly ModuleRegistry $registry,
        private readonly ModulePackageRegistry $modulePackages,
        private readonly PluginEnablement $enablement,
    ) {}

    public function index(): View
    {
        $services = [];

        foreach (self::SERVICES as $key) {
            if (! $this->available($key)) {
                continue;
            }

            $definition = $this->ticketServices->for($key);
            $services[] = [
                'key' => $key,
                'name' => $this->registry->subCore($key)->name,
                'description' => $definition->supportingCopy,
                'areas' => array_map(
                    fn (string $area): array => ['key' => $area, 'label' => $this->areaLabel($key, $area)],
                    $definition->serviceAreas,
                ),
            ];
        }

        return view('tickets::public.index', ['services' => $services]);
    }

    public function start(Request $request, string $service, string $area): RedirectResponse
    {
        abort_unless(in_array($service, self::SERVICES, true) && $this->available($service), 404);

        $definition = $this->ticketServices->for($service);
        abort_unless(in_array($area, $definition->serviceAreas, true), 404);

        $formUrl = route($definition->routePrefix.'.index', ['service_area' => $area]).'#create';

        if ($request->user() !== null) {
            return redirect()->to($formUrl);
        }

        $request->session()->put('url.intended', $formUrl);

        return redirect()->route($request->query('via') === 'register' ? 'public.register' : 'public.login');
    }

    private function available(string $service): bool
    {
        return $this->modulePackages->isEnabled($service) && $this->enablement->isEnabled($service, 'tickets');
    }

    private function areaLabel(string $service, string $area): string
    {
        return $service === 'apes-cic'
            ? $this->categories->labelForArea($service, $area)
            : WorkflowLabels::label('ticket_area', $area);
    }
}
