<?php

namespace Tests\Feature\Push;

use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Soft prompt and profile device notification controls (#794).
 */
class PushSoftPromptTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app-settings.env' => array_fill_keys(array_keys((array) config('app-settings.env')), null),
            'app-settings.env.VAPID_PUBLIC_KEY' => VapidTestKeys::PUBLIC,
            'app-settings.env.VAPID_PRIVATE_KEY' => VapidTestKeys::PRIVATE,
            'app-settings.env.VAPID_SUBJECT' => VapidTestKeys::SUBJECT,
        ]);

        $this->user = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create([
            'email_verified_at' => now(),
            'onboarding_completed_at' => now(),
        ]);
    }

    public function test_dashboard_includes_the_soft_prompt_markup(): void
    {
        $this->actingAs($this->user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-push-soft-prompt', false)
            ->assertSee(__('push.soft.not_now'), false)
            ->assertSee(__('push.device.turn_on'), false);
    }

    public function test_profile_includes_the_device_control_without_dismiss(): void
    {
        $this->actingAs($this->user)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('id="notifications"', false)
            ->assertSee(__('push.device.heading'), false)
            ->assertSee(__('push.device.turn_on'), false)
            ->assertDontSee(__('push.soft.not_now'), false);
    }

    public function test_blocked_copy_lists_unblock_steps_for_each_browser(): void
    {
        $this->actingAs($this->user)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee(__('push.device.unblock_chrome_label'), false)
            ->assertSee(__('push.device.unblock_edge_label'), false)
            ->assertSee(__('push.device.unblock_firefox_label'), false)
            ->assertSee(__('push.device.unblock_safari_label'), false);
    }

    public function test_signed_in_body_includes_logout_unsubscribe_hook(): void
    {
        $this->actingAs($this->user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-push-destroy-url="'.route('push.subscriptions.destroy').'"', false);
    }
}