<?php

namespace Tests\Feature;

use App\Contracts\ModuleAggregateSummaryProvider;
use App\Contracts\ModuleRegistry;
use App\Core\Accounts\Permission;
use App\Core\Accounts\Role;
use App\Core\Accounts\User;
use App\Core\Extensions\Models\ModuleInstallation;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Plugins\PetProfiles\Models\PetProfile;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ShelterPetProfileWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
    }

    public function test_shelter_owner_visibility_requires_exact_view_own_permission(): void
    {
        $owner = User::factory()->create();
        $pet = $this->shelterPetFor($owner, 'Revoked Shelter profile');
        $this->removeRolePermission(
            AuthorizationProfile::ROLE_SERVICE_USER,
            'shelter-rescue.pet-profiles.view-own',
        );
        $owner = $owner->fresh();
        $this->actingAs($owner);

        $this->assertSame([], PetProfile::query()
            ->where('service_domain', PetProfile::DOMAIN_SHELTER)
            ->visibleTo($owner, PetProfile::DOMAIN_SHELTER)
            ->pluck('id')
            ->all());

        $this->get(route('shelter.pets.index'))
            ->assertForbidden();
        $this->get(route('shelter.pets.show', $pet))
            ->assertForbidden();
    }

    public function test_shelter_case_pet_choices_do_not_fall_back_to_legacy_owner_visibility(): void
    {
        $owner = User::factory()->create();
        $this->shelterPetFor($owner, 'Forbidden Shelter case choice');
        $this->removeRolePermission(
            AuthorizationProfile::ROLE_SERVICE_USER,
            'shelter-rescue.pet-profiles.view-own',
        );

        $this->actingAs($owner->fresh())
            ->get(route('shelter.cases.index'))
            ->assertOk()
            ->assertDontSee('Forbidden Shelter case choice');
    }

    public function test_shelter_creation_requires_exact_create_and_hides_the_form_without_it(): void
    {
        $owner = User::factory()->create();
        $this->removeRolePermission(
            AuthorizationProfile::ROLE_SERVICE_USER,
            'shelter-rescue.pet-profiles.create',
        );

        $this->actingAs($owner->fresh())
            ->get(route('shelter.pets.index'))
            ->assertOk()
            ->assertDontSee('action="'.route('shelter.pets.store').'"', false);
        $this->post(route('shelter.pets.store'), [
            'name' => 'Forbidden Shelter profile',
            'sex' => 'unknown',
            'neutering_status' => 'unknown',
        ])->assertForbidden();
        $this->assertDatabaseMissing('pet_profiles', [
            'name' => 'Forbidden Shelter profile',
        ]);
    }

    public function test_shelter_owner_update_requires_exact_update_own_and_hides_the_form_without_it(): void
    {
        $owner = User::factory()->create();
        $pet = $this->shelterPetFor($owner, 'Shelter update boundary');
        $this->removeRolePermission(
            AuthorizationProfile::ROLE_SERVICE_USER,
            'shelter-rescue.pet-profiles.update-own',
        );

        $this->actingAs($owner->fresh())
            ->get(route('shelter.pets.show', $pet))
            ->assertOk()
            ->assertDontSee('action="'.route('shelter.pets.update', $pet).'"', false);
        $this->put(route('shelter.pets.update', $pet), [
            'name' => 'Unauthorized replacement name',
            'sex' => 'unknown',
            'neutering_status' => 'unknown',
        ])->assertForbidden();
        $this->assertSame('Shelter update boundary', $pet->fresh()->name);
    }

    public function test_authorized_shelter_photo_is_streamed_inline_with_private_headers(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create();
        $path = 'pet-profiles/'.str_repeat('a', 40).'.png';
        Storage::disk('public')->put(
            $path,
            base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
                true,
            ),
        );
        $pet = $this->shelterPetFor($owner, 'Protected Shelter photo');
        $pet->update(['photo_path' => $path]);

        $response = $this->actingAs($owner)
            ->get(route('shelter.pets.photo', $pet));

        $response->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith(
            'inline;',
            (string) $response->headers->get('Content-Disposition'),
        );
        $this->assertSame(Storage::disk('public')->get($path), $response->streamedContent());
    }

    public function test_pet_profile_views_use_authenticated_photo_routes(): void
    {
        $owner = User::factory()->create();
        $path = 'pet-profiles/'.str_repeat('b', 40).'.webp';
        $shelterPet = $this->shelterPetFor($owner, 'Shelter routed photo');
        $shelterPet->update(['photo_path' => $path]);
        $carePet = PetProfile::query()->create([
            'user_id' => $owner->id,
            'service_domain' => PetProfile::DOMAIN_PETCARE,
            'name' => 'Pet Care routed photo',
            'species' => 'cat',
            'sex' => 'unknown',
            'neutering_status' => 'unknown',
            'photo_path' => $path,
        ]);

        $this->actingAs($owner)
            ->get(route('shelter.pets.show', $shelterPet))
            ->assertOk()
            ->assertSee(route('shelter.pets.photo', $shelterPet), false)
            ->assertDontSee(asset('storage/'.$path), false);
        $this->get(route('petcare.pets.show', $carePet))
            ->assertOk()
            ->assertSee(route('petcare.pets.photo', $carePet), false)
            ->assertDontSee(asset('storage/'.$path), false);
    }

    public function test_pet_profile_summaries_do_not_fall_back_after_namespaced_view_permissions_are_removed(): void
    {
        $owner = User::factory()->create();
        $this->shelterPetFor($owner, 'Shelter summary profile');
        PetProfile::query()->create([
            'user_id' => $owner->id,
            'service_domain' => PetProfile::DOMAIN_PETCARE,
            'name' => 'Pet Care summary profile',
            'species' => 'cat',
            'sex' => 'unknown',
            'neutering_status' => 'unknown',
        ]);
        $this->removeRolePermission(
            AuthorizationProfile::ROLE_SERVICE_USER,
            'shelter-rescue.pet-profiles.view-own',
        );
        $this->removeRolePermission(
            AuthorizationProfile::ROLE_SERVICE_USER,
            'pet-care-clinic.pet-profiles.view-own',
        );
        $owner = $owner->fresh();
        $this->actingAs($owner);
        $registry = app(ModuleRegistry::class);

        /** @var ModuleAggregateSummaryProvider $shelterProvider */
        $shelterProvider = app(
            $registry->instance('shelter-rescue', 'pet-profiles')
                ->summaryProviderClass(),
        );
        /** @var ModuleAggregateSummaryProvider $petCareProvider */
        $petCareProvider = app(
            $registry->instance('pet-care-clinic', 'pet-profiles')
                ->summaryProviderClass(),
        );

        $this->assertSame(0, $shelterProvider->summarize(
            $registry->instance('shelter-rescue', 'pet-profiles'),
            $owner,
        )->total);
        $this->assertSame(0, $petCareProvider->summarize(
            $registry->instance('pet-care-clinic', 'pet-profiles'),
            $owner,
        )->total);
    }

    public function test_shelter_staff_access_requires_the_exact_view_all_namespace(): void
    {
        $owner = User::factory()->create();
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $administrator = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();
        $first = $this->shelterPetFor($owner, 'First exact staff profile');
        $second = $this->shelterPetFor(
            User::factory()->create(),
            'Second exact staff profile',
        );
        $this->removeRolePermission(
            AuthorizationProfile::ROLE_STAFF,
            'shelter-rescue.pet-profiles.view-all',
        );

        $this->actingAs($staff->fresh())
            ->get(route('shelter.pets.index'))
            ->assertForbidden();
        $this->actingAs($administrator)
            ->get(route('shelter.pets.index'))
            ->assertOk()
            ->assertSee($first->name)
            ->assertSee($second->name);
    }

    public function test_photo_failures_are_non_disclosing_across_owner_domain_path_and_file_boundaries(): void
    {
        Storage::fake('public');
        $privateBytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        ).'PRIVATE_PHOTO_BYTES';
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $validPath = 'pet-profiles/'.str_repeat('c', 40).'.png';
        Storage::disk('public')->put($validPath, $privateBytes);
        $pet = $this->shelterPetFor($owner, 'Private photo boundary');
        $pet->update(['photo_path' => $validPath]);
        $carePet = PetProfile::query()->create([
            'user_id' => $owner->id,
            'service_domain' => PetProfile::DOMAIN_PETCARE,
            'name' => 'Shared Pet Care photo',
            'species' => 'cat',
            'sex' => 'unknown',
            'neutering_status' => 'unknown',
            'photo_path' => $validPath,
        ]);
        $malformed = $this->shelterPetFor($owner, 'Malformed photo path');
        $malformed->update(['photo_path' => 'pet-profiles/../private.png']);
        $missingFile = $this->shelterPetFor($owner, 'Missing photo file');
        $missingFile->update([
            'photo_path' => 'pet-profiles/'.str_repeat('d', 40).'.png',
        ]);
        $absentPath = $this->shelterPetFor($owner, 'Absent photo path');

        $responses = [
            'wrong owner' => $this->actingAs($other)->get(route('shelter.pets.photo', $pet)),
            'malformed path' => $this->actingAs($owner)->get(route('shelter.pets.photo', $malformed)),
            'missing file' => $this->get(route('shelter.pets.photo', $missingFile)),
            'absent path' => $this->get(route('shelter.pets.photo', $absentPath)),
            'direct storage' => $this->get('/storage/'.$validPath),
        ];

        foreach ($responses as $boundary => $response) {
            $this->assertSame(404, $response->getStatusCode(), $boundary);
            $this->assertStringNotContainsString(
                'PRIVATE_PHOTO_BYTES',
                $response->getContent(),
            );
        }

        // Shared pets (#482): the owner's Pet Care pet is also theirs in Shelter.
        $this->actingAs($owner)->get(route('shelter.pets.photo', $carePet))->assertOk();
    }

    public function test_pet_care_photo_route_preserves_legacy_owner_authorization(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create();
        $path = 'pet-profiles/'.str_repeat('e', 40).'.png';
        Storage::disk('public')->put(
            $path,
            base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
                true,
            ),
        );
        $pet = PetProfile::query()->create([
            'user_id' => $owner->id,
            'service_domain' => PetProfile::DOMAIN_PETCARE,
            'name' => 'Compatible Pet Care photo',
            'species' => 'cat',
            'sex' => 'unknown',
            'neutering_status' => 'unknown',
            'photo_path' => $path,
        ]);

        $this->actingAs($owner)
            ->get(route('petcare.pets.photo', $pet))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_shelter_photo_route_is_unavailable_when_its_module_is_disabled(): void
    {
        $owner = User::factory()->create();
        $pet = $this->shelterPetFor($owner, 'Disabled module photo');
        ModuleInstallation::query()
            ->where('sub_core_key', 'shelter-rescue')
            ->where('module_key', 'pet-profiles')
            ->update(['enabled' => false, 'disabled_at' => now()]);

        $this->actingAs($owner)
            ->get(route('shelter.pets.photo', $pet))
            ->assertNotFound();
    }

    public function test_shelter_photo_replacement_preserves_record_identity_and_cleans_only_the_old_file(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create();
        $oldPath = 'pet-profiles/'.str_repeat('f', 40).'.png';
        Storage::disk('public')->put(
            $oldPath,
            base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
                true,
            ),
        );
        $pet = $this->shelterPetFor($owner, 'Upload preservation profile');
        $pet->update(['photo_path' => $oldPath]);
        $replacement = UploadedFile::fake()->createWithContent(
            'replacement.png',
            base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
                true,
            ),
        );

        $this->actingAs($owner)
            ->put(route('shelter.pets.update', $pet), [
                'name' => 'Upload preservation profile',
                'sex' => 'unknown',
                'neutering_status' => 'unknown',
                'photo' => $replacement,
            ])->assertRedirect(route('shelter.pets.show', $pet));

        $pet->refresh();
        $this->assertSame($owner->id, $pet->user_id);
        $this->assertSame(PetProfile::DOMAIN_SHELTER, $pet->service_domain);
        $this->assertMatchesRegularExpression(
            '/\Apet-profiles\/[A-Za-z0-9]{40}\.png\z/',
            (string) $pet->photo_path,
        );
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($pet->photo_path);

        $preservedPath = $pet->photo_path;
        $this->put(route('shelter.pets.update', $pet), [
            'name' => 'Upload preservation profile renamed',
            'sex' => 'unknown',
            'neutering_status' => 'unknown',
        ])->assertRedirect(route('shelter.pets.show', $pet));
        $this->assertSame($preservedPath, $pet->fresh()->photo_path);
        Storage::disk('public')->assertExists($preservedPath);
    }

    private function shelterPetFor(User $owner, string $name): PetProfile
    {
        return PetProfile::query()->create([
            'user_id' => $owner->id,
            'service_domain' => PetProfile::DOMAIN_SHELTER,
            'name' => $name,
            'species' => 'dog',
            'sex' => 'unknown',
            'neutering_status' => 'unknown',
        ]);
    }

    private function removeRolePermission(string $roleName, string $permissionName): void
    {
        $role = Role::query()
            ->where('guard_name', 'web')
            ->where('name', $roleName)
            ->firstOrFail();
        $permission = Permission::query()
            ->where('guard_name', 'web')
            ->where('name', $permissionName)
            ->firstOrFail();
        $role->permissions()->detach($permission->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
