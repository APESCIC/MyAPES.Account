<?php

namespace Tests\Feature\Layout;

use App\Core\Accounts\User;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlashToastTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
    }

    public function test_a_saved_status_renders_once_as_a_polite_dismissible_toast(): void
    {
        $html = $this->actingAs(User::factory()->create())
            ->withSession(['status' => 'Profile updated.'])
            ->get(route('profile.edit'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-toast-region', $html);
        $this->assertMatchesRegularExpression('/class="sg-toast sg-toast-ok" role="status" aria-live="polite"[^>]*data-toast-tone="ok"/', $html);
        $this->assertSame(1, substr_count($html, 'Profile updated.'));
        $this->assertStringContainsString('aria-label="Dismiss message"', $html);
    }

    public function test_failed_saves_show_an_alert_toast_that_stays(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)
            ->from(route('apes-cic.tickets.index'))
            ->post(route('apes-cic.tickets.store'), [])
            ->assertRedirect(route('apes-cic.tickets.index'));

        $this->get(route('apes-cic.tickets.index'))
            ->assertOk()
            ->assertSee('class="sg-toast sg-toast-error" role="alert"', false)
            ->assertSee('data-toast-tone="error"', false)
            ->assertSeeText('Please fix these')
            ->assertSeeText('The subject field is required.');
    }

    public function test_pages_without_feedback_render_an_empty_region(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('data-toast-region', false)
            ->assertDontSee('data-toast-tone', false);
    }
}
