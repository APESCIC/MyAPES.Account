<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\ModuleLifecycleManager;
use App\Core\Extensions\Settings\ManagedListRegistry;
use App\Exceptions\ModuleLifecycleException;
use App\Http\Controllers\Controller;
use App\Modules\ModuleSettingsDescriptor;
use App\Services\AuditLogger;
use App\Services\ModuleAdministrationCatalogue;
use App\Services\ModuleSettingsService;
use App\Services\PluginAdministrationCatalogue;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminModuleController extends Controller
{
    public function index(
        ModuleAdministrationCatalogue $catalogue,
        PluginAdministrationCatalogue $plugins,
    ): View {
        return view('admin.modules.index', [
            ...$catalogue->matrix(),
            ...$plugins->byPlugin(),
        ]);
    }

    public function editSettings(
        string $subCoreKey,
        string $moduleKey,
        ModuleSettingsService $settings,
    ): View {
        $descriptor = $settings->descriptor($subCoreKey, $moduleKey);
        abort_unless($descriptor->supportsSettings, 404);

        $record = $settings->record($subCoreKey, $moduleKey);
        abort_unless($record !== null, 404);

        $view = match ($descriptor->schema) {
            ModuleSettingsDescriptor::SCHEMA_RECRUITMENT_BOARD => 'admin.modules.settings-recruitment',
            ModuleSettingsDescriptor::SCHEMA_WEBSITES_CATEGORIES => 'admin.modules.settings',
            default => abort(404),
        };

        return view($view, [
            'subCoreKey' => $subCoreKey,
            'moduleKey' => $moduleKey,
            'descriptor' => $descriptor,
            'record' => $record,
            'settings' => $record->settings,
            'groupKey' => $descriptor->groupKey,
            'groupLabel' => $descriptor->groupLabel,
            'canManage' => request()->user()?->can($descriptor->managePermission) ?? false,
            'managedLists' => app(ManagedListRegistry::class)->forModule($subCoreKey, $moduleKey),
        ]);
    }

    public function updateSettings(
        Request $request,
        string $subCoreKey,
        string $moduleKey,
        ModuleSettingsService $settings,
        AuditLogger $audit,
    ): RedirectResponse {
        $descriptor = $settings->descriptor($subCoreKey, $moduleKey);
        abort_unless($descriptor->supportsSettings, 404);

        if ($request->boolean('reset_defaults')) {
            $validated = $request->validate([
                'version' => ['required', 'integer', 'min:1'],
                'confirm_reset' => ['accepted'],
            ]);

            try {
                $record = $settings->resetToDefaults(
                    $subCoreKey,
                    $moduleKey,
                    (int) $validated['version'],
                    $request->user(),
                );
            } catch (ValidationException $exception) {
                return back()->withErrors($exception->errors())->withInput();
            }

            $audit->record('module.settings_reset', $request->user(), null, [
                'sub_core_key' => $subCoreKey,
                'module_key' => $moduleKey,
                'lock_version' => $record->lock_version,
            ]);

            return redirect()
                ->route('admin.modules.settings.edit', [$subCoreKey, $moduleKey])
                ->with('status', __('admin.flash.plugin_settings_reset_to_defaults'));
        }

        $payload = match ($descriptor->schema) {
            ModuleSettingsDescriptor::SCHEMA_RECRUITMENT_BOARD => $this->validatedRecruitmentPayload($request),
            ModuleSettingsDescriptor::SCHEMA_WEBSITES_CATEGORIES => $this->validatedWebsitesCategoriesPayload(
                $request,
                $descriptor,
            ),
            default => abort(404),
        };

        try {
            $record = $settings->save(
                $subCoreKey,
                $moduleKey,
                $payload['settings'],
                (int) $payload['version'],
                $request->user(),
            );
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors())->withInput();
        }

        $audit->record('module.settings_updated', $request->user(), null, [
            'sub_core_key' => $subCoreKey,
            'module_key' => $moduleKey,
            'lock_version' => $record->lock_version,
        ]);

        return redirect()
            ->route('admin.modules.settings.edit', [$subCoreKey, $moduleKey])
            ->with('status', __('admin.flash.plugin_settings_saved'));
    }

    public function transition(
        Request $request,
        string $subCoreKey,
        string $moduleKey,
        ModuleLifecycleManager $lifecycle,
        AuditLogger $audit,
    ): RedirectResponse {
        $validator = Validator::make($request->all(), [
            'action' => ['required', Rule::in(['install', 'enable', 'disable'])],
            'confirm_action' => ['accepted'],
            'confirm_navigation' => ['accepted'],
            'version' => [
                Rule::requiredIf(
                    static fn (): bool => $request->input('action') !== 'install',
                ),
                'nullable',
                'integer',
                'min:1',
            ],
        ]);

        if ($validator->fails()) {
            $audit->record(
                'module.lifecycle_validation_failed',
                $request->user(),
                null,
                [
                    'sub_core_key' => $this->safeKey($subCoreKey),
                    'module_key' => $this->safeKey($moduleKey),
                    'action' => $this->safeAction(
                        (string) $request->input('action'),
                    ),
                    'reason' => 'confirmation_or_input_invalid',
                ],
            );

            return back()->withErrors($validator)->withInput();
        }

        $validated = $validator->validated();

        try {
            match ($validated['action']) {
                'install' => $lifecycle->install(
                    $request->user(),
                    $subCoreKey,
                    $moduleKey,
                ),
                'enable' => $lifecycle->enable(
                    $request->user(),
                    $subCoreKey,
                    $moduleKey,
                    (int) $validated['version'],
                ),
                'disable' => $lifecycle->disable(
                    $request->user(),
                    $subCoreKey,
                    $moduleKey,
                    (int) $validated['version'],
                ),
            };
        } catch (ModuleLifecycleException) {
            return back()->withErrors([
                'module' => 'The plugin transition could not be completed. Refresh the page and try again.',
            ]);
        }

        return redirect()
            ->route('admin.modules.index')
            ->with('status', __('admin.flash.plugin_state_updated'));
    }

    /**
     * @return array{version: int, settings: array{public_board_enabled: bool, public_apply_enabled: bool}}
     */
    private function validatedRecruitmentPayload(Request $request): array
    {
        $validated = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'public_board_enabled' => ['nullable', 'boolean'],
            'public_apply_enabled' => ['nullable', 'boolean'],
        ]);

        return [
            'version' => (int) $validated['version'],
            'settings' => [
                'public_board_enabled' => $request->boolean('public_board_enabled'),
                'public_apply_enabled' => $request->boolean('public_apply_enabled'),
            ],
        ];
    }

    /**
     * @return array{version: int, settings: array<string, mixed>}
     */
    private function validatedWebsitesCategoriesPayload(
        Request $request,
        ModuleSettingsDescriptor $descriptor,
    ): array {
        $groupKey = $descriptor->groupKey ?? 'categories';
        $validated = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'websites' => ['required', 'array', 'min:1'],
            'websites.*.key' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/'],
            'websites.*.label' => ['required', 'string', 'max:255'],
            'websites.*.url' => ['nullable', 'string', 'max:2048'],
            $groupKey => ['required', 'array', 'min:1'],
            "{$groupKey}.*.key" => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/'],
            "{$groupKey}.*.label" => ['required', 'string', 'max:255'],
            "{$groupKey}.*.archived" => ['nullable', 'boolean'],
            "{$groupKey}.*.subcategories" => ['required', 'array', 'min:1'],
            "{$groupKey}.*.subcategories.*.key" => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/'],
            "{$groupKey}.*.subcategories.*.label" => ['required', 'string', 'max:255'],
            "{$groupKey}.*.subcategories.*.requires_website" => ['nullable', 'boolean'],
            "{$groupKey}.*.subcategories.*.allows_attachments" => ['nullable', 'boolean'],
        ]);

        return [
            'version' => (int) $validated['version'],
            'settings' => [
                'websites' => collect($validated['websites'])
                    ->map(static fn (array $website): array => [
                        'key' => $website['key'],
                        'label' => $website['label'],
                        'url' => $website['url'] ?? null,
                    ])
                    ->values()
                    ->all(),
                $groupKey => collect($validated[$groupKey])
                    ->map(static function (array $group): array {
                        return [
                            'key' => $group['key'],
                            'label' => $group['label'],
                            'subcategories' => collect($group['subcategories'])
                                ->map(static fn (array $sub): array => [
                                    'key' => $sub['key'],
                                    'label' => $sub['label'],
                                    'requires_website' => (bool) ($sub['requires_website'] ?? false),
                                    'allows_attachments' => (bool) ($sub['allows_attachments'] ?? false),
                                ])
                                ->values()
                                ->all(),
                            ...(empty($group['archived']) ? [] : ['archived' => true]),
                        ];
                    })
                    ->values()
                    ->all(),
            ],
        ];
    }

    private function safeKey(string $value): string
    {
        return preg_match('/^[a-z0-9-]{1,64}$/', $value) === 1
            ? $value
            : 'invalid';
    }

    private function safeAction(string $value): string
    {
        return in_array($value, ['install', 'enable', 'disable'], true)
            ? $value
            : 'invalid';
    }
}
