<?php

namespace Plugins\Tickets\Http\Controllers;

use App\Core\Accounts\AccountClassifier;
use App\Core\BotProtection\BotProtection;
use App\Http\Controllers\Controller;
use App\Rules\NotStaffEmail;
use App\Services\AuditLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Plugins\Tickets\Embed\EmbedToken;
use Plugins\Tickets\Embed\GuestTicketCreator;
use Plugins\Tickets\Models\TicketEmbedForm;
use Plugins\Tickets\TicketCategoryResolver;
use Plugins\Tickets\TicketServiceConfiguration;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Guest embed form, token mint, submit and email verify (#682–#692).
 */
class EmbedTicketController extends Controller
{
    public function __construct(
        private readonly TicketServiceConfiguration $ticketServices,
        private readonly TicketCategoryResolver $categories,
        private readonly AccountClassifier $accounts,
    ) {}

    public function loader(): Response
    {
        $path = public_path('vendor/tickets/embed-loader.js');
        abort_unless(is_file($path), 404);

        return response(file_get_contents($path), 200, [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    public function show(Request $request, string $publicKey): View|Response
    {
        $form = $this->resolveForm($publicKey);
        if (! $form->is_active) {
            return response()->view('tickets::embed.inactive', ['form' => $form], 403);
        }

        $this->rejectStaffUsingEmbedAsSelf($request);

        $origin = $this->requestOrigin($request);
        $token = $request->query('token');
        $tokenOk = EmbedToken::validFor($form, is_string($token) ? $token : null, $origin);

        if (! $tokenOk) {
            return response()->view('tickets::embed.denied', [
                'form' => $form,
                'reason' => 'token',
            ], 403);
        }

        $services = $this->enabledServicesFor($form);

        return response()->view('tickets::embed.form', [
            'form' => $form,
            'services' => $services,
            'token' => $token,
            'parentOrigin' => TicketEmbedForm::normaliseOrigin((string) $origin),
            'cicGroups' => $form->allowsService('apes-cic')
                ? $this->categories->serviceAreas('apes-cic')
                : [],
            'cicWebsites' => $form->allowsService('apes-cic')
                ? $this->categories->websites('apes-cic')
                : [],
        ]);
    }

    public function mintToken(Request $request, string $publicKey): JsonResponse
    {
        $form = $this->resolveForm($publicKey);
        abort_unless($form->is_active, 403);

        $origin = $this->requestOrigin($request);
        abort_unless($form->allowsOrigin($origin), 403);

        $this->rejectStaffUsingEmbedAsSelf($request);

        return response()->json([
            'token' => EmbedToken::mint($form, (string) $origin),
            'form_url' => route('tickets.embed.show', ['publicKey' => $form->public_key]),
            'expires_in' => EmbedToken::TTL_SECONDS,
        ]);
    }

    public function store(
        Request $request,
        string $publicKey,
        BotProtection $botProtection,
        GuestTicketCreator $creator,
        AuditLogger $auditLogger,
    ): RedirectResponse|Response {
        $form = $this->resolveForm($publicKey);
        if (! $form->is_active) {
            return response()->view('tickets::embed.inactive', ['form' => $form], 403);
        }

        $this->rejectStaffUsingEmbedAsSelf($request);

        $origin = $this->requestOrigin($request);
        $token = $request->input('embed_token');
        if (! EmbedToken::validFor($form, is_string($token) ? $token : null, $origin)) {
            throw ValidationException::withMessages([
                'embed_token' => __('tickets::embed.validation.token'),
            ]);
        }

        $key = 'ticket-embed-submit|'.$form->id.'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 10)) {
            throw ValidationException::withMessages([
                'email' => __('tickets::embed.validation.throttled'),
            ]);
        }
        RateLimiter::hit($key, 60);

        $services = $this->enabledServicesFor($form);
        $serviceKeys = array_column($services, 'key');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255', new NotStaffEmail],
            'service' => ['required', 'string', Rule::in($serviceKeys)],
            'service_area' => ['required', 'string', 'max:64'],
            'subject' => ['required', 'string', 'max:255'],
            'details' => ['required', 'string', 'max:10000'],
            'sub_category' => ['nullable', 'string', 'max:64'],
            'affected_website_key' => ['nullable', 'string', 'max:64'],
            'consent' => ['accepted'],
            'embed_token' => ['required', 'string'],
        ], [
            'consent.accepted' => __('tickets::embed.validation.consent'),
        ]);

        $service = $validated['service'];
        $definition = $this->ticketServices->for($service);
        if (! in_array($validated['service_area'], $definition->serviceAreas, true)) {
            throw ValidationException::withMessages([
                'service_area' => __('tickets::embed.validation.service_area'),
            ]);
        }

        // Per-form spam threshold overrides the Integrations default for this action (#687).
        if ($form->spam_score_threshold !== null) {
            $request->attributes->set('bot_protection.threshold_override', (float) $form->spam_score_threshold);
        }

        $botProtection->check($request, BotProtection::TICKET_EMBED);

        $ticket = $creator->createUnverified($form, [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'service' => $service,
            'service_area' => $validated['service_area'],
            'subject' => $validated['subject'],
            'details' => $validated['details'],
            'sub_category' => $validated['sub_category'] ?? null,
            'affected_website_key' => $validated['affected_website_key'] ?? null,
        ], $auditLogger);

        return response()
            ->view('tickets::embed.success', [
                'form' => $form,
                'ticket' => $ticket,
                'parentOrigin' => TicketEmbedForm::normaliseOrigin((string) $origin),
            ]);
    }

    public function verify(string $token, AuditLogger $auditLogger): View
    {
        $ticket = app(GuestTicketCreator::class)->verifyEmail($token, $auditLogger);

        return view('tickets::embed.verified', [
            'ticket' => $ticket,
        ]);
    }

    private function resolveForm(string $publicKey): TicketEmbedForm
    {
        return TicketEmbedForm::query()->where('public_key', $publicKey)->firstOrFail();
    }

    private function rejectStaffUsingEmbedAsSelf(Request $request): void
    {
        $user = $request->user();
        if ($user !== null && $this->accounts->isStaffSide($user)) {
            abort(403, __('tickets::embed.staff_blocked'));
        }
    }

    private function requestOrigin(Request $request): ?string
    {
        $origin = $request->headers->get('Origin')
            ?: $request->input('parent_origin')
            ?: $request->query('parent_origin');

        if (is_string($origin) && $origin !== '') {
            return TicketEmbedForm::normaliseOrigin($origin);
        }

        $referer = $request->headers->get('Referer');
        if (! is_string($referer) || $referer === '') {
            return null;
        }

        $parts = parse_url($referer);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return TicketEmbedForm::normaliseOrigin($parts['scheme'].'://'.$parts['host'].$port);
    }

    /**
     * @return list<array{key: string, label: string, areas: list<string>}>
     */
    private function enabledServicesFor(TicketEmbedForm $form): array
    {
        $out = [];
        foreach ($form->enabled_services ?? [] as $key) {
            if (! is_string($key) || ! $form->allowsService($key)) {
                continue;
            }
            try {
                $definition = $this->ticketServices->for($key);
            } catch (\Throwable) {
                continue;
            }
            $out[] = [
                'key' => $key,
                'label' => $definition->serviceName,
                'areas' => $definition->serviceAreas,
            ];
        }

        return $out;
    }

}
