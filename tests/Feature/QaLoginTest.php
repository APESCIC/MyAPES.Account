<?php

namespace Tests\Feature;

use App\Core\Accounts\AuditLog;
use App\Core\Accounts\User;
use App\Core\Qa\QaLogin;
use App\Services\AuthorizationProfile;
use App\Services\SessionAuthorizationContext;
use App\Support\Navigation\WorkspaceNavigation;
use Database\Seeders\LocalQaSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class QaLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    private function enableQa(string $environment = 'local', bool $flag = true): void
    {
        config(['myapes.qa_login_enabled' => $flag]);
        app()->detectEnvironment(static fn (): string => $environment);
    }

    public function test_is_not_found_when_the_flag_is_off(): void
    {
        $this->enableQa(flag: false);

        $this->get(route('qa.index'))->assertNotFound();
        $this->post(route('qa.login'), ['profile' => 'admin'])->assertNotFound();
        $this->post(route('qa.logout'))->assertNotFound();
        $this->assertGuest();
    }

    #[DataProvider('nonLocalEnvironments')]
    public function test_cannot_be_enabled_outside_local_even_with_the_flag_on(string $environment): void
    {
        $this->enableQa($environment);

        $this->assertFalse(QaLogin::enabled());
        $this->get(route('qa.index'))->assertNotFound();
        $this->post(route('qa.login'), ['profile' => 'admin'])->assertNotFound();
        $this->post(route('qa.logout'))->assertNotFound();
        $this->assertGuest();
    }

    public static function nonLocalEnvironments(): array
    {
        return [
            'production' => ['production'],
            'staging' => ['staging'],
            'testing' => ['testing'],
        ];
    }

    public function test_shows_the_developer_sign_in_panel_on_login_pages_when_enabled_on_local(): void
    {
        $this->enableQa();

        foreach ([route('public.login'), route('staff.login')] as $url) {
            $this->get($url)->assertOk()
                ->assertSee('id="developer-signin"', false)
                ->assertSee('Developer sign-in')
                ->assertSee('Local only')
                ->assertSee('value="admin"', false)
                ->assertSee('value="staff-hr"', false);
        }
    }

    public function test_leaves_the_panel_off_when_the_flag_is_off_or_outside_local(): void
    {
        foreach ([['local', false], ['production', true], ['staging', true], ['testing', true]] as [$environment, $flag]) {
            $this->enableQa($environment, $flag);

            $this->get(route('public.login'))->assertOk()
                ->assertDontSee('developer-signin')
                ->assertDontSee('Local only');
        }
    }

    public function test_opening_login_no_longer_signs_anyone_in(): void
    {
        $this->seed(LocalQaSeeder::class);
        $this->enableQa();

        $this->get(route('public.login'))->assertOk();
        $this->assertGuest();
    }

    public function test_keeps_qa_as_a_fallback_with_the_same_panel(): void
    {
        $this->enableQa();

        $this->get(route('qa.index'))->assertOk()
            ->assertSee('id="developer-signin"', false)
            ->assertSee('Local only');
    }

    public function test_signs_in_from_the_panel_and_returns_to_the_intended_page(): void
    {
        $this->enableQa();
        $this->seed(LocalQaSeeder::class);

        $this->get(route('admin.settings.index'))->assertRedirect(route('public.login'));
        $this->post(route('qa.login'), ['profile' => 'admin'])
            ->assertRedirect(route('admin.settings.index'));
        $this->assertAuthenticated();
        $this->assertSame(LocalQaSeeder::ADMIN_EMAIL, auth()->user()?->email);
    }

    public function test_each_account_type_signs_in_as_exactly_one_fake_account(): void
    {
        $this->enableQa();
        $this->seed(LocalQaSeeder::class);

        foreach (['service_user', 'student', 'volunteer', 'staff', 'admin'] as $profile) {
            $this->post(route('qa.login'), ['profile' => $profile])->assertRedirect(route('dashboard'));
            $this->assertSame(QaLogin::emailFor($profile), auth()->user()?->email);
            $this->post(route('qa.logout'));
        }
    }

    public function test_common_stacks_sign_in_with_expected_job_roles(): void
    {
        $this->enableQa();
        $this->seed(LocalQaSeeder::class);

        $this->post(route('qa.login'), ['profile' => 'staff-hr'])->assertRedirect(route('dashboard'));
        $this->assertSame(LocalQaSeeder::HR_EMAIL, auth()->user()?->email);
        $this->assertTrue(auth()->user()->hasRole('human-resources'));
    }

    public function test_reuses_the_same_fake_account_each_time(): void
    {
        $this->enableQa();
        $this->seed(LocalQaSeeder::class);

        $this->post(route('qa.login'), ['profile' => 'staff']);
        $this->post(route('qa.logout'));
        $this->post(route('qa.login'), ['profile' => 'staff']);

        $this->assertSame(1, User::query()->where('email', LocalQaSeeder::STAFF_EMAIL)->count());
    }

    public function test_rejects_an_unknown_profile(): void
    {
        $this->enableQa();

        $this->post(route('qa.login'), ['profile' => 'superuser'])->assertSessionHasErrors('profile');
        $this->assertGuest();
    }

    public function test_switch_role_route_no_longer_exists(): void
    {
        $this->assertFalse(
            collect(app('router')->getRoutes())->contains(
                static fn ($route): bool => $route->getName() === 'qa.switch-role',
            ),
        );
    }

    public function test_qa_routes_are_only_registered_for_local_and_testing(): void
    {
        $source = file_get_contents(base_path('routes/web.php'));

        $this->assertMatchesRegularExpression(
            "/environment\(\['local',\s*'testing'\]\).{0,400}qa\.index/s",
            $source,
        );
        $this->assertStringNotContainsString('qa.switch-role', $source);
    }

    public function test_shows_the_amber_qa_pill_to_a_qa_account(): void
    {
        $this->enableQa();
        $this->seed(LocalQaSeeder::class);
        $this->post(route('qa.login'), ['profile' => 'staff-hr']);

        $this->get(route('dashboard'))->assertOk()
            ->assertSee('data-qa-pill', false)
            ->assertSee('QA: Staff + HR')
            ->assertSee('Sign out of QA')
            ->assertDontSee('action="'.route('auth.logout').'"', false);
    }

    public function test_ordinary_accounts_see_normal_sign_out_and_no_pill(): void
    {
        $this->enableQa();
        $user = User::factory()->create();
        $user->setAccessLevel(User::ROLE_STAFF)->save();

        $this->actingAs($user)->get(route('dashboard'))->assertOk()
            ->assertSee('action="'.route('auth.logout').'"', false)
            ->assertDontSee('data-qa-pill', false);
    }

    public function test_hides_the_pill_when_the_gate_is_off(): void
    {
        $this->seed(LocalQaSeeder::class);
        $user = User::query()->where('email', LocalQaSeeder::ADMIN_EMAIL)->firstOrFail();

        config(['myapes.qa_login_enabled' => false]);
        $this->actingAs($user)->get(route('dashboard'))->assertOk()
            ->assertDontSee('data-qa-pill', false);

        foreach ([['local', false], ['production', true], ['staging', true], ['testing', true]] as [$environment, $flag]) {
            $this->enableQa($environment, $flag);
            $this->assertFalse(QaLogin::enabled());
            $menu = app(WorkspaceNavigation::class)->forRequest($user, Request::create('/dashboard', 'GET'));
            $this->assertNull($menu->user?->qaProfile);
        }
    }

    public function test_stays_on_the_page_after_a_switch_when_the_new_role_may_open_it(): void
    {
        $this->enableQa();
        $this->seed(LocalQaSeeder::class);
        $this->post(route('qa.login'), ['profile' => 'staff']);

        $this->post(route('qa.login'), [
            'profile' => 'admin',
            'return_to' => '/admin/settings',
        ])->assertRedirect('/admin/settings');
        $this->get('/admin/settings')->assertRedirect();
        $this->followRedirects($this->get('/admin/settings'))->assertOk();
        $this->assertSame(LocalQaSeeder::ADMIN_EMAIL, auth()->user()?->email);
    }

    public function test_lands_on_the_dashboard_after_a_switch_when_the_new_role_may_not_open_the_page(): void
    {
        $this->enableQa();
        $this->seed(LocalQaSeeder::class);
        $this->post(route('qa.login'), ['profile' => 'admin']);

        $this->post(route('qa.login'), [
            'profile' => 'volunteer',
            'return_to' => '/admin/settings',
        ])->assertRedirect('/admin/settings');

        $response = $this->get('/admin/settings');
        $response->assertRedirect(route('dashboard'));
        $this->get(route('dashboard'))->assertOk()
            ->assertSee('Your new QA role cannot open that page');
    }

    public function test_ignores_a_return_path_that_could_lead_to_another_site(): void
    {
        $this->enableQa();
        $this->seed(LocalQaSeeder::class);

        foreach (['//evil.example', 'https://evil.example/', '/\\evil.example', 'dashboard'] as $returnTo) {
            $this->post(route('qa.login'), [
                'profile' => 'staff',
                'return_to' => $returnTo,
            ])->assertRedirect(route('dashboard'));
            $this->post(route('qa.logout'));
        }
    }

    public function test_signs_out_of_qa_to_the_developer_sign_in_panel(): void
    {
        $this->enableQa();
        $this->seed(LocalQaSeeder::class);
        $this->post(route('qa.login'), ['profile' => 'admin']);

        $this->post(route('qa.logout'))
            ->assertRedirect(route('public.login').'#developer-signin');
        $this->assertGuest();
    }

    public function test_audits_qa_sign_in_switch_and_sign_out(): void
    {
        $this->enableQa();
        $this->seed(LocalQaSeeder::class);

        $this->post(route('qa.login'), ['profile' => 'staff']);
        $signIn = AuditLog::query()->where('event', 'auth.signed_in')->latest('id')->first();
        $this->assertSame('qa', $signIn?->context['method'] ?? null);
        $this->assertSame('staff', $signIn?->context['profile'] ?? null);

        $this->post(route('qa.login'), ['profile' => 'admin', 'return_to' => '/dashboard']);
        $switch = AuditLog::query()->where('event', 'auth.signed_in')->latest('id')->first();
        $this->assertSame('qa', $switch?->context['method'] ?? null);
        $this->assertSame('staff', $switch?->context['switched_from'] ?? null);
        $this->assertSame('admin', $switch?->context['profile'] ?? null);

        $this->post(route('qa.logout'));
        $signOut = AuditLog::query()->where('event', 'auth.signed_out')->latest('id')->first();
        $this->assertSame('qa', $signOut?->context['method'] ?? null);
        $this->assertArrayNotHasKey('password', $signOut?->context ?? []);
        $this->assertArrayNotHasKey('token', $signOut?->context ?? []);
    }

    public function test_qa_step_up_shows_confirm_prompt_and_opens_the_page(): void
    {
        $this->enableQa();
        $this->seed(LocalQaSeeder::class);
        config(['auth.password_timeout' => 1]);

        $this->post(route('qa.login'), ['profile' => 'admin']);
        $this->travel(2)->seconds();

        $this->get(route('admin.settings.show', ['tab' => 'keys-and-secrets']))->assertOk();
        $this->travel(2)->seconds();

        $this->put(route('admin.settings.keys-and-secrets.store'), [
            'password' => 'not-used-for-qa',
        ])->assertRedirect(route('password.confirm'));

        $this->get(route('password.confirm'))->assertOk()
            ->assertSee('data-qa-confirm', false)
            ->assertSee('Confirm QA Administrator');

        $returnTo = route('admin.settings.show', ['tab' => 'keys-and-secrets'], absolute: false);
        $this->post(route('qa.login'), [
            'profile' => 'admin',
            'return_to' => $returnTo,
        ])->assertRedirect($returnTo);
        $this->get($returnTo)->assertOk();
    }

    public function test_non_qa_local_account_still_gets_password_confirmation(): void
    {
        $this->enableQa();
        config(['auth.password_timeout' => 1]);

        $user = User::factory()->create([
            'password' => 'MyAPES-Test-Password-2026!',
            'identity_type' => User::IDENTITY_LOCAL,
        ]);
        $user->setAccessLevel(User::ROLE_SERVICE_USER)->save();

        $this->actingAs($user);
        request()->session()->put(
            app(SessionAuthorizationContext::class)->valuesFor(
                $user,
                SessionAuthorizationContext::METHOD_PASSWORD,
            ),
        );
        $this->travel(2)->seconds();

        $this->get(route('password.confirm'))->assertOk()
            ->assertSee(__('auth.confirm_password.heading'))
            ->assertDontSee('data-qa-confirm', false);
    }

    public function test_with_the_gate_off_a_qa_session_cannot_pass_step_up_via_qa_confirm(): void
    {
        $this->seed(LocalQaSeeder::class);
        $this->enableQa();
        $this->post(route('qa.login'), ['profile' => 'admin']);
        $this->assertAuthenticated();

        $this->enableQa(flag: false);
        config(['auth.password_timeout' => 1]);
        $this->travel(2)->seconds();

        $this->put(route('admin.settings.keys-and-secrets.store'), [
            'password' => 'not-used-for-qa',
        ])->assertRedirect(route('public.login'));

        $this->get(route('password.confirm'))
            ->assertRedirect();
        $this->assertFalse(QaLogin::enabled());
    }

    public function test_deploy_check_fails_when_qa_login_enabled_in_production(): void
    {
        $script = file_get_contents(base_path('scripts/deploy/cloudron-run.sh'));

        $this->assertStringContainsString('QA_LOGIN_ENABLED', $script);
        $this->assertStringContainsString('Refusing to run with QA_LOGIN_ENABLED set while APP_ENV=production.', $script);
        $this->assertMatchesRegularExpression(
            '/QA_LOGIN_ENABLED.*true.*APP_ENV=production/s',
            $script,
        );
    }

    public function test_no_qa_account_password_appears_in_the_repository_constants(): void
    {
        $this->assertFalse(defined(LocalQaSeeder::class.'::DEFAULT_PASSWORD'));
        $this->assertStringNotContainsString(
            'MyAPES-Local-QA-2026!',
            file_get_contents(database_path('seeders/LocalQaSeeder.php')),
        );
    }

    public function test_authorization_profile_still_lists_account_types(): void
    {
        $profile = app(AuthorizationProfile::class);
        $this->assertContains('admin', $profile->qaSwitchSelectors());
    }
}
