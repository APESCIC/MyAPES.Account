<?php

namespace App\Services;

use App\Contracts\ModuleActiveRecordDetector;
use App\Contracts\ModuleLifecycleManager;
use App\Contracts\ModuleRegistry;
use App\Core\Accounts\User;
use App\Core\Extensions\Models\ModuleInstallation;
use App\Exceptions\ModuleLifecycleException;
use App\Modules\ModuleCodeStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class DatabaseModuleLifecycleManager implements ModuleLifecycleManager
{
    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly ModuleInstanceLock $lock,
        private readonly ModuleProjectionCache $cache,
        private readonly PrivilegedMutationAuthorizer $authorizer,
        private readonly AuthorizationMetadataSynchronizer $authorization,
        private readonly AuditLogger $audit,
    ) {}

    public function install(
        User $actor,
        string $subCoreKey,
        string $moduleKey,
    ): ModuleInstallation {
        return $this->transition(
            'install',
            $actor,
            $subCoreKey,
            $moduleKey,
            null,
        );
    }

    public function enable(
        User $actor,
        string $subCoreKey,
        string $moduleKey,
        int $expectedVersion,
    ): ModuleInstallation {
        return $this->transition(
            'enable',
            $actor,
            $subCoreKey,
            $moduleKey,
            $expectedVersion,
        );
    }

    public function disable(
        User $actor,
        string $subCoreKey,
        string $moduleKey,
        int $expectedVersion,
    ): ModuleInstallation {
        return $this->transition(
            'disable',
            $actor,
            $subCoreKey,
            $moduleKey,
            $expectedVersion,
        );
    }

    private function transition(
        string $action,
        User $actor,
        string $subCoreKey,
        string $moduleKey,
        ?int $expectedVersion,
    ): ModuleInstallation {
        try {
            return $this->lock->run(
                $subCoreKey,
                $moduleKey,
                function () use (
                    $action,
                    $actor,
                    $subCoreKey,
                    $moduleKey,
                    $expectedVersion,
                ): ModuleInstallation {
                    try {
                        $installation = DB::transaction(function () use (
                            $action,
                            $actor,
                            $subCoreKey,
                            $moduleKey,
                            $expectedVersion,
                        ): ModuleInstallation {
                            [$lockedActor] = $this->authorizer->lock($actor);

                            if (! $this->authorizer
                                ->isEligibleAdministrator($lockedActor)
                                || ! $this->authorizer->authorizes(
                                    $lockedActor,
                                    'admin.modules.manage',
                                )) {
                                throw new ModuleLifecycleException('forbidden');
                            }

                            try {
                                $instance = $this->registry->instance(
                                    $subCoreKey,
                                    $moduleKey,
                                );
                            } catch (\InvalidArgumentException) {
                                throw new ModuleLifecycleException('unknown_instance');
                            }

                            if ($instance->codeStatus === ModuleCodeStatus::Incompatible) {
                                throw new ModuleLifecycleException('incompatible');
                            }

                            if ($instance->codeStatus === ModuleCodeStatus::CodeNotShipped) {
                                throw new ModuleLifecycleException('code_not_shipped');
                            }

                            $installations = ModuleInstallation::query()
                                ->orderBy('sub_core_key')
                                ->orderBy('module_key')
                                ->lockForUpdate()
                                ->get();
                            $installation = $installations->first(
                                static fn (ModuleInstallation $candidate): bool => $candidate->sub_core_key === $subCoreKey
                                    && $candidate->module_key === $moduleKey,
                            );

                            if ($action === 'install') {
                                if ($installation instanceof ModuleInstallation) {
                                    throw new ModuleLifecycleException(
                                        'duplicate_installation',
                                    );
                                }

                                $this->assertDependenciesEnabled(
                                    $instance->dependencyKeys(),
                                    $installations,
                                );
                                $now = now();
                                $installation = new ModuleInstallation;
                                $installation->forceFill([
                                    'sub_core_key' => $subCoreKey,
                                    'module_key' => $moduleKey,
                                    'enabled' => true,
                                    'lock_version' => 1,
                                    'installed_at' => $now,
                                    'installed_by' => $lockedActor->id,
                                    'enabled_at' => $now,
                                    'enabled_by' => $lockedActor->id,
                                    'disabled_at' => null,
                                    'disabled_by' => null,
                                ])->save();
                                $this->authorization->synchronize();
                            } else {
                                if (! $installation instanceof ModuleInstallation) {
                                    throw new ModuleLifecycleException(
                                        'not_installed',
                                    );
                                }

                                $this->assertCurrentVersion(
                                    $installation,
                                    (int) $expectedVersion,
                                );

                                if ($action === 'enable') {
                                    if ($installation->enabled) {
                                        throw new ModuleLifecycleException(
                                            'already_enabled',
                                        );
                                    }

                                    $this->assertDependenciesEnabled(
                                        $instance->dependencyKeys(),
                                        $installations,
                                    );
                                    $installation->forceFill([
                                        'enabled' => true,
                                        'enabled_at' => now(),
                                        'enabled_by' => $lockedActor->id,
                                        'disabled_at' => null,
                                        'disabled_by' => null,
                                        'lock_version' => $installation->lock_version + 1,
                                    ])->save();
                                } elseif ($action === 'disable') {
                                    if (! $installation->enabled) {
                                        throw new ModuleLifecycleException(
                                            'already_disabled',
                                        );
                                    }

                                    $this->assertNoEnabledDependents(
                                        $installation->instanceKey(),
                                        $installations,
                                    );
                                    /** @var ModuleActiveRecordDetector $detector */
                                    $detector = app(
                                        $instance->module->activeRecordDetector,
                                    );
                                    $activeRecords = $detector->count($instance);

                                    if ($activeRecords > 0) {
                                        throw new ModuleLifecycleException(
                                            'active_records',
                                            context: [
                                                'active_record_count' => $activeRecords,
                                            ],
                                        );
                                    }

                                    $installation->forceFill([
                                        'enabled' => false,
                                        'disabled_at' => now(),
                                        'disabled_by' => $lockedActor->id,
                                        'lock_version' => $installation->lock_version + 1,
                                    ])->save();
                                } else {
                                    throw new ModuleLifecycleException(
                                        'invalid_action',
                                    );
                                }
                            }

                            $this->audit->record(
                                'module.lifecycle_succeeded',
                                $lockedActor,
                                $installation,
                                [
                                    'sub_core_key' => $subCoreKey,
                                    'module_key' => $moduleKey,
                                    'action' => $action,
                                    'reason' => 'ok',
                                ],
                            );

                            return $installation->fresh();
                        });
                    } catch (ModuleLifecycleException $exception) {
                        $this->recordFailure(
                            'module.lifecycle_refused',
                            $actor,
                            $action,
                            $subCoreKey,
                            $moduleKey,
                            $exception->reason,
                            $exception->context,
                        );

                        throw $exception;
                    } catch (Throwable) {
                        $this->recordFailure(
                            'module.lifecycle_rolled_back',
                            $actor,
                            $action,
                            $subCoreKey,
                            $moduleKey,
                            'transition_failed',
                        );

                        throw new ModuleLifecycleException('transition_failed');
                    }

                    try {
                        $this->cache->invalidate();
                    } catch (Throwable) {
                        $this->audit->record(
                            'module.projection_invalidation_failed',
                            $actor,
                            $installation,
                            [
                                'sub_core_key' => $subCoreKey,
                                'module_key' => $moduleKey,
                                'action' => $action,
                                'reason' => 'cache_unavailable',
                            ],
                        );
                    }

                    return $installation;
                },
            );
        } catch (ModuleLifecycleException $exception) {
            if ($exception->reason === 'instance_busy') {
                $this->recordFailure(
                    'module.lifecycle_refused',
                    $actor,
                    $action,
                    $subCoreKey,
                    $moduleKey,
                    $exception->reason,
                );
            }

            throw $exception;
        }
    }

    private function assertCurrentVersion(
        ModuleInstallation $installation,
        int $expectedVersion,
    ): void {
        if ($expectedVersion < 1
            || $installation->lock_version !== $expectedVersion) {
            throw new ModuleLifecycleException('stale_transition');
        }
    }

    /**
     * @param  array<int, string>  $dependencies
     * @param  Collection<int, ModuleInstallation>  $installations
     */
    private function assertDependenciesEnabled(
        array $dependencies,
        Collection $installations,
    ): void {
        foreach ($dependencies as $dependency) {
            $enabled = $installations->contains(
                static fn (ModuleInstallation $installation): bool => $installation->instanceKey() === $dependency
                    && $installation->enabled,
            );

            if (! $enabled) {
                throw new ModuleLifecycleException('dependency_unavailable');
            }
        }
    }

    /** @param Collection<int, ModuleInstallation> $installations */
    private function assertNoEnabledDependents(
        string $instanceKey,
        Collection $installations,
    ): void {
        foreach ($this->registry->shippedInstances() as $candidate) {
            if (! in_array(
                $instanceKey,
                $candidate->dependencyKeys(),
                true,
            )) {
                continue;
            }

            if ($installations->contains(
                static fn (ModuleInstallation $installation): bool => $installation->instanceKey() === $candidate->key()
                    && $installation->enabled,
            )) {
                throw new ModuleLifecycleException('enabled_dependent');
            }
        }
    }

    /** @param array<string, int|string|null> $extra */
    private function recordFailure(
        string $event,
        User $actor,
        string $action,
        string $subCoreKey,
        string $moduleKey,
        string $reason,
        array $extra = [],
    ): void {
        $this->audit->record($event, $actor, null, [
            'sub_core_key' => $this->safeKey($subCoreKey),
            'module_key' => $this->safeKey($moduleKey),
            'action' => $action,
            'reason' => $reason,
            ...$extra,
        ]);
    }

    private function safeKey(string $value): string
    {
        return preg_match('/^[a-z0-9-]{1,64}$/', $value) === 1
            ? $value
            : 'invalid';
    }
}
