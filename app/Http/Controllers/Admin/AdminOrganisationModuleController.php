<?php

namespace App\Http\Controllers\Admin;

use App\Core\Extensions\Models\OrganisationModule;
use App\Core\Extensions\Modules\ModulePackageRegistry;
use App\Core\Extensions\Plugins\PluginEnablement;
use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\ModuleAdministrationCatalogue;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Admin page for organisation-area module enablement (#283).
 *
 * Distinct from Admin → Plugins (`admin.modules.*`), which manages plugin
 * enablement per module.
 */
class AdminOrganisationModuleController extends Controller
{
    public function index(
        ModulePackageRegistry $modules,
        ModuleAdministrationCatalogue $catalogue,
        PluginEnablement $enablement,
    ): View {
        $matrix = $catalogue->matrix();
        $rows = [];

        foreach ($modules->modules() as $manifest) {
            $row = OrganisationModule::query()->firstOrCreate(
                ['slug' => $manifest->slug],
                [
                    'enabled' => $manifest->enabledByDefault,
                    'enabled_at' => $manifest->enabledByDefault ? now() : null,
                ],
            );

            $pluginSummaries = [];
            foreach ($matrix['modules'] as $pluginDef) {
                $cell = $matrix['cells'][$manifest->slug.':'.$pluginDef->key] ?? null;
                if ($cell === null || ! $cell['definition']->isShipped()) {
                    continue;
                }

                $pluginSummaries[] = [
                    'key' => $pluginDef->key,
                    'name' => $pluginDef->name,
                    'enabled' => $enablement->isEnabled($manifest->slug, $pluginDef->key),
                ];
            }

            $rows[] = [
                'manifest' => $manifest,
                'record' => $row,
                'plugins' => $pluginSummaries,
            ];
        }

        usort(
            $rows,
            static fn (array $left, array $right): int => $left['manifest']->sortOrder
                <=> $right['manifest']->sortOrder,
        );

        return view('admin.organisation-modules.index', [
            'rows' => $rows,
        ]);
    }

    public function transition(
        Request $request,
        string $slug,
        ModulePackageRegistry $modules,
        AuditLogger $audit,
    ): RedirectResponse {
        abort_unless($modules->has($slug), 404);

        $validated = $request->validate([
            'action' => ['required', 'in:enable,disable'],
        ]);

        $enable = $validated['action'] === 'enable';
        $record = OrganisationModule::query()->firstOrNew(['slug' => $slug]);
        $record->enabled = $enable;

        if ($enable) {
            $record->enabled_at = now();
            $record->enabled_by = $request->user()?->id;
            $record->disabled_at = null;
            $record->disabled_by = null;
        } else {
            $record->disabled_at = now();
            $record->disabled_by = $request->user()?->id;
        }

        $record->save();

        $audit->record(
            $enable ? 'organisation_module.enabled' : 'organisation_module.disabled',
            $request->user(),
            null,
            ['slug' => $slug],
        );

        return redirect()
            ->route('admin.organisation-modules.index')
            ->with('status', $enable
                ? "Module [{$slug}] enabled."
                : "Module [{$slug}] disabled.");
    }

    public function uploads(
        Request $request,
        string $slug,
        ModulePackageRegistry $modules,
        AuditLogger $audit,
    ): RedirectResponse {
        abort_unless($modules->has($slug), 404);

        $validated = $request->validate([
            'uploads_enabled' => ['required', 'boolean'],
        ]);

        $enabled = (bool) $validated['uploads_enabled'];
        $record = OrganisationModule::query()->firstOrNew(['slug' => $slug]);
        if (! $record->exists) {
            $record->enabled = $modules->module($slug)->enabledByDefault;
        }
        $record->uploads_enabled = $enabled;
        $record->save();

        $audit->record(
            $enabled ? 'organisation_module.uploads_enabled' : 'organisation_module.uploads_disabled',
            $request->user(),
            null,
            ['slug' => $slug],
        );

        $name = $modules->module($slug)->name;

        return redirect()
            ->route('admin.organisation-modules.index')
            ->with('status', $enabled
                ? __('admin.plugins.uploads_turned_on', ['module' => $name])
                : __('admin.plugins.uploads_turned_off', ['module' => $name]));
    }
}
