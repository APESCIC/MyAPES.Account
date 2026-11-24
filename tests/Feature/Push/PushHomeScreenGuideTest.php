<?php

namespace Tests\Feature\Push;

use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * iPhone and iPad Add to Home Screen guide (#802).
 */
class PushHomeScreenGuideTest extends TestCase
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

    public function test_profile_renders_home_screen_guide_with_accessible_steps(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('account.privacy'))
            ->assertOk()
            ->assertSee('push-home-screen-guide', false)
            ->assertSee(__('push.home_screen.heading'), false)
            ->assertSee(__('push.home_screen.version_requirement'), false)
            ->assertSee('data-push-state="home_screen"', false)
            ->assertSee('data-push-actions', false);

        $steps = __('push.home_screen.steps');

        foreach ($steps as $step) {
            $response
                ->assertSee($step['title'], false)
                ->assertSee('alt="'.$step['image_alt'].'"', false);
        }
    }

    public function test_home_screen_step_images_exist_and_manifest_matches_guide_footnote(): void
    {
        foreach (__('push.home_screen.steps') as $step) {
            $path = public_path($step['image']);
            $this->assertFileExists($path, $step['image']);
            $this->assertNotSame('', trim($step['image_alt']));
        }

        $manifest = json_decode(
            (string) file_get_contents(public_path('site.webmanifest')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame('MyAPES Account', $manifest['name']);
        $this->assertSame('/', $manifest['start_url']);

        foreach ($manifest['icons'] as $icon) {
            $this->assertFileExists(public_path(ltrim((string) $icon['src'], '/')));
        }

        $this->assertStringContainsString('MyAPES Account', __('push.home_screen.footnote'));
    }
}