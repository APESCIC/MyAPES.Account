<?php

namespace Tests\Feature;

use App\Contracts\ModuleNavigationProvider;
use App\Core\Accounts\User;
use App\Notifications\Auth\VerifyEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Plugins\Tickets\Models\SupportTicket;
use Tests\TestCase;

class PublicAccountLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_an_unverified_normalized_account_with_selected_services(): void
    {
        Notification::fake();

        $this->travelTo('2026-09-12 17:00:00');

        $response = $this->post(route('public.register.submit'), [
            'first_name' => 'Public',
            'last_name' => 'Person',
            'date_of_birth' => '14/03/1990',
            'username' => 'Public.Person',
            'email' => 'PUBLIC@EXAMPLE.COM',
            'password' => 'Correct-horse-42',
            'password_confirmation' => 'Correct-horse-42',
            'services' => ['apes-cic', 'shelter-rescue'],
            'registration_consent' => '1',
        ]);

        $response->assertRedirect(route('verification.notice'));
        $user = User::query()->sole();
        $this->assertSame('public.person', $user->username);
        $this->assertSame('public@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
        $this->assertSame(
            '2026-09-12 17:00:00',
            $user->registration_consented_at?->format('Y-m-d H:i:s'),
        );
        $this->assertSame(
            ['apes-cic', 'shelter-rescue'],
            $user->serviceSelections()->orderBy('sub_core_key')->pluck('sub_core_key')->all(),
        );
        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    public function test_register_page_requires_visible_terms_and_privacy_consent(): void
    {
        config(['myapes.consent.privacy_notice_url' => null]);

        $response = $this->get(route('public.register'));
        $html = $response->getContent();

        $response->assertOk()
            ->assertSeeText('Consent')
            ->assertSeeText('I have read and accept the')
            ->assertSeeText('terms of use')
            ->assertSeeText('privacy notice')
            ->assertSee('href="'.route('terms').'"', false)
            ->assertSee('href="'.route('privacy').'"', false);

        $this->assertMatchesRegularExpression(
            '/<input\b[^>]*\bname="registration_consent"[^>]*>/i',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '/<input\b[^>]*\bname="registration_consent"[^>]*\brequired\b/i',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '/<input\b[^>]*\btype="checkbox"[^>]*\bname="registration_consent"|\bname="registration_consent"[^>]*\btype="checkbox"/i',
            $html,
        );
    }

    public function test_registration_without_consent_does_not_create_an_account(): void
    {
        $response = $this->from(route('public.register'))->post(route('public.register.submit'), [
            'first_name' => 'Public',
            'last_name' => 'Person',
            'date_of_birth' => '14/03/1990',
            'username' => 'public.person',
            'email' => 'public@example.com',
            'password' => 'Correct-horse-42',
            'password_confirmation' => 'Correct-horse-42',
            'services' => ['apes-cic'],
        ]);

        $response->assertRedirect(route('public.register'));
        $response->assertSessionHasErrors([
            'registration_consent' => 'You must accept the terms of use and privacy notice to create an account.',
        ]);
        $this->assertSame(0, User::query()->count());
        $this->assertGuest();
    }

    public function test_login_accepts_username_or_email_case_insensitively(): void
    {
        $user = User::factory()->create([
            'username' => 'public.person',
            'email' => 'public@example.com',
            'password' => 'password',
            'onboarding_completed_at' => now(),
        ]);

        $this->post(route('public.login.submit'), [
            'login' => 'PUBLIC.PERSON',
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);

        $this->post(route('auth.logout'));

        $this->post(route('public.login.submit'), [
            'login' => 'PUBLIC@EXAMPLE.COM',
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_verification_and_onboarding_gates_protected_pages(): void
    {
        $unverified = User::factory()->unverified()->create([
            'onboarding_completed_at' => null,
        ]);

        $this->actingAs($unverified)
            ->get(route('dashboard'))
            ->assertRedirect(route('verification.notice'));

        $unverified->forceFill(['email_verified_at' => now()])->save();

        $this->actingAs($unverified)
            ->get(route('dashboard'))
            ->assertRedirect(route('onboarding.edit'));
    }

    public function test_signed_verification_marks_the_email_verified_and_rejects_tampering(): void
    {
        $user = User::factory()->unverified()->create([
            'onboarding_completed_at' => null,
        ]);
        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(30),
            ['id' => $user->id, 'hash' => sha1($user->email)],
        );

        $this->actingAs($user)->get($url)
            ->assertRedirect(route('onboarding.edit'));
        $this->assertTrue($user->refresh()->hasVerifiedEmail());

        $tampered = str_replace('signature=', 'signature=x', $url);
        $this->actingAs($user)->get($tampered)->assertForbidden();
    }

    public function test_onboarding_records_explicit_preferences_and_service_access_without_false_consent_events(): void
    {
        $user = User::factory()->create(['onboarding_completed_at' => null]);

        $this->actingAs($user)->put(route('onboarding.update'), [
            'first_name' => 'Public',
            'last_name' => 'Person',
            'date_of_birth' => '14/03/1990',
            'address_line_1' => '1 Test Street',
            'town_city' => 'London',
            'postcode' => 'SW1A 1AA',
            'mobile_number' => '+447400123456',
            'telegram_username' => '@public_person',
            'services' => ['apes-cic'],
            'contact_preferences_confirmed' => '1',
        ])->assertRedirect(route('dashboard'));

        $user->refresh();
        $this->assertNotNull($user->onboarding_completed_at);
        $this->assertSame('+447400123456', $user->profile->mobile_number);
        $this->assertSame('+447400123456', $user->profile->phone);
        $this->assertSame('public_person', $user->profile->telegram_username);
        $this->assertSame('+447400123456', $user->profile->effectiveWhatsappNumber());
        $this->assertNotNull($user->contactPreference->confirmed_at);
        $this->assertDatabaseCount('contact_consent_events', 0);
        $this->assertSame(['apes-cic'], $user->serviceSelections()->pluck('sub_core_key')->all());

        $this->actingAs($user)->get(route('apes-cic.index'))->assertOk();
        $this->actingAs($user)
            ->get(route('shelter.index'))
            ->assertRedirect(route('profile.edit'));
    }

    public function test_contact_grants_and_withdrawals_are_append_only_policy_versioned_events(): void
    {
        $user = User::factory()->create(['onboarding_completed_at' => now()]);
        $user->profile()->create([
            'first_name' => 'Public',
            'last_name' => 'Person',
            'date_of_birth' => '1990-03-14',
            'address_line_1' => '1 Test Street',
            'town_city' => 'London',
            'postcode' => 'SW1A 1AA',
            'mobile_number' => '+447400123456',
        ]);

        $this->actingAs($user)->put(route('account.privacy.contact-preferences.update'), [
            'channel' => 'email',
            'granted' => '1',
        ])->assertRedirect(route('account.privacy').'#email-opt-in');

        $this->actingAs($user)->put(route('account.privacy.contact-preferences.update'), [
            'channel' => 'email',
            'granted' => '0',
        ])->assertRedirect(route('account.privacy').'#email-opt-in');

        $events = $user->contactConsentEvents()->orderBy('id')->get();
        $this->assertCount(2, $events);
        $this->assertTrue($events[0]->granted);
        $this->assertFalse($events[1]->granted);
        $this->assertSame(config('myapes.consent.policy_version'), $events[0]->policy_version);
        $this->assertSame('account_privacy', $events[0]->source);
    }

    public function test_profile_links_to_the_configured_privacy_notice(): void
    {
        config(['myapes.consent.privacy_notice_url' => 'https://apes.example/privacy']);
        $user = User::factory()->create(['onboarding_completed_at' => now()]);

        $this->actingAs($user)
            ->get(route('account.profile'))
            ->assertOk()
            ->assertSee('href="https://apes.example/privacy"', false)
            ->assertSeeText('Read the privacy notice');
    }

    public function test_profile_falls_back_to_the_in_app_privacy_page(): void
    {
        config(['myapes.consent.privacy_notice_url' => null]);
        $user = User::factory()->create(['onboarding_completed_at' => now()]);

        $this->actingAs($user)
            ->get(route('account.profile'))
            ->assertOk()
            ->assertSee('href="'.route('privacy').'"', false)
            ->assertSeeText('Read the privacy notice');
    }

    public function test_register_page_honors_the_configured_privacy_notice(): void
    {
        config(['myapes.consent.privacy_notice_url' => 'https://apes.example/privacy']);

        $this->get(route('public.register'))
            ->assertOk()
            ->assertSee('href="https://apes.example/privacy"', false)
            ->assertSee('target="_blank"', false)
            ->assertSeeText('privacy notice');
    }

    public function test_service_selection_filters_navigation_and_public_permissions(): void
    {
        $user = User::factory()->create(['onboarding_completed_at' => now()]);
        $user->serviceSelections()->where('sub_core_key', '!=', 'apes-cic')->delete();
        $this->actingAs($user);

        $navigation = app(ModuleNavigationProvider::class)->forUser($user);

        $this->assertSame(['apes-cic'], array_map(
            static fn ($entry): string => $entry->subCore->key,
            $navigation,
        ));
        $this->assertTrue($user->can('apes-cic.tickets.view-own'));
        $this->assertFalse($user->can('shelter-rescue.pet-profiles.view-own'));
    }

    public function test_unselected_services_are_absent_from_dashboard_attention_items(): void
    {
        $user = User::factory()->create(['onboarding_completed_at' => now()]);
        $user->serviceSelections()->where('sub_core_key', 'apes-cic')->delete();
        SupportTicket::query()->create([
            'user_id' => $user->id,
            'service_area' => 'general',
            'subject' => 'Hidden unselected ticket',
            'priority' => 'medium',
            'status' => 'open',
            'description' => 'Should not be surfaced.',
        ]);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Hidden unselected ticket');
    }
}
