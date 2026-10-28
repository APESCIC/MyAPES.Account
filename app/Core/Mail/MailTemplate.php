<?php

namespace App\Core\Mail;

use App\Core\Accounts\User;
use App\Core\Extensions\Plugins\PluginRegistry;
use Closure;
use Illuminate\Notifications\Notification;
use InvalidArgumentException;

/**
 * One registered outbound mail template (#352).
 *
 * The Blade view and lang keys stay the source of truth; the registry only
 * describes the template so Admin can list, preview and test it.
 */
final class MailTemplate
{
    /**
     * @param  class-string<Notification>  $notification
     * @param  Closure(User): Notification  $sample  Builds a notification from in-memory sample data; must not persist or send.
     * @param  string|null  $subjectKey  Lang key Admins may override for the subject (#361).
     * @param  string|null  $introKey  Lang key Admins may override for the opening line (#361).
     */
    public function __construct(
        public readonly string $key,
        public readonly string $notification,
        public readonly MailCategory $category,
        public readonly string $labelKey,
        public readonly string $descriptionKey,
        public readonly Closure $sample,
        public readonly string $owner = 'core',
        public readonly ?string $subjectKey = null,
        public readonly ?string $introKey = null,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $key) !== 1) {
            throw new InvalidArgumentException("Mail template key [{$key}] must look like group.name in snake_case.");
        }

        if (! is_a($notification, Notification::class, true)) {
            throw new InvalidArgumentException("Mail template [{$key}] must reference a Notification class.");
        }
    }

    public function hasEditableCopy(): bool
    {
        return $this->subjectKey !== null || $this->introKey !== null;
    }

    public function label(): string
    {
        return __($this->labelKey);
    }

    public function description(): string
    {
        return __($this->descriptionKey);
    }

    public function ownerLabel(): string
    {
        $plugins = app(PluginRegistry::class);

        return $this->owner !== 'core' && $plugins->has($this->owner)
            ? $plugins->plugin($this->owner)->label()
            : __('admin.emails.owner_core');
    }

    public function sampleNotification(User $recipient): Notification
    {
        return ($this->sample)($recipient);
    }
}
