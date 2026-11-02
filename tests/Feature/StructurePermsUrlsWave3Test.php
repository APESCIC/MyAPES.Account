<?php

namespace Tests\Feature;

use App\Contracts\ModuleRegistry;
use App\Core\Access\PermissionGrantMigrator;
use App\Core\Access\PermissionGrantReporter;
use App\Core\Access\PermissionNaming;
use App\Core\Accounts\Permission;
use App\Core\Accounts\PermissionSource;
use App\Core\Accounts\Role;
use App\Core\Accounts\User;
use App\Core\Http\LegacyRedirectTable;
use App\Services\AuthorizationDirectPermissionMaterializer;
use App\Services\AuthorizationMetadataSynchronizer;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use App\Support\PermissionDescriptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StructurePermsUrlsWave3Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
    }

    public function test_every_code_owned_permission_matches_documented_naming_convention(): void
    {
        $profile = app(AuthorizationProfile::class);

        foreach ($profile->permissions() as $name) {
            $this->assertTrue(
                PermissionNaming::matchesConvention($name),
                "Permission [{$name}] does not match Core/Module/Plugin naming convention.",
            );
        }

        $this->assertSame(
            PermissionNaming::LAYER_CORE,
            PermissionNaming::layer('admin.users.manage'),
        );
        $this->assertSame(
            PermissionNaming::LAYER_CORE,
            PermissionNaming::layer('superadmin.access'),
        );
        $this->assertSame(
            PermissionNaming::LAYER_PLUGIN,
            PermissionNaming::layer('apes-cic.recruitment.review-applications'),
        );
        $this->assertSame(
            'apes-cic.tickets.view-own',
            PermissionNaming::pluginPermission('apes-cic', 'tickets', 'view-own'),
        );
        $this->assertSame([], PermissionNaming::aliases());
    }

    public function test_registry_permissions_are_plugin_scoped_names(): void
    {
        $registry = app(ModuleRegistry::class);

        foreach ($registry->permissions() as $permission) {
            $this->assertTrue(
                PermissionNaming::isPluginScoped($permission->name),
                "Registry permission [{$permission->name}] is not {module}.{plugin}.{ability}.",
            );
            $this->assertSame(
                PermissionNaming::pluginPermission(
                    $permission->subCoreKey,
                    $permission->moduleKey,
                    $permission->ability,
                ),
                $permission->name,
            );
        }
    }

    public function test_grant_migrator_with_empty_aliases_preserves_grant_counts_and_abilities(): void
    {
        $user = User::factory()->create();
        $role = Role::query()
            ->where('guard_name', 'web')
            ->where('name', AuthorizationProfile::ROLE_STAFF)
            ->firstOrFail();
        $permission = Permission::query()
            ->where('guard_name', 'web')
            ->where('name', 'apes-cic.tickets.view-own')
            ->firstOrFail();

        DB::table('role_has_permissions')->insertOrIgnore([
            'permission_id' => $permission->id,
            'role_id' => $role->id,
        ]);
        app(AuthorizationDirectPermissionMaterializer::class)->grant(
            $user,
            $permission,
            PermissionSource::SOURCE_SYSTEM,
        );

        $beforeAbilities = $user->fresh()
            ->getAllPermissions()
            ->pluck('name')
            ->sort()
            ->values()
            ->all();
        $reporter = app(PermissionGrantReporter::class);
        $before = $reporter->snapshot();

        $result = app(PermissionGrantMigrator::class)->migrateAliases();
        app(AuthorizationMetadataSynchronizer::class)->synchronize();

        $after = $reporter->snapshot();
        $afterAbilities = $user->fresh()
            ->getAllPermissions()
            ->pluck('name')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(0, $result['renamed']);
        $this->assertTrue($result['grants_preserved']);
        $this->assertTrue($reporter->grantsPreserved($before, $after));
        $this->assertSame($beforeAbilities, $afterAbilities);
        $this->assertDatabaseHas('permissions', [
            'id' => $permission->id,
            'name' => 'apes-cic.tickets.view-own',
        ]);
    }

    public function test_access_permissions_tab_groups_by_core_module_plugin_layers(): void
    {
        $superAdmin = User::factory()->accessLevel(User::ROLE_ADMIN)->create();

        $this->actingAs($superAdmin)
            ->get(route('admin.access.index', ['tab' => 'permissions']))
            ->assertOk()
            ->assertSee('data-permission-layer="Core"', false)
            ->assertSee('data-permission-layer="Plugin"', false)
            ->assertSee('All layers')
            ->assertSee('admin.users.view')
            ->assertSee('apes-cic.recruitment.review-applications');

        $this->actingAs($superAdmin)
            ->get(route('admin.access.index', [
                'tab' => 'permissions',
                'layer' => PermissionNaming::LAYER_CORE,
            ]))
            ->assertOk()
            ->assertSee('data-permission-layer="Core"', false)
            ->assertDontSee('data-permission-layer="Plugin"', false);

        $this->assertSame(
            [PermissionNaming::LAYER_CORE, PermissionNaming::LAYER_PLUGIN],
            PermissionDescriptions::layersFor(app(AuthorizationProfile::class)->permissions()),
        );
    }

    public function test_legacy_redirects_are_permanent_301(): void
    {
        $superAdmin = User::factory()->accessLevel(User::ROLE_ADMIN)->create();

        $this->actingAs($superAdmin)
            ->get('/superadmin')
            ->assertRedirect(route('admin.index'))
            ->assertStatus(301);
        $this->actingAs($superAdmin)
            ->get('/superadmin/plugins')
            ->assertRedirect(route('admin.modules.index'))
            ->assertStatus(301);
        $this->actingAs($superAdmin)
            ->get('/admin/permissions')
            ->assertRedirect(route('admin.access.index', ['tab' => 'permissions']))
            ->assertStatus(301);

        $staff = User::factory()->accessLevel(User::ROLE_STAFF)->create();
        $this->actingAs($staff)
            ->get('/shelter/pet-profiles')
            ->assertRedirect('/shelter/pets')
            ->assertStatus(301);
        $this->actingAs($staff)
            ->get('/petcare/pet-profiles')
            ->assertRedirect('/petcare/pets')
            ->assertStatus(301);
    }

    public function test_live_module_prefixes_and_public_recruitment_stay_put(): void
    {
        $this->assertSame(
            [
                'apes-cic' => '/apes-cic',
                'shelter-rescue' => '/shelter',
                'pet-care-clinic' => '/petcare',
            ],
            LegacyRedirectTable::modulePrefixes(),
        );

        $staff = User::factory()->accessLevel(User::ROLE_STAFF)->create();

        $this->actingAs($staff)->get('/apes-cic')->assertOk();
        $this->actingAs($staff)->get('/shelter')->assertOk();
        $this->actingAs($staff)->get('/petcare')->assertOk();
        $this->get('/recruitment')->assertOk();

        $active = collect(LegacyRedirectTable::activePermanent())
            ->pluck('from')
            ->all();
        $this->assertContains('/superadmin', $active);
        $this->assertContains('/shelter/pet-profiles', $active);

        $placeholders = collect(LegacyRedirectTable::entries())
            ->filter(fn (array $row): bool => $row['status'] === null)
            ->pluck('from')
            ->all();
        $this->assertContains('/recruitment', $placeholders);
        $this->assertContains('/apes-cic/tickets', $placeholders);
    }
}
