<?php

namespace Tests\Feature;

use App\Core\Accounts\CallLog;
use App\Core\Accounts\User;
use App\Core\Accounts\UserProfile;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Plugins\Cases\Models\ShelterCase;
use Plugins\Consultations\Models\PetCareConsultation;
use Plugins\PetProfiles\Models\PetProfile;
use Plugins\Recruitment\Models\RecruitmentApplication;
use Plugins\Recruitment\Models\RecruitmentRole;
use Plugins\Tickets\Models\SupportTicket;
use Tests\TestCase;

class DemoDataSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_command_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('myapes:demo-data')
            ->expectsOutputToContain('Demo data only runs with APP_ENV=local')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'admin@example.test']);
    }

    public function test_reset_refuses_to_wipe_anything_outside_local(): void
    {
        $this->app['env'] = 'production';
        User::factory()->create(['email' => 'kept@example.test']);

        $this->artisan('myapes:demo-data', ['--reset' => true])->assertFailed();

        $this->assertDatabaseHas('users', ['email' => 'kept@example.test']);
    }

    public function test_command_refuses_to_run_on_cloudron(): void
    {
        $this->app['env'] = 'local';
        $_SERVER['CLOUDRON_APP_ORIGIN'] = 'https://myaccount.example.test';

        try {
            $this->artisan('myapes:demo-data')
                ->expectsOutputToContain('looks like Cloudron')
                ->assertFailed();
        } finally {
            unset($_SERVER['CLOUDRON_APP_ORIGIN']);
        }
    }

    public function test_seeder_refuses_outside_local_even_when_called_directly(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('APP_ENV is "testing"');

        $this->seed(DemoDataSeeder::class);
    }

    public function test_seeding_twice_gives_identical_data_without_duplicates(): void
    {
        $this->app['env'] = 'local';

        $this->seed(DemoDataSeeder::class);
        $first = $this->snapshot();

        $this->seed(DemoDataSeeder::class);

        $this->assertSame($first, $this->snapshot());
    }

    public function test_every_list_has_more_than_one_page_of_records(): void
    {
        $this->app['env'] = 'local';
        $this->seed(DemoDataSeeder::class);

        $this->assertGreaterThan(25, User::query()->count());
        foreach (['apes-cic', 'shelter-rescue', 'pet-care-clinic'] as $service) {
            $this->assertGreaterThan(20, SupportTicket::query()->where('sub_core_key', $service)->count());
        }
        foreach (['apes-cic', 'shelter-rescue'] as $service) {
            $this->assertGreaterThan(20, ShelterCase::query()->where('sub_core_key', $service)->count());
        }
        $this->assertGreaterThan(20, PetProfile::query()->where('service_domain', PetProfile::DOMAIN_SHELTER)->count());
        $this->assertGreaterThan(20, PetProfile::query()->where('service_domain', PetProfile::DOMAIN_PETCARE)->count());
        $this->assertGreaterThan(20, PetCareConsultation::query()->count());
        $this->assertGreaterThan(20, RecruitmentApplication::query()->count());
        $this->assertGreaterThan(
            20,
            RecruitmentRole::query()->where('status', RecruitmentRole::STATUS_OPEN)->count(),
        );
        $this->assertSame(DemoDataSeeder::CALL_LOG_COUNT, CallLog::query()->count());
        $this->assertSame(DemoDataSeeder::ATTACHMENT_COUNT, DB::table('support_attachments')->count());
    }

    public function test_demo_people_use_only_example_domains_and_drama_phone_numbers(): void
    {
        $this->app['env'] = 'local';
        $this->seed(DemoDataSeeder::class);

        foreach (User::query()->pluck('email') as $email) {
            $domain = substr((string) strrchr($email, '@'), 1);
            $this->assertContains($domain, DemoDataSeeder::EMAIL_DOMAINS, "{$email} is not on an example domain.");
        }

        foreach (UserProfile::query()->get(['phone', 'mobile_number', 'landline_number']) as $profile) {
            $this->assertMatchesRegularExpression('/^\+447700900\d{3}$/', (string) $profile->mobile_number);
            $this->assertMatchesRegularExpression('/^\+447700900\d{3}$/', (string) $profile->phone);
            $this->assertMatchesRegularExpression('/^\+442079460\d{3}$/', (string) $profile->landline_number);
        }
        foreach (DB::table('staff_profiles')->pluck('work_phone') as $phone) {
            $this->assertMatchesRegularExpression('/^\+442079460\d{3}$/', (string) $phone);
        }
    }

    public function test_each_documented_login_has_its_role(): void
    {
        $this->app['env'] = 'local';
        $this->seed(DemoDataSeeder::class);

        foreach (DemoDataSeeder::LOGINS as $email => $login) {
            $user = User::query()->where('email', $email)->firstOrFail();

            $this->assertSame($login['access'], $user->accessLevel(), "{$email} has the wrong access level.");
            if ($login['job_role'] !== null) {
                $this->assertTrue($user->hasRole($login['job_role']), "{$email} is missing {$login['job_role']}.");
            }
        }
    }

    public function test_member_and_staff_demo_logins_sign_in(): void
    {
        $this->app['env'] = 'local';
        $this->seed(DemoDataSeeder::class);
        $this->withSession(['_token' => 'demo-csrf-token']);

        $this->post('/login', [
            '_token' => 'demo-csrf-token',
            'email' => 'member@example.test',
            'password' => DemoDataSeeder::PASSWORD,
        ])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs(User::query()->where('email', 'member@example.test')->firstOrFail());

        auth()->logout();
        $this->withSession(['_token' => 'demo-csrf-token']);

        $this->post('/staff/login', [
            '_token' => 'demo-csrf-token',
            'email' => 'admin@example.test',
            'password' => DemoDataSeeder::PASSWORD,
        ])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs(User::query()->where('email', 'admin@example.test')->firstOrFail());
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function snapshot(): array
    {
        $tables = [
            'users' => ['id', 'email', 'name', 'username', 'created_at', 'updated_at'],
            'user_profiles' => ['user_id', 'phone', 'address_line_1', 'town_city', 'postcode'],
            'staff_profiles' => ['user_id', 'job_title', 'work_phone'],
            'support_tickets' => ['id', 'sub_core_key', 'subject', 'user_id', 'assigned_to', 'status', 'priority', 'updated_at'],
            'support_ticket_messages' => ['id', 'support_ticket_id', 'user_id', 'message', 'is_staff_note', 'updated_at'],
            'shelter_cases' => ['id', 'sub_core_key', 'title', 'user_id', 'pet_profile_id', 'status', 'updated_at'],
            'case_updates' => ['id', 'shelter_case_id', 'user_id', 'body', 'visibility'],
            'pet_profiles' => ['id', 'service_domain', 'name', 'user_id', 'species'],
            'pet_care_consultations' => ['id', 'pet_profile_id', 'subject', 'status', 'scheduled_for'],
            'recruitment_roles' => ['id', 'title', 'category', 'status'],
            'recruitment_applications' => ['id', 'recruitment_role_id', 'user_id', 'status'],
            'call_logs' => ['id', 'caller_id', 'staff_id', 'summary', 'called_at'],
            'support_attachments' => ['id', 'attachable_id', 'path'],
        ];

        $snapshot = [];
        foreach ($tables as $table => $columns) {
            $snapshot[$table] = DB::table($table)
                ->orderBy($columns[0])
                ->get($columns)
                ->map(static fn (object $row): array => (array) $row)
                ->all();
        }

        return $snapshot;
    }
}
