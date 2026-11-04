<?php

namespace App\Core\Extensions;

use App\Core\Extensions\Models\ExtensionVersion;
use App\Core\Extensions\Modules\ModulePackageRegistry;
use App\Core\Extensions\Plugins\PluginRegistry;
use Illuminate\Support\Facades\DB;

/**
 * Records the code version of every module and plugin package (#473) and
 * logs each change, so Admin can show "update applied" with a date.
 * Runs with myapes:modules:sync on every deploy.
 */
final class ExtensionVersionSynchronizer
{
    public function __construct(
        private readonly ModulePackageRegistry $modules,
        private readonly PluginRegistry $plugins,
    ) {}

    /**
     * @return array{recorded: int, updated: int}
     */
    public function synchronize(): array
    {
        $recorded = 0;
        $updated = 0;

        foreach ($this->codeVersions() as [$kind, $slug, $version]) {
            $row = ExtensionVersion::query()->where('kind', $kind)->where('slug', $slug)->first();

            if ($row === null) {
                ExtensionVersion::query()->create(['kind' => $kind, 'slug' => $slug, 'version' => $version]);
                $this->history($kind, $slug, null, $version);
                $recorded++;

                continue;
            }

            if ($row->version !== $version) {
                $this->history($kind, $slug, $row->version, $version);
                $row->forceFill([
                    'previous_version' => $row->version,
                    'version' => $version,
                    'version_updated_at' => now(),
                ])->save();
                $updated++;
            }
        }

        return compact('recorded', 'updated');
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public function codeVersions(): array
    {
        $versions = [];

        foreach ($this->modules->modules() as $module) {
            $versions[] = [ExtensionVersion::KIND_MODULE, $module->slug, $module->version];
        }

        foreach ($this->plugins->plugins() as $plugin) {
            $versions[] = [ExtensionVersion::KIND_PLUGIN, $plugin->slug, $plugin->version];
        }

        return $versions;
    }

    private function history(string $kind, string $slug, ?string $from, string $to): void
    {
        DB::table('extension_version_history')->insert([
            'kind' => $kind,
            'slug' => $slug,
            'from_version' => $from,
            'to_version' => $to,
            'recorded_at' => now(),
        ]);
    }
}
