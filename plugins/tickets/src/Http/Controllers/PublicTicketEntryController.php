<?php

namespace Plugins\Tickets\Http\Controllers;

use App\Contracts\ModuleRegistry;
use App\Core\Accounts\AccountClassifier;
use App\Core\BotProtection\BotProtection;
use App\Core\Extensions\Modules\ModulePackageRegistry;
use App\Core\Extensions\Plugins\PluginEnablement;
use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Plugins\Tickets\TicketCategoryResolver;
use Plugins\Tickets\TicketCreator;
use Plugins\Tickets\TicketServiceConfiguration;
use Plugins\Tickets\TicketsArea;

/**
 * Public "Open a ticket" flow (#662–#665): login-first, combined form, auto-route by division.
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
        private readonly AccountClassifier $accounts,
    ) {}

    public function index(Request $request): View|RedirectResponse
    {
        $services = $this->enabledServices();
        $user = $request->user();

        if ($user === null) {
            $request->session()->put('url.intended', route('tickets.public.index', $request->query()));

            return view('tickets::public.index', [
                'mode' => 'guest',
                'services' => $services,
                'prefillService' => null,
                'prefillArea' => null,
            ]);
        }

        if ($this->accounts->isStaffSide($user)) {
            return view('tickets::public.index', [
                'mode' => 'staff',
                'services' => $services,
                'prefillService' => null,
                'prefillArea' => null,
            ]);
        }

        [$prefillService, $prefillArea] = $this->resolvePrefill($request, $services);

        return view('tickets::public.index', [
            'mode' => 'form',
            'services' => $services,
            'prefillService' => $prefillService,
            'prefillArea' => $prefillArea,
            'priorities' => TicketController::PUBLIC_CREATE_PRIORITIES,
            'cicGroups' => $this->available('apes-cic')
                ? $this->categories->serviceAreas('apes-cic')
                : [],
            'cicWebsites' => $this->available('apes-cic')
                ? $this->categories->websites('apes-cic')
                : [],
        ]);
    }

    public function store(
        Request $request,
        BotProtection $botProtection,
        TicketCreator $creator,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user !== null && ! $this->accounts->isStaffSide($user), 403);

        $services = $this->enabledServices();
        $serviceKeys = array_column($services, 'key');

        $validated = $request->validate([
            'service' => ['required', 'string', Rule::in($serviceKeys)],
        ]);

        $service = $validated['service'];
        Gate::authorize(TicketsArea::forModule($service)->permission('create'));
        $botProtection->check($request, BotProtection::TICKET);

        return $creator->createFromRequest($request, $user, $service, $auditLogger);
    }

    public function start(Request $request, string $service, string $area): RedirectResponse
    {
        abort_unless(in_array($service, self::SERVICES, true) && $this->available($service), 404);

        $definition = $this->ticketServices->for($service);
        abort_unless(in_array($area, $definition->serviceAreas, true), 404);

        $formUrl = route('tickets.public.index', [
            'service' => $service,
            'area' => $area,
        ]);

        if ($request->user() !== null) {
            return redirect()->to($formUrl);
        }

        $request->session()->put('url.intended', $formUrl);

        return redirect()->route($request->query('via') === 'register' ? 'public.register' : 'public.login');
    }

    /**
     * @return list<array{key: string, name: string, description: string, areas: list<array{key: string, label: string}>}>
     */
    private function enabledServices(): array
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

        return $services;
    }

    /**
     * @param  list<array{key: string, areas: list<array{key: string, label: string}>}>  $services
     * @return array{0: ?string, 1: ?string}
     */
    private function resolvePrefill(Request $request, array $services): array
    {
        $service = old('service', $request->query('service'));
        $area = old('service_area', $request->query('area'));

        if (! is_string($service) || ! is_string($area)) {
            return [is_string($service) ? $service : null, null];
        }

        foreach ($services as $entry) {
            if ($entry['key'] !== $service) {
                continue;
            }

            foreach ($entry['areas'] as $areaEntry) {
                if ($areaEntry['key'] === $area) {
                    return [$service, $area];
                }
            }
        }

        return [null, null];
    }

    private function available(string $service): bool
    {
        return $this->modulePackages->isEnabled($service) && $this->enablement->isEnabled($service, 'tickets');
    }

    private function areaLabel(string $service, string $area): string
    {
        return $this->categories->labelForArea($service, $area);
    }
}
