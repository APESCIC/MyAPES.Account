<?php

namespace Tests\Feature\Push;

use App\Core\Accounts\User;
use App\Core\Push\CanReceivePush;
use App\Core\Push\PushNotificationTypeKeys;
use App\Core\Push\PushPreferenceInitializer;
use App\Core\Push\PushRecipientContext;
use App\Core\Push\PushRecipientFilter;
use App\Core\Push\UserPushPreference;
use App\Services\AuthorizationProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use NotificationChannels\WebPush\PushSubscription;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PushRecipientFilterTest extends TestCase
{
    use RefreshDatabase;

    private PushRecipientFilter $filter;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app-settings.env' => array_fill_keys(array_keys((array) config('app-settings.env')), null),
            'app-settings.env.VAPID_PUBLIC_KEY' => VapidTestKeys::PUBLIC,
            'app-settings.env.VAPID_PRIVATE_KEY' => VapidTestKeys::PRIVATE,
            'app-settings.env.VAPID_SUBJECT' => VapidTestKeys::SUBJECT,
        ]);

        $this->filter = app(PushRecipientFilter::class);
    }

    public function test_user_without_a_device_gets_no_push(): void
    {
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();
        app(PushPreferenceInitializer::class)->seedDefaultsForUser($staff);

        $context = new PushRecipientContext(
            PushNotificationTypeKeys::TICKET_CLIENT_REPLY,
            actorUserId: $staff->id + 1,
        );

        $this->assertFalse($this->filter->allows($staff, $context));
        $this->assertFalse(app(CanReceivePush::class)($staff, $context));
    }

    public function test_type_off_suppresses_push(): void
    {
        $staff = $this->staffWithDevice();
        UserPushPreference::query()->updateOrCreate(
            ['user_id' => $staff->id, 'type' => PushNotificationTypeKeys::TICKET_CLIENT_REPLY],
            ['enabled' => false],
        );

        $context = new PushRecipientContext(
            PushNotificationTypeKeys::TICKET_CLIENT_REPLY,
            actorUserId: $staff->id + 1,
        );

        $this->assertFalse($this->filter->allows($staff, $context));
    }

    public function test_nobody_gets_a_push_for_their_own_action(): void
    {
        $staff = $this->staffWithDevice();
        UserPushPreference::query()->updateOrCreate(
            ['user_id' => $staff->id, 'type' => PushNotificationTypeKeys::TICKET_CLIENT_REPLY],
            ['enabled' => true],
        );

        $context = new PushRecipientContext(
            PushNotificationTypeKeys::TICKET_CLIENT_REPLY,
            actorUserId: $staff->id,
        );

        $this->assertFalse($this->filter->allows($staff, $context));
    }

    public function test_volunteers_only_receive_division_scoped_pushes_for_divisions_they_can_view(): void
    {
        $permissionId = fn (string $name): int => (int) Permission::query()
            ->where('guard_name', 'web')
            ->where('name', $name)
            ->value('id');

        $volunteerRole = \Spatie\Permission\Models\Role::query()
            ->where('guard_name', 'web')
            ->where('name', AuthorizationProfile::ROLE_VOLUNTEER)
            ->firstOrFail();
        $volunteerRole->permissions()->detach($permissionId('shelter-rescue.tickets.view-all'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $volunteer = $this->volunteerWithDevice();
        UserPushPreference::query()->updateOrCreate(
            ['user_id' => $volunteer->id, 'type' => PushNotificationTypeKeys::TICKET_CREATED],
            ['enabled' => true],
        );

        $allowed = new PushRecipientContext(
            PushNotificationTypeKeys::TICKET_CREATED,
            actorUserId: $volunteer->id + 1,
            divisionPermission: 'apes-cic.tickets.view-all',
        );
        $blocked = new PushRecipientContext(
            PushNotificationTypeKeys::TICKET_CREATED,
            actorUserId: $volunteer->id + 1,
            divisionPermission: 'shelter-rescue.tickets.view-all',
        );

        $this->assertTrue($volunteer->can('apes-cic.tickets.view-all'));
        $this->assertFalse($volunteer->can('shelter-rescue.tickets.view-all'));
        $this->assertTrue($this->filter->allows($volunteer, $allowed));
        $this->assertFalse($this->filter->allows($volunteer, $blocked));
    }

    public function test_students_only_receive_division_scoped_pushes_for_divisions_they_can_view(): void
    {
        $permissionId = fn (string $name): int => (int) Permission::query()
            ->where('guard_name', 'web')
            ->where('name', $name)
            ->value('id');

        $studentRole = \Spatie\Permission\Models\Role::query()
            ->where('guard_name', 'web')
            ->where('name', AuthorizationProfile::ROLE_STUDENT)
            ->firstOrFail();
        $studentRole->permissions()->detach($permissionId('pet-care-clinic.tickets.view-all'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $student = $this->studentWithDevice();
        UserPushPreference::query()->updateOrCreate(
            ['user_id' => $student->id, 'type' => PushNotificationTypeKeys::TICKET_CREATED],
            ['enabled' => true],
        );

        $allowed = new PushRecipientContext(
            PushNotificationTypeKeys::TICKET_CREATED,
            actorUserId: $student->id + 1,
            divisionPermission: 'apes-cic.tickets.view-all',
        );
        $blocked = new PushRecipientContext(
            PushNotificationTypeKeys::TICKET_CREATED,
            actorUserId: $student->id + 1,
            divisionPermission: 'pet-care-clinic.tickets.view-all',
        );

        $this->assertTrue($this->filter->allows($student, $allowed));
        $this->assertFalse($this->filter->allows($student, $blocked));
    }

    private function staffWithDevice(): User
    {
        $user = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create([
            'email_verified_at' => now(),
            'onboarding_completed_at' => now(),
        ]);
        $this->attachDevice($user);

        return $user;
    }

    private function volunteerWithDevice(): User
    {
        $user = User::factory()->protectedRole(AuthorizationProfile::ROLE_VOLUNTEER)->create([
            'email_verified_at' => now(),
            'onboarding_completed_at' => now(),
        ]);
        $this->attachDevice($user);

        return $user;
    }

    private function studentWithDevice(): User
    {
        $user = User::factory()->protectedRole(AuthorizationProfile::ROLE_STUDENT)->create([
            'email_verified_at' => now(),
            'onboarding_completed_at' => now(),
        ]);
        $this->attachDevice($user);

        return $user;
    }

    private function attachDevice(User $user): void
    {
        $payload = VapidTestKeys::subscriptionPayload('device-'.$user->id);
        $this->actingAs($user)->postJson(route('push.subscriptions.store'), $payload)->assertOk();
        PushSubscription::query()->where('endpoint', $payload['endpoint'])->firstOrFail();
    }
}