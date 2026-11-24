<?php

namespace Tests\Feature;

use App\Core\Accounts\PhoneVerificationPinService;
use App\Core\Accounts\User;
use App\Notifications\Auth\StaffAssistedSetPasswordNotification;
use App\Core\Accounts\AuditLog;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * Staff-created public client accounts (#669–#673).
 */
class StaffClientAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
    }

    public function test_staff_can_create_a_public_client_with_pin_shown_once(): void
    {
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();

        $response = $this->actingAs($staff)->post(route('clients.store'), $this->payload([
            'pin' => '4826',
            'pin_confirmation' => '4826',
        ]));

        $client = User::query()->whereNotNull('registration_consent_staff_id')->latest('id')->first();
        $this->assertNotNull($client);
        $this->assertSame(User::IDENTITY_LOCAL, $client->identity_type);
        $this->assertSame($staff->id, $client->registration_consent_staff_id);
        $this->assertNotNull($client->registration_consented_at);
        $this->assertFalse($this->profile()->hasDirectoryProtectedEligibility($client));
        $this->assertTrue(app(PhoneVerificationPinService::class)->matches($client, [1 => '4', 2 => '8']));

        $response->assertRedirect(route('clients.show', $client))
            ->assertSessionHas('client_created_pin', '4826');

        $this->actingAs($staff)->get(route('clients.show', $client))
            ->assertOk()
            ->assertSee('data-client-pin-once', false)
            ->assertSeeText('4826');

        $this->actingAs($staff)->get(route('clients.show', $client))
            ->assertOk()
            ->assertDontSee('data-client-pin-once', false);
    }

    public function test_duplicate_email_phone_and_staff_domain_are_rejected(): void
    {
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();
        $existing = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create([
            'email' => 'existing.client@example.com',
        ]);
        $existing->profile()->create([
            'mobile_number' => '+447700900111',
            'phone' => '+447700900111',
            'address_line_1' => '1 Test Street',
            'town_city' => 'London',
            'postcode' => 'E1 6AN',
        ]);

        $this->actingAs($staff)->from(route('clients.index'))->post(route('clients.store'), $this->payload([
            'email' => 'existing.client@example.com',
        ]))->assertRedirect(route('clients.index'))->assertSessionHasErrors('email');

        $this->actingAs($staff)->from(route('clients.index'))->post(route('clients.store'), $this->payload([
            'email' => 'unique@example.com',
            'mobile_number' => '07700900111',
        ]))->assertRedirect(route('clients.index'))->assertSessionHasErrors('mobile_number');

        $this->actingAs($staff)->from(route('clients.index'))->post(route('clients.store'), $this->payload([
            'email' => 'someone@apes.org.uk',
            'mobile_number' => '07000900999',
        ]))->assertRedirect(route('clients.index'))->assertSessionHasErrors('email');
    }

    public function test_optional_set_password_email_is_sent_without_pin_or_password(): void
    {
        Notification::fake();
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_VOLUNTEER)->create();

        $this->actingAs($staff)->post(route('clients.store'), $this->payload([
            'email' => 'new.client@example.com',
            'send_set_password_email' => '1',
            'pin' => '1357',
            'pin_confirmation' => '1357',
        ]))->assertRedirect();

        $client = User::query()->where('email', 'new.client@example.com')->firstOrFail();
        Notification::assertSentTo($client, StaffAssistedSetPasswordNotification::class, function (StaffAssistedSetPasswordNotification $mail) use ($client): bool {
            $message = $mail->toMail($client);
            $html = $message->render();

            return ! str_contains($html, '1357')
                && ! str_contains($html, 'password is')
                && str_contains($html, '/set-password/');
        });

        $audit = AuditLog::query()->where('event', 'client.created')->latest('id')->first();
        $this->assertNotNull($audit);
        $context = $audit->context ?? [];
        $this->assertTrue($context['set_password_email_sent'] ?? false);
        $this->assertTrue($context['pin_set'] ?? false);
        $this->assertArrayNotHasKey('pin', $context);
        $this->assertStringNotContainsString('1357', json_encode($context) ?: '');
    }

    public function test_phone_only_account_skips_email_and_set_password_link_expires(): void
    {
        Notification::fake();
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STUDENT)->create();

        $this->actingAs($staff)->post(route('clients.store'), $this->payload([
            'email' => null,
            'send_set_password_email' => '1',
            'mobile_number' => '07000900888',
            'pin' => '2468',
            'pin_confirmation' => '2468',
        ]))->assertRedirect();

        $client = User::query()->whereNull('email')->whereNotNull('registration_consent_staff_id')->latest('id')->firstOrFail();
        Notification::assertNothingSent();

        auth()->logout();

        $token = Password::broker('staff_assisted')->createToken(
            User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create([
                'email' => 'expire@example.com',
            ]),
        );
        DB::table('staff_assisted_password_reset_tokens')
            ->where('email', 'expire@example.com')
            ->update(['created_at' => now()->subHours(80)]);

        $this->from(route('password.set', [
            'token' => $token,
            'email' => 'expire@example.com',
        ]))->post(route('password.set.update'), [
            'token' => $token,
            'email' => 'expire@example.com',
            'password' => 'Correct-Horse-Battery-1',
            'password_confirmation' => 'Correct-Horse-Battery-1',
        ])->assertSessionHasErrors('email');
    }

    public function test_client_search_returns_public_clients_and_hides_staff_accounts(): void
    {
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();
        $client = User::factory()->create(['name' => 'Ziggy Public Client']);
        User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create([
            'name' => 'Ziggy Staff Only',
        ]);

        $this->actingAs($staff)
            ->get(route('clients.index', ['q' => 'Ziggy']))
            ->assertOk()
            ->assertSee('Ziggy Public Client')
            ->assertDontSee('Ziggy Staff Only')
            ->assertSee(route('clients.show', $client), false);
    }

    public function test_client_search_query_count_stays_constant_for_twenty_five_hits(): void
    {
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();

        for ($i = 1; $i <= 25; $i++) {
            User::factory()->create(['name' => sprintf('Bulk Search Client %02d', $i)]);
        }

        $this->actingAs($staff);

        $perIdFinds = 0;
        DB::listen(static function (QueryExecuted $query) use (&$perIdFinds): void {
            $sql = strtolower($query->sql);
            if (preg_match('/from\s+[`"]?users[`"]?\s+where\s+[`"]?id[`"]?\s*=\s*\?/i', $sql) === 1) {
                $perIdFinds++;
            }
        });

        $this->get(route('clients.index', ['q' => 'Bulk Search Client']))
            ->assertOk()
            ->assertSee('Bulk Search Client 01')
            ->assertSee('Bulk Search Client 25');

        $this->assertSame(
            0,
            $perIdFinds,
            "Expected no per-id User::find loops for 25 hits, got {$perIdFinds}",
        );
    }

    public function test_public_users_and_guests_cannot_create_clients(): void
    {
        $this->get(route('clients.index'))->assertRedirect();
        $this->post(route('clients.store'), $this->payload())->assertRedirect();

        $public = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create();
        $this->actingAs($public)->get(route('clients.index'))->assertForbidden();
        $this->actingAs($public)->post(route('clients.store'), $this->payload())->assertForbidden();
    }

    public function test_created_client_appears_in_caller_search(): void
    {
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();
        $this->actingAs($staff)->post(route('clients.store'), $this->payload([
            'first_name' => 'Alex',
            'last_name' => 'Caller',
            'email' => 'alex.caller@example.com',
            'mobile_number' => '07000900777',
            'pin' => '9876',
            'pin_confirmation' => '9876',
        ]))->assertRedirect();

        $client = User::query()->where('email', 'alex.caller@example.com')->firstOrFail();
        $this->actingAs($staff)
            ->get(route('callers.index', ['q' => 'Alex']))
            ->assertOk()
            ->assertSee('data-caller-result="'.$client->id.'"', false);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
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
            'send_set_password_email' => '0',
        ], $overrides);
    }

    private function profile(): AuthorizationProfile
    {
        return app(AuthorizationProfile::class);
    }
}
