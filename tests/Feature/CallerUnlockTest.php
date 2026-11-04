<?php

namespace Tests\Feature;

use App\Core\Accounts\AuditLog;
use App\Core\Accounts\CallerVerificationService;
use App\Core\Accounts\PhoneVerificationPinService;
use App\Core\Accounts\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Profile editing stays locked until the caller is verified (#478).
 */
class CallerUnlockTest extends TestCase
{
    use RefreshDatabase;

    private const PIN = '482917';

    private User $staff;

    private User $caller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staff = User::factory()->protectedRole('staff')->create();
        $this->caller = User::factory()->accessLevel(User::ROLE_SERVICE_USER)->create();
        app(PhoneVerificationPinService::class)->set($this->caller, self::PIN);
    }

    public function test_unverified_edits_are_refused_and_controls_disabled(): void
    {
        $this->actingAs($this->staff)
            ->get(route('callers.show', $this->caller))
            ->assertOk()
            ->assertSee('data-locked="true"', false)
            ->assertSee('<fieldset disabled', false)
            ->assertSeeText('Verify the caller before you change anything.')
            ->assertDontSee('data-end-call', false);

        $this->actingAs($this->staff)
            ->put(route('callers.details.update', $this->caller), $this->details())
            ->assertForbidden();
    }

    public function test_verified_staff_can_edit_until_they_end_the_call(): void
    {
        $this->verify();

        $this->actingAs($this->staff)
            ->get(route('callers.show', $this->caller))
            ->assertSee('data-locked="false"', false)
            ->assertDontSee('<fieldset disabled', false)
            ->assertSee('data-end-call', false);

        $this->actingAs($this->staff)
            ->put(route('callers.details.update', $this->caller), $this->details())
            ->assertRedirect(route('callers.show', $this->caller))
            ->assertSessionHas('status', 'Contact details saved.');
        $this->assertSame('+447400123456', $this->caller->fresh()->profile->mobile_number);
        $this->assertSame('SW1A 1AA', $this->caller->fresh()->profile->postcode);

        $this->actingAs($this->staff)
            ->post(route('callers.end-call', $this->caller))
            ->assertSessionHas('status', 'Call ended. Details are locked again.');

        $this->actingAs($this->staff)
            ->put(route('callers.details.update', $this->caller), $this->details())
            ->assertForbidden();

        $this->assertTrue(AuditLog::query()->where('event', 'caller.unlocked')->exists());
        $this->assertTrue(AuditLog::query()->where('event', 'caller.details_updated')->exists());
        $this->assertTrue(AuditLog::query()->where('event', 'caller.call_ended')->exists());
    }

    public function test_unlock_expires_after_sixty_minutes(): void
    {
        $this->verify();

        $this->travel(59)->minutes();
        $this->actingAs($this->staff)->put(route('callers.details.update', $this->caller), $this->details())->assertRedirect();

        $this->travel(2)->minutes();
        $this->actingAs($this->staff)->put(route('callers.details.update', $this->caller), $this->details())->assertForbidden();

        $this->assertSame(1, AuditLog::query()->where('event', 'caller.unlock_timed_out')->count());
        $this->actingAs($this->staff)->get(route('callers.show', $this->caller))->assertSee('data-locked="true"', false);
    }

    public function test_an_unlock_belongs_to_the_staff_session_that_verified(): void
    {
        $this->verify();
        $other = User::factory()->protectedRole('staff')->create();

        $this->flushSession();
        $this->actingAs($other)
            ->put(route('callers.details.update', $this->caller), $this->details())
            ->assertForbidden();
    }

    private function verify(): void
    {
        $this->actingAs($this->staff)->get(route('callers.show', $this->caller));
        $positions = session(app(CallerVerificationService::class)->sessionKey($this->caller, 'positions'));
        $digits = [];
        foreach ($positions as $position) {
            $digits[$position] = self::PIN[$position - 1];
        }

        $this->actingAs($this->staff)
            ->post(route('callers.verify', $this->caller), ['pin_digits' => $digits])
            ->assertSessionHas('status', 'Caller verified.');
    }

    /**
     * @return array<string, string>
     */
    private function details(): array
    {
        return [
            'mobile_number' => '+447400123456',
            'address_line_1' => '1 Test Street',
            'town_city' => 'London',
            'postcode' => 'sw1a 1aa',
        ];
    }
}
