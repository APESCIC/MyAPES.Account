<?php

namespace Tests\Feature\Theme;

use App\Core\Accounts\User;
use App\Core\Mail\MailTemplateRegistry;
use App\Notifications\Auth\VerifyEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fixed Glassy brand email shell and themed public pages (#905).
 */
class EmailsAndPublicPagesThemeTest extends TestCase
{
    use RefreshDatabase;

    private const JUNGLE_LEAF_GREEN = '#1f6b3c';

    private const GLASSY_TEAL = '#107481';

    public function test_branded_mail_preview_uses_glassy_teal_not_jungle_palette(): void
    {
        $user = User::factory()->create([
            'name' => 'Theme Mail',
            'email' => 'theme.mail@example.com',
        ]);

        $html = strtolower((string) app(MailTemplateRegistry::class)
            ->find('auth.verify_email')
            ->sampleNotification($user)
            ->toMail($user)
            ->render());

        $this->assertStringContainsString(self::GLASSY_TEAL, $html);
        $this->assertStringNotContainsString(self::JUNGLE_LEAF_GREEN, $html);
        $this->assertStringContainsString('data-skin="glassy"', $html);
        $this->assertMatchesRegularExpression(
            '/alt="[^"]*'.preg_quote(__('terms.app_name'), '/').'"/i',
            $html,
        );
    }

    public function test_auth_verify_notification_mail_uses_glassy_shell(): void
    {
        $user = User::factory()->create([
            'name' => 'Verify User',
            'email' => 'verify.user@example.com',
        ]);

        $html = strtolower((string) (new VerifyEmailNotification)->toMail($user)->render());

        $this->assertStringContainsString(self::GLASSY_TEAL, $html);
        $this->assertStringContainsString('data-skin="glassy"', $html);
    }

    public function test_guest_home_renders_footer_theme_switcher(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('data-theme-switcher', false)
            ->assertSee('theme-switcher--footer', false);
    }

    public function test_public_pages_use_workspace_layout_and_theme_switcher(): void
    {
        foreach ([
            route('home'),
            route('public.login'),
            route('bookings.index'),
            route('tickets.public.index'),
        ] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('data-skin="glassy"', false)
                ->assertSee('data-theme-switcher', false)
                ->assertSee('appearanceKey = \'myapes-appearance\'', false);
        }
    }

    public function test_public_pages_render_jungle_when_visitor_preference_is_saved(): void
    {
        $user = User::factory()->create([
            'theme_skin' => 'jungle',
            'theme_mode' => 'light',
            'theme_calm' => false,
        ]);

        $this->actingAs($user)
            ->get(route('tickets.public.index'))
            ->assertOk()
            ->assertSee('data-skin="jungle"', false)
            ->assertSee('data-appearance-source="server"', false);
    }

    public function test_error_pages_use_themed_app_layout(): void
    {
        $this->get('/__no-such-page')
            ->assertNotFound()
            ->assertSee('data-skin="glassy"', false)
            ->assertSee('data-theme-switcher', false);

        $html = view('errors.maintenance', [
            'message' => 'Planned maintenance.',
            'plannedEndAt' => null,
        ])->render();

        $this->assertStringContainsString('data-skin="glassy"', $html);
        $this->assertStringContainsString('data-theme-switcher', $html);
    }
}