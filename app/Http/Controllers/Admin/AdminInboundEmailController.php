<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\ModuleRegistry;
use App\Core\Extensions\Plugins\PluginRegistry;
use App\Core\Mail\Inbound\InboundEmail;
use App\Core\Mail\Inbound\InboundMailSettings;
use App\Core\Mail\Inbound\InboundReplyRegistry;
use App\Core\Mail\Inbound\InboundReplySetting;
use App\Core\Mail\Inbound\Local\ClientReplyBuilder;
use App\Core\Mail\Inbound\Local\LocalReplies;
use App\Core\Mail\Inbound\MailboxUnavailable;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureLocalReplyLoop;
use App\Jobs\ProcessInboundEmail;
use App\Services\AuditLogger;
use App\Services\ModuleState;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Admin → Emails → Inbound (#585): replies received by email, what happened
 * to each, retry or discard for quarantined and failed ones, and the
 * per-service switch.
 */
class AdminInboundEmailController extends Controller
{
    public function index(Request $request, InboundMailSettings $settings): View
    {
        $status = $request->query('status');
        $status = is_string($status) && in_array($status, InboundEmail::STATUSES, true) ? $status : null;

        return view('admin.emails.inbound.index', [
            'emails' => InboundEmail::query()
                ->with('user')
                ->when($status !== null, static fn ($query) => $query->where('status', $status))
                ->latest('id')
                ->paginate(50)
                ->withQueryString(),
            'status' => $status,
            'statuses' => array_combine(InboundEmail::STATUSES, array_map(InboundEmail::labelForStatus(...), InboundEmail::STATUSES)),
            'repliesEnabled' => $settings->repliesEnabled(),
            'pollingConfigured' => $settings->pollingConfigured(),
            'address' => $settings->address(),
            'retentionDays' => $settings->retentionDays(),
            'services' => $this->services(),
            'localReplies' => $this->localReplies($request, $settings),
        ]);
    }

    /**
     * The local test replies panel (#758), only on a local install reading Mailpit.
     *
     * @return array{emails: list<array{id: string, subject: string, to: string, created: string, url: string}>, error: ?string, variants: array<string, string>}|null
     */
    private function localReplies(Request $request, InboundMailSettings $settings): ?array
    {
        if (! EnsureLocalReplyLoop::available($settings) || ! $request->user()?->can('admin.emails.manage')) {
            return null;
        }

        try {
            $emails = app(LocalReplies::class)->recentRecordEmails();
            $error = null;
        } catch (MailboxUnavailable $exception) {
            $emails = [];
            $error = $exception->getMessage();
        }

        return ['emails' => $emails, 'error' => $error, 'variants' => [
            ClientReplyBuilder::NO_TAG => __('admin.emails.inbound.local.variant.no-tag'),
            ClientReplyBuilder::NO_REFERENCES => __('admin.emails.inbound.local.variant.no-references'),
            ClientReplyBuilder::EDITED_SUBJECT => __('admin.emails.inbound.local.variant.edited-subject'),
            ClientReplyBuilder::HTML_ONLY => __('admin.emails.inbound.local.variant.html-only'),
            ClientReplyBuilder::ATTACHMENT => __('admin.emails.inbound.local.variant.attachment'),
        ]];
    }

    public function show(string $inboundEmail, InboundMailSettings $settings, InboundReplyRegistry $registry): View
    {
        $inboundEmail = $this->findOrFail($inboundEmail);

        return view('admin.emails.inbound.show', [
            'email' => $inboundEmail->load('user'),
            'recordUrl' => $this->recordUrl($inboundEmail, $registry),
            'retentionDays' => $settings->retentionDays(),
        ]);
    }

    public function retry(Request $request, AuditLogger $audit, string $inboundEmail): RedirectResponse
    {
        $inboundEmail = $this->findOrFail($inboundEmail);

        if (! $inboundEmail->isActionable()) {
            return back()->withErrors(['inbound' => __('admin.emails.inbound.not_actionable')]);
        }

        $previous = ['status' => $inboundEmail->status, 'reason' => $inboundEmail->reason];
        $inboundEmail->forceFill([
            'status' => InboundEmail::STATUS_RECEIVED,
            'reason' => null,
            'detail' => null,
            'processed_at' => null,
        ])->save();

        $audit->record('admin.emails.inbound_retried', $request->user(), $inboundEmail, $previous);
        ProcessInboundEmail::dispatch((int) $inboundEmail->id);

        return redirect()
            ->route('admin.emails.inbound.show', $inboundEmail)
            ->with('status', __('admin.emails.inbound.retried'));
    }

    public function discard(Request $request, AuditLogger $audit, string $inboundEmail): RedirectResponse
    {
        $inboundEmail = $this->findOrFail($inboundEmail);
        $request->validate(['confirm_discard' => ['accepted']]);

        if (! $inboundEmail->isActionable()) {
            return back()->withErrors(['inbound' => __('admin.emails.inbound.not_actionable')]);
        }

        $previous = ['status' => $inboundEmail->status, 'reason' => $inboundEmail->reason];
        Storage::disk(InboundEmail::RAW_DISK)->delete((string) $inboundEmail->raw_path);
        $inboundEmail->forceFill([
            'status' => InboundEmail::STATUS_DISCARDED,
            'reason' => 'discarded',
            'raw_path' => null,
            'raw_pruned_at' => now(),
            'processed_at' => now(),
        ])->save();

        $audit->record('admin.emails.inbound_discarded', $request->user(), $inboundEmail, $previous);

        return redirect()
            ->route('admin.emails.inbound.show', $inboundEmail)
            ->with('status', __('admin.emails.inbound.discarded'));
    }

    public function updateServices(Request $request, AuditLogger $audit): RedirectResponse
    {
        $services = $this->services();
        $keys = array_keys($services);
        $validated = $request->validate([
            'services' => ['required', 'array'],
            'services.*' => ['required', Rule::in(['0', '1'])],
        ]);
        $saved = [];

        foreach ($validated['services'] as $key => $value) {
            if (! in_array($key, $keys, true)) {
                continue;
            }

            $service = $services[$key];
            $enabled = $value === '1';

            if ($service['enabled'] === $enabled) {
                continue;
            }

            InboundReplySetting::query()->updateOrCreate(
                ['sub_core_key' => $service['module'], 'plugin' => $service['plugin']],
                ['enabled' => $enabled, 'updated_by' => $request->user()->id],
            );
            $audit->record('admin.emails.inbound_service_changed', $request->user(), context: [
                'sub_core_key' => $service['module'],
                'plugin' => $service['plugin'],
                'enabled' => $enabled,
            ]);
            $saved[] = __('admin.emails.inbound.toggle_saved', [
                'service' => $service['label'],
                'state' => $enabled ? __('admin.emails.inbound.toggle_on') : __('admin.emails.inbound.toggle_off'),
            ]);
        }

        return redirect()
            ->route('admin.emails.inbound.index')
            ->with('status', $saved === [] ? __('admin.emails.inbound.toggle_no_changes') : implode(' ', $saved));
    }

    /**
     * Every plugin and service where replies by email can be switched.
     *
     * @return array<string, array{module: string, plugin: string, label: string, enabled: bool}>
     */
    private function services(): array
    {
        $registry = app(InboundReplyRegistry::class);
        $plugins = app(PluginRegistry::class);
        $modules = app(ModuleRegistry::class);
        $state = app(ModuleState::class);
        $services = [];

        foreach ($registry->all() as $handler) {
            $slug = $handler->plugin();

            if (! $plugins->has($slug)) {
                continue;
            }

            foreach ($plugins->plugin($slug)->shippedModules as $module) {
                if (! $state->enabled($module, $slug)) {
                    continue;
                }

                try {
                    $moduleName = $modules->subCore($module)->name;
                } catch (Throwable) {
                    $moduleName = $module;
                }

                $services[$module.'|'.$slug] = [
                    'module' => $module,
                    'plugin' => $slug,
                    'label' => __('admin.emails.inbound.toggle_service', ['plugin' => $plugins->plugin($slug)->label(), 'module' => $moduleName]),
                    'enabled' => $registry->enabledFor($module, $slug),
                ];
            }
        }

        return $services;
    }

    private function findOrFail(string $id): InboundEmail
    {
        return InboundEmail::query()->findOrFail((int) $id);
    }

    private function recordUrl(InboundEmail $email, InboundReplyRegistry $registry): ?string
    {
        if ($email->record_prefix === null || $email->record_id === null) {
            return null;
        }

        $handler = $registry->handler($email->record_prefix);
        $record = $handler?->find((int) $email->record_id);

        return $handler === null || $record === null ? null : $handler->url($record);
    }
}
