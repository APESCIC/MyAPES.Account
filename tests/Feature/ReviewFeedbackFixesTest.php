<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Plugins\PetProfiles\Models\PetProfile;
use Plugins\Tickets\Models\SupportTicket;
use Plugins\Tickets\Models\SupportTicketMessage;
use Tests\Concerns\CreatesClinicalPersonas;
use Tests\TestCase;

class ReviewFeedbackFixesTest extends TestCase
{
    use CreatesClinicalPersonas;
    use RefreshDatabase;

    public function test_service_user_cannot_see_staff_only_ticket_notes(): void
    {
        $serviceUser = User::factory()->accessLevel(User::ROLE_SERVICE_USER)->create();
        $staffUser = User::factory()->accessLevel(User::ROLE_STAFF)->create();
        $ticket = SupportTicket::create([
            'user_id' => $serviceUser->id,
            'assigned_to' => $staffUser->id,
            'service_area' => 'it',
            'subject' => 'Printer access',
            'priority' => 'medium',
            'status' => 'open',
            'description' => 'Need help with network printer.',
        ]);

        SupportTicketMessage::create([
            'support_ticket_id' => $ticket->id,
            'user_id' => $serviceUser->id,
            'message' => 'Customer-facing update',
            'is_staff_note' => false,
        ]);
        SupportTicketMessage::create([
            'support_ticket_id' => $ticket->id,
            'user_id' => $staffUser->id,
            'message' => 'Internal triage note',
            'is_staff_note' => true,
        ]);

        $response = $this->actingAs($serviceUser)->get(route('apes-cic.tickets.show', $ticket));

        $response->assertOk();
        $response->assertSee('Customer-facing update');
        $response->assertDontSee('Internal triage note');
    }

    public function test_ticket_assignment_must_target_staff_roles(): void
    {
        $serviceUser = User::factory()->accessLevel(User::ROLE_SERVICE_USER)->create();
        $staffUser = User::factory()->accessLevel(User::ROLE_STAFF)->create();
        $ticket = SupportTicket::create([
            'user_id' => $serviceUser->id,
            'service_area' => 'it',
            'subject' => 'Mailbox issue',
            'priority' => 'medium',
            'status' => 'open',
            'description' => 'Mailbox problem',
        ]);

        $response = $this->actingAs($staffUser)->from('/')->put(route('apes-cic.tickets.update', $ticket), [
            'status' => 'open',
            'priority' => 'medium',
            'assigned_to' => $serviceUser->id,
            'message' => '',
        ]);

        $response->assertRedirect('/');
        $response->assertSessionHasErrors('assigned_to');
    }

    public function test_staff_created_shelter_case_is_owned_by_pet_owner(): void
    {
        $serviceUser = User::factory()->accessLevel(User::ROLE_SERVICE_USER)->create();
        $staffUser = User::factory()->accessLevel(User::ROLE_STAFF)->create();
        $pet = PetProfile::create([
            'user_id' => $serviceUser->id,
            'service_domain' => PetProfile::DOMAIN_SHELTER,
            'name' => 'Mango',
            'species' => 'Cockatiel',
            'sex' => 'female',
            'neutering_status' => 'unknown',
        ]);

        $this->actingAs($staffUser)->post(route('shelter.cases.store'), [
            'pet_profile_id' => $pet->id,
            'case_type' => 'rescue',
            'title' => 'Rescue intake',
            'details' => 'Initial assessment',
        ])->assertRedirect();

        $this->assertDatabaseHas('shelter_cases', [
            'pet_profile_id' => $pet->id,
            'user_id' => $serviceUser->id,
            'title' => 'Rescue intake',
        ]);
    }

    public function test_staff_created_consultation_is_owned_by_pet_owner(): void
    {
        $serviceUser = User::factory()->accessLevel(User::ROLE_SERVICE_USER)->create();
        $staffUser = User::factory()->accessLevel(User::ROLE_STAFF)->create();
        $pet = PetProfile::create([
            'user_id' => $serviceUser->id,
            'service_domain' => PetProfile::DOMAIN_PETCARE,
            'name' => 'Pico',
            'species' => 'African Grey',
            'sex' => 'male',
            'neutering_status' => 'unknown',
        ]);

        $this->actingAs($staffUser)->post(route('petcare.consultations.store'), [
            'pet_profile_id' => $pet->id,
            'subject' => 'Wellness check',
            'notes' => 'Assess diet',
        ])->assertForbidden();

        $this->actingAs($this->vetStaff())->post(route('petcare.consultations.store'), [
            'pet_profile_id' => $pet->id,
            'subject' => 'Wellness check',
            'notes' => 'Assess diet',
        ])->assertRedirect();

        $this->assertDatabaseHas('pet_care_consultations', [
            'pet_profile_id' => $pet->id,
            'user_id' => $serviceUser->id,
            'subject' => 'Wellness check',
        ]);
    }

    public function test_public_login_is_rate_limited_after_repeated_failures(): void
    {
        $email = 'qa@example.test';

        RateLimiter::clear('account|'.$email);
        RateLimiter::clear('ip|127.0.0.1');

        User::factory()
            ->accessLevel(User::ROLE_SERVICE_USER)
            ->create([
                'email' => $email,
                'password' => 'correct-password',
            ]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('public.login.submit'), [
                'login' => $email,
                'password' => 'wrong-password',
            ])->assertRedirect();
        }

        $this->post(route('public.login.submit'), [
            'login' => $email,
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }
}
