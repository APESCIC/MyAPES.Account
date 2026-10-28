<?php

namespace App\Core\Mail;

use Closure;
use LogicException;

/**
 * Core registry of outbound mail templates (#352).
 *
 * Core and plugins contribute closures that return {@see MailTemplate} lists, so
 * plugin notification classes never have to be imported by Core.
 */
final class MailTemplateRegistry
{
    /** @var list<Closure(): list<MailTemplate>> */
    private array $contributors = [];

    /** @var array<string, MailTemplate>|null */
    private ?array $resolved = null;

    /**
     * @param  Closure(): list<MailTemplate>  $contributor
     */
    public function contribute(Closure $contributor): void
    {
        $this->contributors[] = $contributor;
        $this->resolved = null;
    }

    /**
     * @return array<string, MailTemplate> keyed and sorted by template key
     */
    public function all(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $templates = [];

        foreach ($this->contributors as $contributor) {
            foreach ($contributor() as $template) {
                if (isset($templates[$template->key])) {
                    throw new LogicException("Mail template key [{$template->key}] is registered twice.");
                }

                $templates[$template->key] = $template;
            }
        }

        ksort($templates);

        return $this->resolved = $templates;
    }

    public function find(string $key): ?MailTemplate
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * Resolve the template for a notification instance or class, including
     * compatibility subclasses kept for stored notification type names.
     */
    public function forNotification(object|string $notification): ?MailTemplate
    {
        $class = is_object($notification) ? $notification::class : $notification;

        foreach ($this->all() as $template) {
            if ($class === $template->notification) {
                return $template;
            }
        }

        foreach ($this->all() as $template) {
            if (is_a($class, $template->notification, true)) {
                return $template;
            }
        }

        return null;
    }
}
