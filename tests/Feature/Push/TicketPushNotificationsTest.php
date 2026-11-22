<?php

namespace Tests\Feature\Push;

use App\Core\Accounts\User;
use App\Core\Push\PushNotificationTypeKeys;
use App\Core\Push\PushPreferenceInitializer;
use App\Core\Push\UserPushPreference;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use Plugins\Tickets\Models\SupportTicket;
use Plugins\Tickets\Notifications\TicketUpdatedNotification;
use Plugins\Tickets\TicketPushKind;
use Plugins\Tickets\TicketStakeholderNotifier;
use Tests\TestCase;
use Tests\Unit\Push\Concerns\AssertsPrivatePushPayload;

/**
 * Ticket web push routing and privacy (#797).
 */
class TicketPushNotificationsTest extends TestCase
{
    use AssertsPrivatePushPayload;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app-settings.env' => array_fill_keys(array_keys((array) config('app-settings.env')), null),
            'app-settings.env.VAPID_PUBLIC_KEY' => VapidTestKeys::PUBLIC,
            'app-settings.env.VAPID_PRIVATE_KEY' => VapidTestKeys::PRIVATE,
            'app-settings.env.VAPID_SUBJECT' => VapidTestKeys::SUBJECT,
        ]);

        app(ModuleInstallationSynchronizer::class)->synchronize();
    }

    public function test_new_ticket_push_targets_division_staff_not_the_client(): void
    {
        Notification::fake();

        $client = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create([
            'name' => 'Jane Doe',
        ]);
        $divisionStaff = $this->userReadyForPush(AuthorizationProfile::ROLE_STAFF);
        $otherStaff = $this->userReadyForPush(AuthorizationProfile::ROLE_STAFF);

        $ticket = $this->shelterTicket($client, [
            'subject' => 'Secret subject',
            'description' => 'Welfare concern detail in the body.',
        ]);

        app(TicketStakeholderNotifier::class)->notify(
            $ticket,
            $client,
            'created',
            pushKind: TicketPushKind::CREATED,
        );

        $this->assertPushType($divisionStaff, PushNotificationTypeKeys::TICKET_CREATED);
        $this->assertPushType($otherStaff, PushNotificationTypeKeys::TICKET_CREATED);
        $this->assertNotNotified($client);
    }

    public function test_client_reply_push_targets_assignee_when_assigned_otherwise_division(): void
    {
        Notification::fake();

        $client = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create();
        $assignee = $this->userReadyForPush(AuthorizationProfile::ROLE_VOLUNTEER);
        $divisionStaff = $this->userReadyForPush(AuthorizationProfile::ROLE_STAFF);

        $ticket = $this->shelterTicket($client, ['assigned_to' => $assignee->id]);

        app(TicketStakeholderNotifier::class)->notify(
            $ticket,
            $client,
            'updated',
            pushKind: TicketPushKind::CLIENT_REPLY,
        );

        $this->assertPushType($assignee, PushNotificationTypeKeys::TICKET_CLIENT_REPLY);
        $this->assertMailWithoutPush($divisionStaff);

        Notification::fake();

        $unassigned = $this->shelterTicket($client);
        app(TicketStakeholderNotifier::class)->notify(
            $unassigned,
            $client,
            'updated',
            pushKind: TicketPushKind::CLIENT_REPLY,
        );

        $this->assertPushType($divisionStaff, PushNotificationTypeKeys::TICKET_CLIENT_REPLY);
    }

    public function test_staff_reply_push_targets_ticket_owner_only(): void
    {
        Notification::fake();

        $owner = $this->userReadyForPush(AuthorizationProfile::ROLE_SERVICE_USER);
        $staff = $this->userReadyForPush(AuthorizationProfile::ROLE_STAFF);
        $ticket = $this->shelterTicket($owner);

        app(TicketStakeholderNotifier::class)->notify(
            $ticket,
            $staff,
            'updated',
            pushKind: TicketPushKind::STAFF_REPLY,
        );

        $this->assertPushType($owner, PushNotificationTypeKeys::TICKET_STAFF_REPLY);
        $this->assertNotNotified($staff);
    }

    public function test_assignment_push_targets_the_new_assignee_only(): void
    {
        Notification::fake();

        $owner = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create();
        $assignee = $this->userReadyForPush(AuthorizationProfile::ROLE_STUDENT);
        $actor = $this->userReadyForPush(AuthorizationProfile::ROLE_STAFF);
        $bystander = $this->userReadyForPush(AuthorizationProfile::ROLE_STAFF);
        $ticket = $this->shelterTicket($owner, ['assigned_to' => $assignee->id]);

        app(TicketStakeholderNotifier::class)->notify(
            $ticket,
            $actor,
            'updated',
            pushKind: TicketPushKind::ASSIGNED,
        );

        $this->assertPushType($assignee, PushNotificationTypeKeys::TICKET_ASSIGNED);
        $this->assertMailWithoutPush($owner);
        $this->assertMailWithoutPush($bystander);
    }

    public function test_internal_note_push_targets_assignee_and_never_the_client(): void
    {
        Notification::fake();

        $owner = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create();
        $assignee = $this->userReadyForPush(AuthorizationProfile::ROLE_STAFF);
        $author = $this->userReadyForPush(AuthorizationProfile::ROLE_STAFF);
        $ticket = $this->shelterTicket($owner, ['assigned_to' => $assignee->id]);

        app(TicketStakeholderNotifier::class)->notify(
            $ticket,
            $author,
            'updated',
            staffOnly: true,
            pushKind: TicketPushKind::INTERNAL_NOTE,
        );

        $this->assertPushType($assignee, PushNotificationTypeKeys::TICKET_INTERNAL_NOTE);
        $this->assertNotNotified($owner);
    }

    public function test_resolved_or_closed_push_targets_the_ticket_owner(): void
    {
        $owner = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create();
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();
        $ticket = $this->shelterTicket($owner);

        $pushTypeKey = app(\Plugins\Tickets\TicketPushRecipientResolver::class)->resolveTypeKey(
            $owner,
            TicketPushKind::STATUS_CHANGED,
            $ticket,
            $staff,
        );

        $this->assertSame(PushNotificationTypeKeys::TICKET_STATUS_CHANGED, $pushTypeKey);
        $this->assertNull(app(\Plugins\Tickets\TicketPushRecipientResolver::class)->resolveTypeKey(
            $staff,
            TicketPushKind::STATUS_CHANGED,
            $ticket,
            $staff,
        ));

        $notification = new TicketUpdatedNotification(
            $ticket,
            $staff,
            'updated',
            'shelter-rescue',
            'shelter.tickets.show',
            'Shelter and Rescue',
            pushTypeKey: $pushTypeKey,
        );

        $this->assertSame(PushNotificationTypeKeys::TICKET_STATUS_CHANGED, $notification->pushTypeKey());
    }

    public function test_email_reply_push_matches_a_site_reply(): void
    {
        Notification::fake();

        $owner = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create();
        $staff = $this->userReadyForPush(AuthorizationProfile::ROLE_STAFF);
        $ticket = $this->shelterTicket($owner);

        $notifier = app(TicketStakeholderNotifier::class);
        $pushKinds = app(\Plugins\Tickets\TicketPushKindResolver::class);

        $notifier->notify(
            $ticket,
            $owner,
            'updated',
            pushKind: $pushKinds->forInboundReply($owner),
        );

        $this->assertPushType($staff, PushNotificationTypeKeys::TICKET_CLIENT_REPLY);

        Notification::fake();

        $notifier->notify(
            $ticket,
            $owner,
            'updated',
            pushKind: $pushKinds->forInboundReply($owner),
        );

        $this->assertPushType($staff, PushNotificationTypeKeys::TICKET_CLIENT_REPLY);
    }

    public function test_repeated_replies_share_topic_and_tag(): void
    {
        $owner = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create();
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();
        $ticket = $this->shelterTicket($owner, [
            'subject' => 'Secret subject',
        ]);

        $first = new TicketUpdatedNotification(
            $ticket,
            $owner,
            'updated',
            'shelter-rescue',
            'shelter.tickets.show',
            'Shelter and Rescue',
            pushTypeKey: PushNotificationTypeKeys::TICKET_CLIENT_REPLY,
        );
        $second = new TicketUpdatedNotification(
            $ticket->fresh(),
            $owner,
            'updated',
            'shelter-rescue',
            'shelter.tickets.show',
            'Shelter and Rescue',
            pushTypeKey: PushNotificationTypeKeys::TICKET_CLIENT_REPLY,
        );

        $firstPayload = $first->toWebPush($staff)->toArray();
        $secondPayload = $second->toWebPush($staff)->toArray();

        $this->assertSame($firstPayload['tag'], $secondPayload['tag']);
        $this->assertSame(
            $first->toWebPush($staff)->getOptions()['topic'],
            $second->toWebPush($staff)->getOptions()['topic'],
        );
        $this->assertSame('ticket:'.$ticket->id, $first->toWebPush($staff)->getOptions()['topic']);
    }

    public function test_ticket_push_payload_omits_subject_message_text_and_names(): void
    {
        $owner = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create([
            'name' => 'Jane Doe',
            'email' => 'jane.doe@example.org',
        ]);
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();
        $ticket = $this->shelterTicket($owner, [
            'subject' => 'Secret subject about Fluffy',
            'description' => 'Welfare concern detail.',
        ]);

        $notification = new TicketUpdatedNotification(
            $ticket,
            $staff,
            'updated',
            'shelter-rescue',
            'shelter.tickets.show',
            'Shelter and Rescue',
            pushTypeKey: PushNotificationTypeKeys::TICKET_STAFF_REPLY,
        );

        $payload = $notification->toWebPush($owner)->toArray();
        $this->assertPrivatePushPayload($payload);
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Secret subject', $encoded);
        $this->assertStringNotContainsString('Fluffy', $encoded);
        $this->assertStringNotContainsString('Jane Doe', $encoded);
        $this->assertStringNotContainsString('Welfare concern detail', $encoded);
    }

    public function test_mail_and_database_channels_stay_available_when_push_is_present(): void
    {
        $owner = $this->userReadyForPush(AuthorizationProfile::ROLE_SERVICE_USER);
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();
        $ticket = $this->shelterTicket($owner);

        $notification = new TicketUpdatedNotification(
            $ticket,
            $staff,
            'updated',
            'shelter-rescue',
            'shelter.tickets.show',
            'Shelter and Rescue',
            pushTypeKey: PushNotificationTypeKeys::TICKET_STAFF_REPLY,
        );

        $channels = $notification->via($owner);
        $this->assertContains('mail', $channels);
        $this->assertContains('database', $channels);
        $this->assertContains(WebPushChannel::class, $channels);
    }

    private function userReadyForPush(string $role, ?string $email = null): User
    {
        $user = User::factory()->protectedRole($role)->create(array_filter([
            'email' => $email,
            'email_verified_at' => now(),
            'onboarding_completed_at' => now(),
        ]));

        app(PushPreferenceInitializer::class)->seedDefaultsForUser($user);
        foreach (PushNotificationTypeKeys::catalogueKeys() as $type) {
            UserPushPreference::query()->updateOrCreate(
                ['user_id' => $user->id, 'type' => $type],
                ['enabled' => true, 'email_enabled' => true],
            );
        }

        $this->actingAs($user)->postJson(
            route('push.subscriptions.store'),
            VapidTestKeys::subscriptionPayload('device-'.$user->id),
        )->assertOk();

        return $user->fresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function shelterTicket(User $owner, array $attributes = []): SupportTicket
    {
        return SupportTicket::query()->create([
            'sub_core_key' => 'shelter-rescue',
            'user_id' => $owner->id,
            'service_area' => 'rescue',
            'subject' => 'Shelter ticket',
            'priority' => 'medium',
            'status' => 'open',
            'description' => 'Ticket description.',
            ...$attributes,
        ]);
    }

    private function assertPushType(User $user, string $expectedType): void
    {
        Notification::assertSentTo($user, TicketUpdatedNotification::class, function (TicketUpdatedNotification $notification) use ($expectedType): bool {
            $this->assertSame($expectedType, $notification->pushTypeKey());

            return true;
        });
    }

    private function assertMailWithoutPush(User $user): void
    {
        Notification::assertSentTo($user, TicketUpdatedNotification::class, function (TicketUpdatedNotification $notification): bool {
            $this->assertNull($notification->pushTypeKey());

            return true;
        });
    }

    private function assertNotNotified(User $user): void
    {
        Notification::assertNotSentTo($user, TicketUpdatedNotification::class);
    }
}