<?php

namespace Tests\Feature;

use App\Core\Accounts\AuditLog;
use App\Core\Accounts\User;
use App\Services\AccountSecurityActivity;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Account → Security page (#920).
 */
class AccountSecurityPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_page_renders_password_passkeys_two_factor_pin_devices_and_activity(): void
    {
        $user = User::factory()->create(['password' => 'password']);

        $this->actingAs($user)
            ->get(route('account.security'))
            ->assertOk()
            ->assertSee('id="password"', false)
            ->assertSee('id="passkeys"', false)
            ->assertSee('id="two-factor"', false)
            ->assertSee('id="phone-pin"', false)
            ->assertSee('id="signed-in-devices"', false)
            ->assertSee('id="security-activity"', false)
            ->assertSee('name="current_password"', false);
    }

    public function test_pin_is_never_shown_in_the_page_after_save(): void
    {
        $user = User::factory()->create(['password' => 'password']);

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->put(route('profile.phone-pin.update'), ['pin' => '482917', 'pin_confirmation' => '482917'])
            ->assertRedirect(route('account.security').'#phone-pin');

        $html = $this->actingAs($user)->get(route('account.security'))->assertOk()->getContent();
        $this->assertStringNotContainsString('482917', $html);
        $this->assertStringNotContainsString('digit_hashes', $html);
    }

    public function test_sign_out_other_devices_requires_step_up(): void
    {
        $user = User::factory()->create(['password' => 'password']);

        $this->actingAs($user)
            ->delete(route('account.security.sessions.destroy-others'))
            ->assertRedirect(route('password.confirm'));
    }

    public function test_directory_staff_security_page_includes_step_up_gated_forms(): void
    {
        $staff = User::factory()
            ->protectedRole('staff')
            ->create(['password' => 'password']);

        $this->actingAs($staff)
            ->get(route('account.security'))
            ->assertOk()
            ->assertSee(__('account.settings.tabs.security'), false)
            ->assertSee('id="signed-in-devices"', false)
            ->assertSee('id="security-activity"', false);

        $this->actingAs($staff)
            ->delete(route('account.security.sessions.destroy-others'))
            ->assertRedirect(route('password.confirm'));
    }

    public function test_security_activity_lists_up_to_twenty_events(): void
    {
        $user = User::factory()->create();
        $audit = app(AuditLogger::class);

        for ($i = 0; $i < 25; $i++) {
            $audit->record('auth.public_login_success', $user, $user, ['channel' => 'test']);
        }

        $this->actingAs($user)
            ->get(route('account.security'))
            ->assertOk()
            ->assertSeeText(__('account.settings.security_activity.heading'))
            ->assertSeeText('Signed in');

        $this->assertSame(25, AuditLog::query()->where('user_id', $user->id)->count());
        $this->assertCount(20, app(AccountSecurityActivity::class)->recentFor($user));
    }
}