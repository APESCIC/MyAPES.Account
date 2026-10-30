<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PublicLegalPagesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function legalPages(): array
    {
        return [
            'privacy' => [
                '/privacy',
                'privacy',
                'Privacy notice',
                'How Association of Protecting Exotic Species CIC uses personal information',
            ],
            'cookies' => [
                '/cookies',
                'cookies',
                'Cookie notice',
                'We do not set advertising cookies',
            ],
            'help' => [
                '/help',
                'help',
                'Help',
                'Public Login is for service users',
            ],
            'terms' => [
                '/terms',
                'terms',
                'Terms of use',
                'These terms cover use of the portal',
            ],
        ];
    }

    #[DataProvider('legalPages')]
    public function test_guest_legal_pages_render_branded_content(
        string $path,
        string $page,
        string $title,
        string $snippet,
    ): void {
        $this->get($path)
            ->assertOk()
            ->assertSeeText('MyAPES Account')
            ->assertSeeText($title)
            ->assertSeeText($snippet)
            ->assertSee('data-legal-page="'.$page.'"', false)
            ->assertSee('href="'.route('privacy').'"', false)
            ->assertSee('href="'.route('cookies').'"', false)
            ->assertSee('href="'.route('help').'"', false)
            ->assertSee('href="'.route('terms').'"', false)
            ->assertDontSeeText('Page not found')
            ->assertDontSee('404 | Not Found', false);
    }

    public function test_signed_in_public_accounts_can_read_the_legal_pages(): void
    {
        $user = User::factory()->accessLevel(User::ROLE_SERVICE_USER)->create();

        foreach (array_keys(self::legalPages()) as $route) {
            $this->actingAs($user)
                ->get(route($route))
                ->assertOk()
                ->assertSee('data-legal-page="'.$route.'"', false)
                ->assertDontSeeText('Page not found');
        }
    }

    public function test_public_chrome_links_to_privacy_cookies_help_and_terms(): void
    {
        foreach (['/', '/register', '/login', '/staff/login', '/change-log'] as $path) {
            $this->get($path)
                ->assertOk()
                ->assertSee('site-footer__links', false)
                ->assertSee('href="'.route('privacy').'"', false)
                ->assertSee('href="'.route('cookies').'"', false)
                ->assertSee('href="'.route('help').'"', false)
                ->assertSee('href="'.route('terms').'"', false);
        }
    }

    public function test_register_page_links_to_terms_privacy_cookies_and_help(): void
    {
        config(['myapes.consent.privacy_notice_url' => null]);

        $register = $this->get(route('public.register'));
        $register->assertOk()
            ->assertSeeText('I have read and accept the')
            ->assertSeeText('terms of use')
            ->assertSeeText('privacy notice')
            ->assertSeeText('cookie notice')
            ->assertSee('name="registration_consent"', false)
            ->assertSee('href="'.route('terms').'"', false)
            ->assertSee('href="'.route('privacy').'"', false)
            ->assertSee('href="'.route('cookies').'"', false)
            ->assertSee('href="'.route('help').'"', false);

        $this->assertMatchesRegularExpression(
            '/<input\b[^>]*\bname="registration_consent"[^>]*\brequired\b/i',
            $register->getContent(),
        );
    }

    public function test_sidebar_help_link_reaches_the_help_page(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('href="'.route('help').'"', false)
            ->assertSeeText('Help');

        $this->get(route('help'))
            ->assertOk()
            ->assertSeeText('Which sign-in should I use?')
            ->assertSeeText('Privacy and data requests')
            ->assertSeeText('contact APES CIC through the')
            ->assertSee('href="https://www.apes.org.uk"', false)
            ->assertSee('aria-current="page"', false);
    }

    public function test_privacy_notice_gives_guests_a_usable_contact_path(): void
    {
        $this->get(route('privacy'))
            ->assertOk()
            ->assertSeeText('If you cannot sign in')
            ->assertSee('href="'.route('public.register').'"', false)
            ->assertSee('href="https://www.apes.org.uk"', false);
    }
}
