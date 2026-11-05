<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use App\Support\Tour\GuidedTour;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Spike's guided tour (#489).
 */
class GuidedTourTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function audiences(): array
    {
        return [
            'public account' => ['service-user', GuidedTour::AUDIENCE_PUBLIC, 'bookings'],
            'staff' => ['staff', GuidedTour::AUDIENCE_STAFF, 'callers'],
            'volunteer' => ['volunteer', GuidedTour::AUDIENCE_STAFF, 'callers'],
            'administrator' => ['administrator', GuidedTour::AUDIENCE_ADMIN, 'data-workspace-panel-switch'],
        ];
    }

    #[DataProvider('audiences')]
    public function test_the_tour_starts_on_first_login_for_each_audience(string $role, string $audience, string $distinctTarget): void
    {
        $user = User::factory()->protectedRole($role)->create();

        $config = $this->tourConfig($this->actingAs($user)->get(route('dashboard'))->assertOk());

        $this->assertSame($audience, $config['audience']);
        $this->assertTrue($config['autoStart']);
        $this->assertSame(route('tour.complete', $audience), $config['completeUrl']);
        $this->assertNotEmpty($config['csrfToken']);
        $this->assertStringEndsWith('mascot/spike-tip.png', $config['image']);
        $this->assertSame('Hello, I am Spike', $config['steps'][0]['title']);
        $this->assertStringContainsString($distinctTarget, json_encode(array_column($config['steps'], 'targets')));

        foreach ($config['steps'] as $step) {
            $this->assertNotSame([], $step['targets']);
            $this->assertNotSame('', $step['body']);
        }
    }

    public function test_finishing_or_skipping_saves_state_and_stops_the_auto_start(): void
    {
        $user = User::factory()->protectedRole('staff')->create();

        $this->actingAs($user)
            ->postJson(route('tour.complete', GuidedTour::AUDIENCE_STAFF), ['skipped' => true])
            ->assertNoContent();

        $saved = $user->fresh()->guided_tours[GuidedTour::AUDIENCE_STAFF];
        $this->assertTrue($saved['skipped']);
        $this->assertNotEmpty($saved['at']);

        $this->assertFalse($this->tourConfig($this->actingAs($user)->get(route('dashboard')))['autoStart']);
    }

    public function test_the_tour_can_be_restarted_from_help_and_the_palette(): void
    {
        $user = User::factory()->protectedRole('staff')->create();
        $user->forceFill(['guided_tours' => [GuidedTour::AUDIENCE_STAFF => ['at' => now()->toIso8601String(), 'skipped' => false]]])->save();
        $restartUrl = route('dashboard', [GuidedTour::RESTART_QUERY => 1]);

        $this->assertTrue($this->tourConfig($this->actingAs($user)->get($restartUrl))['autoStart']);

        $this->actingAs($user)->get(route('help'))->assertOk()->assertSee('href="'.e($restartUrl).'"', false);
        $this->actingAs($user)->get(route('help.staff'))->assertOk()->assertSee('href="'.e($restartUrl).'"', false);

        $palette = $this->actingAs($user)->get(route('dashboard'))->getContent();
        $this->assertStringContainsString('"id":"action:tour.restart"', (string) $palette);
    }

    public function test_users_cannot_save_another_audience_and_guests_are_sent_to_sign_in(): void
    {
        $user = User::factory()->protectedRole('service-user')->create();

        $this->actingAs($user)->postJson(route('tour.complete', GuidedTour::AUDIENCE_ADMIN))->assertNotFound();
        $this->assertNull($user->fresh()->guided_tours);

        $this->post('/tour/nobody/complete')->assertNotFound();
        auth()->logout();
        $this->post(route('tour.complete', GuidedTour::AUDIENCE_PUBLIC))->assertRedirect(route('public.login'));
    }

    public function test_the_tour_only_renders_on_the_dashboard(): void
    {
        $user = User::factory()->protectedRole('staff')->create();

        $this->actingAs($user)->get(route('help.staff'))->assertOk()->assertDontSee('data-guided-tour', false);
        $this->get(route('help'))->assertOk()->assertDontSee('data-guided-tour', false);
    }

    /**
     * @return array<string, mixed>
     */
    private function tourConfig(TestResponse $response): array
    {
        $this->assertSame(1, preg_match('#<script type="application/json" data-guided-tour>(.+?)</script>#s', (string) $response->getContent(), $match));

        return json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
    }
}
