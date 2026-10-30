<?php

namespace Tests\Feature;

use App\Core\Accounts\Permission;
use App\Core\Accounts\Role;
use App\Core\Accounts\User;
use App\Core\Extensions\Models\ModuleInstallation;
use App\Core\Extensions\Models\ModuleSetting;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use App\Support\DefaultJobRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugins\Recruitment\Models\RecruitmentApplication;
use Plugins\Recruitment\Models\RecruitmentRole;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesRecruitmentPersonas;
use Tests\TestCase;

/**
 * Contract for the unified Recruitment menu and access matrix (epic #342, plugins/recruitment/README.md).
 */
class RecruitmentUnifiedMenuAccessMatrixTest extends TestCase
{
    use CreatesRecruitmentPersonas;
    use RefreshDatabase;

    private const ALL_SECTIONS = ['Open roles', 'My applications', 'Roles', 'Applications'];

    private const BROWSE_SECTIONS = ['Open roles', 'My applications'];

    private const LOGIN = 'login';

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
    }

    /**
     * @return array<string, array{0: string, 1: ?string, 2: ?list<string>, 3: array<string, int|string>}>
     */
    public static function personas(): array
    {
        $applicant = [
            'board' => 200,
            'board.show' => 200,
            'my-applications' => 200,
            'manage.roles' => 403,
            'manage.applications' => 403,
            'manage.application' => 403,
            'manage.store' => 403,
        ];
        $denied = array_fill_keys(array_keys($applicant), 403);
        $manager = [
            'board' => 200,
            'board.show' => 200,
            'my-applications' => 200,
            'manage.roles' => 200,
            'manage.applications' => 200,
            'manage.application' => 200,
            'manage.store' => 302,
        ];

        return [
            'guest' => ['guest', 'recruitment.index', ['Open roles'], [
                'board' => 200,
                'board.show' => 200,
                'my-applications' => self::LOGIN,
                'manage.roles' => self::LOGIN,
                'manage.applications' => self::LOGIN,
                'manage.application' => self::LOGIN,
                'manage.store' => self::LOGIN,
            ]],
            'service user' => ['service-user', 'recruitment.index', self::BROWSE_SECTIONS, $applicant],
            'student' => ['student', null, null, $denied],
            'volunteer' => ['volunteer', null, null, $denied],
            'staff without HR' => ['staff', 'recruitment.index', self::BROWSE_SECTIONS, $applicant],
            'HR staff' => ['hr', 'apes-cic.recruitment.index', self::ALL_SECTIONS, $manager],
            'administrator' => ['administrator', 'apes-cic.recruitment.index', self::ALL_SECTIONS, $manager],
            'super admin' => ['super-admin', 'apes-cic.recruitment.index', self::ALL_SECTIONS, $manager],
            'review only' => ['review-only', 'apes-cic.recruitment.applications.index', ['Open roles', 'Applications'], [
                'board' => 200,
                'board.show' => 200,
                'my-applications' => 403,
                'manage.roles' => 403,
                'manage.applications' => 200,
                'manage.application' => 200,
                'manage.store' => 403,
            ]],
        ];
    }

    /**
     * @param  ?list<string>  $expectedSections
     * @param  array<string, int|string>  $outcomes
     */
    #[DataProvider('personas')]
    public function test_menu_and_routes_follow_the_access_matrix(
        string $persona,
        ?string $expectedPrimaryRoute,
        ?array $expectedSections,
        array $outcomes,
    ): void {
        $role = RecruitmentRole::factory()->open()->create(['title' => 'Matrix opening']);
        $application = RecruitmentApplication::factory()->forRole($role)->create();
        $actor = $this->persona($persona);

        $home = $this->actingAsPersona($actor)->get(route($actor === null ? 'home' : 'dashboard'))->assertOk();
        $this->assertSame(0, substr_count($home->getContent(), 'Recruit manage'));
        $this->assertSame(
            $expectedPrimaryRoute === null ? null : route($expectedPrimaryRoute),
            $this->recruitmentPrimaryHref($home->getContent()),
        );

        $board = $this->actingAsPersona($actor)->get(route('recruitment.index'));
        if ($expectedSections === null) {
            $board->assertForbidden();
        } else {
            $board->assertOk();
            $this->assertSame($expectedSections, $this->recruitmentSubmenuLabels($board->getContent()));
        }

        $requests = [
            'board' => fn (): TestResponse => $this->actingAsPersona($actor)->get(route('recruitment.index')),
            'board.show' => fn (): TestResponse => $this->actingAsPersona($actor)->get(route('recruitment.show', $role)),
            'my-applications' => fn (): TestResponse => $this->actingAsPersona($actor)->get(route('recruitment.applications.index')),
            'manage.roles' => fn (): TestResponse => $this->actingAsPersona($actor)->get(route('apes-cic.recruitment.index')),
            'manage.applications' => fn (): TestResponse => $this->actingAsPersona($actor)->get(route('apes-cic.recruitment.applications.index')),
            'manage.application' => fn (): TestResponse => $this->actingAsPersona($actor)->get(route('apes-cic.recruitment.applications.show', $application)),
            'manage.store' => fn (): TestResponse => $this->actingAsPersona($actor)->post(route('apes-cic.recruitment.store'), [
                'title' => "Matrix draft by {$persona}",
                'description' => 'Matrix coverage.',
                'category' => 'staff',
            ]),
        ];

        $this->assertSame(array_keys($requests), array_keys($outcomes));

        foreach ($requests as $key => $request) {
            $response = $request();
            $expected = $outcomes[$key];

            if ($expected === self::LOGIN) {
                $response->assertRedirect(route('public.login'));
            } else {
                $this->assertSame($expected, $response->getStatusCode(), "{$persona}: {$key}");
            }
        }

        $this->assertSame(
            $outcomes['manage.store'] === 302,
            RecruitmentRole::query()->where('title', "Matrix draft by {$persona}")->exists(),
        );
    }

    public function test_board_toggle_off_hides_open_roles_and_keeps_manage_for_hr(): void
    {
        $this->setRecruitmentToggles(board: false, apply: false);
        $hr = $this->hrStaff();
        $staff = $this->persona('staff');

        $this->get(route('home'))->assertOk()->assertDontSee('href="'.route('recruitment.index').'"', false);
        $this->get(route('recruitment.index'))->assertNotFound();

        $this->assertNull($this->recruitmentPrimaryHref(
            $this->actingAs($staff)->get(route('dashboard'))->assertOk()->getContent(),
        ));

        $this->assertSame(route('apes-cic.recruitment.index'), $this->recruitmentPrimaryHref(
            $this->actingAs($hr)->get(route('dashboard'))->assertOk()->getContent(),
        ));
        $this->assertSame(
            ['My applications', 'Roles', 'Applications'],
            $this->recruitmentSubmenuLabels(
                $this->actingAs($hr)->get(route('apes-cic.recruitment.index'))->assertOk()->getContent(),
            ),
        );
    }

    public function test_apply_toggle_off_hides_apply_and_returns_not_found(): void
    {
        $this->setRecruitmentToggles(board: true, apply: false);
        $applicant = $this->persona('service-user');
        $role = RecruitmentRole::factory()->open()->create();

        $this->actingAs($applicant)
            ->get(route('recruitment.show', $role))
            ->assertOk()
            ->assertDontSee('action="'.route('recruitment.apply', $role).'"', false);
        $this->actingAs($applicant)
            ->post(route('recruitment.apply', $role), ['statement' => 'Closed for now'])
            ->assertNotFound();

        $this->assertSame(0, RecruitmentApplication::query()->count());
    }

    public function test_recruitment_menu_and_routes_disappear_when_plugin_is_disabled(): void
    {
        $hr = $this->hrStaff();

        ModuleInstallation::query()
            ->where('sub_core_key', 'apes-cic')
            ->where('module_key', 'recruitment')
            ->update(['enabled' => false]);

        $this->assertNull($this->recruitmentPrimaryHref(
            $this->actingAs($hr)->get(route('dashboard'))->assertOk()->getContent(),
        ));
        $this->actingAs($hr)->get('/apes-cic/recruitment')->assertNotFound();
        $this->actingAs($hr)->get('/apes-cic/recruitment/applications')->assertNotFound();
    }

    public function test_apes_cic_hub_recruitment_tile_is_for_managers_only(): void
    {
        $listUrl = route('apes-cic.recruitment.index');

        $hrHub = $this->actingAs($this->hrStaff())->get('/apes-cic')->assertOk()->getContent();
        $this->assertSame($listUrl.'#list', $this->hubActionHref($hrHub, 'view'));
        $this->assertSame($listUrl.'#create', $this->hubActionHref($hrHub, 'create'));

        $staffHub = $this->actingAs($this->persona('staff'))->get('/apes-cic')->assertOk()->getContent();
        $this->assertSame(0, preg_match('/data-module-key="recruitment"/', $staffHub));
    }

    public function test_signed_in_public_user_without_service_selection_can_browse_open_roles(): void
    {
        $applicant = $this->persona('service-user');
        $applicant->serviceSelections()->delete();
        $role = RecruitmentRole::factory()->open()->create(['title' => 'Browsable role']);

        $this->actingAs($applicant)
            ->get(route('recruitment.index'))
            ->assertOk()
            ->assertSeeText('Browsable role');
        $this->actingAs($applicant)->get(route('recruitment.show', $role))->assertOk();
    }

    private function persona(string $persona): ?User
    {
        return match ($persona) {
            'guest' => null,
            'hr' => $this->hrStaff(),
            'review-only' => $this->reviewOnlyStaff(),
            'service-user', 'student', 'volunteer', 'staff', 'administrator', 'super-admin' => User::factory()
                ->protectedRole($persona)
                ->create(),
        };
    }

    private function reviewOnlyStaff(): User
    {
        $reviewer = $this->hrStaff();

        $roles = Role::query()
            ->where('guard_name', 'web')
            ->whereIn('name', [AuthorizationProfile::ROLE_STAFF, DefaultJobRoles::HUMAN_RESOURCES])
            ->get();
        $permissions = Permission::query()
            ->where('guard_name', 'web')
            ->where('name', 'like', 'apes-cic.recruitment.%')
            ->where('name', '!=', RecruitmentApplication::PERMISSION_PREFIX.'review-applications')
            ->get();

        foreach ($roles as $role) {
            foreach ($permissions as $permission) {
                $role->revokePermissionTo($permission);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $reviewer = $reviewer->fresh();
        $reviewer->unsetRelation('roles');
        $reviewer->unsetRelation('permissions');

        return $reviewer;
    }

    private function actingAsPersona(?User $actor): static
    {
        if ($actor === null) {
            auth()->logout();

            return $this;
        }

        return $this->actingAs($actor);
    }

    private function setRecruitmentToggles(bool $board, bool $apply): void
    {
        $record = ModuleSetting::query()
            ->where('sub_core_key', 'apes-cic')
            ->where('module_key', 'recruitment')
            ->firstOrFail();

        $this->actingAs($this->persona('super-admin'))
            ->put(route('admin.modules.settings.update', ['apes-cic', 'recruitment']), [
                'version' => $record->lock_version,
                'public_board_enabled' => $board ? '1' : '0',
                'public_apply_enabled' => $apply ? '1' : '0',
            ])
            ->assertRedirect();

        auth()->logout();
    }

    /**
     * @return list<string>
     */
    private function recruitmentSubmenuLabels(string $html): array
    {
        $this->assertSame(1, substr_count($html, 'aria-label="Recruitment sections"'), 'Expected exactly one Recruitment submenu.');
        preg_match('/<nav class="admin-nav" aria-label="Recruitment sections">(.*?)<\/nav>/s', $html, $nav);
        preg_match_all('/<span>([^<]+)<\/span>/', $nav[1], $labels);

        return $labels[1];
    }

    private function recruitmentPrimaryHref(string $html): ?string
    {
        $count = preg_match_all(
            '/<a\s+href="([^"]+)"\s+class="sg-nav-link"[^>]*>\s*<i[^>]*><\/i>\s*<span>Recruitment<\/span>/',
            $html,
            $matches,
        );

        $this->assertLessThanOrEqual(1, $count, 'Expected at most one Recruitment primary.');

        return $count === 1 ? html_entity_decode($matches[1][0], ENT_QUOTES) : null;
    }

    private function hubActionHref(string $html, string $action): string
    {
        $pattern = sprintf(
            '/<a[^>]*href="([^"]+)"[^>]*data-hub-action="%s"[^>]*data-module-key="recruitment"/',
            preg_quote($action, '/'),
        );

        $this->assertSame(1, preg_match($pattern, $html, $matches), "Missing hub {$action} link for recruitment.");

        return html_entity_decode($matches[1], ENT_QUOTES);
    }
}
