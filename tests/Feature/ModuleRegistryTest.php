<?php

namespace Tests\Feature;

use App\Contracts\ModuleRegistry;
use App\Modules\ModuleCodeStatus;
use App\Modules\ModuleDependency;
use App\Modules\ModuleInstanceDefinition;
use App\Services\AuthorizationProfile;
use App\Services\ModuleRegistryValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Mockery;
use Plugins\Tickets\Dashboard\SupportTicketSummaryProvider;
use Tests\TestCase;

class ModuleRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_first_party_registry_exposes_the_permanent_sub_cores_and_module_types(): void
    {
        $registry = app(ModuleRegistry::class);

        $this->assertSame(
            ['apes-cic', 'pet-care-clinic', 'shelter-rescue'],
            array_keys($registry->subCores()),
        );
        $this->assertSame(
            ['cases', 'consultations', 'pet-profiles', 'recruitment', 'tickets'],
            array_keys($registry->modules()),
        );

        $this->assertSame('/apes-cic', $registry->subCore('apes-cic')->basePath);
        $this->assertSame('/shelter', $registry->subCore('shelter-rescue')->basePath);
        $this->assertSame('/petcare', $registry->subCore('pet-care-clinic')->basePath);
    }

    public function test_all_fifteen_matrix_cells_have_an_explicit_code_status(): void
    {
        $registry = app(ModuleRegistry::class);
        $matrix = collect($registry->matrix())
            ->mapWithKeys(fn ($cell): array => [$cell->key() => $cell->codeStatus])
            ->all();

        $this->assertCount(15, $matrix);
        $this->assertSame([
            'apes-cic:cases' => ModuleCodeStatus::Shipped,
            'apes-cic:consultations' => ModuleCodeStatus::Incompatible,
            'apes-cic:pet-profiles' => ModuleCodeStatus::Incompatible,
            'apes-cic:recruitment' => ModuleCodeStatus::Shipped,
            'apes-cic:tickets' => ModuleCodeStatus::Shipped,
            'pet-care-clinic:cases' => ModuleCodeStatus::Incompatible,
            'pet-care-clinic:consultations' => ModuleCodeStatus::Shipped,
            'pet-care-clinic:pet-profiles' => ModuleCodeStatus::Shipped,
            'pet-care-clinic:recruitment' => ModuleCodeStatus::Incompatible,
            'pet-care-clinic:tickets' => ModuleCodeStatus::Shipped,
            'shelter-rescue:cases' => ModuleCodeStatus::Shipped,
            'shelter-rescue:consultations' => ModuleCodeStatus::Incompatible,
            'shelter-rescue:pet-profiles' => ModuleCodeStatus::Shipped,
            'shelter-rescue:recruitment' => ModuleCodeStatus::Incompatible,
            'shelter-rescue:tickets' => ModuleCodeStatus::Shipped,
        ], $matrix);
    }

    public function test_shipped_instances_and_dependencies_are_code_owned(): void
    {
        $registry = app(ModuleRegistry::class);

        $this->assertSame([
            'apes-cic:cases',
            'apes-cic:recruitment',
            'apes-cic:tickets',
            'pet-care-clinic:consultations',
            'pet-care-clinic:pet-profiles',
            'pet-care-clinic:tickets',
            'shelter-rescue:cases',
            'shelter-rescue:pet-profiles',
            'shelter-rescue:tickets',
        ], array_keys($registry->shippedInstances()));

        $this->assertSame(
            ['shelter-rescue:pet-profiles'],
            $registry->instance('shelter-rescue', 'cases')->dependencyKeys(),
        );
        $this->assertSame(
            ['pet-care-clinic:pet-profiles'],
            $registry->instance('pet-care-clinic', 'consultations')->dependencyKeys(),
        );
    }

    public function test_navigation_orders_shipped_modules_for_each_sub_core(): void
    {
        $registry = app(ModuleRegistry::class);

        $this->assertSame(
            10,
            $registry->instance('apes-cic', 'tickets')
                ->module->navigation['apes-cic']->order,
        );
        $this->assertSame(
            20,
            $registry->instance('apes-cic', 'cases')
                ->module->navigation['apes-cic']->order,
        );
        $this->assertSame(
            30,
            $registry->instance('apes-cic', 'recruitment')
                ->module->navigation['apes-cic']->order,
        );
        $this->assertSame(
            20,
            $registry->instance('shelter-rescue', 'tickets')
                ->module->navigation['shelter-rescue']->order,
        );
        $this->assertSame(
            30,
            $registry->instance('shelter-rescue', 'cases')
                ->module->navigation['shelter-rescue']->order,
        );
        $this->assertSame(
            10,
            $registry->instance('pet-care-clinic', 'pet-profiles')
                ->module->navigation['pet-care-clinic']->order,
        );
        $this->assertSame(
            20,
            $registry->instance('pet-care-clinic', 'tickets')
                ->module->navigation['pet-care-clinic']->order,
        );
        $this->assertSame(
            30,
            $registry->instance('pet-care-clinic', 'consultations')
                ->module->navigation['pet-care-clinic']->order,
        );
    }

    public function test_registry_validation_rejects_dependency_cycles(): void
    {
        $registry = app(ModuleRegistry::class);
        $matrix = collect($registry->matrix())
            ->mapWithKeys(
                static fn (ModuleInstanceDefinition $instance): array => [
                    $instance->key() => $instance,
                ],
            )
            ->all();
        $tickets = $matrix['apes-cic:tickets'];
        $consultations = $matrix['pet-care-clinic:consultations'];
        $matrix['apes-cic:tickets'] = new ModuleInstanceDefinition(
            $tickets->subCore,
            $tickets->module,
            $tickets->codeStatus,
            [new ModuleDependency('pet-care-clinic', 'consultations')],
        );
        $matrix['pet-care-clinic:consultations'] = new ModuleInstanceDefinition(
            $consultations->subCore,
            $consultations->module,
            $consultations->codeStatus,
            [new ModuleDependency('apes-cic', 'tickets')],
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Module dependency cycle detected.');

        app(ModuleRegistryValidator::class)->validate(
            $registry->subCores(),
            $registry->modules(),
            $matrix,
        );
    }

    public function test_registry_validation_rejects_invalid_or_unavailable_instance_provider_overrides(): void
    {
        $registry = app(ModuleRegistry::class);
        $matrix = collect($registry->matrix())
            ->mapWithKeys(
                static fn (ModuleInstanceDefinition $instance): array => [
                    $instance->key() => $instance,
                ],
            )
            ->all();
        $tickets = $matrix['shelter-rescue:tickets'];
        $matrix['shelter-rescue:tickets'] = new ModuleInstanceDefinition(
            $tickets->subCore,
            $tickets->module,
            $tickets->codeStatus,
            [],
            \stdClass::class,
        );

        try {
            app(ModuleRegistryValidator::class)->validate(
                $registry->subCores(),
                $registry->modules(),
                $matrix,
            );
            $this->fail('An invalid summary-provider override was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'Module summary provider is invalid.',
                $exception->getMessage(),
            );
        }

        $unavailable = $registry->instance('pet-care-clinic', 'cases');
        $matrix['pet-care-clinic:cases'] = new ModuleInstanceDefinition(
            $unavailable->subCore,
            $unavailable->module,
            $unavailable->codeStatus,
            [],
            SupportTicketSummaryProvider::class,
        );

        try {
            app(ModuleRegistryValidator::class)->validate(
                $registry->subCores(),
                $registry->modules(),
                $matrix,
            );
            $this->fail('An unavailable instance override was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'Unavailable module instances cannot declare overrides.',
                $exception->getMessage(),
            );
        }
    }

    public function test_permission_descriptors_are_unique_namespaced_and_classified(): void
    {
        $registry = app(ModuleRegistry::class);
        $permissions = $registry->permissions();
        $names = array_map(
            static fn ($permission): string => $permission->name,
            $permissions,
        );

        $this->assertCount(65, $permissions);
        $this->assertCount(65, array_unique($names));
        $this->assertContains('apes-cic.cases.comment-own', $names);
        $this->assertContains('apes-cic.cases.delete', $names);
        $this->assertContains('apes-cic.recruitment.view-own', $names);
        $this->assertContains('apes-cic.recruitment.create', $names);
        $this->assertContains('apes-cic.recruitment.view-all', $names);
        $this->assertContains('apes-cic.recruitment.update', $names);
        $this->assertContains('apes-cic.recruitment.delete', $names);
        $this->assertContains('apes-cic.recruitment.review-applications', $names);
        $this->assertContains('apes-cic.tickets.view-own', $names);
        $this->assertContains('apes-cic.tickets.comment-own', $names);
        $this->assertContains('apes-cic.tickets.delete', $names);
        $this->assertContains('shelter-rescue.pet-profiles.update-own', $names);
        $this->assertContains('shelter-rescue.tickets.view-own', $names);
        $this->assertContains('shelter-rescue.tickets.delete', $names);
        $this->assertContains('pet-care-clinic.consultations.assign', $names);
        $this->assertContains('pet-care-clinic.tickets.view-own', $names);
        $this->assertContains('pet-care-clinic.tickets.delete', $names);

        foreach ($permissions as $permission) {
            $this->assertMatchesRegularExpression(
                '/^[a-z0-9-]+\.[a-z0-9-]+\.[a-z0-9-]+$/',
                $permission->name,
            );
            $expectsDirectoryContext = in_array(
                $permission->ability,
                [
                    'view-all',
                    'update-all',
                    'update',
                    'assign',
                    'close',
                    'delete',
                    'review-applications',
                ],
                true,
            ) || ($permission->moduleKey === 'recruitment'
                && $permission->ability === 'create');
            $this->assertSame(
                $expectsDirectoryContext,
                $permission->requiresDirectoryContext,
            );
        }
    }

    public function test_module_permissions_extend_protected_role_matrices_without_granting_module_management_to_administrators(): void
    {
        $profile = app(AuthorizationProfile::class);
        $matrix = $profile->permissionMatrix();

        $this->assertContains(
            'apes-cic.tickets.create',
            $matrix[AuthorizationProfile::ROLE_SERVICE_USER],
        );
        $this->assertNotContains(
            'apes-cic.recruitment.create',
            $matrix[AuthorizationProfile::ROLE_SERVICE_USER],
        );
        $this->assertNotContains(
            'apes-cic.recruitment.create',
            $matrix[AuthorizationProfile::ROLE_STAFF],
        );
        $this->assertContains(
            'apes-cic.recruitment.create',
            $matrix[AuthorizationProfile::ROLE_ADMINISTRATOR],
        );
        $this->assertContains(
            'apes-cic.tickets.assign',
            $matrix[AuthorizationProfile::ROLE_STAFF],
        );
        $this->assertContains(
            'apes-cic.tickets.assign',
            $matrix[AuthorizationProfile::ROLE_VOLUNTEER],
        );
        $this->assertContains(
            'apes-cic.tickets.assign',
            $matrix[AuthorizationProfile::ROLE_STUDENT],
        );
        $this->assertNotContains(
            'apes-cic.tickets.delete',
            $matrix[AuthorizationProfile::ROLE_VOLUNTEER],
        );
        $this->assertNotContains(
            'apes-cic.tickets.delete',
            $matrix[AuthorizationProfile::ROLE_STUDENT],
        );
        $this->assertContains(
            'apes-cic.tickets.delete',
            $matrix[AuthorizationProfile::ROLE_STAFF],
        );
        $this->assertContains(
            'pet-care-clinic.consultations.close',
            $matrix[AuthorizationProfile::ROLE_ADMINISTRATOR],
        );
        $this->assertNotContains(
            'admin.modules.manage',
            $matrix[AuthorizationProfile::ROLE_ADMINISTRATOR],
        );
        $this->assertContains(
            'admin.modules.manage',
            $matrix[AuthorizationProfile::ROLE_SUPER_ADMIN],
        );
        $this->assertTrue(
            $profile->isSuperAdminOnlyPermission('admin.modules.manage'),
        );
        $this->assertCount(81, $profile->permissions());
        $this->assertTrue(
            $profile->isSuperAdminOnlyPermission('superadmin.access'),
        );
        $this->assertNotContains(
            'admin.modules.view',
            $matrix[AuthorizationProfile::ROLE_ADMINISTRATOR],
        );
        $this->assertContains(
            'superadmin.access',
            $matrix[AuthorizationProfile::ROLE_SUPER_ADMIN],
        );
        $this->assertFalse(
            $profile->isDirectoryRestrictedPermission(
                'apes-cic.tickets.create',
            ),
        );
        $this->assertTrue(
            $profile->isDirectoryRestrictedPermission(
                'apes-cic.tickets.assign',
            ),
        );
    }

    public function test_authorization_profile_is_request_scoped_and_memoizes_its_permission_catalogue(): void
    {
        $registry = Mockery::mock(ModuleRegistry::class);
        $registry->shouldReceive('permissions')->once()->andReturn([]);
        Schema::shouldReceive('hasTable')
            ->once()
            ->with('module_plugins')
            ->andReturnTrue();
        $profile = new AuthorizationProfile($registry);

        $firstMatrix = $profile->permissionMatrix();
        $this->assertSame($firstMatrix, $profile->permissionMatrix());
        $firstPermissions = $profile->permissions();
        $this->assertSame($firstPermissions, $profile->permissions());
        $this->assertSame(
            app(AuthorizationProfile::class),
            app(AuthorizationProfile::class),
        );
    }
}
