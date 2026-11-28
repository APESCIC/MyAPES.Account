<?php

namespace Tests\Feature\Admin;

use App\Core\Accounts\User;
use App\Core\Accounts\UserProfile;
use App\Services\AuthorizationProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPublicProfilesTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_profiles_list_only_profile_only_records_with_dob_and_postcode(): void
    {
        $admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();
        $profileOnly = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create([
            'name' => 'Profile Only Person',
            'account_state' => User::ACCOUNT_STATE_PROFILE_ONLY,
        ]);
        UserProfile::query()->updateOrCreate(
            ['user_id' => $profileOnly->id],
            [
                'date_of_birth' => '1990-05-15',
                'postcode' => 'SW1A 1AA',
            ],
        );
        User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create([
            'name' => 'Full Account Person',
            'account_state' => User::ACCOUNT_STATE_ACTIVE,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.public-profiles.index'))
            ->assertOk()
            ->assertSee('Profile Only Person', false)
            ->assertSee('15/05/1990', false)
            ->assertSee('SW1A 1AA', false)
            ->assertDontSee('Full Account Person')
            ->assertSee('href="'.route('admin.public-profiles.show', $profileOnly).'"', false);
    }

    public function test_public_profile_show_opens_shared_hub_and_rejects_non_profile_only(): void
    {
        $admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();
        $profileOnly = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create([
            'account_state' => User::ACCOUNT_STATE_PROFILE_ONLY,
        ]);
        $full = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create([
            'account_state' => User::ACCOUNT_STATE_ACTIVE,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.public-profiles.show', $profileOnly))
            ->assertOk();

        $this->actingAs($admin)
            ->get(route('admin.public-profiles.show', $full))
            ->assertNotFound();
    }
}
