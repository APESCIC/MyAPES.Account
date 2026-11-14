<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use App\Core\Settings\AppSettings;
use App\Core\Settings\GeneralSettings;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public registration switch and the soft banner from Admin → Settings → General (#739).
 */
class PublicRegistrationGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
    }

    private function general(array $values): void
    {
        app(AppSettings::class)->set(GeneralSettings::GROUP, $values);
    }

    public function test_guests_can_register_while_registration_is_open(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('href="'.route('public.register').'"', false);

        $this->get(route('public.register'))->assertOk();
        $this->get(route('public.login'))->assertSee(__('auth.common.create_account'));
        $this->get('/sitemap.xml')->assertSee(route('public.register'), false);
    }

    public function test_closing_registration_shuts_the_page_and_form_and_hides_every_link(): void
    {
        $this->general(['registration_enabled' => false]);

        $this->get(route('public.register'))
            ->assertRedirect(route('public.login'))
            ->assertSessionHas('status', __('auth.common.registration_closed'));

        $this->post(route('public.register.submit'), [
            'name' => 'Public Person',
            'username' => 'public.person',
            'email' => 'public@example.com',
            'password' => 'Correct-horse-42',
            'password_confirmation' => 'Correct-horse-42',
            'services' => ['apes-cic'],
            'registration_consent' => '1',
        ])->assertRedirect(route('public.login'));
        $this->assertDatabaseMissing('users', ['email' => 'public@example.com']);

        $register = 'href="'.route('public.register').'"';
        foreach ([route('home'), route('public.login'), route('help'), route('privacy'), route('tickets.public.index')] as $url) {
            $this->get($url)->assertOk()->assertDontSee($register, false);
        }
        $this->get(route('help'))->assertSee(__('auth.common.registration_closed_help'));
        $this->get(route('home'))
            ->assertSee(__('auth.landing.open_ticket_blurb_closed'))
            ->assertDontSee(__('auth.landing.open_ticket_blurb'));
        $this->get(route('tickets.public.index'))
            ->assertDontSee('via=register', false)
            ->assertSee(__('tickets::public.intro_closed'));
        $this->get('/sitemap.xml')->assertDontSee(route('public.register'), false);
    }

    public function test_the_register_path_from_open_a_ticket_lands_on_public_login_when_closed(): void
    {
        $this->general(['registration_enabled' => false]);

        $this->followingRedirects()
            ->get(route('tickets.public.start', ['service' => 'apes-cic', 'area' => 'it_systems', 'via' => 'register']))
            ->assertOk()
            ->assertSee(__('auth.common.registration_closed'));

        $this->assertSame(route('apes-cic.tickets.index', ['service_area' => 'it_systems']).'#create', session('url.intended'));
    }

    public function test_staff_sign_in_and_public_sign_in_are_unaffected(): void
    {
        $this->general(['registration_enabled' => false]);

        $this->get(route('staff.login'))->assertOk();
        $this->get(route('public.login'))->assertOk();

        $user = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create(['password' => 'correct-horse-battery']);
        $this->post(route('public.login.submit'), ['login' => $user->email, 'password' => 'correct-horse-battery']);
        $this->assertAuthenticatedAs($user);
    }

    public function test_an_administrator_can_switch_registration_back_on(): void
    {
        $this->general(['registration_enabled' => false]);
        $admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();

        $this->actingAs($admin)->get(route('admin.settings.show', 'general'))
            ->assertOk()
            ->assertDontSee('name="registration_enabled" value="1" checked', false);

        $this->actingAs($admin)->put(route('admin.settings.general.update'), [
            'registration_enabled' => '1',
            'banner_enabled' => '0',
        ])->assertRedirect(route('admin.settings.show', 'general'));

        $this->assertTrue(app(GeneralSettings::class)->registrationOpen());

        auth()->logout();
        $this->get(route('public.register'))->assertOk();
        $this->get('/sitemap.xml')->assertSee(route('public.register'), false);
    }

    public function test_the_banner_shows_on_public_pages_as_plain_text_only_when_switched_on(): void
    {
        $this->get(route('home'))->assertDontSee('data-site-banner', false);

        $this->general(['banner_enabled' => false, 'banner_text' => 'Hidden while off.']);
        $this->get(route('home'))->assertDontSee('Hidden while off.');

        $this->general(['banner_enabled' => true, 'banner_text' => '<b>Planned</b> downtime on Saturday.']);
        $this->get(route('home'))
            ->assertSee('data-site-banner', false)
            ->assertSee('&lt;b&gt;Planned&lt;/b&gt; downtime on Saturday.', false)
            ->assertDontSee('<b>Planned</b>', false);
        $this->get(route('public.login'))->assertSee('data-site-banner', false);

        $this->general(['banner_enabled' => true, 'banner_text' => '']);
        $this->get(route('home'))->assertDontSee('data-site-banner', false);
    }

    public function test_the_banner_is_not_shown_on_admin_pages(): void
    {
        $this->general(['banner_enabled' => true, 'banner_text' => 'Planned downtime on Saturday.']);
        $admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();

        $this->actingAs($admin)->get(route('admin.settings.show', 'general'))
            ->assertOk()
            ->assertDontSee('data-site-banner', false);
    }
}
