<?php

namespace App\Http\Controllers\Admin;

use App\Core\Extensions\ExtensionVersionSynchronizer;
use App\Core\Extensions\Models\ExtensionVersion;
use App\Core\Extensions\Modules\ModulePackageRegistry;
use App\Core\Extensions\Plugins\PluginRegistry;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;

/**
 * Installed and code versions of every module and plugin (#473).
 */
class AdminVersionController extends Controller
{
    public function __invoke(
        ExtensionVersionSynchronizer $versions,
        ModulePackageRegistry $modules,
        PluginRegistry $plugins,
    ): View {
        $names = [];
        foreach ($modules->modules() as $module) {
            $names[ExtensionVersion::KIND_MODULE.':'.$module->slug] = $module->name;
        }
        foreach ($plugins->plugins() as $plugin) {
            $names[ExtensionVersion::KIND_PLUGIN.':'.$plugin->slug] = $plugin->name;
        }

        $installed = ExtensionVersion::query()->get()->keyBy(static fn (ExtensionVersion $row): string => $row->kind.':'.$row->slug);
        $history = DB::table('extension_version_history')
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy(static fn (object $entry): string => $entry->kind.':'.$entry->slug);

        $rows = [];
        foreach ($versions->codeVersions() as [$kind, $slug, $codeVersion]) {
            $id = $kind.':'.$slug;
            $record = $installed->get($id);

            $rows[] = [
                'id' => $id,
                'kind' => $kind,
                'name' => $names[$id] ?? $slug,
                'code_version' => $codeVersion,
                'installed_version' => $record?->version,
                'state' => match (true) {
                    $record === null => 'not_recorded',
                    $record->version !== $codeVersion => 'pending',
                    $record->version_updated_at !== null => 'updated',
                    default => 'current',
                },
                'updated_at' => $record?->version_updated_at ?? $record?->created_at,
                'history' => ($history->get($id) ?? collect())->take(5)->all(),
            ];
        }

        return view('admin.versions.index', ['rows' => $rows]);
    }
}
