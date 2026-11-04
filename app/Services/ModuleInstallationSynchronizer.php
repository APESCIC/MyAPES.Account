<?php

namespace App\Services;

use App\Contracts\ModuleRegistry;
use App\Core\Extensions\ExtensionVersionSynchronizer;
use App\Modules\ModuleInstanceDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ModuleInstallationSynchronizer
{
    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly ModuleInstanceLock $locks,
        private readonly AuthorizationMetadataSynchronizer $authorization,
        private readonly AuthorizationProfile $profile,
        private readonly ModuleProjectionCache $cache,
        private readonly ModuleSettingsService $moduleSettings,
        private readonly ExtensionVersionSynchronizer $versions,
    ) {}

    /** @return array{created: int, existing: int} */
    public function synchronize(): array
    {
        $this->profile->flushRuntimeCache();
        $result = $this->locks->runMany(
            array_map(
                static fn (ModuleInstanceDefinition $instance): string => $instance->key(),
                $this->registry->matrix(),
            ),
            fn (): array => DB::transaction(function (): array {
                $this->authorization->synchronize();
                $created = 0;
                $existing = 0;
                $now = now();
                $states = DB::table('module_plugins')
                    ->orderBy('sub_core_key')
                    ->orderBy('module_key')
                    ->lockForUpdate()
                    ->get(['sub_core_key', 'module_key', 'enabled'])
                    ->mapWithKeys(
                        static fn ($installation): array => [
                            "{$installation->sub_core_key}:{$installation->module_key}" => (bool) $installation->enabled,
                        ],
                    )
                    ->all();

                foreach ($this->orderedShippedInstances() as $instance) {
                    if (array_key_exists($instance->key(), $states)) {
                        $existing++;

                        continue;
                    }

                    $enabled = collect($instance->dependencyKeys())
                        ->every(
                            static fn (string $dependency): bool => ($states[$dependency] ?? false) === true,
                        );
                    $inserted = DB::table('module_plugins')->insertOrIgnore([
                        'sub_core_key' => $instance->subCore->key,
                        'module_key' => $instance->module->key,
                        'enabled' => $enabled,
                        'lock_version' => 1,
                        'installed_at' => $now,
                        'installed_by' => null,
                        'enabled_at' => $enabled ? $now : null,
                        'enabled_by' => null,
                        'disabled_at' => null,
                        'disabled_by' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    if ($inserted === 1) {
                        $created++;
                        $states[$instance->key()] = $enabled;
                    } else {
                        $existing++;
                        $states[$instance->key()] = (bool) DB::table(
                            'module_plugins',
                        )
                            ->where('sub_core_key', $instance->subCore->key)
                            ->where('module_key', $instance->module->key)
                            ->value('enabled');
                    }
                }

                $this->moduleSettings->seedConfigurableDefaults();
                $this->versions->synchronize();

                return compact('created', 'existing');
            }),
        );

        if ($result['created'] > 0) {
            try {
                $this->cache->invalidate();
            } catch (Throwable) {
                Log::warning('Module projection invalidation failed.', [
                    'reason' => 'cache_unavailable',
                ]);
            }
        }

        return $result;
    }

    /** @return array<int, ModuleInstanceDefinition> */
    private function orderedShippedInstances(): array
    {
        $pending = $this->registry->shippedInstances();
        $ordered = [];

        while ($pending !== []) {
            $progress = false;

            foreach ($pending as $key => $instance) {
                $waiting = array_filter(
                    $instance->dependencyKeys(),
                    static fn (string $dependency): bool => array_key_exists(
                        $dependency,
                        $pending,
                    ),
                );

                if ($waiting !== []) {
                    continue;
                }

                $ordered[] = $instance;
                unset($pending[$key]);
                $progress = true;
            }

            if (! $progress) {
                throw new RuntimeException('Module dependency order is invalid.');
            }
        }

        return $ordered;
    }
}
