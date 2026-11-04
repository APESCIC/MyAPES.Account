<?php

namespace Tests\Feature;

use App\Core\Accounts\AuditLog;
use App\Core\Accounts\CallerVerificationService;
use App\Core\Accounts\PhoneVerificationPinService;
use App\Core\Accounts\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Staff caller verification with a partial PIN (#477).
 */
class CallerVerificationTest extends TestCase
{
    use RefreshDatabase;

    private const PIN = '482917';

    private User $staff;

    private User $caller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staff = User::factory()->protectedRole('staff')->create();
        $this->caller = User::factory()->accessLevel(User::ROLE_SERVICE_USER)->create(['name' => 'Casey Caller']);
        app(PhoneVerificationPinService::class)->set($this->caller, self::PIN);
    }

    public function test_staff_find_public_callers_only(): void
    {
        $otherStaff = User::factory()->protectedRole('volunteer')->create(['name' => 'Casey Volunteer']);

        $this->actingAs($this->staff)
            ->get(route('callers.index', ['q' => 'Casey']))
            ->assertOk()
            ->assertSee('data-caller-result="'.$this->caller->id.'"', false)
            ->assertDontSee('data-caller-result="'.$otherStaff->id.'"', false);

        $this->actingAs($this->staff)->get(route('callers.show', $otherStaff))->assertNotFound();
        $this->actingAs($this->caller)->get(route('callers.index'))->assertForbidden();
        $this->actingAs($this->caller)->get(route('callers.show', $this->caller))->assertForbidden();
    }

    public function test_correct_digits_verify_and_no_digit_is_ever_shown(): void
    {
        $page = $this->actingAs($this->staff)->get(route('callers.show', $this->caller))->assertOk();
        $positions = session(app(CallerVerificationService::class)->sessionKey($this->caller, 'positions'));
        $this->assertCount(2, $positions);
        $page->assertSeeText('Ask the caller for digit '.$positions[0].' and digit '.$positions[1].' of their PIN');
        $this->assertStringNotContainsString(self::PIN, (string) $page->getContent());

        $this->actingAs($this->staff)
            ->post(route('callers.verify', $this->caller), ['pin_digits' => $this->digitsFor($positions)])
            ->assertRedirect(route('callers.show', $this->caller))
            ->assertSessionHas('status', 'Caller verified.')
            ->assertSessionMissing('_old_input.pin_digits');

        $this->actingAs($this->staff)->get(route('callers.show', $this->caller))->assertSee('Caller verified.');

        $passed = AuditLog::query()->where('event', 'caller.verification_passed')->sole();
        $this->assertSame($positions, $passed->context['positions']);
        $this->assertStringNotContainsString(self::PIN, AuditLog::query()->get()->toJson());
    }

    public function test_three_wrong_attempts_lock_verification_and_every_attempt_is_audited(): void
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->actingAs($this->staff)->get(route('callers.show', $this->caller));
            $positions = session(app(CallerVerificationService::class)->sessionKey($this->caller, 'positions'));
            $wrong = array_map(static fn (string $digit): string => (string) (((int) $digit + 1) % 10), $this->digitsFor($positions));

            $this->actingAs($this->staff)->post(route('callers.verify', $this->caller), ['pin_digits' => $wrong]);
        }

        $this->assertSame(3, AuditLog::query()->where('event', 'caller.verification_failed')->count());
        $this->assertSame(1, AuditLog::query()->where('event', 'caller.verification_locked')->count());

        $this->actingAs($this->staff)
            ->get(route('callers.show', $this->caller))
            ->assertSeeText('Too many wrong attempts');

        $otherStaff = User::factory()->protectedRole('staff')->create();
        $this->actingAs($otherStaff)
            ->post(route('callers.verify', $this->caller), ['pin_digits' => [1 => '4', 2 => '8']])
            ->assertSessionHas('status', 'Too many wrong attempts. Checking is paused for 15 minutes.');
        $this->assertSame(1, AuditLog::query()->where('event', 'caller.verification_refused_locked')->count());

        $this->travel(16)->minutes();
        $this->actingAs($this->staff)->get(route('callers.show', $this->caller))->assertSee('name="pin_digits[', false);
    }

    public function test_callers_without_a_pin_cannot_be_verified_by_pin(): void
    {
        $noPin = User::factory()->accessLevel(User::ROLE_SERVICE_USER)->create();

        $this->actingAs($this->staff)
            ->get(route('callers.show', $noPin))
            ->assertOk()
            ->assertSeeText('has not set a phone verification PIN');
    }

    /**
     * @param  list<int>  $positions
     * @return array<int, string>
     */
    private function digitsFor(array $positions): array
    {
        $digits = [];
        foreach ($positions as $position) {
            $digits[$position] = self::PIN[$position - 1];
        }

        return $digits;
    }
}
