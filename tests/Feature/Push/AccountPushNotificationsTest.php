<?php

namespace Tests\Feature\Push;

use App\Core\Accounts\Permission;
use App\Core\Accounts\Role;
use App\Core\Accounts\User;
use App\Core\Mail\Inbound\Contracts\InboundMailbox;
use App\Core\Mail\Inbound\InboundMailPoller;
use App\Core\Mail\StaffMessage;
use App\Core\Push\PushNotificationTypeKeys;
use App\Core\Push\PushPreferenceInitializer;
use App\Core\Push\UserPushPreference;
use App\Notifications\Auth\PasswordChangedNotification;
use App\Notifications\Auth\TwoFactorEnabledNotification;
use App\Notifications\EmailReplyNotPostedNotification;
use App\Notifications\InboundImportFailingNotification;
use App\Notifications\InboundImportRecoveredNotification;
use App\Notifications\StaffMessageNotification;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use Plugins\Cases\CaseUpdateNotifier;
use Plugins\Cases\Models\CaseUpdate;
use Plugins\Cases\Models\ShelterCase;
use Plugins\Cases\Notifications\ShelterCaseUpdatedNotification;
use Plugins\Consultations\Models\PetCareConsultation;
use Plugins\Consultations\Notifications\ConsultationUpdatedNotification;
use Plugins\PetProfiles\Models\PetProfile;
use Plugins\Recruitment\Models\RecruitmentApplication;
use Plugins\Recruitment\Models\RecruitmentRole;
use Plugins\Recruitment\Notifications\RecruitmentApplicationReceivedNotification;
use Plugins\Recruitment\Notifications\RecruitmentApplicationStatusNotification;
use Tests\Feature\Mail\Inbound\FakeMailbox;
use Tests\Feature\Mail\Inbound\InboundMailTestHelpers;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Unit\Push\Concerns\AssertsPrivatePushPayload;

/**
 * Account-area web push routing and privacy (#798).
 */
