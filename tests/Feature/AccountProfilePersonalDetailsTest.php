<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountProfilePersonalDetailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_profile_page_shows_personal_details_form(): void
    {
        $user = $this->userWithProfile();

        $this->actingAs($user)
            ->get(route('account.profile'))
            ->assertOk()
            ->assertSee('id="profile-personal"', false)
            ->assertSee('name="first_name"', false)
            ->assertSee('name="date_of_birth"', false)
            ->assertSee(__('public.profile.edit.blade.save_profile'), false);
    }

    public function test_profile_update_rejects_missing_mandatory_identity_fields(): void
    {
        $user = $this->userWithProfile();

        $this->actingAs($user)
            ->from(route('account.profile'))
            ->put(route('profile.update'), [
                'preferred_name' => 'Public',
                'address_line_1' => '1 Test Street',
                'town_city' => 'London',
                'postcode' => 'SW1A 1AA',
                'mobile_number' => '+447400123456',
                'services' => ['apes-cic'],
            ])
            ->assertRedirect(route('account.profile'))
            ->assertSessionHasErrors(['first_name', 'last_name', 'date_of_birth']);
    }

    public function test_date_of_birth_change_requires_recent_step_up(): void
    {
        $user = $this->userWithProfile([
            'date_of_birth' => '1990-03-14',
        ]);

        $this->actingAs($user)
            ->from(route('account.profile'))
            ->put(route('profile.update'), $this->profilePayload([
                'date_of_birth' => '15/03/1990',
            ]))
            ->assertRedirect(route('password.confirm'));

        $user->refresh();
        $this->assertSame('1990-03-14', $user->profile->date_of_birth?->format('Y-m-d'));
    }

    public function test_date_of_birth_change_is_audited_after_step_up(): void
    {
        $user = $this->userWithProfile([
            'date_of_birth' => '1990-03-14',
        ]);

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => now()->unix()])
            ->from(route('account.profile'))
            ->put(route('profile.update'), $this->profilePayload([
                'date_of_birth' => '15/03/1990',
            ]))
            ->assertRedirect(route('account.profile'))
            ->assertSessionHas('status', __('flash.profile_updated'));

        $user->refresh();
        $this->assertSame('1990-03-15', $user->profile->date_of_birth?->format('Y-m-d'));

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'profile.date_of_birth_updated',
            'user_id' => $user->id,
        ]);
    }

    public function test_profile_update_without_dob_change_does_not_require_step_up(): void
    {
        $user = $this->userWithProfile([
            'date_of_birth' => '1990-03-14',
        ]);

        $this->actingAs($user)
            ->from(route('account.profile'))
            ->put(route('profile.update'), $this->profilePayload([
                'preferred_name' => 'Samuel',
            ]))
            ->assertRedirect(route('account.profile'))
            ->assertSessionHas('status', __('flash.profile_updated'));

        $this->assertSame('Samuel', $user->profile()->first()->refresh()->preferred_name);
    }

    /**
     * @param  array<string, mixed>  $profileOverrides
     */
    private function userWithProfile(array $profileOverrides = []): User
    {
        $user = User::factory()->create();

        $user->profile()->create(array_merge([
            'first_name' => 'Public',
            'last_name' => 'Person',
            'date_of_birth' => '1990-03-14',
            'address_line_1' => '1 Test Street',
            'town_city' => 'London',
            'postcode' => 'SW1A 1AA',
            'mobile_number' => '+447400123456',
        ], $profileOverrides));

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function profilePayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Public',
            'last_name' => 'Person',
            'date_of_birth' => '14/03/1990',
            'preferred_name' => 'Public',
            'address_line_1' => '1 Test Street',
            'town_city' => 'London',
            'postcode' => 'SW1A 1AA',
            'mobile_number' => '+447400123456',
            'services' => ['apes-cic'],
        ], $overrides);
    }
}
