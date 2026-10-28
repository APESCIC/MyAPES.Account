<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use App\Http\Controllers\Admin\AdminEmailController;
use App\Notifications\Auth\VerifyEmailNotification;
use App\Services\AuthorizationProfile;
use App\Services\VerificationResendService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Plugins\Tickets\Notifications\TicketUpdatedNotification;
use Tests\TestCase;

class AdminEmailSendTestAndResendTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_sends_a_test_to_their_own_address_only(): void
    {
        Notification::fake();
        $superAdmin = User::factory()->protectedRole(AuthorizationProfile::ROLE_SUPER_ADMIN)->create();
        $bystander = User::factory()->create();

        $this->actingAs($superAdmin)
            ->get(route('admin.emails.show', 'tickets.ticket_updated'))
            ->assertOk()
            ->assertSee('action="'.route('admin.emails.test', 'tickets.ticket_updated').'"', false)
            ->assertSeeText($superAdmin->email);

        $this->actingAs($superAdmin)
            ->post(route('admin.emails.test', 'tickets.ticket_updated'))
            ->assertRedirect(route('admin.emails.show', 'tickets.ticket_updated'))
            ->assertSessionHas('status', __('admin.flash.email_test_sent'));

        Notification::assertSentTo(
            $superAdmin,
            TicketUpdatedNotification::class,
            fn (TicketUpdatedNotification $notification, array $channels): bool => $channels === ['mail'],
        );
        Notification::assertNothingSentTo($bystander);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'admin.emails.test_sent',
            'user_id' => $superAdmin->id,
        ]);
    }

    public function test_send_test_is_immediate_and_leaves_no_in_app_notification(): void
    {
        Queue::fake();
        $superAdmin = User::factory()->protectedRole(AuthorizationProfile::ROLE_SUPER_ADMIN)->create();

        $this->actingAs($superAdmin)
            ->post(route('admin.emails.test', 'tickets.ticket_updated'))
            ->assertRedirect();

        Queue::assertNothingPushed();
        $this->assertSame(0, DB::table('notifications')->count());
    }

    public function test_send_test_is_rate_limited_per_admin(): void
    {
        Notification::fake();
        $superAdmin = User::factory()->protectedRole(AuthorizationProfile::ROLE_SUPER_ADMIN)->create();

        for ($i = 0; $i < AdminEmailController::TEST_SENDS_PER_WINDOW; $i++) {
            $this->actingAs($superAdmin)
                ->post(route('admin.emails.test', 'auth.password_changed'))
                ->assertSessionHasNoErrors();
        }

        $this->actingAs($superAdmin)
            ->from(route('admin.emails.show', 'auth.password_changed'))
            ->post(route('admin.emails.test', 'auth.password_changed'))
            ->assertRedirect(route('admin.emails.show', 'auth.password_changed'))
            ->assertSessionHasErrors('send_test');

        Notification::assertCount(AdminEmailController::TEST_SENDS_PER_WINDOW);
    }

    public function test_administrators_without_manage_cannot_send_tests(): void
    {
        Notification::fake();
        $administrator = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();

        $this->actingAs($administrator)
            ->get(route('admin.emails.show', 'auth.verify_email'))
            ->assertOk()
            ->assertDontSee('action="'.route('admin.emails.test', 'auth.verify_email').'"', false);

        $this->actingAs($administrator)
            ->post(route('admin.emails.test', 'auth.verify_email'))
            ->assertForbidden();

        Notification::assertNothingSent();
    }

    public function test_admin_resends_verification_with_a_fresh_link_each_time(): void
    {
        Notification::fake();
        $administrator = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();
        $target = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->unverified()->create();

        $this->actingAs($administrator)
            ->get(route('admin.users.show', $target))
            ->assertOk()
            ->assertSee('action="'.route('admin.users.resend-verification', $target).'"', false);

        $this->actingAs($administrator)
            ->post(route('admin.users.resend-verification', $target))
            ->assertRedirect(route('admin.users.show', $target))
            ->assertSessionHas('status', __('admin.flash.verification_resent'));
        $firstUrl = $this->latestVerificationUrl($target);

        $this->travel(2)->minutes();

        $this->actingAs($administrator)
            ->post(route('admin.users.resend-verification', $target))
            ->assertSessionHasNoErrors();
        $secondUrl = $this->latestVerificationUrl($target);

        Notification::assertSentToTimes($target, VerifyEmailNotification::class, 2);
        $this->assertNotSame($firstUrl, $secondUrl);
        $this->assertStringContainsString('signature=', $secondUrl);
        $this->assertSame(2, DB::table('audit_logs')->where('event', 'auth.verification_resent')->count());
        $this->assertStringNotContainsString('url', (string) json_encode(
            DB::table('audit_logs')->where('event', 'auth.verification_resent')->pluck('context')->all(),
        ));
    }

    public function test_resend_is_rate_limited_per_target(): void
    {
        Notification::fake();
        $administrator = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();
        $target = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->unverified()->create();

        for ($i = 0; $i < VerificationResendService::MAX_PER_HOUR; $i++) {
            $this->actingAs($administrator)->post(route('admin.users.resend-verification', $target))->assertSessionHasNoErrors();
        }

        $this->actingAs($administrator)
            ->post(route('admin.users.resend-verification', $target))
            ->assertSessionHasErrors('resend_verification');

        Notification::assertSentToTimes($target, VerifyEmailNotification::class, VerificationResendService::MAX_PER_HOUR);
    }

    public function test_resend_refuses_verified_and_directory_accounts(): void
    {
        Notification::fake();
        $administrator = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();
        $verified = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create();
        $directory = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create([
            'identity_type' => User::IDENTITY_CLOUDRON_OIDC,
            'email_verified_at' => null,
        ]);

        foreach ([$verified, $directory] as $target) {
            $this->actingAs($administrator)
                ->get(route('admin.users.show', $target))
                ->assertOk()
                ->assertDontSee('action="'.route('admin.users.resend-verification', $target).'"', false);

            $this->actingAs($administrator)
                ->post(route('admin.users.resend-verification', $target))
                ->assertSessionHasErrors('resend_verification');
        }

        Notification::assertNothingSent();
    }

    private function latestVerificationUrl(User $target): string
    {
        $notification = Notification::sent($target, VerifyEmailNotification::class)->last();

        return (string) $notification->toMail($target)->viewData['url'];
    }

    public function test_staff_cannot_resend_verification(): void
    {
        Notification::fake();
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();
        $target = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->unverified()->create();

        $this->actingAs($staff)
            ->post(route('admin.users.resend-verification', $target))
            ->assertForbidden();

        Notification::assertNothingSent();
    }
}
