<?php

namespace App\Core\Mail\Inbound;

use App\Core\Mail\Inbound\Contracts\InboundReplyHandler;
use Closure;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Throwable;

/**
 * Plugins register their reply handlers here (#589), so Core never imports
 * plugin classes. Handlers are built on first use.
 */
final class InboundReplyRegistry
{
    /** @var list<Closure(): InboundReplyHandler> */
    private array $factories = [];

    /** @var array<string, InboundReplyHandler>|null */
    private ?array $resolved = null;

    /**
     * @param  Closure(): InboundReplyHandler  $factory
     */
    public function contribute(Closure $factory): void
    {
        $this->factories[] = $factory;
        $this->resolved = null;
    }

    /**
     * @return array<string, InboundReplyHandler> keyed by prefix
     */
    public function all(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $handlers = [];

        foreach ($this->factories as $factory) {
            $handler = $factory();
            $prefix = strtoupper($handler->prefix());

            if (preg_match('/^[A-Z]$/', $prefix) !== 1) {
                throw new LogicException("Inbound reply prefix [{$prefix}] must be one letter.");
            }

            if (isset($handlers[$prefix])) {
                throw new LogicException("Inbound reply prefix [{$prefix}] is registered twice.");
            }

            $handlers[$prefix] = $handler;
        }

        ksort($handlers);

        return $this->resolved = $handlers;
    }

    public function handler(string $prefix): ?InboundReplyHandler
    {
        return $this->all()[strtoupper($prefix)] ?? null;
    }

    public function offersRepliesFor(string $prefix, Model $record): bool
    {
        $handler = $this->handler($prefix);

        return $handler !== null && $this->enabledFor($handler->moduleSlug($record), $handler->plugin());
    }

    /**
     * The per-service switch on Admin → Emails → Inbound (#585). On unless an admin turned it off.
     */
    public function enabledFor(string $subCoreKey, string $plugin): bool
    {
        try {
            $enabled = InboundReplySetting::query()
                ->where('sub_core_key', $subCoreKey)
                ->where('plugin', $plugin)
                ->value('enabled');
        } catch (Throwable) {
            return true;
        }

        return $enabled === null || (bool) $enabled;
    }
}
