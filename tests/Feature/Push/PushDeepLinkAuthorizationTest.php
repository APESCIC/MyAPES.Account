<?php

namespace Tests\Feature\Push;

use App\Core\Accounts\User;
use App\Core\Push\PushNotificationTypeKeys;
use App\Core\Push\PushPayloadBuilder;
use App\Core\Push\PushTarget;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Plugins\Cases\Models\ShelterCase;
use Plugins\Tickets\Models\SupportTicket;
use Tests\TestCase;

/**
 * Push deep links use normal sign-in and record authorization (#799).
 */
class PushDeepLinkAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
    }

    public function test_guest_is_redirected_to_sign_in_before_opening_a_record(): void
    {
        $owner = User::factory()->create();
        $ticket = SupportTicket::query()->create([
            'sub_core_key' => 'apes-cic',
            'user_id' => $owner->id,
            'subject' => 'Privacy safe subject must not leak to push',
            'description' => 'Internal detail',
            'status' => 'open',
            'priority' => 'medium',
            'service_area' => 'general',
        ]);
        $path = app(PushPayloadBuilder::class)
            ->payloadArray(
                PushNotificationTypeKeys::TICKET_CREATED,
                new PushTarget('apes-cic.tickets.show', ['ticket' => $ticket->id], (string) $ticket->id, 'APES CIC'),
            )['url'];

        $this->assertStringStartsWith('/apes-cic/tickets/', $path);

        $this->get($path)->assertRedirect(route('public.login'));
    }

    public function test_signed_in_user_without_access_gets_forbidden(): void
    {
        $owner = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create([
            'email_verified_at' => now(),
            'onboarding_completed_at' => now(),
        ]);
        $other = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create([
            'email_verified_at' => now(),
            'onboarding_completed_at' => now(),
        ]);

        $case = ShelterCase::query()->create([
            'sub_core_key' => 'apes-cic',
            'user_id' => $owner->id,
            'category' => 'formal_complaint',
            'sub_category' => 'service_complaint',
            'priority' => 'medium',
            'status' => 'open',
            'title' => 'Case title must not appear on push',
            'details' => 'Sensitive case detail',
            'opened_at' => now(),
        ]);

        $path = app(PushPayloadBuilder::class)
            ->payloadArray(
                PushNotificationTypeKeys::CASE_UPDATE_SHARED,
                new PushTarget('apes-cic.cases.show', ['case' => $case->id], (string) $case->id, 'APES CIC'),
            )['url'];

        $this->actingAs($other)->get($path)->assertForbidden();
        $this->actingAs($owner)->get($path)->assertOk();
    }
}