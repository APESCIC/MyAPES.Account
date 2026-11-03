<?php

namespace Tests\Feature;

use App\Core\Accounts\StaffProfile;
use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StaffProfileWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_profile_form_keeps_current_fields_and_omits_staff_fields(): void
    {
        $user = User::factory()->create(['onboarding_completed_at' => now()]);

        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('name="address_line_1"', false)
            ->assertSee('name="services[]"', false)
            ->assertDontSee('name="job_title"', false)
            ->assertDontSee('name="work_phone"', false);
    }

    public function test_staff_profile_form_shows_directory_details_and_staff_fields_only(): void
    {
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create([
                'name' => 'Directory Staff',
                'email' => 'staff.directory@example.com',
                'ldap_groups' => ['myapesaccount.staff'],
            ]);
        $staff->staffProfile()->create([
            'job_title' => 'Coordinator',
            'team' => StaffProfile::TEAM_OPERATIONS,
        ]);

        $this->actingAs($staff)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSeeText('Directory Staff')
            ->assertSeeText('staff.directory@example.com')
            ->assertSeeText('myapesaccount.staff')
            ->assertSee('directory-group-list__chip', false)
            ->assertDontSee('myapesaccount.staff</code>,', false)
            ->assertSee('name="job_title"', false)
            ->assertSee('name="team"', false)
            ->assertSee('name="work_phone"', false)
            ->assertDontSee('name="address_line_1"', false)
            ->assertDontSee('name="services[]"', false)
            ->assertDontSee('name="support_needs"', false);
    }

    public function test_staff_profile_renders_many_directory_groups_as_chips(): void
    {
        $groups = [
            'board-of-directors',
            'department.developers',
            'department.animal.care',
            'myapesaccount.admin',
            'position.staff',
        ];

        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create([
                'ldap_groups' => $groups,
            ]);

        $response = $this->actingAs($staff)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('directory-group-list', false)
            ->assertDontSeeText('None recorded');

        foreach ($groups as $group) {
            $response->assertSeeText($group);
        }

        $response->assertDontSee('board-of-directors</code>,', false);
    }

    public function test_staff_can_update_staff_profile_without_changing_directory_identity(): void
    {
        Storage::fake('public');
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create([
                'name' => 'Directory Staff',
                'email' => 'staff.directory@example.com',
            ]);

        $this->actingAs($staff)
            ->put(route('profile.update'), [
                'name' => 'Forged Name',
                'email' => 'forged@example.com',
                'job_title' => 'Shelter lead',
                'team' => StaffProfile::TEAM_SHELTER_RESCUE,
                'work_phone' => '+447400999888',
                'photo' => UploadedFile::fake()->createWithContent(
                    'badge.png',
                    base64_decode(
                        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
                        true,
                    ),
                ),
                'address_line_1' => 'Should be ignored',
                'services' => ['apes-cic'],
            ])
            ->assertRedirect(route('profile.edit'));

        $staff->refresh();
        $this->assertSame('Directory Staff', $staff->name);
        $this->assertSame('staff.directory@example.com', $staff->email);
        $this->assertNull($staff->profile);
        $this->assertSame('Shelter lead', $staff->staffProfile->job_title);
        $this->assertSame(StaffProfile::TEAM_SHELTER_RESCUE, $staff->staffProfile->team);
        $this->assertSame('+447400999888', $staff->staffProfile->work_phone);
        $this->assertNotNull($staff->staffProfile->photo_path);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'staff_profile.updated',
            'user_id' => $staff->id,
        ]);
    }

    public function test_staff_profile_rejects_unknown_teams(): void
    {
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();

        $this->actingAs($staff)
            ->from(route('profile.edit'))
            ->put(route('profile.update'), [
                'job_title' => 'Volunteer',
                'team' => 'unknown-team',
            ])
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHasErrors('team');
    }
}
