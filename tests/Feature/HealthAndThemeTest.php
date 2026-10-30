<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthAndThemeTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_reports_database_cache_and_development_release(): void
    {
        $this->get(route('health'))
            ->assertOk()
            ->assertExactJson([
                'status' => 'ok',
                'version' => '0.45.6',
                'release' => 'development',
                'maintenance' => false,
                'checks' => [
                    'database' => 'ok',
                    'cache' => 'ok',
                    'environment' => 'ok',
                ],
            ]);
    }

    public function test_health_endpoint_returns_sanitized_service_unavailable_response(): void
    {
        $databaseConnection = config('database.default');
        $cacheStore = config('cache.default');

        try {
            config([
                'database.default' => 'missing-health-connection',
                'cache.default' => 'missing-health-store',
            ]);

            $response = $this->get(route('health'));
        } finally {
            config([
                'database.default' => $databaseConnection,
                'cache.default' => $cacheStore,
            ]);
        }

        $response
            ->assertServiceUnavailable()
            ->assertExactJson([
                'status' => 'unavailable',
                'version' => '0.45.6',
                'release' => 'development',
                'maintenance' => false,
                'checks' => [
                    'database' => 'failed',
                    'cache' => 'failed',
                    'environment' => 'ok',
                ],
            ]);
    }

    public function test_packaged_health_fails_closed_outside_production(): void
    {
        $revisionPath = base_path('REVISION');
        $existingRevision = is_file($revisionPath)
            ? file_get_contents($revisionPath)
            : null;

        try {
            file_put_contents($revisionPath, str_repeat('a', 40));
            $this->app->detectEnvironment(
                static fn (): string => 'testing',
            );

            $this->get(route('health'))
                ->assertServiceUnavailable()
                ->assertJsonPath('status', 'unavailable')
                ->assertJsonPath('release', str_repeat('a', 40))
                ->assertJsonPath('checks.database', 'ok')
                ->assertJsonPath('checks.cache', 'ok')
                ->assertJsonPath('checks.environment', 'failed');
        } finally {
            if (is_string($existingRevision)) {
                file_put_contents($revisionPath, $existingRevision);
            } elseif (is_file($revisionPath)) {
                unlink($revisionPath);
            }
        }
    }

    public function test_light_theme_and_responsive_guest_workspace_shell_are_rendered(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertSee('data-theme="light"', false);
        $response->assertSee("localStorage.getItem('myapes-theme')", false);
        $response->assertSee('data-theme-toggle', false);
        $response->assertSee('data-workspace-rail', false);
        $response->assertSee('data-workspace-menu-open', false);
        $response->assertSee('logos/myapes-mark-40x40.png', false);
        $response->assertSee('logos/myapes-mark-80x80.png 2x', false);
        $response->assertSee('width="40"', false);
        $response->assertSee('height="40"', false);
        $response->assertSee('mascot/spike-welcome.png', false);
        $response->assertSee('welcome-heading__mascot', false);
        $response->assertDontSee('class="hero-image"', false);
        $response->assertSee('data-mascot-dock', false);
        $response->assertSee('data-mascot-dismiss', false);
        $response->assertSee('href="'.route('public.login').'"', false);
        $response->assertSee('href="'.route('public.register').'"', false);
        $response->assertSee('href="'.route('staff.login').'"', false);
        $response->assertSee('data-workspace-dock', false);
        $response->assertSeeText(__('workspace.groups.support'));
        $response->assertSee('https://github.com/APESCIC/MyAPES-Account"', false);
        $response->assertSee('site-footer__links', false);
        $response->assertSee('href="'.route('privacy').'"', false);
        $response->assertSee('href="'.route('cookies').'"', false);
        $response->assertSee('href="'.route('help').'"', false);
        $response->assertSee('href="'.route('terms').'"', false);
        $response->assertDontSee('rel="mask-icon"', false);
    }

    public function test_public_entry_points_and_metadata_use_the_complete_pet_care_clinic_name(): void
    {
        foreach (['/'] as $path) {
            $content = $this->get($path)
                ->assertOk()
                ->assertSeeText('APES Pet Care Clinic')
                ->getContent();

            $this->assertStringNotContainsString('>APES Pet Care<', $content);
            $this->assertStringNotContainsString('and APES Pet Care.</p>', $content);
        }

        $this->view('auth.login')
            ->assertSeeText('APES Pet Care Clinic')
            ->assertDontSee('>APES Pet Care<', false)
            ->assertDontSee('and APES Pet Care.</p>', false);

        $this->get('/')
            ->assertSee(
                'content="'.e(__('seo.home.description')).'"',
                false,
            )
            ->assertSeeText('APES Pet Care Clinic');

    }

    public function test_authenticated_workspace_rail_is_role_aware(): void
    {
        $serviceUser = User::factory()->accessLevel(User::ROLE_SERVICE_USER)->create();

        $this->actingAs($serviceUser)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('aria-current="page"', false)
            ->assertSee('href="'.route('profile.edit').'"', false)
            ->assertSee('href="'.route('apes-cic.tickets.index').'"', false)
            ->assertSee('href="'.route('shelter.pets.index').'"', false)
            ->assertSee('href="'.route('petcare.pets.index').'"', false)
            ->assertSee('data-workspace-dock', false)
            ->assertSeeText(__('workspace.groups.support'))
            ->assertDontSee('href="'.route('admin.index').'"', false);

        $admin = User::factory()->accessLevel(User::ROLE_ADMIN)->create();

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('href="'.route('admin.index').'"', false);
    }

    public function test_generated_brand_assets_have_expected_dimensions(): void
    {
        $this->assertSame([1254, 1254], array_slice(getimagesize(resource_path('branding/source/apes-logo-v3.png')), 0, 2));
        $this->assertSame([1024, 1024], array_slice(getimagesize(public_path('branding/logo-myapes-account.png')), 0, 2));
        $this->assertSame([40, 40], array_slice(getimagesize(public_path('logos/myapes-mark-40x40.png')), 0, 2));
        $this->assertSame([80, 80], array_slice(getimagesize(public_path('logos/myapes-mark-80x80.png')), 0, 2));
        $this->assertSame([120, 120], array_slice(getimagesize(public_path('logos/myapes-mark-120x120.png')), 0, 2));
        $this->assertSame([192, 192], array_slice(getimagesize(public_path('icons/pwa-maskable-192x192.png')), 0, 2));
        $this->assertSame([512, 512], array_slice(getimagesize(public_path('icons/pwa-maskable-512x512.png')), 0, 2));
        $this->assertSame([1200, 630], array_slice(getimagesize(public_path('social/og-image-1200x630.jpg')), 0, 2));
    }
}
