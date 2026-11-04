<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use App\Support\Navigation\NavItem;
use App\Support\Navigation\NavPanel;
use App\Support\Navigation\WorkspaceNavigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Public Microsoft Bookings pages (#488).
 */
class BookingsPagesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function pages(): array
    {
        return [
            'APES Bookings' => ['apes', 'APES Bookings', 'https://bookings.cloud.microsoft/book/APESBookings@apes.org.uk/'],
            'Shelter and Rescue' => ['shelter-and-rescue', 'APES Shelter and Rescue bookings', 'https://bookings.cloud.microsoft/book/APESShelterRescueBookings@apes.org.uk/'],
            'Pet Care Clinic' => ['pet-care-clinic', 'APES Pet Care Clinic bookings', 'https://bookings.cloud.microsoft/book/APESPetCareClinicBookings@apes.org.uk/'],
        ];
    }

    #[DataProvider('pages')]
    public function test_each_page_embeds_bookings_with_a_new_tab_fallback(string $slug, string $title, string $url): void
    {
        $response = $this->get(route('bookings.show', $slug))->assertOk();

        $response
            ->assertSeeText($title)
            ->assertSee('<iframe', false)
            ->assertSee('src="'.$url.'"', false)
            ->assertSee('title="'.$title.' booking form"', false)
            ->assertSee('href="'.$url.'" target="_blank" rel="noopener noreferrer" data-bookings-fallback', false)
            ->assertSeeText('Schedule online');

        $policy = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('frame-src', $policy);
        $this->assertStringContainsString(parse_url($url, PHP_URL_SCHEME).'://'.parse_url($url, PHP_URL_HOST), $policy);
    }

    public function test_the_index_links_to_all_three_pages_for_guests(): void
    {
        $response = $this->get(route('bookings.index'))->assertOk();

        foreach (self::pages() as [$slug, $title]) {
            $response->assertSee('href="'.route('bookings.show', $slug).'"', false)->assertSeeText($title);
        }
    }

    public function test_unknown_pages_are_not_found(): void
    {
        $this->get('/bookings/not-a-service')->assertNotFound();
    }

    public function test_bookings_are_in_the_public_and_signed_in_menus(): void
    {
        $guestMenu = $this->menuFor(null);
        $this->assertContains('bookings', $this->itemKeys($guestMenu->panels[NavPanel::PUBLIC]));

        $user = User::factory()->protectedRole('service-user')->create();
        $this->assertContains('bookings', $this->itemKeys($this->menuFor($user)->panels[NavPanel::STAFF]));

        $this->actingAs($user)->get(route('bookings.show', 'apes'))->assertOk();
    }

    private function menuFor(?User $user): \App\Support\Navigation\WorkspaceMenu
    {
        $request = Request::create(route('bookings.index'));
        $request->setLaravelSession(app('session.store'));
        $route = app('router')->getRoutes()->match($request);
        $route->bind($request);
        $request->setRouteResolver(static fn () => $route);
        $this->app->instance('request', $request);
        if ($user !== null) {
            $this->actingAs($user);
        }

        return app(WorkspaceNavigation::class)->forRequest($user, $request);
    }

    /**
     * @return list<string>
     */
    private function itemKeys(NavPanel $panel): array
    {
        return array_map(static fn (NavItem $item): string => $item->key, $panel->items());
    }
}
