<?php

namespace Tests\Feature;

use App\Core\Accounts\AuditLog;
use App\Core\Accounts\CallerVerificationService;
use App\Core\Accounts\CallLog;
use App\Core\Accounts\PhoneVerificationPinService;
use App\Core\Accounts\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Call logs on caller profiles (#479).
 */
class CallLogsTest extends TestCase
{
    use RefreshDatabase;

    private const PIN = '482917';

    private User $staff;

    private User $caller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staff = User::factory()->protectedRole('volunteer')->create(['name' => 'Vera Volunteer']);
        $this->caller = User::factory()->accessLevel(User::ROLE_SERVICE_USER)->create();
        app(PhoneVerificationPinService::class)->set($this->caller, self::PIN);
    }

    public function test_staff_add_a_log_only_after_verification(): void
    {
        $this->actingAs($this->staff)
            ->post(route('callers.call-logs.store', $this->caller), ['direction' => 'inbound', 'summary' => 'Asked about adoption.'])
            ->assertForbidden();
        $this->assertSame(0, CallLog::query()->count());

        $this->verify();

        $this->actingAs($this->staff)
            ->post(route('callers.call-logs.store', $this->caller), ['direction' => 'inbound', 'summary' => 'Asked about adoption.'])
            ->assertRedirect(route('callers.show', $this->caller).'#call-logs')
            ->assertSessionHas('status', 'Call logged.');

        $log = CallLog::query()->sole();
        $this->assertSame($this->caller->id, $log->caller_id);
        $this->assertSame($this->staff->id, $log->staff_id);
        $this->assertSame('inbound', $log->direction);
        $this->assertTrue(AuditLog::query()->where('event', 'caller.call_logged')->exists());

        $this->actingAs($this->staff)
            ->get(route('callers.show', $this->caller))
            ->assertSee('data-call-log="'.$log->id.'"', false)
            ->assertSeeText('Asked about adoption.')
            ->assertSeeText('Vera Volunteer')
            ->assertSeeText('Incoming call');
    }

    public function test_invalid_entries_are_rejected(): void
    {
        $this->verify();

        $this->actingAs($this->staff)
            ->post(route('callers.call-logs.store', $this->caller), ['direction' => 'sideways', 'summary' => ''])
            ->assertSessionHasErrors(['direction', 'summary']);
        $this->assertSame(0, CallLog::query()->count());
    }

    public function test_the_caller_cannot_see_logs_on_their_profile_or_by_url(): void
    {
        CallLog::query()->create([
            'caller_id' => $this->caller->id,
            'staff_id' => $this->staff->id,
            'direction' => 'outbound',
            'summary' => 'Private staff note about the caller.',
            'called_at' => now(),
        ]);

        $this->actingAs($this->caller)->get(route('profile.edit'))->assertOk()->assertDontSeeText('Private staff note about the caller.');
        $this->actingAs($this->caller)->get(route('dashboard'))->assertOk()->assertDontSeeText('Private staff note about the caller.');
        $this->actingAs($this->caller)->get(route('callers.show', $this->caller))->assertForbidden();
        $this->actingAs($this->caller)
            ->post(route('callers.call-logs.store', $this->caller), ['direction' => 'inbound', 'summary' => 'Self note'])
            ->assertForbidden();

        $admin = User::factory()->protectedRole('administrator')->create();
        $this->actingAs($admin)->get(route('callers.show', $this->caller))->assertOk()->assertSeeText('Private staff note about the caller.');
    }

    private function verify(): void
    {
        $this->actingAs($this->staff)->get(route('callers.show', $this->caller));
        $positions = session(app(CallerVerificationService::class)->sessionKey($this->caller, 'positions'));
        $digits = [];
        foreach ($positions as $position) {
            $digits[$position] = self::PIN[$position - 1];
        }

        $this->actingAs($this->staff)->post(route('callers.verify', $this->caller), ['pin_digits' => $digits]);
    }
}
