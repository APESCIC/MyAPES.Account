<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugins\Recruitment\Models\RecruitmentRole;
use Tests\TestCase;

class PublicSeoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function publicIndexablePages(): array
    {
        return [
            'home' => ['/', 'Welcome | MyAPES Account', 'seo.home.description'],
            'privacy' => ['/privacy', 'Privacy notice | MyAPES Account', 'seo.privacy.description'],
            'cookies' => ['/cookies', 'Cookie notice | MyAPES Account', 'seo.cookies.description'],
            'help' => ['/help', 'Help | MyAPES Account', 'seo.help.description'],
            'terms' => ['/terms', 'Terms of use | MyAPES Account', 'seo.terms.description'],
            'change log' => ['/change-log', 'Change Log Hub | MyAPES Account', 'seo.change_log.description'],
            'public login' => ['/login', 'Public Login | MyAPES Account', 'seo.public_login.description'],
            'register' => ['/register', 'Register | MyAPES Account', 'seo.register.description'],
            'staff login' => ['/staff/login', 'Staff Login | MyAPES Account', 'seo.staff_login.description'],
            'open roles' => ['/recruitment', 'Open roles | MyAPES Account', 'seo.recruitment.index.description'],
        ];
    }

    #[DataProvider('publicIndexablePages')]
    public function test_public_pages_have_unique_translated_seo_meta(string $path, string $title, string $descriptionKey): void
    {
        $response = $this->get($path);

        $response->assertOk();
        $response->assertSee('<title>'.$title.'</title>', false);
        $response->assertSee('name="description" content="'.e(__($descriptionKey)).'"', false);
        $response->assertSee('rel="canonical"', false);
        $response->assertSee('property="og:title" content="'.$title.'"', false);
        $response->assertSee('property="og:site_name" content="MyAPES Account"', false);
        $response->assertSee('property="og:locale" content="en_GB"', false);
        $response->assertSee('name="robots" content="index, follow"', false);
        $response->assertSee('lang="en-GB"', false);
    }

    public function test_open_role_detail_uses_dynamic_seo_title_and_truncated_description(): void
    {
        $role = RecruitmentRole::factory()->open()->create([
            'title' => 'Volunteer garden helper',
            'summary' => str_repeat('Garden care for rescued animals. ', 20),
        ]);

        $response = $this->get(route('recruitment.show', $role));

        $response->assertOk();
        $response->assertSee('<title>Volunteer garden helper | Open roles | MyAPES Account</title>', false);
        $response->assertSee('name="robots" content="index, follow"', false);
        $html = $response->getContent();
        $this->assertMatchesRegularExpression(
            '/name="description" content="[^"]{1,155}"/',
            $html,
        );
        preg_match('/name="description" content="([^"]*)"/', $html, $matches);
        $description = html_entity_decode($matches[1] ?? '', ENT_QUOTES);
        $this->assertLessThanOrEqual(155, mb_strlen($description));
        $this->assertStringStartsWith('Garden care for rescued animals.', $description);
    }

    public function test_signed_in_and_admin_pages_send_noindex(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->accessLevel(User::ROLE_ADMIN)->create();
        $superAdmin = User::factory()->accessLevel(User::ROLE_ADMIN)->create();
        $homeCanonical = 'rel="canonical" href="'.e(url('/')).'"';
        $homeOgUrl = 'property="og:url" content="'.e(url('/')).'"';

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('name="robots" content="noindex, nofollow"', false)
            ->assertSee($homeCanonical, false)
            ->assertSee($homeOgUrl, false);

        $this->actingAs($admin)
            ->get(route('admin.index'))
            ->assertOk()
            ->assertSee('name="robots" content="noindex, nofollow"', false)
            ->assertSee($homeCanonical, false)
            ->assertSee($homeOgUrl, false);

        $this->actingAs($superAdmin)
            ->get(route('admin.modules.index'))
            ->assertOk()
            ->assertSee('name="robots" content="noindex, nofollow"', false)
            ->assertSee($homeCanonical, false)
            ->assertSee($homeOgUrl, false);
    }

    public function test_password_reset_form_is_noindex_for_guests(): void
    {
        $this->get('/forgot-password')
            ->assertOk()
            ->assertSee('name="robots" content="noindex, nofollow"', false)
            ->assertSee('rel="canonical" href="'.e(url('/')).'"', false)
            ->assertSee('property="og:url" content="'.e(url('/')).'"', false);
    }

    public function test_robots_txt_disallows_staff_paths_and_links_sitemap(): void
    {
        $response = $this->get('/robots.txt');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $body = $response->getContent();
        $this->assertStringContainsString('Disallow: /admin', $body);
        $this->assertStringContainsString('Disallow: /apes-cic', $body);
        $this->assertStringContainsString('Disallow: /recruitment/applications', $body);
        $this->assertStringContainsString('Disallow: /profile', $body);
        $this->assertStringContainsString('Disallow: /dashboard', $body);
        $this->assertStringContainsString('Sitemap: '.rtrim((string) config('app.url'), '/').'/sitemap.xml', $body);
    }

    public function test_sitemap_lists_public_pages_and_open_roles_only(): void
    {
        $open = RecruitmentRole::factory()->open()->create(['title' => 'Open kennel assistant']);
        $draft = RecruitmentRole::factory()->draft()->create(['title' => 'Draft only role']);
        $closed = RecruitmentRole::factory()->closed()->create(['title' => 'Closed role']);

        $response = $this->get('/sitemap.xml');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $body = $response->getContent();

        $this->assertStringContainsString(route('home'), $body);
        $this->assertStringContainsString(route('privacy'), $body);
        $this->assertStringContainsString(route('recruitment.index'), $body);
        $this->assertStringContainsString(route('recruitment.show', $open), $body);
        $this->assertStringNotContainsString(route('recruitment.show', $draft), $body);
        $this->assertStringNotContainsString(route('recruitment.show', $closed), $body);
        $this->assertStringNotContainsString('/admin', $body);
        $this->assertStringNotContainsString('/apes-cic', $body);
        $this->assertStringNotContainsString('/dashboard', $body);
    }
}
