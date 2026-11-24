<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Plugins\Cases\Models\ShelterCase;
use Tests\TestCase;

class AccountPrivacyPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_privacy_page_lists_contact_email_and_data_sections(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('account.privacy'))
            ->assertOk()
            ->assertSee(__('account.settings.tabs_label'), false)
            ->assertSee('id="contact-preferences"', false)
            ->assertSee('id="email-opt-in"', false)
            ->assertSee('id="notifications"', false)
            ->assertSee('id="cookies"', false)
            ->assertSee('id="data-export"', false)
            ->assertSee('id="deletion-request"', false)
            ->assertSee(route('account.privacy.data-export'), false);
    }

    public function test_staff_privacy_page_includes_notifications_without_personal_address_fields(): void
    {
        $staff = User::factory()
            ->protectedRole('staff')
            ->create(['onboarding_completed_at' => now()]);

        $this->actingAs($staff)
            ->get(route('account.privacy'))
            ->assertOk()
            ->assertSee('id="notifications"', false)
            ->assertDontSee('name="address_line_1"', false);
    }

    public function test_contact_toggle_records_policy_versioned_consent_event(): void
    {
        config(['myapes.consent.policy_version' => '2026-11-test']);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('account.privacy'))
            ->put(route('account.privacy.contact-preferences.update'), [
                'channel' => 'email',
                'granted' => '1',
            ])
            ->assertRedirect(route('account.privacy').'#email-opt-in');

        $user->refresh();
        $this->assertTrue((bool) $user->contactPreference?->email);
        $this->assertSame('2026-11-test', $user->contactPreference?->policy_version);

        $event = $user->contactConsentEvents()->sole();
        $this->assertSame('email', $event->channel);
        $this->assertTrue($event->granted);
        $this->assertSame('2026-11-test', $event->policy_version);
        $this->assertSame('account_privacy', $event->source);
    }

    public function test_data_export_includes_only_the_signed_in_person(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com']);
        $user->profile()->create([
            'first_name' => 'Owner',
            'last_name' => 'Person',
            'date_of_birth' => '1990-01-01',
            'address_line_1' => '1 Road',
            'town_city' => 'Town',
            'postcode' => 'SW1A 1AA',
            'mobile_number' => '+447400123456',
        ]);

        $other = User::factory()->create(['email' => 'other@example.com']);

        $response = $this->actingAs($user)->get(route('account.privacy.data-export'));
        $response->assertOk();
        $response->assertHeader('content-disposition');

        $json = json_decode($response->streamedContent(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('owner@example.com', $json['account']['email']);
        $this->assertSame('Owner', $json['profile']['first_name']);
        $this->assertStringNotContainsString('other@example.com', $response->streamedContent());
        $this->assertSame($other->id !== $json['account']['id'], true);
    }

    public function test_deletion_request_opens_apes_cic_case(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->from(route('account.privacy'))
            ->post(route('account.privacy.deletion-request'), [
                'confirmed' => '1',
            ]);

        $case = ShelterCase::query()
            ->where('user_id', $user->id)
            ->where('sub_category', 'deletion')
            ->sole();

        $response->assertRedirect(route('apes-cic.cases.show', $case));
        $this->assertSame('data_access_request', $case->category);
        $this->assertSame('myapes_account', $case->affected_website_key);
    }
}