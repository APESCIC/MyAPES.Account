<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use App\Services\LocalPublicPasswordResetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ClientDetailsAndAccountStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_form_rejects_missing_mandatory_fields_and_invalid_dob(): void
    {
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();

        $this->actingAs($staff)
            ->from(route('clients.index'))
            ->post(route('clients.store'), [
                'first_name' => '',
                'last_name' => '',
                'date_of_birth' => '',
                'email' => null,
                'mobile_number' => '07000900123',
                'address_line_1' => '10 High Street',
                'town_city' => 'Birmingham',
                'postcode' => 'B1 1AA',
                'pin' => '1234',
                'pin_confirmation' => '1234',
                'staff_assisted_consent' => '1',
            ])
            ->assertRedirect(route('clients.index'))
            ->assertSessionHasErrors(['first_name', 'last_name', 'date_of_birth']);

        $this->actingAs($staff)
            ->from(route('clients.index'))
            ->post(route('clients.store'), $this->clientPayload([
                'date_of_birth' => now()->addDay()->format('d/m/Y'),
            ]))
            ->assertRedirect(route('clients.index'))
            ->assertSessionHasErrors('date_of_birth');

        $this->actingAs($staff)
            ->from(route('clients.index'))
            ->post(route('clients.store'), $this->clientPayload([
                'date_of_birth' => now()->subYears(121)->format('d/m/Y'),
            ]))
            ->assertRedirect(route('clients.index'))
            ->assertSessionHasErrors('date_of_birth');
    }

    public function test_staff_create_sets_profile_only_and_blocks_login_reset_and_shows_state(): void
    {
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();

        $this->actingAs($staff)
            ->post(route('clients.store'), $this->clientPayload())
            ->assertRedirect();

        $client = User::query()->where('email', 'sam.client@example.com')->firstOrFail();
        $this->assertSame(User::ACCOUNT_STATE_PROFILE_ONLY, $client->account_state);
        $this->assertSame('Sam', $client->profile->first_name);
        $this->assertSame('Client', $client->profile->last_name);
        $this->assertSame('1990-03-14', $client->profile->date_of_birth?->format('Y-m-d'));
        $this->assertSame('Sam Client', $client->name);

        $this->post(route('auth.logout'));

        $this->from(route('public.login'))
            ->post(route('public.login.submit'), [
                'login' => 'sam.client@example.com',
                'password' => 'anything-that-fails-or-matches',
            ])
            ->assertRedirect(route('public.login'));

        // Even with the real random password unknown, force a known hash and prove the state gate.
        $client->forceFill(['password' => Hash::make('password')])->save();

        $this->from(route('public.login'))
            ->post(route('public.login.submit'), [
                'login' => 'sam.client@example.com',
                'password' => 'password',
            ])
            ->assertRedirect(route('public.login'))
            ->assertSessionHasErrors('login');

        $this->from(route('password.request'))
            ->post(route('password.email'), ['email' => 'sam.client@example.com'])
            ->assertRedirect();

        $this->actingAs($staff)
            ->get(route('clients.show', $client))
            ->assertOk()
            ->assertSeeText('Profile only');
    }

    public function test_invited_client_becomes_active_after_set_password(): void
    {
        Notification::fake();
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();

        $this->actingAs($staff)
            ->post(route('clients.store'), $this->clientPayload([
                'create_myapes_account' => '1',
            ]))
            ->assertRedirect();

        $client = User::query()->where('email', 'sam.client@example.com')->firstOrFail();
        $this->assertSame(User::ACCOUNT_STATE_INVITED, $client->account_state);
        $this->assertTrue($client->must_change_password);

        app(LocalPublicPasswordResetService::class)
            ->changeOwnPassword($client, 'Correct-horse-99x!');

        $client->refresh();
        $this->assertSame(User::ACCOUNT_STATE_ACTIVE, $client->account_state);
        $this->assertFalse($client->must_change_password);
    }

    public function test_register_saves_identity_fields_to_the_profile(): void
    {
        $this->post(route('public.register.submit'), [
            'first_name' => 'Public',
            'last_name' => 'Person',
            'date_of_birth' => '14/03/1990',
            'username' => 'public.person',
            'email' => 'public.person@example.com',
            'password' => 'Correct-horse-42',
            'password_confirmation' => 'Correct-horse-42',
            'services' => ['apes-cic'],
            'registration_consent' => '1',
        ])->assertRedirect(route('verification.notice'));

        $user = User::query()->where('email', 'public.person@example.com')->firstOrFail();
        $this->assertSame('Public Person', $user->name);
        $this->assertSame(User::ACCOUNT_STATE_ACTIVE, $user->account_state);
        $this->assertSame('Public', $user->profile->first_name);
        $this->assertSame('Person', $user->profile->last_name);
        $this->assertSame('1990-03-14', $user->profile->date_of_birth?->format('Y-m-d'));
    }

    public function test_missing_details_filter_and_name_review_list(): void
    {
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();
        $complete = User::factory()->create(['name' => 'Complete Client']);
        $complete->profile()->create([
            'first_name' => 'Complete',
            'last_name' => 'Client',
            'date_of_birth' => '1990-03-14',
            'address_line_1' => '1 Road',
            'town_city' => 'London',
            'postcode' => 'E1 6AN',
            'mobile_number' => '+447700900001',
        ]);

        $incomplete = User::factory()->create(['name' => 'Incomplete Client']);
        $incomplete->profile()->create([
            'first_name' => 'Incomplete',
            'last_name' => null,
            'mobile_number' => '+447700900002',
        ]);

        $ambiguous = User::factory()->create(['name' => 'Madonna']);
        $ambiguous->profile()->updateOrCreate(
            ['user_id' => $ambiguous->id],
            ['first_name' => null, 'last_name' => null],
        );

        $this->actingAs($staff)
            ->get(route('clients.index', ['missing_details' => 1]))
            ->assertOk()
            ->assertSeeText('Incomplete Client')
            ->assertDontSeeText('Complete Client');

        $this->actingAs($staff)
            ->get(route('clients.show', $incomplete))
            ->assertOk()
            ->assertSee('data-client-missing-details', false);

        $this->actingAs($staff)
            ->get(route('clients.index', ['name_review' => 1]))
            ->assertOk()
            ->assertSeeText('Madonna');
    }

    public function test_account_state_report_counts_each_state(): void
    {
        User::factory()->create(['account_state' => User::ACCOUNT_STATE_ACTIVE]);
        User::factory()->create(['account_state' => User::ACCOUNT_STATE_PROFILE_ONLY]);
        User::factory()->create(['account_state' => User::ACCOUNT_STATE_INVITED]);

        $this->artisan('myapes:accounts:report-states')
            ->assertSuccessful()
            ->expectsTable(
                ['State', 'Count'],
                [
                    [User::ACCOUNT_STATE_PROFILE_ONLY, 1],
                    [User::ACCOUNT_STATE_INVITED, 1],
                    [User::ACCOUNT_STATE_ACTIVE, 1],
                ],
            );
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function clientPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Sam',
            'last_name' => 'Client',
            'date_of_birth' => '14/03/1990',
            'email' => 'sam.client@example.com',
            'mobile_number' => '07000900123',
            'landline_number' => null,
            'address_line_1' => '10 High Street',
            'address_line_2' => null,
            'town_city' => 'Birmingham',
            'county' => 'West Midlands',
            'postcode' => 'B1 1AA',
            'pin' => '1234',
            'pin_confirmation' => '1234',
            'staff_assisted_consent' => '1',
            'create_myapes_account' => '0',
        ], $overrides);
    }
}
