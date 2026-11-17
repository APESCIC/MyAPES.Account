<?php

namespace Plugins\Tickets\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Plugins\Tickets\Models\TicketEmbedForm;
use Plugins\Tickets\TicketServiceConfiguration;

/**
 * Admin → Tickets → Embed forms (#689, #690, #692).
 */
class AdminTicketEmbedFormController extends Controller
{
    public function __construct(
        private readonly TicketServiceConfiguration $ticketServices,
    ) {}

    public function index(): View
    {
        $this->authorizeManage();

        return view('tickets::admin.embed-forms.index', [
            'forms' => TicketEmbedForm::query()->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        $this->authorizeManage();

        return view('tickets::admin.embed-forms.edit', [
            'form' => null,
            'serviceOptions' => $this->serviceOptions(),
            'defaultOrigins' => $this->defaultLocalOrigins(),
        ]);
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $this->authorizeManage();
        $validated = $this->validated($request);

        $form = TicketEmbedForm::query()->create([
            ...$validated,
            'public_key' => TicketEmbedForm::newPublicKey(),
            'signing_secret' => TicketEmbedForm::newSigningSecret(),
            'created_by' => $request->user()->id,
        ]);

        $audit->record('admin.tickets.embed_form_created', $request->user(), context: [
            'ticket_embed_form_id' => $form->id,
            'name' => $form->name,
            'public_key' => $form->public_key,
        ]);

        return redirect()
            ->route('admin.tickets.embed-forms.edit', $form)
            ->with('status', __('tickets::embed.admin.created', ['name' => $form->name]));
    }

    public function edit(TicketEmbedForm $embedForm): View
    {
        $this->authorizeManage();

        return view('tickets::admin.embed-forms.edit', [
            'form' => $embedForm,
            'serviceOptions' => $this->serviceOptions(),
            'defaultOrigins' => $this->defaultLocalOrigins(),
            'loaderUrl' => route('tickets.embed.loader'),
            'embedSnippet' => $this->snippet($embedForm),
        ]);
    }

    public function update(Request $request, TicketEmbedForm $embedForm, AuditLogger $audit): RedirectResponse
    {
        $this->authorizeManage();
        $validated = $this->validated($request);
        $embedForm->fill($validated)->save();

        $audit->record('admin.tickets.embed_form_updated', $request->user(), context: [
            'ticket_embed_form_id' => $embedForm->id,
            'name' => $embedForm->name,
        ]);

        return redirect()
            ->route('admin.tickets.embed-forms.edit', $embedForm)
            ->with('status', __('tickets::embed.admin.updated', ['name' => $embedForm->name]));
    }

    public function destroy(Request $request, TicketEmbedForm $embedForm, AuditLogger $audit): RedirectResponse
    {
        $this->authorizeManage();
        $name = $embedForm->name;
        $audit->record('admin.tickets.embed_form_deleted', $request->user(), context: [
            'name' => $name,
            'public_key' => $embedForm->public_key,
        ]);
        $embedForm->delete();

        return redirect()
            ->route('admin.tickets.embed-forms.index')
            ->with('status', __('tickets::embed.admin.deleted', ['name' => $name]));
    }

    public function toggle(Request $request, TicketEmbedForm $embedForm, AuditLogger $audit): RedirectResponse
    {
        $this->authorizeManage();
        $embedForm->forceFill(['is_active' => ! $embedForm->is_active])->save();
        $audit->record(
            $embedForm->is_active ? 'admin.tickets.embed_form_enabled' : 'admin.tickets.embed_form_disabled',
            $request->user(),
            context: ['ticket_embed_form_id' => $embedForm->id],
        );

        return redirect()
            ->route('admin.tickets.embed-forms.edit', $embedForm)
            ->with('status', __($embedForm->is_active ? 'tickets::embed.admin.enabled' : 'tickets::embed.admin.disabled', [
                'name' => $embedForm->name,
            ]));
    }

    public function rotate(Request $request, TicketEmbedForm $embedForm, AuditLogger $audit): RedirectResponse
    {
        $this->authorizeManage();
        $embedForm->forceFill([
            'public_key' => TicketEmbedForm::newPublicKey(),
            'signing_secret' => TicketEmbedForm::newSigningSecret(),
        ])->save();

        $audit->record('admin.tickets.embed_form_rotated', $request->user(), context: [
            'ticket_embed_form_id' => $embedForm->id,
            'public_key' => $embedForm->public_key,
        ]);

        return redirect()
            ->route('admin.tickets.embed-forms.edit', $embedForm)
            ->with('status', __('tickets::embed.admin.rotated', ['name' => $embedForm->name]));
    }

    private function authorizeManage(): void
    {
        abort_unless(auth()->user()?->can('admin.settings.manage'), 403);
    }

    /**
     * @return array{name: string, is_active: bool, enabled_services: list<string>, allowed_origins: list<string>, spam_score_threshold: float|null, confirmation_text: string|null}
     */
    private function validated(Request $request): array
    {
        $serviceKeys = array_keys($this->serviceOptions());
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
            'enabled_services' => ['required', 'array', 'min:1'],
            'enabled_services.*' => ['string', Rule::in($serviceKeys)],
            'allowed_origins_text' => ['required', 'string'],
            'spam_score_threshold' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'confirmation_text' => ['nullable', 'string', 'max:2000'],
        ]);

        $origins = collect(preg_split('/\r\n|\r|\n/', $validated['allowed_origins_text']) ?: [])
            ->map(static fn (string $line): string => TicketEmbedForm::normaliseOrigin(trim($line)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($origins === []) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'allowed_origins_text' => __('tickets::embed.admin.origins_required'),
            ]);
        }

        return [
            'name' => $validated['name'],
            'is_active' => $request->boolean('is_active', true),
            'enabled_services' => array_values(array_unique($validated['enabled_services'])),
            'allowed_origins' => $origins,
            'spam_score_threshold' => isset($validated['spam_score_threshold'])
                ? (float) $validated['spam_score_threshold']
                : null,
            'confirmation_text' => $validated['confirmation_text'] ?? null,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function serviceOptions(): array
    {
        $out = [];
        foreach (TicketEmbedForm::DEFAULT_SERVICES as $key) {
            try {
                $out[$key] = $this->ticketServices->for($key)->serviceName;
            } catch (\Throwable) {
                $out[$key] = $key;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function defaultLocalOrigins(): array
    {
        $app = rtrim((string) config('app.url'), '/');

        return array_values(array_unique(array_filter([
            $app,
            'https://myapes-account.test',
            'http://myapes-account.test',
            'https://www.apes.org.uk',
            'https://apes.org.uk',
            'https://myapes.me.uk',
        ])));
    }

    private function snippet(TicketEmbedForm $form): string
    {
        $loader = route('tickets.embed.loader');
        $key = e($form->public_key);

        return <<<HTML
<div data-myapes-ticket-embed data-public-key="{$key}"></div>
<script src="{$loader}" async defer></script>
HTML;
    }
}
