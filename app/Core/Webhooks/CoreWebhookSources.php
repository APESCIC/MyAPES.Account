<?php

namespace App\Core\Webhooks;

use App\Core\Accounts\AccountClassifier;
use App\Core\Accounts\ContactConsentEvent;
use App\Core\Accounts\StaffProfile;
use App\Core\Accounts\User;
use App\Core\Api\ApiScope;
use Closure;

/**
 * The Core changes that send webhooks (#641): public accounts (user.*),
 * contact consent (consent.changed) and staff (staff.updated). Plugins
 * register their own records with WebhookSources::changes().
 */
final class CoreWebhookSources
{
    /** User fields whose change is worth telling other apps about. */
    private const USER_FIELDS = ['name', 'email', 'username', 'email_verified_at', 'suspended_at'];

    public static function register(): void
    {
        User::created(static function (User $user): void {
            self::staffSide($user)
                ? self::dispatch(WebhookEvents::STAFF_UPDATED, self::staffOrganisations($user), ['user_id' => $user->id, 'changes' => ['created']])
                : self::dispatch(WebhookEvents::USER_CREATED, self::peopleOrganisations($user), ['user_id' => $user->id]);
        });

        User::updated(static function (User $user): void {
            $changes = array_values(array_intersect(self::USER_FIELDS, array_keys($user->getChanges())));
            if ($changes === []) {
                return;
            }

            self::staffSide($user)
                ? self::dispatch(WebhookEvents::STAFF_UPDATED, self::staffOrganisations($user), ['user_id' => $user->id, 'changes' => $changes])
                : self::dispatch(WebhookEvents::USER_UPDATED, self::peopleOrganisations($user), ['user_id' => $user->id, 'changes' => $changes]);
        });

        User::deleting(static function (User $user): void {
            if (! self::staffSide($user)) {
                self::dispatch(WebhookEvents::USER_DELETED, $user->serviceSelections()->pluck('sub_core_key')->all(), ['user_id' => $user->id]);
            }
        });

        StaffProfile::saved(static function (StaffProfile $profile): void {
            $changes = WebhookSources::changedFields($profile);
            if ($changes === [] || $profile->user === null) {
                return;
            }

            self::dispatch(WebhookEvents::STAFF_UPDATED, self::staffOrganisations($profile->user), ['user_id' => $profile->user_id, 'changes' => $changes]);
        });

        ContactConsentEvent::created(static function (ContactConsentEvent $event): void {
            $user = $event->user;
            if ($user === null) {
                return;
            }

            self::dispatch(WebhookEvents::CONSENT_CHANGED, self::peopleOrganisations($user), [
                'user_id' => $user->id,
                'channel' => $event->channel,
                'granted' => (bool) $event->granted,
            ]);
        });
    }

    private static function staffSide(User $user): bool
    {
        return app(AccountClassifier::class)->isStaffSide($user);
    }

    /**
     * @return Closure(): list<string>
     */
    private static function peopleOrganisations(User $user): Closure
    {
        return static fn (): array => $user->serviceSelections()->pluck('sub_core_key')->all();
    }

    /**
     * @return Closure(): list<string>
     */
    private static function staffOrganisations(User $user): Closure
    {
        return static fn (): array => array_values(array_filter([
            ApiScope::UMBRELLA_ORGANISATION,
            StaffProfile::query()->where('user_id', $user->id)->value('team'),
        ]));
    }

    /**
     * @param  list<string>|Closure(): iterable<string>  $organisations
     * @param  array<string, mixed>  $data
     */
    private static function dispatch(string $event, array|Closure $organisations, array $data): void
    {
        app(WebhookDispatcher::class)->dispatch($event, $organisations, $data);
    }
}
