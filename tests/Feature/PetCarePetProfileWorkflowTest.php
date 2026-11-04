<?php

namespace Tests\Feature;

use App\Contracts\ModuleNavigationProvider;
use App\Contracts\ModuleRegistry;
use App\Core\Accounts\AuditLog;
use App\Core\Accounts\Permission;
use App\Core\Accounts\Role;
use App\Core\Accounts\User;
use App\Core\Extensions\Models\ModuleInstallation;
use App\Services\AuthorizationProfile;
use App\Services\ModuleDashboardSummaryService;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Plugins\PetProfiles\Models\PetProfile;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PetCarePetProfileWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
    }

    public function test_owner_create_and_update_preserve_record_and_media_identity_with_additive_audit_context(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create();
        $firstPhoto = $this->image('first.png');

        $response = $this->actingAs($owner)->post(route('petcare.pets.store'), [
            'name' => 'Clinic profile',
            'species' => 'cat',
            'age_years' => 4,
            'sex' => 'female',
            'neutering_status' => 'neutered',
            'health_issues' => 'Dietary support',
            'photo' => $firstPhoto,
        ]);

        $pet = PetProfile::query()->where('name', 'Clinic profile')->firstOrFail();
        $response->assertRedirect(route('petcare.pets.show', $pet));
        $originalId = $pet->id;
        $originalPath = $pet->photo_path;
        $this->assertSame($owner->id, $pet->user_id);
        $this->assertSame(PetProfile::DOMAIN_PETCARE, $pet->service_domain);
        $this->assertMatchesRegularExpression(
            '/\Apet-profiles\/[A-Za-z0-9]{40}\.png\z/',
            (string) $originalPath,
        );
        Storage::disk('public')->assertExists($originalPath);

        $createdAudit = AuditLog::query()
            ->where('event', 'petcare.pet_profile.created')
            ->where('auditable_type', 'pet_profile')
            ->where('auditable_id', $pet->id)
            ->firstOrFail();
        $this->assertSame([
            'has_photo' => true,
            'sub_core_key' => 'pet-care-clinic',
            'module_key' => 'pet-profiles',
        ], $createdAudit->context);

        $this->put(route('petcare.pets.update', $pet), [
            'name' => 'Clinic profile updated',
            'species' => 'cat',
            'age_years' => 5,
            'sex' => 'female',
            'neutering_status' => 'neutered',
            'health_issues' => 'Updated dietary support',
            'photo' => $this->image('replacement.png'),
        ])->assertRedirect(route('petcare.pets.show', $pet));

        $pet->refresh();
        $this->assertSame($originalId, $pet->id);
        $this->assertSame($owner->id, $pet->user_id);
        $this->assertSame(PetProfile::DOMAIN_PETCARE, $pet->service_domain);
        $this->assertNotSame($originalPath, $pet->photo_path);
        $this->assertSame('Clinic profile updated', $pet->name);
        Storage::disk('public')->assertMissing($originalPath);
        Storage::disk('public')->assertExists($pet->photo_path);

        $updatedAudit = AuditLog::query()
            ->where('event', 'petcare.pet_profile.updated')
            ->where('auditable_type', 'pet_profile')
            ->where('auditable_id', $pet->id)
            ->firstOrFail();
        $this->assertSame([
            'photo_replaced' => true,
            'sub_core_key' => 'pet-care-clinic',
            'module_key' => 'pet-profiles',
        ], $updatedAudit->context);

        $replacementPath = $pet->photo_path;
        $this->put(route('petcare.pets.update', $pet), [
            'name' => 'Clinic profile renamed',
            'species' => 'cat',
            'age_years' => 5,
            'sex' => 'female',
            'neutering_status' => 'neutered',
            'health_issues' => 'Updated dietary support',
        ])->assertRedirect(route('petcare.pets.show', $pet));
        $this->assertSame($replacementPath, $pet->fresh()->photo_path);
        Storage::disk('public')->assertExists($replacementPath);
    }

    public function test_create_requires_the_exact_permission_before_upload_or_persistence_and_hides_the_form(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create();
        $this->removeRolePermissions(
            AuthorizationProfile::ROLE_SERVICE_USER,
            ['pet-care-clinic.pet-profiles.create'],
        );

        $this->actingAs($owner->fresh())
            ->get(route('petcare.pets.index'))
            ->assertOk()
            ->assertDontSee('action="'.route('petcare.pets.store').'"', false);
        $this->post(route('petcare.pets.store'), [
            'name' => 'Forbidden Clinic profile',
            'sex' => 'unknown',
            'neutering_status' => 'unknown',
            'photo' => $this->image('forbidden.png'),
        ])->assertForbidden();

        $this->assertDatabaseMissing('pet_profiles', [
            'name' => 'Forbidden Clinic profile',
        ]);
        $this->assertSame([], Storage::disk('public')->allFiles('pet-profiles'));
    }

    public function test_consultation_index_offers_owned_pets_from_the_shared_pool_only(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $owned = $this->petFor($owner, 'Owned consultation choice');
        $foreign = $this->petFor($other, 'Foreign consultation choice');
        $shelter = $this->petFor(
            $owner,
            'Shelter consultation choice',
            PetProfile::DOMAIN_SHELTER,
        );

        $this->actingAs($owner)
            ->get(route('petcare.consultations.index'))
            ->assertOk()
            ->assertSee($owned->name)
            ->assertDontSee($foreign->name)
            ->assertSee($shelter->name);
    }

    public function test_visibility_and_record_access_use_only_exact_pet_care_permissions(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $owned = $this->petFor($owner, 'Owned Clinic profile');
        $foreign = $this->petFor($other, 'Foreign Clinic profile');
        $shelter = $this->petFor($owner, 'Shelter profile', PetProfile::DOMAIN_SHELTER);

        $this->actingAs($owner)
            ->get(route('petcare.pets.index'))
            ->assertOk()
            ->assertSee('service-label apes-petcare">APES Pet Care Clinic</span>', false)
            ->assertSee($owned->name)
            ->assertDontSee($foreign->name)
            ->assertSee($shelter->name);
        $this->get(route('petcare.pets.show', $owned))
            ->assertOk()
            ->assertSee('service-label apes-petcare">APES Pet Care Clinic</span>', false);
        $this->get(route('petcare.pets.show', $foreign))->assertForbidden();
        $this->put(route('petcare.pets.update', $foreign), $this->validUpdate(
            'Unauthorized foreign update',
        ))->assertForbidden();
        $this->assertSame('Foreign Clinic profile', $foreign->fresh()->name);

        $this->actingAs($staff)
            ->get(route('petcare.pets.index'))
            ->assertOk()
            ->assertSee($owned->name)
            ->assertSee($foreign->name);
        $this->put(
            route('petcare.pets.update', $foreign),
            $this->validUpdate('Exact staff update'),
        )->assertRedirect(route('petcare.pets.show', $foreign));
        $this->assertSame('Exact staff update', $foreign->fresh()->name);

        $this->removeRolePermissions(AuthorizationProfile::ROLE_STAFF, [
            'pet-care-clinic.pet-profiles.view-own',
            'pet-care-clinic.pet-profiles.view-all',
            'pet-care-clinic.pet-profiles.update-own',
            'pet-care-clinic.pet-profiles.update-all',
        ]);
        $staff = $staff->fresh();
        $this->assertTrue($staff->can(AuthorizationProfile::PERMISSION_STAFF_ACCESS));
        $this->assertSame([], PetProfile::query()
            ->where('service_domain', PetProfile::DOMAIN_PETCARE)
            ->visibleTo($staff, PetProfile::DOMAIN_PETCARE)
            ->pluck('id')
            ->all());
        $this->actingAs($staff)
            ->get(route('petcare.pets.index'))
            ->assertOk()
            ->assertDontSee($owned->name)
            ->assertDontSee($foreign->fresh()->name);
        $this->get(route('petcare.pets.show', $foreign))->assertForbidden();
        $this->put(
            route('petcare.pets.update', $foreign),
            $this->validUpdate('Legacy staff fallback update'),
        )->assertForbidden();
        $this->assertSame('Exact staff update', $foreign->fresh()->name);
    }

    public function test_owner_update_requires_view_own_and_update_own_and_hides_the_form_without_the_pair(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create();
        $pet = $this->petFor($owner, 'Owner update boundary');
        $originalPath = 'pet-profiles/'.str_repeat('c', 40).'.png';
        Storage::disk('public')->put($originalPath, $this->imageBytes());
        $pet->update(['photo_path' => $originalPath]);
        $this->removeRolePermissions(
            AuthorizationProfile::ROLE_SERVICE_USER,
            ['pet-care-clinic.pet-profiles.update-own'],
        );

        $this->actingAs($owner->fresh())
            ->get(route('petcare.pets.show', $pet))
            ->assertOk()
            ->assertDontSee('action="'.route('petcare.pets.update', $pet).'"', false);
        $this->put(
            route('petcare.pets.update', $pet),
            [
                ...$this->validUpdate('Forbidden owner update'),
                'photo' => $this->image('forbidden-owner-replacement.png'),
            ],
        )->assertForbidden();
        $pet->refresh();
        $this->assertSame('Owner update boundary', $pet->name);
        $this->assertSame($originalPath, $pet->photo_path);
        $this->assertSame(
            [$originalPath],
            Storage::disk('public')->allFiles('pet-profiles'),
        );
    }

    public function test_view_all_without_update_all_cannot_mutate_or_render_foreign_update_control(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create();
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $originalPath = 'pet-profiles/'.str_repeat('d', 40).'.png';
        Storage::disk('public')->put($originalPath, $this->imageBytes());
        $pet = $this->petFor(
            $owner,
            'View-only staff profile',
            PetProfile::DOMAIN_PETCARE,
            $originalPath,
        );
        $this->removeRolePermissions(
            AuthorizationProfile::ROLE_STAFF,
            ['pet-care-clinic.pet-profiles.update-all'],
        );
        $staff = $staff->fresh();
        $this->actingAs($staff);

        $this->assertTrue($staff->can('pet-care-clinic.pet-profiles.view-all'));
        $this->assertFalse($staff->can('pet-care-clinic.pet-profiles.update-all'));
        $this->get(route('petcare.pets.show', $pet))
            ->assertOk()
            ->assertDontSee('action="'.route('petcare.pets.update', $pet).'"', false);
        $this->put(route('petcare.pets.update', $pet), [
            ...$this->validUpdate('Forbidden view-only staff update'),
            'photo' => $this->image('forbidden-view-only.png'),
        ])->assertForbidden();

        $pet->refresh();
        $this->assertSame('View-only staff profile', $pet->name);
        $this->assertSame($originalPath, $pet->photo_path);
        $this->assertSame(
            [$originalPath],
            Storage::disk('public')->allFiles('pet-profiles'),
        );
    }

    public function test_update_all_without_view_all_cannot_discover_or_mutate_a_foreign_profile(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create();
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $originalPath = 'pet-profiles/'.str_repeat('e', 40).'.png';
        Storage::disk('public')->put($originalPath, $this->imageBytes());
        $pet = $this->petFor(
            $owner,
            'Update-only staff profile',
            PetProfile::DOMAIN_PETCARE,
            $originalPath,
        );
        $this->removeRolePermissions(
            AuthorizationProfile::ROLE_STAFF,
            ['pet-care-clinic.pet-profiles.view-all'],
        );
        $staff = $staff->fresh();
        $this->actingAs($staff);

        $this->assertFalse($staff->can('pet-care-clinic.pet-profiles.view-all'));
        $this->assertTrue($staff->can('pet-care-clinic.pet-profiles.update-all'));
        $this->get(route('petcare.pets.index'))
            ->assertOk()
            ->assertDontSee($pet->name)
            ->assertDontSee(route('petcare.pets.show', $pet), false);
        $this->get(route('petcare.pets.show', $pet))->assertForbidden();
        $this->put(route('petcare.pets.update', $pet), [
            ...$this->validUpdate('Forbidden update-only staff update'),
            'photo' => $this->image('forbidden-update-only.png'),
        ])->assertForbidden();

        $pet->refresh();
        $this->assertSame('Update-only staff profile', $pet->name);
        $this->assertSame($originalPath, $pet->photo_path);
        $this->assertSame(
            [$originalPath],
            Storage::disk('public')->allFiles('pet-profiles'),
        );
    }

    public function test_view_own_and_update_own_never_expose_another_owners_record_or_control(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $originalPath = 'pet-profiles/'.str_repeat('f', 40).'.png';
        Storage::disk('public')->put($originalPath, $this->imageBytes());
        $pet = $this->petFor(
            $owner,
            'Another owner private profile',
            PetProfile::DOMAIN_PETCARE,
            $originalPath,
        );
        $this->actingAs($other);

        $this->assertTrue($other->can('pet-care-clinic.pet-profiles.view-own'));
        $this->assertTrue($other->can('pet-care-clinic.pet-profiles.update-own'));
        $this->assertFalse($other->can('pet-care-clinic.pet-profiles.view-all'));
        $this->assertFalse($other->can('pet-care-clinic.pet-profiles.update-all'));
        $this->get(route('petcare.pets.index'))
            ->assertOk()
            ->assertDontSee($pet->name)
            ->assertDontSee(route('petcare.pets.show', $pet), false);
        $this->get(route('petcare.pets.show', $pet))->assertForbidden();
        $this->put(route('petcare.pets.update', $pet), [
            ...$this->validUpdate('Forbidden other-owner update'),
            'photo' => $this->image('forbidden-other-owner.png'),
        ])->assertForbidden();

        $pet->refresh();
        $this->assertSame('Another owner private profile', $pet->name);
        $this->assertSame($originalPath, $pet->photo_path);
        $this->assertSame(
            [$originalPath],
            Storage::disk('public')->allFiles('pet-profiles'),
        );
    }

    /**
     * Shared pets (#482): the module a pet is opened in decides access, not where it was added.
     */
    public function test_a_shelter_pet_follows_pet_care_permissions_in_pet_care_and_shelter_permissions_in_shelter(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create();
        $path = 'pet-profiles/'.str_repeat('a', 40).'.png';
        $sentinelPath = 'pet-profiles/'.str_repeat('b', 40).'.png';
        Storage::disk('public')->put($path, $this->imageBytes());
        Storage::disk('public')->put($sentinelPath, $this->imageBytes());
        $shelter = $this->petFor(
            $owner,
            'Private Shelter profile',
            PetProfile::DOMAIN_SHELTER,
            $path,
        );
        $this->removeRolePermissions(
            AuthorizationProfile::ROLE_SERVICE_USER,
            [
                'shelter-rescue.pet-profiles.view-own',
                'shelter-rescue.pet-profiles.update-own',
            ],
        );
        $owner = $owner->fresh();
        $this->actingAs($owner);

        $this->assertTrue($owner->can('pet-care-clinic.pet-profiles.view-own'));
        $this->assertTrue($owner->can('pet-care-clinic.pet-profiles.update-own'));
        $this->assertFalse($owner->can('shelter-rescue.pet-profiles.view-own'));
        $this->assertFalse($owner->can('shelter-rescue.pet-profiles.update-own'));

        $this->get(route('petcare.pets.show', $shelter))
            ->assertOk()
            ->assertSee('Private Shelter profile');
        $this->get(route('petcare.pets.photo', $shelter))
            ->assertOk();
        $this->get(route('shelter.pets.show', $shelter))
            ->assertForbidden();
        $this->get(route('shelter.pets.photo', $shelter))
            ->assertNotFound();

        $this->assertSame('Private Shelter profile', $shelter->fresh()->name);
        $this->assertSame($path, $shelter->fresh()->photo_path);
        $this->assertSame(
            [$path, $sentinelPath],
            Storage::disk('public')->allFiles('pet-profiles'),
        );
    }

    public function test_authorized_photo_is_private_inline_and_cross_owner_photo_is_non_disclosing(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $path = 'pet-profiles/'.str_repeat('b', 40).'.png';
        Storage::disk('public')->put($path, $this->imageBytes());
        $pet = $this->petFor(
            $owner,
            'Protected Clinic photo',
            PetProfile::DOMAIN_PETCARE,
            $path,
        );

        $response = $this->actingAs($owner)
            ->get(route('petcare.pets.photo', $pet));

        $response->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith(
            'inline;',
            (string) $response->headers->get('Content-Disposition'),
        );
        $this->assertSame($this->imageBytes(), $response->streamedContent());
        $this->actingAs($other)
            ->get(route('petcare.pets.photo', $pet))
            ->assertNotFound();
    }

    public function test_disabled_module_removes_routes_navigation_summary_activity_and_prevents_mutation(): void
    {
        $owner = User::factory()->create();
        $pet = $this->petFor($owner, 'Disabled Clinic profile');
        ModuleInstallation::query()
            ->where('sub_core_key', 'pet-care-clinic')
            ->where('module_key', 'pet-profiles')
            ->update([
                'enabled' => false,
                'disabled_at' => now(),
                'updated_at' => now(),
            ]);
        $this->actingAs($owner);

        $navigation = app(ModuleNavigationProvider::class)
            ->forSubCore($owner, 'pet-care-clinic');
        $this->assertNotContains(
            'pet-care-clinic:pet-profiles',
            array_column($navigation, 'instanceKey'),
        );
        $summaryProvider = app(ModuleRegistry::class)
            ->instance('pet-care-clinic', 'pet-profiles')
            ->summaryProviderClass();
        $this->assertNotNull($summaryProvider);
        $this->assertFalse(collect(app(ModuleDashboardSummaryService::class)
            ->forUser($owner))
            ->contains('instanceKey', 'pet-care-clinic:pet-profiles'));

        $this->get(route('petcare.index'))
            ->assertOk()
            ->assertDontSee('data-module-instance="pet-care-clinic:pet-profiles"', false)
            ->assertDontSee($pet->name);
        $this->get(route('petcare.pets.index'))->assertNotFound();
        $this->get(route('petcare.pets.show', $pet))->assertNotFound();
        $this->get(route('petcare.pets.photo', $pet))->assertNotFound();
        $this->put(
            route('petcare.pets.update', $pet),
            $this->validUpdate('Disabled module mutation'),
        )->assertNotFound();
        $this->post(route('petcare.pets.store'), [
            'name' => 'Disabled module creation',
            'sex' => 'unknown',
            'neutering_status' => 'unknown',
        ])->assertNotFound();

        $this->assertSame('Disabled Clinic profile', $pet->fresh()->name);
        $this->assertDatabaseMissing('pet_profiles', [
            'name' => 'Disabled module creation',
        ]);
    }

    public function test_service_selection_gates_pet_profile_routes_before_mutation(): void
    {
        $owner = User::factory()->create();
        $pet = $this->petFor($owner, 'Service-gated Clinic profile');
        $owner->serviceSelections()
            ->where('sub_core_key', 'pet-care-clinic')
            ->delete();

        $this->actingAs($owner->fresh())
            ->get(route('petcare.pets.index'))
            ->assertRedirect(route('profile.edit'));
        $this->put(
            route('petcare.pets.update', $pet),
            $this->validUpdate('Service gate mutation'),
        )->assertRedirect(route('profile.edit'));
        $this->post(route('petcare.pets.store'), [
            'name' => 'Service gate creation',
            'sex' => 'unknown',
            'neutering_status' => 'unknown',
        ])->assertRedirect(route('profile.edit'));

        $this->assertSame('Service-gated Clinic profile', $pet->fresh()->name);
        $this->assertDatabaseMissing('pet_profiles', [
            'name' => 'Service gate creation',
        ]);
    }

    private function petFor(
        User $owner,
        string $name,
        string $domain = PetProfile::DOMAIN_PETCARE,
        ?string $photoPath = null,
    ): PetProfile {
        return PetProfile::query()->create([
            'user_id' => $owner->id,
            'service_domain' => $domain,
            'name' => $name,
            'species' => 'cat',
            'sex' => 'unknown',
            'neutering_status' => 'unknown',
            'photo_path' => $photoPath,
        ]);
    }

    /** @return array<string, mixed> */
    private function validUpdate(string $name): array
    {
        return [
            'name' => $name,
            'species' => 'cat',
            'sex' => 'unknown',
            'neutering_status' => 'unknown',
        ];
    }

    /** @param array<int, string> $permissionNames */
    private function removeRolePermissions(
        string $roleName,
        array $permissionNames,
    ): void {
        $role = Role::query()
            ->where('guard_name', 'web')
            ->where('name', $roleName)
            ->firstOrFail();
        $permissionIds = Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', $permissionNames)
            ->pluck('id');
        $this->assertCount(count($permissionNames), $permissionIds);
        $role->permissions()->detach($permissionIds);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function image(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $this->imageBytes());
    }

    private function imageBytes(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );
    }
}
