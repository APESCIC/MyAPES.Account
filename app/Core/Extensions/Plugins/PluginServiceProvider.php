<?php

namespace App\Core\Extensions\Plugins;

use App\Core\Mail\MailTemplate;
use App\Core\Mail\MailTemplateRegistry;
use Illuminate\Support\ServiceProvider;
use ReflectionClass;

/**
 * Base service provider for plugin packages (#287).
 *
 * Loads each plugin's `lang/` tree under {@see PluginManifest::$translationNamespace}
 * so Admin and feature code can resolve `cases::plugin.name` style keys (#272).
 * Translations load whenever the package provider boots — independent of enablement —
 * so disabled plugins do not break other pages that resolve labels.
 */
abstract class PluginServiceProvider extends ServiceProvider
{
    abstract protected function manifest(): PluginManifest;

    /**
     * Outbound mail templates this plugin owns (#352).
     *
     * @return list<MailTemplate>
     */
    protected function mailTemplates(): array
    {
        return [];
    }

    public function register(): void
    {
        $registry = $this->app->make(PluginRegistry::class);

        if (! $registry->has($this->manifest()->slug)) {
            $registry->register($this->manifest());
        }
    }

    public function boot(): void
    {
        $manifest = $this->manifest();

        $langPath = $this->pluginPackagePath().'/lang';
        if (is_dir($langPath) && $manifest->translationNamespace !== '') {
            $this->loadTranslationsFrom($langPath, $manifest->translationNamespace);
        }

        if ($manifest->migrationsPath !== null && is_dir($manifest->migrationsPath)) {
            $this->loadMigrationsFrom($manifest->migrationsPath);
        }

        $this->app->make(MailTemplateRegistry::class)
            ->contribute(fn (): array => $this->mailTemplates());
    }

    /**
     * Package root for the concrete plugin provider (`plugins/<slug>`).
     */
    protected function pluginPackagePath(): string
    {
        $file = (new ReflectionClass($this))->getFileName();

        return dirname((string) $file, 2);
    }
}
