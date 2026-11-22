<?php

namespace Tests\Feature\Push;

use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Push service worker route, manifest and registration hooks (#793).
 */
class PushServiceWorkerTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_worker_is_served_at_scope_root_with_no_cache(): void
    {
        $response = $this->get(route('push.service-worker'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/javascript; charset=UTF-8');
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-cache', $cacheControl);
        $this->assertStringContainsString('must-revalidate', $cacheControl);
        $response->assertHeader('Service-Worker-Allowed', '/');
        $response->assertSee('const SW_VERSION', false);
        $response->assertSee('resolveSameOriginNotificationUrl', false);
        $response->assertSee('pushsubscriptionchange', false);
        $response->assertDontSee('caches.open', false);
    }

    public function test_signed_in_workspace_layout_registers_the_service_worker(): void
    {
        $user = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create([
            'email_verified_at' => now(),
            'onboarding_completed_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-push-register', false)
            ->assertSee(route('push.service-worker'), false);
    }

    public function test_guest_pages_do_not_register_the_service_worker(): void
    {
        $this->get(route('public.login'))
            ->assertOk()
            ->assertDontSee('data-push-register', false);
    }
}