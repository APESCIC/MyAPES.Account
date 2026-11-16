<?php

namespace Tests\Feature;

use App\Core\Accounts\StaffProfile;
use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminProfileManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_user_index_separates_public_and_staff_accounts(): void
    {
        $administrator = $this->administrator();
        $public = User::factory()->create([
            'name' => 'Public Listed User',
            'email' => 'public.listed@example.com',
        ]);
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create([
                'name' => 'Staff Listed User',
                'email' => 'staff.listed@example.com',
            ]);

        $this->actingAs($administrator)
            ->get(route('admin.users.index', ['account_type' => 'public']))
            ->assertOk()
            ->assertSeeText('Public Listed User')
            ->assertDontSeeText('Staff Listed User')
            ->assertSee('Public users')
            ->assertSee('Staff');

        $this->actingAs($administrator)
            ->get(route('admin.users.index', ['account_type' => 'staff']))
            ->assertOk()
            ->assertSeeText('Staff Listed User')
            ->assertDontSeeText('Public Listed User');
    }

    public function test_admin_can_update_a_public_user_profile(): void
    {
        $administrator = $this->administrator();
        $public = User::factory()->create([
            'onboarding_completed_at' => now(),
        ]);

        $this->actingAs($administrator)
            ->get(route('admin.users.show', ['user' => $public, 'tab' => 'details']))
            ->assertOk()
            ->assertSee('name="address_line_1"', false)
            ->assertDontSee('name="job_title"', false);

        $this->actingAs($administrator)
            ->put(route('admin.users.profile.update', $public), [
                'preferred_name' => 'Managed Public',
                'address_line_1' => '10 Admin Street',
                'town_city' => 'London',
                'postcode' => 'SW1A 1AA',
                'mobile_number' => '+447400111222',
                'services' => ['apes-cic'],
                'contact_preferences_confirmed' => '1',
            ])
            ->assertRedirect(route('admin.users.show', $public));

        $public->refresh();
        $this->assertSame('Managed Public', $public->profile->preferred_name);
        $this->assertSame('10 Admin Street', $public->profile->address_line_1);
        $this->assertSame(['apes-cic'], $public->serviceSelections()->pluck('sub_core_key')->all());
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'profile.updated',
            'user_id' => $administrator->id,
        ]);
    }

    public function test_admin_can_update_a_staff_profile_without_changing_directory_identity(): void
    {
        $administrator = $this->administrator();
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create([
                'name' => 'Directory Staff',
                'email' => 'staff.managed@example.com',
                'ldap_groups' => ['myapesaccount.staff'],
            ]);

        $this->actingAs($administrator)
            ->get(route('admin.users.show', ['user' => $staff, 'tab' => 'details']))
            ->assertOk()
            ->assertSeeText('Directory Staff')
            ->assertSeeText('myapesaccount.staff')
            ->assertSee('name="job_title"', false)
            ->assertDontSee('name="address_line_1"', false);

        $this->actingAs($administrator)
            ->put(route('admin.users.staff-profile.update', $staff), [
                'name' => 'Forged',
                'email' => 'forged@example.com',
                'job_title' => 'Clinic nurse',
                'team' => StaffProfile::TEAM_PET_CARE_CLINIC,
                'work_phone' => '+447400333444',
            ])
            ->assertRedirect(route('admin.users.show', $staff));

        $staff->refresh();
        $this->assertSame('Directory Staff', $staff->name);
        $this->assertSame('staff.managed@example.com', $staff->email);
        $this->assertSame('Clinic nurse', $staff->staffProfile->job_title);
        $this->assertSame(StaffProfile::TEAM_PET_CARE_CLINIC, $staff->staffProfile->team);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'staff_profile.updated',
            'user_id' => $administrator->id,
        ]);
    }

    public function test_admin_cannot_use_the_wrong_profile_editor_for_account_type(): void
    {
        $administrator = $this->administrator();
        $public = User::factory()->create();
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();

        $this->actingAs($administrator)
            ->put(route('admin.users.staff-profile.update', $public), [
                'job_title' => 'Should fail',
            ])
            ->assertForbidden();

        $this->actingAs($administrator)
            ->put(route('admin.users.profile.update', $staff), [
                'preferred_name' => 'Should fail',
                'address_line_1' => '1 Test Street',
                'town_city' => 'London',
                'postcode' => 'SW1A 1AA',
                'mobile_number' => '+447400123456',
                'services' => ['apes-cic'],
                'contact_preferences_confirmed' => '1',
            ])
            ->assertForbidden();
    }

    private function administrator(): User
    {
        return User::factory()
            ->accessLevel(User::ROLE_ADMIN)
            ->create()
            ->refresh();
    }
}