class AccountPushNotificationsTest extends TestCase
{
    use AssertsPrivatePushPayload;
    use InboundMailTestHelpers;
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
        $this->grantRolePermission(AuthorizationProfile::ROLE_STAFF, 'shelter-rescue.cases.view-all');
    }

    public function test_internal_case_update_push_targets_staff_not_the_client(): void
    {
        Notification::fake();

        $owner = $this->userReadyForPush(AuthorizationProfile::ROLE_SERVICE_USER);
        $actor = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();
        $staffPeer = $this->userReadyForPush(AuthorizationProfile::ROLE_STAFF);
        $case = $this->shelterCase($owner);

        app(CaseUpdateNotifier::class)->notify($case, $actor, CaseUpdate::VISIBILITY_INTERNAL);

        Notification::assertNotSentTo($owner, ShelterCaseUpdatedNotification::class);
        $this->assertNotificationIncludesPush($staffPeer, ShelterCaseUpdatedNotification::class);
    }

    public function test_public_case_update_push_targets_the_client_when_opted_in(): void
    {
        Notification::fake();

        $owner = $this->userReadyForPush(AuthorizationProfile::ROLE_SERVICE_USER);
        $actor = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();
        $staffPeer = $this->userReadyForPush(AuthorizationProfile::ROLE_STAFF);
        $case = $this->shelterCase($owner);

        app(CaseUpdateNotifier::class)->notify($case, $actor, CaseUpdate::VISIBILITY_PUBLIC);

        $this->assertNotificationIncludesPush($owner, ShelterCaseUpdatedNotification::class);
        $this->assertNotificationIncludesPush($staffPeer, ShelterCaseUpdatedNotification::class);
    }

    public function test_consultation_update_adds_push_without_removing_mail(): void
    {
        $owner = $this->userReadyForPush(AuthorizationProfile::ROLE_SERVICE_USER);
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create([
            'name' => 'Jane Doe',
        ]);
        $pet = PetProfile::query()->create([
            'user_id' => $owner->id,
            'service_domain' => PetProfile::DOMAIN_PETCARE,
            'name' => 'Fluffy',
            'species' => 'cat',
            'sex' => 'unknown',
            'neutering_status' => 'unknown',
        ]);
        $consultation = PetCareConsultation::query()->create([
            'user_id' => $owner->id,
            'pet_profile_id' => $pet->id,
            'subject' => 'Fluffy follow-up',
            'status' => 'open',
            'notes' => 'Secret notes about Fluffy',
        ]);

        $notification = new ConsultationUpdatedNotification($consultation, $staff, 'updated');
        $channels = $notification->via($owner);

        $this->assertContains('mail', $channels);
        $this->assertContains('database', $channels);
        $this->assertContains(WebPushChannel::class, $channels);

        $payload = $notification->toWebPush($owner)->toArray();
        $this->assertPrivatePushPayload($payload);
        $this->assertStringNotContainsString('Fluffy', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_recruitment_application_notifications_add_push_and_keep_mail(): void
    {
        $applicant = $this->userReadyForPush(AuthorizationProfile::ROLE_STUDENT);
        $applicant->contactPreference()->updateOrCreate([], [
            'email' => true,
            'policy_version' => 'test',
            'confirmed_at' => now(),
        ]);
        $applicant = $applicant->fresh();
        $role = RecruitmentRole::factory()->create(['title' => 'Volunteer role']);
        $application = RecruitmentApplication::factory()->create([
            'user_id' => $applicant->id,
            'recruitment_role_id' => $role->id,
            'status' => RecruitmentApplication::STATUS_SUBMITTED,
        ]);

        $received = new RecruitmentApplicationReceivedNotification($application);
        $receivedChannels = $received->via($applicant);
        $this->assertContains('mail', $receivedChannels);
        $this->assertContains(WebPushChannel::class, $receivedChannels);

        $status = new RecruitmentApplicationStatusNotification($application, RecruitmentApplication::STATUS_SHORTLISTED);
        $statusChannels = $status->via($applicant);
        $this->assertContains('mail', $statusChannels);
        $this->assertContains(WebPushChannel::class, $statusChannels);
    }

    public function test_security_push_is_generic_and_mail_stays(): void
    {
        $user = $this->userReadyForPush(AuthorizationProfile::ROLE_SERVICE_USER);

        foreach ([new PasswordChangedNotification, new TwoFactorEnabledNotification] as $notification) {
            $channels = $notification->via($user);
            $this->assertContains('mail', $channels);
            $this->assertContains(WebPushChannel::class, $channels);

            $payload = $notification->toWebPush($user)->toArray();
            $this->assertPrivatePushPayload($payload);
            $this->assertSame('Account security change', $payload['body']);
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('device', strtolower($encoded));
            $this->assertStringNotContainsString('127.0.0.1', $encoded);
        }
    }

    public function test_reply_not_posted_notification_adds_private_push(): void
    {
        $owner = $this->userReadyForPush(AuthorizationProfile::ROLE_SERVICE_USER);
        $ticket = $this->shelterTicket($owner);

        $notification = new EmailReplyNotPostedNotification(
            __('tickets::mail.reply_reference', ['id' => $ticket->id]),
            route('shelter.tickets.show', $ticket),
        );

        $channels = $notification->via($owner);
        $this->assertContains('mail', $channels);
        $this->assertContains(WebPushChannel::class, $channels);

        $payload = $notification->toWebPush($owner)->toArray();
        $this->assertPrivatePushPayload($payload);
        $this->assertStringContainsString('#'.$ticket->id, (string) ($payload['body'] ?? ''));
    }

    public function test_import_alert_push_reaches_administrators_once_per_incident(): void
    {
        $this->enableEmailReplies([
            'inbound-mail.imap.host' => 'imap.example.test',
            'inbound-mail.imap.username' => 'communications@example.test',
            'inbound-mail.imap.password' => 'not-a-real-password',
        ]);
        $failingMailbox = new FakeMailbox;
        $failingMailbox->unavailable = true;
        $this->app->instance(InboundMailbox::class, $failingMailbox);

        Notification::fake();

        $admin = $this->userReadyForPush(AuthorizationProfile::ROLE_ADMINISTRATOR);
        $this->failPolls(2);
        Notification::assertNothingSent();

        $this->failPolls(1);
        Notification::assertSentTo($admin, InboundImportFailingNotification::class, function (InboundImportFailingNotification $notification) use ($admin): bool {
            return in_array(WebPushChannel::class, $notification->via($admin), true);
        });
        Notification::assertSentTimes(InboundImportFailingNotification::class, 1);

        $healthyMailbox = new FakeMailbox;
        $healthyMailbox->unavailable = false;
        $this->app->instance(InboundMailbox::class, $healthyMailbox);
        $next = app(\App\Core\Mail\Inbound\ReplyMailboxStatus::class)->nextPollAt();
        if ($next?->isFuture()) {
            $this->travelTo($next->addSecond());
        }
        app(InboundMailPoller::class)->poll();

        Notification::assertSentTo($admin, InboundImportRecoveredNotification::class, function (InboundImportRecoveredNotification $notification) use ($admin): bool {
            return in_array(WebPushChannel::class, $notification->via($admin), true);
        });
        Notification::assertSentTimes(InboundImportRecoveredNotification::class, 1);
    }

    public function test_staff_message_notification_adds_push_when_opted_in(): void
    {
        $recipient = $this->userReadyForPush(AuthorizationProfile::ROLE_VOLUNTEER);
        $sender = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();
        $message = StaffMessage::query()->create([
            'sender_id' => $sender->id,
            'category' => StaffMessage::CATEGORY_SERVICE,
            'subject' => 'Secret campaign subject',
            'body_html' => '<p>Private body about Jane Doe</p>',
            'recipient_filter' => 'users',
            'recipient_ids' => [$recipient->id],
            'status' => StaffMessage::STATUS_SENT,
        ]);

        $notification = new StaffMessageNotification($message, $sender);
        $channels = $notification->via($recipient);

        $this->assertContains('mail', $channels);
        $this->assertContains(WebPushChannel::class, $channels);

        $payload = $notification->toWebPush($recipient)->toArray();
        $this->assertPrivatePushPayload($payload);
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Secret campaign subject', $encoded);
        $this->assertStringNotContainsString('Jane Doe', $encoded);
    }

    private function failPolls(int $times): void
    {
        if ($times < 0) {
            return;
        }

        for ($i = 0; $i < $times; $i++) {
            $next = app(\App\Core\Mail\Inbound\ReplyMailboxStatus::class)->nextPollAt();
            if ($next?->isFuture()) {
                $this->travelTo($next->addSecond());
            }
            app(InboundMailPoller::class)->poll();
        }
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

    private function shelterCase(User $owner): ShelterCase
    {
        $pet = PetProfile::query()->create([
            'user_id' => $owner->id,
            'service_domain' => PetProfile::DOMAIN_SHELTER,
            'name' => 'Push pet',
            'species' => 'dog',
            'sex' => 'unknown',
            'neutering_status' => 'unknown',
        ]);

        return ShelterCase::query()->create([
            'sub_core_key' => ShelterCase::SUB_CORE_SHELTER_RESCUE,
            'pet_profile_id' => $pet->id,
            'user_id' => $owner->id,
            'case_type' => 'rescue',
            'status' => 'open',
            'title' => 'Secret case title',
            'details' => 'Welfare concern detail.',
        ]);
    }

    private function shelterTicket(User $owner): \Plugins\Tickets\Models\SupportTicket
    {
        return \Plugins\Tickets\Models\SupportTicket::query()->create([
            'sub_core_key' => 'shelter-rescue',
            'user_id' => $owner->id,
            'service_area' => 'rescue',
            'subject' => 'Secret subject',
            'priority' => 'medium',
            'status' => 'open',
            'description' => 'Ticket description.',
        ]);
    }

    /**
     * @param  class-string  $notificationClass
     */
    private function assertNotificationIncludesPush(User $user, string $notificationClass): void
    {
        Notification::assertSentTo($user, $notificationClass, function (object $notification) use ($user): bool {
            $channels = $notification->via($user);

            return in_array('mail', $channels, true)
                && in_array('database', $channels, true)
                && in_array(WebPushChannel::class, $channels, true);
        });
    }

    private function grantRolePermission(string $roleName, string $permissionName): void
    {
        $role = Role::query()
            ->where('guard_name', 'web')
            ->where('name', $roleName)
            ->firstOrFail();
        $permission = Permission::query()
            ->where('guard_name', 'web')
            ->where('name', $permissionName)
            ->firstOrFail();
        $role->permissions()->syncWithoutDetaching([$permission->id]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}