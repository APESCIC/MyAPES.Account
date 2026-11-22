<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PublicCloudronContentTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_and_public_routes_do_not_mention_cloudron_except_staff_login(): void
    {
        $paths = [
            '/',
            '/login',
            '/help',
            '/privacy',
            '/terms',
            '/cookies',
            '/forgot-password',
        ];

        if (Route::has('public.register')) {
            $paths[] = '/register';
        }

        foreach ($paths as $path) {
            $html = $this->get($path)->assertOk()->getContent();
            $this->assertDoesNotMatchRegularExpression(
                '/cloudron/i',
                $html,
                "Unexpected Cloudron text on {$path}",
            );
        }

        $staffLogin = $this->get(route('staff.login'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/cloudron/i', $staffLogin);

        $public = User::factory()->accessLevel(User::ROLE_SERVICE_USER)->create();
        $dashboard = $this->actingAs($public)->get('/dashboard')->assertOk()->getContent();
        $this->assertDoesNotMatchRegularExpression('/cloudron/i', $dashboard);
    }
}
