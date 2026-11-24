<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileAccountEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_public_account_tab_shows_email_change_form(): void
    {
        $public = User::factory()->create([
            'email' => 'local.public.email@example.com',
            'password' => 'password',
        ]);

        $account = $this->actingAs($public)->get(route('account.show'));
        $html = $account->getContent();

        $account->assertOk()
            ->assertSeeText('Account email')
            ->assertSeeText('local.public.email@example.com')
            ->assertSeeText(__('auth.email_change.intro'))
            ->assertSeeText(__('auth.email_change.heading'))
            ->assertSee('id="account-email"', false)
            ->assertSee('id="change-email"', false)
            ->assertSee('name="email"', false)
            ->assertDontSeeText('Email cannot be changed here.');

        $this->assertDoesNotOfferImpersonation($html);
    }

    public function test_directory_public_profile_shows_readonly_email_without_password_form(): void
    {
        $directoryPublic = User::factory()
            ->directoryIdentity('directory-public-email-subject')
            ->create([
                'email' => 'directory.public.email@example.com',
                'password' => 'password',
            ]);

        $profile = $this->actingAs($directoryPublic)->get(route('account.show'));
        $html = $profile->getContent();

        $profile->assertOk()
            ->assertSeeText('Account email')
            ->assertSeeText('directory.public.email@example.com')
            ->assertSeeText(__('auth.email_change.directory_owned'))
            ->assertDontSee('id="change-email"', false)
            ->assertDontSee('name="current_password"', false)
            ->assertDontSee('name="password_confirmation"', false)
            ->assertDontSee('Change password')
            ->assertSeeText('This account uses organisation directory sign-in.');

        $this->assertAccountEmailIsReadOnly($html, 'directory.public.email@example.com');
        $this->assertDoesNotOfferImpersonation($html);
    }

    public function test_staff_profile_still_shows_readonly_directory_email(): void
    {
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->directoryIdentity('staff-directory-email-subject')
            ->create([
                'email' => 'staff.directory.email@example.com',
                'password' => 'password',
            ]);

        $profile = $this->actingAs($staff)->get(route('account.show'));
        $html = $profile->getContent();

        $profile->assertOk()
            ->assertSeeText('staff.directory.email@example.com')
            ->assertDontSee('name="current_password"', false)
            ->assertDontSee('Change password');

        $this->assertDoesNotMatchRegularExpression(
            '/<(input|select|textarea)[^>]*\b(name|id)="email"/i',
            $html,
        );
        $this->assertDoesNotOfferImpersonation($html);
    }

    public function test_profile_update_does_not_change_account_email(): void
    {
        $public = User::factory()->create([
            'email' => 'keep.this.email@example.com',
            'password' => 'password',
        ]);

        $this->actingAs($public)
            ->from(route('profile.edit'))
            ->put(route('profile.update'), [
                'preferred_name' => 'Public',
                'email' => 'forged.email@example.com',
                'address_line_1' => '1 Test Street',
                'town_city' => 'London',
                'postcode' => 'SW1A 1AA',
                'mobile_number' => '+447400123456',
                'services' => ['apes-cic'],
                'contact_preferences_confirmed' => '1',
            ])
            ->assertRedirect(route('profile.edit'));

        $public->refresh();
        $this->assertSame('keep.this.email@example.com', $public->email);
    }

    private function assertAccountEmailIsReadOnly(string $html, string $email): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/<(input|select|textarea)[^>]*\b(name|id)="email"/i',
            $html,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<(input|select|textarea)[^>]*type="email"/i',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '/<dd>\s*'.preg_quote($email, '/').'\s*<\/dd>/',
            $html,
        );
    }

    private function assertDoesNotOfferImpersonation(string $html): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/impersonat|log in as|sign in as/i',
            $html,
        );
    }
}
