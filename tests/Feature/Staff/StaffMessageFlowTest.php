<?php

namespace Tests\Feature\Staff;

use App\Core\Accounts\AccountClassifier;
use App\Core\Accounts\Permission;
use App\Core\Accounts\Role;
use App\Core\Accounts\RoleSource;
use App\Core\Accounts\User;
use App\Core\Mail\MailTemplateRegistry;
use App\Core\Mail\StaffMessage;
use App\Jobs\SendStaffMessageJob;
use App\Notifications\StaffMessageNotification;
use App\Services\AuthorizationProfile;
use App\Services\AuthorizationRoleMaterializer;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Staff email flow (#509).
 */
class StaffMessageFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
    }

    public function test_staff_can_save_draft_and_preview_public_group(): void
    {
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();

        User::factory()->create(['email' => 'recipient@example.com']);

        $response = $this->actingAs($staff)->post(route('messages.store'), [
            'category' => StaffMessage::CATEGORY_SERVICE,
            'subject' => 'Service update',
            'body_html' => '<p>Hello everyone</p>',
            'filter_mode' => 'group',
            'filter_group' => AccountClassifier::PUBLIC,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status', __('staff_messages.flash.draft_saved'));

        $message = StaffMessage::query()->sole();
        $this->assertSame(StaffMessage::STATUS_DRAFT, $message->status);

        $this->actingAs($staff)
            ->put(route('messages.update', $message), [
                'category' => StaffMessage::CATEGORY_SERVICE,
                'subject' => 'Service update',
                'body_html' => '<p>Hello everyone</p>',
                'filter_mode' => 'group',
                'filter_group' => AccountClassifier::PUBLIC,
            ])
            ->assertRedirect(route('messages.preview', $message));

        $message->refresh();
        $this->assertGreaterThanOrEqual(1, $message->recipient_count);
    }

    public function test_staff_without_send_all_cannot_use_all_mode(): void
    {
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();

        $this->actingAs($staff)
            ->post(route('messages.store'), [
                'category' => StaffMessage::CATEGORY_SERVICE,
                'subject' => 'Broadcast',
                'body_html' => '<p>Hello</p>',
                'filter_mode' => 'all',
            ])
            ->assertForbidden();
    }

    public function test_optional_excludes_opted_out_recipients(): void
    {
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();

        $optedIn = User::factory()->create(['email' => 'opted-in@example.com']);
        $optedIn->contactPreference()->updateOrCreate([], ['email' => true, 'policy_version' => 'test', 'confirmed_at' => now()]);

        $optedOut = User::factory()->create(['email' => 'opted-out@example.com']);
        $optedOut->contactPreference()->updateOrCreate([], ['email' => false, 'policy_version' => 'test', 'confirmed_at' => now()]);

        $message = StaffMessage::query()->create([
            'sender_id' => $staff->id,
            'category' => StaffMessage::CATEGORY_OPTIONAL,
            'subject' => 'Optional hello',
            'body_html' => '<p>Hi</p>',
            'recipient_filter' => [
                'mode' => 'users',
                'user_ids' => [$optedIn->id, $optedOut->id],
            ],
            'status' => StaffMessage::STATUS_PREVIEW,
        ]);

        $this->actingAs($staff)
            ->get(route('messages.preview', $message))
            ->assertOk();

        $message->refresh();
        $this->assertSame(1, $message->recipient_count);
        $this->assertSame(1, $message->opted_out_count);
    }

    public function test_confirm_queues_job_and_writes_audit(): void
    {
        Bus::fake();

        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();
        $recipient = User::factory()->create(['email' => 'queue-me@example.com']);

        $message = StaffMessage::query()->create([
            'sender_id' => $staff->id,
            'category' => StaffMessage::CATEGORY_SERVICE,
            'subject' => 'Queue me',
            'body_html' => '<p>Hello</p>',
            'recipient_filter' => [
                'mode' => 'users',
                'user_ids' => [$recipient->id],
            ],
            'status' => StaffMessage::STATUS_PREVIEW,
            'recipient_count' => 1,
        ]);

        $this->actingAs($staff)
            ->post(route('messages.confirm', $message))
            ->assertRedirect(route('messages.show', $message))
            ->assertSessionHas('status', __('staff_messages.flash.queued'));

        Bus::assertDispatched(SendStaffMessageJob::class, static fn (SendStaffMessageJob $job): bool => $job->staffMessageId === $message->id);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'staff.messages.queued',
            'user_id' => $staff->id,
            'auditable_type' => 'staff_message',
            'auditable_id' => $message->id,
        ]);
    }

    public function test_send_job_registers_staff_messages_rate_limiter(): void
    {
        $this->assertNotNull(RateLimiter::limiter('staff-messages'));

        $middleware = (new SendStaffMessageJob(1))->middleware();
        $this->assertInstanceOf(RateLimited::class, $middleware[0]);
    }

    public function test_signed_unsubscribe_get_is_confirmation_only(): void
    {
        $user = User::factory()->create(['email' => 'unsub@example.com']);
        $user->contactPreference()->updateOrCreate([], ['email' => true, 'policy_version' => 'test', 'confirmed_at' => now()]);

        $url = URL::signedRoute('mail.unsubscribe', ['user' => $user]);

        $this->get($url)
            ->assertOk()
            ->assertSee(__('staff_messages.unsubscribe.confirm_action'), false);

        $this->assertTrue($user->contactPreference()->firstOrFail()->email);
    }

    public function test_signed_unsubscribe_post_sets_preference_consent_and_audits(): void
    {
        $user = User::factory()->create(['email' => 'unsub-post@example.com']);
        $user->contactPreference()->updateOrCreate([], ['email' => true, 'policy_version' => 'test', 'confirmed_at' => now()]);

        $url = URL::signedRoute('mail.unsubscribe.store', ['user' => $user]);

        $this->post($url, ['List-Unsubscribe' => 'One-Click'])->assertOk();

        $this->assertFalse($user->contactPreference()->firstOrFail()->email);
        $this->assertDatabaseHas('contact_consent_events', [
            'user_id' => $user->id,
            'channel' => 'email',
            'granted' => 0,
            'source' => 'staff_message_list_unsubscribe',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'contact_preferences.unsubscribed',
            'auditable_type' => 'user',
            'auditable_id' => $user->id,
        ]);
    }

    public function test_staff_without_send_all_cannot_target_admin_group(): void
    {
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();

        $this->actingAs($staff)
            ->post(route('messages.store'), [
                'category' => StaffMessage::CATEGORY_SERVICE,
                'subject' => 'Admins only',
                'body_html' => '<p>Hello</p>',
                'filter_mode' => 'group',
                'filter_group' => AccountClassifier::ADMIN,
            ])
            ->assertForbidden();
    }

    public function test_optional_notification_respects_opt_out(): void
    {
        Notification::fake();

        $sender = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();
        $recipient = User::factory()->create(['email' => 'no-mail@example.com']);
        $recipient->contactPreference()->updateOrCreate([], ['email' => false, 'policy_version' => 'test', 'confirmed_at' => now()]);

        $message = StaffMessage::query()->create([
            'sender_id' => $sender->id,
            'category' => StaffMessage::CATEGORY_OPTIONAL,
            'subject' => 'Optional',
            'body_html' => '<p>Hello</p>',
            'recipient_filter' => ['mode' => 'users', 'user_ids' => [$recipient->id]],
            'status' => StaffMessage::STATUS_QUEUED,
        ]);

        $recipient->notify(new StaffMessageNotification($message, $sender));

        Notification::assertNothingSent();
    }

    public function test_mail_template_matrix_includes_staff_message(): void
    {
        $this->assertArrayHasKey('staff.staff_message', app(MailTemplateRegistry::class)->all());
    }

    public function test_send_all_grant_allows_all_filter(): void
    {
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();
        $role = Role::query()->create([
            'name' => 'staff-messages-all-flow',
            'guard_name' => 'web',
            'is_protected' => false,
        ]);
        $role->permissions()->attach(
            Permission::query()->where('name', AuthorizationProfile::PERMISSION_MESSAGES_SEND_ALL)->value('id'),
        );
        app(AuthorizationRoleMaterializer::class)->grant(
            $staff,
            $role,
            RoleSource::SOURCE_LOCAL,
            actor: $staff,
        );

        $this->actingAs($staff)
            ->post(route('messages.store'), [
                'category' => StaffMessage::CATEGORY_SERVICE,
                'subject' => 'Everyone',
                'body_html' => '<p>Hello</p>',
                'filter_mode' => 'all',
            ])
            ->assertRedirect();
    }
}
