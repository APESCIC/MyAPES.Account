<?php

namespace Tests\Feature;

use App\Core\Accounts\PhoneVerificationPin;
use App\Core\Accounts\PhoneVerificationPinService;
use App\Core\Accounts\AuditLog;
use App\Core\Accounts\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Users set a phone verification PIN on their profile (#476).
 */
class PhoneVerificationPinTest extends TestCase
{
    use RefreshDatabase;

    private const PIN = '482917';

    public function test_user_can_set_and_change_a_pin_after_re_authenticating(): void
    {
        $user = User::factory()->accessLevel(User::ROLE_SERVICE_USER)->create();

        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('data-phone-pin', false)
            ->assertSeeText('You have not set a PIN yet.');

        $this->actingAs($user)
            ->put(route('profile.phone-pin.update'), ['pin' => self::PIN, 'pin_confirmation' => self::PIN])
            ->assertRedirect(route('password.confirm'));
        $this->assertNull(app(PhoneVerificationPinService::class)->for($user));

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->put(route('profile.phone-pin.update'), ['pin' => self::PIN, 'pin_confirmation' => self::PIN])
            ->assertRedirect(route('profile.edit').'#phone-pin')
            ->assertSessionHas('status', 'Your phone verification PIN is set.');

        $pins = app(PhoneVerificationPinService::class);
        $this->assertTrue($pins->matches($user, [2 => '8', 5 => '1']));
        $this->assertFalse($pins->matches($user, [2 => '8', 5 => '2']));

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->put(route('profile.phone-pin.update'), ['pin' => '1357', 'pin_confirmation' => '1357'])
            ->assertSessionHas('status', 'Your phone verification PIN has been changed.');
        $this->assertTrue($pins->matches($user, [1 => '1', 4 => '7']));
        $this->assertFalse($pins->matches($user, [2 => '8']));

        $this->assertTrue(AuditLog::query()->where('event', 'profile.phone_pin_set')->exists());
        $this->assertTrue(AuditLog::query()->where('event', 'profile.phone_pin_changed')->exists());
    }

    public function test_the_pin_is_never_stored_or_shown_in_full(): void
    {
        $user = User::factory()->accessLevel(User::ROLE_SERVICE_USER)->create();
        app(PhoneVerificationPinService::class)->set($user, self::PIN);

        $row = (array) DB::table('phone_verification_pins')->where('user_id', $user->id)->first();
        $this->assertStringNotContainsString(self::PIN, json_encode($row, JSON_THROW_ON_ERROR));
        $this->assertCount(6, json_decode($row['digit_hashes'], true));
        $this->assertSame([], array_intersect(json_decode($row['digit_hashes'], true), str_split(self::PIN)));

        $this->assertArrayNotHasKey('digit_hashes', PhoneVerificationPin::query()->first()->toArray());
        $this->assertArrayNotHasKey('salt', PhoneVerificationPin::query()->first()->toArray());

        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSeeText('Your PIN is set.')
            ->assertDontSee(self::PIN);

        $logs = AuditLog::query()->get()->toJson();
        $this->assertStringNotContainsString(self::PIN, $logs);
    }

    public function test_invalid_pins_are_rejected_and_not_flashed_back(): void
    {
        $user = User::factory()->accessLevel(User::ROLE_SERVICE_USER)->create();

        foreach ([['12a4', '12a4'], ['123', '123'], ['123456789', '123456789'], ['123456', '654321']] as [$pin, $confirmation]) {
            $this->actingAs($user)
                ->withSession(['auth.password_confirmed_at' => time()])
                ->from(route('profile.edit'))
                ->put(route('profile.phone-pin.update'), ['pin' => $pin, 'pin_confirmation' => $confirmation])
                ->assertSessionHasErrors('pin')
                ->assertSessionMissing('_old_input.pin')
                ->assertSessionMissing('_old_input.pin_confirmation');
        }

        $this->assertNull(app(PhoneVerificationPinService::class)->for($user));
    }

    public function test_staff_directory_accounts_cannot_set_a_pin_here(): void
    {
        $staff = User::factory()->protectedRole('staff')->create();

        $this->actingAs($staff)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->put(route('profile.phone-pin.update'), ['pin' => self::PIN, 'pin_confirmation' => self::PIN])
            ->assertForbidden();

        $this->assertNull(app(PhoneVerificationPinService::class)->for($staff));
    }
}
