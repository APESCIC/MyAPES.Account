<?php

namespace Tests\Feature\AccountSeparation;

use App\Core\Accounts\AuditLog;
use App\Core\Accounts\ClientRecords\ClientRecordRegistry;
use App\Core\Accounts\User;
use App\Core\Mail\MailTemplateRegistry;
use App\Notifications\StaffClientRecordsArchivedNotification;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Plugins\Cases\Models\ShelterCase;
use Plugins\Consultations\Models\PetCareConsultation;
use Plugins\PetProfiles\Models\PetProfile;
use Plugins\Recruitment\Models\RecruitmentApplication;
use Plugins\Recruitment\Models\RecruitmentRole;
use Plugins\Tickets\Models\SupportTicket;
use Tests\TestCase;

class StaffClientRecordArchiveTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
        Notification::fake();
        Storage::fake('local');

        $this->staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create(['email' => 'staff.member@example.com']);
        $this->client = User::factory()->create(['email' => 'client.person@example.com']);
        $this->recordsFor($this->staff, 'Staff');
        $this->recordsFor($this->client, 'Client');
    }

    public function test_plugins_register_all_five_client_record_types(): void
    {
        $this->assertSame(
            ['cases', 'consultations', 'pet-profiles', 'recruitment-applications', 'tickets'],
            array_keys(app(ClientRecordRegistry::class)->all()),
        );
        $this->assertNotNull(app(MailTemplateRegistry::class)->find('accounts.staff_client_records_archived'));
    }

    public function test_dry_run_reports_staff_records_to_the_console_and_csv_without_changing_anything(): void
    {
        $this->artisan('myapes:accounts:detach-staff-client-records', ['--dry-run' => true])
            ->expectsOutputToContain('staff.member@example.com')
            ->expectsOutputToContain('Dry run: 6 record(s) on 1 account(s) would be archived. Nothing was changed.')
            ->doesntExpectOutputToContain('client.person@example.com')
            ->assertSuccessful();

        $reports = Storage::disk('local')->files('reports');
        $this->assertCount(1, $reports);
        $csv = Storage::disk('local')->get($reports[0]);
        $this->assertStringStartsWith('user_id,email,account,record_type,record_id', $csv);
        $this->assertSame(6, substr_count($csv, 'staff.member@example.com'));
        $this->assertStringNotContainsString('client.person@example.com', $csv);

        $this->assertSame(2, SupportTicket::query()->count());
        $this->assertNull($this->staff->fresh()->client_records_archived_at);
        Notification::assertNothingSent();
    }

    public function test_declining_the_confirmation_changes_nothing(): void
    {
        $this->artisan('myapes:accounts:detach-staff-client-records')
            ->expectsConfirmation('Archive 6 record(s) on 1 account(s) and email those accounts?', 'no')
            ->assertFailed();

        $this->assertSame(2, SupportTicket::query()->count());
        $this->assertSame(0, AuditLog::query()->where('event', 'accounts.client_records_archived')->count());
        Notification::assertNothingSent();
    }

    public function test_applying_archives_staff_records_recoverably_audits_and_emails_the_account(): void
    {
        $this->artisan('myapes:accounts:detach-staff-client-records')
            ->expectsConfirmation('Archive 6 record(s) on 1 account(s) and email those accounts?', 'yes')
            ->expectsOutputToContain('Archived 6 record(s) on 1 account(s). Nothing was deleted.')
            ->assertSuccessful();

        foreach ([SupportTicket::class, PetProfile::class, ShelterCase::class, PetCareConsultation::class, RecruitmentApplication::class] as $model) {
            $this->assertSame(0, $model::query()->where('user_id', $this->staff->id)->count(), $model);
            $this->assertGreaterThan(0, $model::withArchived()->where('user_id', $this->staff->id)->count(), $model);
            $this->assertGreaterThan(0, $model::query()->where('user_id', $this->client->id)->count(), $model);
        }
        $this->assertSame(6, SupportTicket::onlyArchived()->count()
            + PetProfile::onlyArchived()->count()
            + ShelterCase::onlyArchived()->count()
            + PetCareConsultation::onlyArchived()->count()
            + RecruitmentApplication::onlyArchived()->count());

        $staff = $this->staff->fresh();
        $this->assertNotNull($staff->client_records_archived_at);
        $audit = AuditLog::query()->where('event', 'accounts.client_records_archived')->sole();
        $this->assertSame($staff->id, (int) $audit->auditable_id);
        $this->assertSame(['cases' => 1, 'consultations' => 1, 'pet-profiles' => 2, 'recruitment-applications' => 1, 'tickets' => 1], $audit->context['records']);

        Notification::assertSentTo($staff, StaffClientRecordsArchivedNotification::class, function (StaffClientRecordsArchivedNotification $notification) use ($staff): bool {
            $mail = $notification->toMail($staff);

            return $mail->actionUrl === route('public.register')
                && in_array('Tickets: 1', $mail->introLines, true);
        });
        Notification::assertNotSentTo($this->client, StaffClientRecordsArchivedNotification::class);

        $this->actingAs($this->client)
            ->get(route('apes-cic.tickets.index'))
            ->assertOk()
            ->assertSee('Client ticket');
    }

    public function test_force_applies_without_a_prompt_and_a_second_run_finds_nothing(): void
    {
        $this->artisan('myapes:accounts:detach-staff-client-records', ['--force' => true])->assertSuccessful();

        $this->artisan('myapes:accounts:detach-staff-client-records', ['--dry-run' => true])
            ->expectsOutputToContain('No staff-side account holds active client records.')
            ->assertSuccessful();
    }

    public function test_restore_brings_every_archived_record_back(): void
    {
        $this->artisan('myapes:accounts:detach-staff-client-records', ['--force' => true])->assertSuccessful();

        $this->artisan('myapes:accounts:restore-staff-client-records', ['user' => 'STAFF.MEMBER@example.com'])
            ->expectsConfirmation("Restore archived client records for user {$this->staff->id}?", 'yes')
            ->expectsOutputToContain('Restored 6 record(s)')
            ->assertSuccessful();

        $this->assertSame(1, SupportTicket::query()->where('user_id', $this->staff->id)->count());
        $this->assertSame(2, PetProfile::query()->where('user_id', $this->staff->id)->count());
        $this->assertNull($this->staff->fresh()->client_records_archived_at);
        $this->assertSame(1, AuditLog::query()->where('event', 'accounts.client_records_restored')->count());
    }

    public function test_restore_rejects_an_unknown_account(): void
    {
        $this->artisan('myapes:accounts:restore-staff-client-records', ['user' => 'nobody@example.com', '--force' => true])
            ->expectsOutputToContain('No account matches that user ID or email.')
            ->assertFailed();
    }

    private function recordsFor(User $owner, string $label): void
    {
        SupportTicket::query()->create([
            'sub_core_key' => 'apes-cic',
            'user_id' => $owner->id,
            'service_area' => 'operations_facilities',
            'sub_category' => 'premises',
            'subject' => "{$label} ticket",
            'priority' => 'medium',
            'status' => 'open',
            'description' => 'Archive fixture.',
        ]);
        $shelterPet = PetProfile::query()->create([
            'user_id' => $owner->id,
            'service_domain' => PetProfile::DOMAIN_SHELTER,
            'name' => "{$label} shelter pet",
            'sex' => 'unknown',
            'neutering_status' => 'unknown',
        ]);
        ShelterCase::query()->create([
            'sub_core_key' => 'shelter-rescue',
            'pet_profile_id' => $shelterPet->id,
            'user_id' => $owner->id,
            'case_type' => 'rescue',
            'title' => "{$label} case",
        ]);
        $carePet = PetProfile::query()->create([
            'user_id' => $owner->id,
            'service_domain' => PetProfile::DOMAIN_PETCARE,
            'name' => "{$label} care pet",
            'sex' => 'unknown',
            'neutering_status' => 'unknown',
        ]);
        PetCareConsultation::query()->create([
            'pet_profile_id' => $carePet->id,
            'user_id' => $owner->id,
            'subject' => "{$label} consultation",
        ]);
        RecruitmentApplication::factory()
            ->forRole(RecruitmentRole::factory()->open()->create())
            ->forUser($owner)
            ->create();
    }
}
