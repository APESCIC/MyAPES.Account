<?php

namespace Tests\Feature;

use App\Core\Accounts\AuditLog;
use App\Core\Accounts\User;
use App\Notifications\Auth\WelcomeSignInDetailsNotification;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use App\Services\TemporaryPasswordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Create MyAPES account from client profile (#937–#944).
 */
class CreateMyApesAccountFromProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
    }

    public function test_access_tab_creates_account_from_profile_only_client(): void
    {
        Notification::fake();
        $admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();
        $client = $this->profileOnlyClient('joe.squash@example.com', 'Joe', 'Squash');

        $this->actingAs($admin)
            ->post(route('admin.users.create-account', $client), [
                'confirm_create_account' => '1',
            ])
            ->assertRedirect();

        $client->refresh();
        $this->assertSame(User::ACCOUNT_STATE_INVITED, $client->account_state);
        $this->assertTrue($client->must_change_password);
        $this->assertSame('joe.squash', $client->username);
        Notification::assertSentTo($client, WelcomeSignInDetailsNotification::class);
        $this->assertNotNull(AuditLog::query()->where('event', 'Account.created_from_profile')->first());
        $this->assertNotNull(AuditLog::query()->where('event', 'temporary_password.issued')->first());
    }

    public function test_create_account_hidden_when_already_active(): void
    {
        $admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();
        $client = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create([
            'account_state' => User::ACCOUNT_STATE_ACTIVE,
            'email' => 'active.client@example.com',
        ]);
        $client->profile()->create([
            'first_name' => 'Active',
            'last_name' => 'Client',
            'mobile_number' => '+447700900222',
            'phone' => '+447700900222',
            'address_line_1' => '1 Road',
            'town_city' => 'London',
            'postcode' => 'E1 6AN',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.public-accounts.show', ['user' => $client, 'tab' => 'account_management']))
            ->assertOk()
            ->assertDontSee('data-hub-create-account', false);
    }

    public function test_forced_password_change_and_expiry_and_resend(): void
    {
        Notification::fake();
        $admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();
        $client = $this->profileOnlyClient('forced.user@example.com', 'Forced', 'User');

        $this->actingAs($admin)->post(route('admin.users.create-account', $client), [
            'confirm_create_account' => '1',
        ])->assertRedirect();

        $client->refresh();
        $plain = null;
        Notification::assertSentTo($client, WelcomeSignInDetailsNotification::class, function (WelcomeSignInDetailsNotification $mail) use (&$plain): bool {
            $plain = $mail->temporaryPassword;

            return strlen($plain) >= TemporaryPasswordService::MIN_LENGTH;
        });
        $this->assertNotNull($plain);

        $this->post(route('auth.logout'));
        $this->assertGuest();

        $this->post(route('public.login.submit'), [
            'login' => $client->fresh()->username,
            'password' => $plain,
        ])->assertRedirect();

        $authed = auth()->user();
        $this->assertNotNull($authed);
        $this->assertTrue($authed->mustChangePassword(), 'User should require password change after temp login');
        $this->assertSame($client->id, $authed->id);

        $this->get(route('dashboard'))
            ->assertRedirect(route('password.forced-change.edit'));

        $this->get(route('password.forced-change.edit'))
            ->assertOk()
            ->assertSee('data-forced-password-change', false)
            ->assertSee('data-strength-status', false);

        $this->post(route('password.forced-change.update'), [
            'password' => 'Correct-Horse-Battery-99!',
            'password_confirmation' => 'Correct-Horse-Battery-99!',
        ])->assertRedirect(route('dashboard'));

        $client->refresh();
        $this->assertFalse($client->must_change_password);
        $this->assertSame(User::ACCOUNT_STATE_ACTIVE, $client->account_state);
        $this->assertTrue(Hash::check('Correct-Horse-Battery-99!', $client->password));
        $this->assertNotNull(AuditLog::query()->where('event', 'temporary_password.used')->first());

        $this->post(route('auth.logout'));
        $this->post(route('public.login.submit'), [
            'login' => 'forced.user',
            'password' => $plain,
        ])->assertSessionHasErrors();

        $client->forceFill([
            'must_change_password' => true,
            'temporary_password_expires_at' => now()->subHour(),
            'password' => 'ExpiredTempPass1!',
            'account_state' => User::ACCOUNT_STATE_INVITED,
        ])->save();

        $this->post(route('public.login.submit'), [
            'login' => 'forced.user',
            'password' => 'ExpiredTempPass1!',
        ])->assertSessionHasErrors('login');

        $this->assertNotNull(AuditLog::query()->where('event', 'temporary_password.expired')->first());

        Notification::fake();
        $this->actingAs($admin)->post(route('admin.users.resend-sign-in', $client), [
            'confirm_resend' => '1',
        ])->assertRedirect();

        $this->assertNotNull(AuditLog::query()->where('event', 'temporary_password.resent')->first());
        Notification::assertSentTo($client->fresh(), WelcomeSignInDetailsNotification::class);
    }

    public function test_welcome_email_preview_uses_placeholder_password(): void
    {
        $admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();

        $this->actingAs($admin)
            ->get(route('admin.emails.preview', ['template' => 'auth.welcome_sign_in']))
            ->assertOk()
            ->assertSee(WelcomeSignInDetailsNotification::PREVIEW_PASSWORD, false);
    }

    private function profileOnlyClient(string $email, string $first, string $last): User
    {
        $client = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create([
            'name' => trim($first.' '.$last),
            'email' => $email,
            'username' => 'legacy'.random_int(1000, 9999),
            'account_state' => User::ACCOUNT_STATE_PROFILE_ONLY,
            'identity_type' => User::IDENTITY_LOCAL,
        ]);
        $client->profile()->create([
            'first_name' => $first,
            'last_name' => $last,
            'mobile_number' => '+4477009'.random_int(10000, 99999),
            'phone' => '+4477009'.random_int(10000, 99999),
            'address_line_1' => '1 Road',
            'town_city' => 'London',
            'postcode' => 'E1 6AN',
        ]);

        return $client;
    }
}
