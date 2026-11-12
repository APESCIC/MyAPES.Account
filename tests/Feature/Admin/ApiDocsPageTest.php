<?php

namespace Tests\Feature\Admin;

use App\Core\Accounts\User;
use App\Core\Webhooks\WebhookEvents;
use App\Services\AuthorizationProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admin → Settings → API docs (#643): admins only.
 */
class ApiDocsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrators_see_every_endpoint_event_and_the_signature_guide(): void
    {
        $admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();

        $page = $this->actingAs($admin)->get(route('admin.settings.show', 'api-docs'))
            ->assertOk()
            ->assertSee('data-api-docs-endpoints', false)
            ->assertSee('/api/v1/tickets/{ref}')
            ->assertSee('cases:read')
            ->assertSee('data-api-docs-verifier', false)
            ->assertSee('docs/api/openapi-v1.yaml');

        foreach (WebhookEvents::all() as $event) {
            $page->assertSee($event);
        }
    }

    public function test_other_people_are_refused(): void
    {
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();

        $this->actingAs($staff)->get(route('admin.settings.show', 'api-docs'))->assertForbidden();
    }
}
