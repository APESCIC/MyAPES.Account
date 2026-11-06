<?php

namespace Tests\Feature;

use App\Core\Accounts\AuditLog;
use App\Core\Accounts\User;
use App\Services\AuditLogger;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Plugins\Consultations\Models\PetCareConsultation;
use Plugins\PetProfiles\Models\PetProfile;
use Plugins\Tickets\Models\SupportTicket;
use Tests\Concerns\CreatesClinicalPersonas;
use Tests\TestCase;

class VetOnlyConsultationsTest extends TestCase
{
    use CreatesClinicalPersonas;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
        Notification::fake();
    }

    public function test_vet_vet_nurse_and_admin_create_consultations_for_a_clients_pet(): void
    {
        $owner = User::factory()->create();
        $pet = $this->petFor($owner);
        $administrator = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();

        foreach (['vet' => $this->vetStaff(), 'nurse' => $this->vetNurseStaff(), 'admin' => $administrator] as $label => $clinician) {
            $this->actingAs($clinician)
                ->post(route('petcare.consultations.store'), [
                    'pet_profile_id' => $pet->id,
                    'subject' => "Created by {$label}",
                ])
                ->assertRedirect();

            $this->assertDatabaseHas('pet_care_consultations', [
                'subject' => "Created by {$label}",
                'user_id' => $owner->id,
            ]);
        }
    }

    public function test_public_users_and_non_clinical_staff_cannot_create(): void
    {
        $owner = User::factory()->create();
        $pet = $this->petFor($owner);

        foreach ([
            $owner,
            User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create(),
            User::factory()->protectedRole(AuthorizationProfile::ROLE_VOLUNTEER)->create(),
            User::factory()->protectedRole(AuthorizationProfile::ROLE_STUDENT)->create(),
        ] as $user) {
            $this->actingAs($user)
                ->post(route('petcare.consultations.store'), [
                    'pet_profile_id' => $pet->id,
                    'subject' => 'Not allowed',
                ])
                ->assertForbidden();
        }

        $this->assertDatabaseMissing('pet_care_consultations', ['subject' => 'Not allowed']);
    }

    public function test_only_clinical_roles_edit_and_clients_cannot_edit_their_own(): void
    {
        $owner = User::factory()->create();
        $consultation = $this->consultationFor($owner, $this->petFor($owner));

        foreach ([
            $owner,
            User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create(),
            User::factory()->protectedRole(AuthorizationProfile::ROLE_VOLUNTEER)->create(),
            User::factory()->protectedRole(AuthorizationProfile::ROLE_STUDENT)->create(),
        ] as $user) {
            $this->actingAs($user)
                ->patch(route('petcare.consultations.update', $consultation), ['notes' => 'Edited'])
                ->assertForbidden();
        }
        $this->assertSame('Consultation notes.', $consultation->fresh()->notes);

        $this->actingAs($this->vetNurseStaff())
            ->patch(route('petcare.consultations.update', $consultation), ['notes' => 'Edited by the nurse'])
            ->assertRedirect(route('petcare.consultations.show', $consultation));
        $this->assertSame('Edited by the nurse', $consultation->fresh()->notes);
    }

    public function test_non_clinical_staff_still_view_assign_and_close(): void
    {
        $owner = User::factory()->create();
        $consultation = $this->consultationFor($owner, $this->petFor($owner));
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();
        $volunteer = User::factory()->protectedRole(AuthorizationProfile::ROLE_VOLUNTEER)->create();

        $this->actingAs($volunteer)
            ->get(route('petcare.consultations.show', $consultation))
            ->assertOk();
        $this->actingAs($staff)
            ->patch(route('petcare.consultations.update', $consultation), ['assigned_to' => $volunteer->id])
            ->assertRedirect();
        $this->actingAs($staff)
            ->patch(route('petcare.consultations.update', $consultation), ['status' => 'closed'])
            ->assertRedirect();

        $consultation->refresh();
        $this->assertSame($volunteer->id, $consultation->assigned_to);
        $this->assertSame('closed', $consultation->status);
    }

    public function test_public_users_see_a_ticket_link_instead_of_create_and_edit_controls(): void
    {
        $owner = User::factory()->create();
        $consultation = $this->consultationFor($owner, $this->petFor($owner), ['subject' => 'Owner can read this']);
        $otherConsultation = $this->consultationFor($other = User::factory()->create(), $this->petFor($other), ['subject' => 'Someone else']);
        $ticketUrl = route('tickets.public.start', ['service' => 'pet-care-clinic', 'area' => 'consultation']);

        $this->actingAs($owner)
            ->get(route('petcare.consultations.index'))
            ->assertOk()
            ->assertSeeText('Owner can read this')
            ->assertDontSeeText('Someone else')
            ->assertDontSee('action="'.route('petcare.consultations.store').'"', false)
            ->assertSee('href="'.$ticketUrl.'"', false)
            ->assertSeeText('Open a Pet Care Clinic ticket');

        $this->actingAs($owner)
            ->get(route('petcare.consultations.show', $consultation))
            ->assertOk()
            ->assertDontSee('id="consultation-update-form"', false)
            ->assertDontSee('id="consultation-assignment-form"', false);
        $this->actingAs($owner)
            ->get(route('petcare.consultations.show', $otherConsultation))
            ->assertForbidden();

        $this->actingAs($this->vetStaff())
            ->get(route('petcare.consultations.index'))
            ->assertOk()
            ->assertSee('action="'.route('petcare.consultations.store').'"', false)
            ->assertDontSee('href="'.$ticketUrl.'"', false);
    }

    public function test_created_by_and_updated_by_are_recorded_shown_and_audited(): void
    {
        $owner = User::factory()->create();
        $pet = $this->petFor($owner);
        $vet = $this->vetStaff(['name' => 'Vera Vet']);
        $nurse = $this->vetNurseStaff(['name' => 'Nina Nurse']);

        $this->actingAs($vet)
            ->post(route('petcare.consultations.store'), ['pet_profile_id' => $pet->id, 'subject' => 'Tracked'])
            ->assertRedirect();
        $consultation = PetCareConsultation::query()->where('subject', 'Tracked')->firstOrFail();
        $this->assertSame($vet->id, $consultation->created_by);
        $this->assertSame($vet->id, $consultation->updated_by);

        $this->actingAs($nurse)
            ->patch(route('petcare.consultations.update', $consultation), ['notes' => 'Nurse follow-up'])
            ->assertRedirect();
        $consultation->refresh();
        $this->assertSame($vet->id, $consultation->created_by);
        $this->assertSame($nurse->id, $consultation->updated_by);

        $audit = AuditLog::query()
            ->where('event', 'petcare.consultation.updated')
            ->where('auditable_id', $consultation->id)
            ->latest('id')
            ->firstOrFail();
        $this->assertSame($nurse->id, $audit->user_id);
        $this->assertSame($nurse->id, $audit->context['updated_by']);

        $this->actingAs($owner)
            ->get(route('petcare.consultations.show', $consultation))
            ->assertOk()
            ->assertSee('<dd data-consultation-created-by>Vera Vet</dd>', false)
            ->assertSee('<dd data-consultation-updated-by>Nina Nurse</dd>', false);

        $legacy = $this->consultationFor($owner, $pet);
        $this->actingAs($owner)
            ->get(route('petcare.consultations.show', $legacy))
            ->assertSee('<dd data-consultation-created-by>Not recorded</dd>', false);
    }

    public function test_flag_command_dry_run_reports_and_changes_nothing(): void
    {
        $this->legacyConsultations();

        $this->artisan('consultations:flag-public-created', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run: 2 consultation(s) would be flagged for vet review. Nothing was changed.')
            ->expectsOutputToContain('1 consultation(s) have no recorded creator and are reported only.')
            ->assertSuccessful();

        $this->assertSame(0, PetCareConsultation::query()->whereNotNull('vet_review_flagged_at')->count());
        $this->assertCount(1, Storage::disk('local')->files('reports'));
    }

    public function test_flag_command_needs_confirmation_then_flags_and_logs_without_deleting(): void
    {
        [$publicCreated, $vetCreated, $unknown, $staffCreated] = $this->legacyConsultations();
        $count = PetCareConsultation::query()->count();

        $this->artisan('consultations:flag-public-created')
            ->expectsConfirmation('Flag 2 consultation(s) for vet review?', 'no')
            ->expectsOutputToContain('Cancelled. Nothing was changed.')
            ->assertFailed();
        $this->assertNull($publicCreated->fresh()->vet_review_flagged_at);

        $this->artisan('consultations:flag-public-created')
            ->expectsConfirmation('Flag 2 consultation(s) for vet review?', 'yes')
            ->expectsOutputToContain('Flagged 2 consultation(s) for vet review. Nothing was archived or deleted.')
            ->assertSuccessful();

        $this->assertTrue($publicCreated->fresh()->needsVetReview());
        $this->assertTrue($staffCreated->fresh()->needsVetReview());
        $this->assertFalse($vetCreated->fresh()->needsVetReview());
        $this->assertFalse($unknown->fresh()->needsVetReview());
        $this->assertSame($count, PetCareConsultation::query()->count());
        $this->assertSame(2, AuditLog::query()->where('event', 'petcare.consultation.flagged_for_vet_review')->count());

        $this->artisan('consultations:flag-public-created', ['--force' => true])
            ->expectsOutputToContain('Nothing to flag.')
            ->assertSuccessful();
    }

    public function test_vets_see_the_review_queue_and_clear_the_flag(): void
    {
        $owner = User::factory()->create();
        $flagged = $this->consultationFor($owner, $this->petFor($owner), ['subject' => 'Flagged one', 'vet_review_flagged_at' => now()]);
        $this->consultationFor($owner, $this->petFor($owner), ['subject' => 'Not flagged']);
        $vet = $this->vetStaff(['name' => 'Vera Vet']);
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();

        $this->actingAs($vet)
            ->get(route('petcare.consultations.index', ['review' => 'needed']))
            ->assertOk()
            ->assertSeeText('Flagged one')
            ->assertDontSeeText('Not flagged')
            ->assertSeeText('Needs vet review (1)');
        $this->actingAs($owner)
            ->get(route('petcare.consultations.index', ['review' => 'needed']))
            ->assertOk()
            ->assertSeeText('Not flagged')
            ->assertDontSee('data-vet-review-filter', false);

        $this->actingAs($staff)
            ->get(route('petcare.consultations.show', $flagged))
            ->assertSee('data-vet-review-notice', false)
            ->assertDontSee('id="consultation-vet-review-form"', false);
        $this->actingAs($staff)
            ->post(route('petcare.consultations.vet-review', $flagged))
            ->assertForbidden();
        $this->actingAs($owner)
            ->post(route('petcare.consultations.vet-review', $flagged))
            ->assertForbidden();

        $this->actingAs($vet)
            ->get(route('petcare.consultations.show', $flagged))
            ->assertSee('id="consultation-vet-review-form"', false);
        $this->actingAs($vet)
            ->post(route('petcare.consultations.vet-review', $flagged))
            ->assertRedirect(route('petcare.consultations.show', $flagged));

        $flagged->refresh();
        $this->assertFalse($flagged->needsVetReview());
        $this->assertSame($vet->id, $flagged->vet_reviewed_by);
        $this->assertTrue(AuditLog::query()->where('event', 'petcare.consultation.vet_reviewed')->where('user_id', $vet->id)->exists());
        $this->actingAs($vet)
            ->get(route('petcare.consultations.show', $flagged))
            ->assertSee('data-vet-reviewed', false)
            ->assertSeeText('Reviewed by Vera Vet');
        $this->actingAs($vet)
            ->post(route('petcare.consultations.vet-review', $flagged))
            ->assertSessionHasErrors('consultation');
    }

    public function test_vet_creates_a_consultation_from_a_pet_care_ticket_and_both_records_link(): void
    {
        $owner = User::factory()->create(['name' => 'Olive Owner']);
        $ownerPet = $this->petFor($owner);
        $otherPet = $this->petFor(User::factory()->create());
        $ticket = $this->petCareTicket($owner, 'Limping since Tuesday');
        $vet = $this->vetStaff();
        $ticketUrl = route('petcare.tickets.show', $ticket->id);
        $formUrl = route('petcare.consultations.index', ['ticket' => $ticket->id]).'#create';

        $this->actingAs($vet)
            ->get($ticketUrl)
            ->assertOk()
            ->assertSee('href="'.e($formUrl).'"', false)
            ->assertSeeText('Create consultation');

        $form = $this->actingAs($vet)
            ->get(route('petcare.consultations.index', ['ticket' => $ticket->id]))
            ->assertOk()
            ->assertSee('name="support_ticket_id" value="'.$ticket->id.'"', false)
            ->assertSee('value="Limping since Tuesday"', false)
            ->getContent();
        $this->assertStringContainsString('<option value="'.$ownerPet->id.'"', $form);
        $this->assertStringNotContainsString('<option value="'.$otherPet->id.'"', $form);

        $this->actingAs($vet)
            ->post(route('petcare.consultations.store'), [
                'pet_profile_id' => $otherPet->id,
                'subject' => 'Wrong pet',
                'support_ticket_id' => $ticket->id,
            ])
            ->assertSessionHasErrors('support_ticket_id');

        $this->actingAs($vet)
            ->post(route('petcare.consultations.store'), [
                'pet_profile_id' => $ownerPet->id,
                'subject' => 'Limping since Tuesday',
                'support_ticket_id' => $ticket->id,
            ])
            ->assertRedirect();
        $consultation = PetCareConsultation::query()->where('support_ticket_id', $ticket->id)->firstOrFail();

        $this->actingAs($owner)
            ->get(route('petcare.consultations.show', $consultation))
            ->assertSee('href="'.$ticketUrl.'" data-consultation-ticket-link', false);
        $this->actingAs($owner)
            ->get($ticketUrl)
            ->assertOk()
            ->assertSee('href="'.route('petcare.consultations.show', $consultation).'"', false)
            ->assertDontSee('data-ticket-related-action', false);
    }

    public function test_create_from_ticket_button_is_only_for_clinical_roles_and_pet_care_tickets(): void
    {
        $owner = User::factory()->create();
        $ticket = $this->petCareTicket($owner, 'Needs a check-up');
        $administrator = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();

        $this->actingAs($staff)
            ->get(route('petcare.tickets.show', $ticket->id))
            ->assertOk()
            ->assertDontSee('data-ticket-related-action', false);
        $this->actingAs($this->vetNurseStaff())
            ->get(route('petcare.tickets.show', $ticket->id))
            ->assertSee('data-ticket-related-action', false);
        $this->actingAs($administrator)
            ->get(route('petcare.tickets.show', $ticket->id))
            ->assertSee('data-ticket-related-action', false);

        $cicTicket = SupportTicket::query()->create([
            'sub_core_key' => SupportTicket::SUB_CORE_APES_CIC,
            'user_id' => $owner->id,
            'service_area' => 'it_support',
            'subject' => 'Website issue',
            'priority' => 'medium',
            'status' => 'open',
            'description' => 'Not a clinic ticket.',
        ]);
        $this->actingAs($administrator)
            ->get(route('petcare.consultations.index', ['ticket' => $cicTicket->id]))
            ->assertOk()
            ->assertDontSee('name="support_ticket_id"', false);
    }

    private function petCareTicket(User $owner, string $subject): SupportTicket
    {
        return SupportTicket::query()->create([
            'sub_core_key' => 'pet-care-clinic',
            'user_id' => $owner->id,
            'service_area' => 'consultation',
            'subject' => $subject,
            'priority' => 'medium',
            'status' => 'open',
            'description' => 'Please book a consultation.',
        ]);
    }

    /**
     * @return array{PetCareConsultation, PetCareConsultation, PetCareConsultation, PetCareConsultation}
     */
    private function legacyConsultations(): array
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $pet = $this->petFor($owner);
        $vet = $this->vetStaff();
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();

        $publicCreated = $this->consultationFor($owner, $pet, ['subject' => 'Public created']);
        app(AuditLogger::class)->record('petcare.consultation.created', $owner, $publicCreated, []);
        $vetCreated = $this->consultationFor($owner, $pet, ['subject' => 'Vet created']);
        $vetCreated->forceFill(['created_by' => $vet->id])->save();
        $unknown = $this->consultationFor($owner, $pet, ['subject' => 'Unknown creator']);
        $staffCreated = $this->consultationFor($owner, $pet, ['subject' => 'Staff created']);
        app(AuditLogger::class)->record('petcare.consultation.created', $staff, $staffCreated, []);

        return [$publicCreated, $vetCreated, $unknown, $staffCreated];
    }

    private function petFor(User $owner, string $domain = PetProfile::DOMAIN_PETCARE): PetProfile
    {
        return PetProfile::query()->create([
            'user_id' => $owner->id,
            'service_domain' => $domain,
            'name' => 'Pico',
            'species' => 'cat',
            'sex' => 'unknown',
            'neutering_status' => 'unknown',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function consultationFor(User $owner, PetProfile $pet, array $attributes = []): PetCareConsultation
    {
        $consultation = (new PetCareConsultation)->forceFill(array_merge([
            'pet_profile_id' => $pet->id,
            'user_id' => $owner->id,
            'subject' => 'Annual check',
            'status' => 'open',
            'notes' => 'Consultation notes.',
        ], $attributes));
        $consultation->save();

        return $consultation->fresh();
    }
}
