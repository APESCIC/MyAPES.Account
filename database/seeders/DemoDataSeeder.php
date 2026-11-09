<?php

namespace Database\Seeders;

use App\Core\Accounts\CallLog;
use App\Core\Accounts\Role;
use App\Core\Accounts\RoleSource;
use App\Core\Accounts\StaffProfile;
use App\Core\Accounts\User;
use App\Core\Accounts\UserProfile;
use App\Core\Attachments\SupportAttachment;
use App\Services\AuthorizationMetadataSynchronizer;
use App\Services\AuthorizationProfile;
use App\Services\AuthorizationRoleMaterializer;
use App\Services\ModuleInstallationSynchronizer;
use App\Support\DefaultJobRoles;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DemoEnvironment;
use Database\Seeders\Demo\FakeUk;
use Faker\Factory as FakerFactory;
use Faker\Generator;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Plugins\Cases\Models\CaseUpdate;
use Plugins\Cases\Models\ShelterCase;
use Plugins\Consultations\Models\PetCareConsultation;
use Plugins\PetProfiles\Models\PetProfile;
use Plugins\Recruitment\Models\RecruitmentApplication;
use Plugins\Recruitment\Models\RecruitmentRole;
use Plugins\Tickets\Models\SupportTicket;

/**
 * Deterministic, clearly fake demo data for local Laragon development (#722).
 *
 * Every run with the same seed writes the same records: Faker uses en_GB and a fixed
 * seed, timestamps come from a fixed base time, and records are upserted on stable keys.
 * It refuses to run outside APP_ENV=local; see docs/laragon/demo-data.md.
 */
class DemoDataSeeder extends Seeder
{
    use WithoutModelEvents;

    public const SEED = 20261109;

    public const PASSWORD = 'Laragon-Demo-Only-2026!';

    public const BASE_TIME = '2026-10-01 09:00:00';

    public const EMAIL_DOMAINS = ['example.test', 'example.org', 'example.com'];

    public const SERVICE_USER_COUNT = 40;

    public const TICKETS_PER_SERVICE = 25;

    public const CASES_PER_SERVICE = 22;

    public const PETS_PER_SERVICE = 22;

    public const CONSULTATION_COUNT = 25;

    public const RECRUITMENT_ROLE_COUNT = 24;

    public const APPLICATION_COUNT = 30;

    public const CALL_LOG_COUNT = 20;

    public const ATTACHMENT_COUNT = 10;

    /**
     * Ready-made sign-ins. Key: email. Values: name, legacy access level, job role, job title, team.
     *
     * @var array<string, array{name: string, access: string, job_role: ?string, job_title: ?string, team: ?string, groups: list<string>}>
     */
    public const LOGINS = [
        'admin@example.test' => [
            'name' => 'Harriet Quillfeather',
            'access' => User::ROLE_ADMIN,
            'job_role' => null,
            'job_title' => 'Operations manager (demo)',
            'team' => StaffProfile::TEAM_OPERATIONS,
            'groups' => ['myapesaccount.admin', 'intranet.managers', 'position.staff'],
        ],
        'staff@example.test' => [
            'name' => 'Tobias Fernsby',
            'access' => User::ROLE_STAFF,
            'job_role' => null,
            'job_title' => 'Rescue coordinator (demo)',
            'team' => StaffProfile::TEAM_SHELTER_RESCUE,
            'groups' => ['myapesaccount.staff', 'position.staff'],
        ],
        'hr@example.test' => [
            'name' => 'Priya Ashdownley',
            'access' => User::ROLE_STAFF,
            'job_role' => DefaultJobRoles::HUMAN_RESOURCES,
            'job_title' => 'People and recruitment lead (demo)',
            'team' => StaffProfile::TEAM_OPERATIONS,
            'groups' => ['myapesaccount.hr', 'myapesaccount.staff', 'position.staff'],
        ],
        'vet@example.test' => [
            'name' => 'Callum Rookwood',
            'access' => User::ROLE_STAFF,
            'job_role' => DefaultJobRoles::VET,
            'job_title' => 'Clinic vet (demo)',
            'team' => StaffProfile::TEAM_PET_CARE_CLINIC,
            'groups' => ['myapesaccount.vet', 'myapesaccount.staff', 'position.staff'],
        ],
        'vetnurse@example.test' => [
            'name' => 'Imogen Larkspur',
            'access' => User::ROLE_STAFF,
            'job_role' => DefaultJobRoles::VET_NURSE,
            'job_title' => 'Veterinary nurse (demo)',
            'team' => StaffProfile::TEAM_PET_CARE_CLINIC,
            'groups' => ['myapesaccount.vetnurse', 'myapesaccount.staff', 'position.staff'],
        ],
        'reception@example.test' => [
            'name' => 'Rhys Penhallow',
            'access' => User::ROLE_STAFF,
            'job_role' => DefaultJobRoles::RECEPTIONIST,
            'job_title' => 'Receptionist (demo)',
            'team' => StaffProfile::TEAM_APES_CIC,
            'groups' => ['myapesaccount.staff', 'position.staff'],
        ],
        'volunteer@example.test' => [
            'name' => 'Niamh Thistlewood',
            'access' => User::ROLE_VOLUNTEER,
            'job_role' => null,
            'job_title' => 'Shelter volunteer (demo)',
            'team' => StaffProfile::TEAM_SHELTER_RESCUE,
            'groups' => ['myapesaccount.volunteer'],
        ],
        'student@example.test' => [
            'name' => 'Oliver Brackenbury',
            'access' => User::ROLE_STUDENT,
            'job_role' => null,
            'job_title' => 'Placement student (demo)',
            'team' => StaffProfile::TEAM_APES_CIC,
            'groups' => ['myapesaccount.student'],
        ],
        'member@example.test' => [
            'name' => 'Sophie Marchbank',
            'access' => User::ROLE_SERVICE_USER,
            'job_role' => null,
            'job_title' => null,
            'team' => null,
            'groups' => [],
        ],
    ];

    private const APES_CIC_TICKET_TOPICS = [
        ['it_systems', 'email_cloudron', null, 'Shared mailbox not syncing on a laptop'],
        ['web_development', 'website_issue', 'apes_org_uk', 'Events page shows the wrong date format'],
        ['web_development', 'login_issue', 'myapes_account', 'Password reset email not arriving'],
        ['governance_legal', 'policy_query', null, 'Question about the volunteer expenses policy'],
        ['human_resources', 'staff_query', null, 'Rota swap request for a weekend shift'],
        ['other', null, null, 'Request for a printed information leaflet'],
    ];

    private const SHELTER_TICKET_TOPICS = [
        ['adoption', 'Adoption enquiry about a bearded dragon'],
        ['surrender', 'Asking about surrendering a corn snake'],
        ['rescue', 'Found a tortoise in the garden'],
        ['fostering', 'Interested in becoming a foster carer'],
        ['animal_welfare', 'Advice on vivarium temperatures'],
        ['other', 'Question about visiting the rescue centre'],
    ];

    private const PET_CARE_TICKET_TOPICS = [
        ['appointment', 'Booking a check-up'],
        ['consultation', 'Question after a recent consultation'],
        ['prescription', 'Repeat prescription request'],
        ['billing', 'Query about an invoice'],
        ['follow_up', 'Follow-up after treatment'],
        ['other', 'Changing contact details for the clinic'],
    ];

    private const CIC_CASE_TOPICS = [
        ['membership_casework', 'member_dispute', null, 'Membership renewal dispute'],
        ['welfare_concern', 'animal_welfare', null, 'Welfare concern reported by a neighbour'],
        ['formal_complaint', 'service_complaint', null, 'Complaint about a delayed reply'],
        ['data_access_request', 'copy_of_data', 'myapes_account', 'Subject access request for account data'],
        ['privacy_request', 'rectification', null, 'Correction of a stored postal address'],
    ];

    private const SHELTER_CASE_TYPES = ['adoption', 'surrender', 'rescue', 'fostering'];

    private const SPECIES = [
        'Bearded dragon', 'Leopard gecko', 'Corn snake', 'Royal python', 'Cockatiel',
        'African grey parrot', 'Budgerigar', 'Chinchilla', 'Degu', "Hermann's tortoise",
        'Axolotl', 'Ferret', 'Crested gecko', 'Chilean rose tarantula', 'Sugar glider',
    ];

    private const PET_NAMES = [
        'Biscuit', 'Pepper', 'Juniper', 'Nutmeg', 'Pickle', 'Saffron', 'Bramble', 'Toffee',
        'Clementine', 'Figaro', 'Hazel', 'Marmalade', 'Pebble', 'Quince', 'Rosemary', 'Sorrel',
        'Tansy', 'Umber', 'Waffle', 'Yarrow', 'Zephyr', 'Acorn', 'Basil', 'Crumpet',
        'Dumpling', 'Ember', 'Fennel', 'Gingersnap', 'Honeydew', 'Inkcap', 'Jasper', 'Kipper',
        'Lentil', 'Moss', 'Nettle', 'Olive', 'Parsnip', 'Quill', 'Rhubarb', 'Sage',
        'Thistle', 'Udon', 'Velvet', 'Willow',
    ];

    private const HEALTH_NOTES = [
        'No known health issues.',
        'Mild shedding problems last spring.',
        'Needs a UVB lamp check every six months.',
        'Overgrown claws trimmed at the last visit.',
        'Watch weight; slightly under target.',
        'Previous respiratory infection, fully recovered.',
    ];

    private const RECRUITMENT_TITLES = [
        'staff' => ['Animal care assistant', 'Fundraising officer', 'Reception coordinator', 'Education officer', 'Clinic administrator', 'Digital content assistant', 'Finance assistant', 'Facilities officer'],
        'volunteer' => ['Weekend feeding volunteer', 'Event helper', 'Transport driver', 'Gardening volunteer', 'Charity shop volunteer', 'Social media helper', 'Photography volunteer', 'School talks volunteer'],
        'student' => ['Animal science placement', 'Veterinary nursing placement', 'Marketing placement', 'Data and research placement', 'Business administration placement', 'Education outreach placement', 'Conservation placement', 'Web design placement'],
    ];

    private Generator $faker;

    private FakeUk $uk;

    private CarbonImmutable $baseTime;

    private string $passwordHash;

    private int $phoneIndex = 0;

    public function run(): void
    {
        DemoEnvironment::assertAllowed();

        app(ModuleInstallationSynchronizer::class)->synchronize();
        app(AuthorizationMetadataSynchronizer::class)->synchronize();

        $this->faker = FakerFactory::create('en_GB');
        $this->faker->seed(self::SEED);
        $this->uk = new FakeUk($this->faker);
        $this->baseTime = CarbonImmutable::parse(self::BASE_TIME, 'Europe/London')->utc();
        $this->passwordHash = Hash::make(self::PASSWORD);

        $logins = $this->seedLogins();
        $serviceUsers = [$logins['member@example.test'], ...$this->seedServiceUsers()];
        $staff = [
            $logins['staff@example.test'],
            $logins['hr@example.test'],
            $logins['reception@example.test'],
            $logins['admin@example.test'],
        ];
        $clinicians = [$logins['vet@example.test'], $logins['vetnurse@example.test']];

        $apesCicTickets = $this->seedApesCicTickets($serviceUsers, $staff);
        $this->seedServiceTickets(ShelterCase::SUB_CORE_SHELTER_RESCUE, self::SHELTER_TICKET_TOPICS, $serviceUsers, $staff);
        $this->seedServiceTickets('pet-care-clinic', self::PET_CARE_TICKET_TOPICS, $serviceUsers, [...$clinicians, $logins['reception@example.test']]);
        $this->seedCicCases($serviceUsers, $staff);
        $shelterPets = $this->seedPets(PetProfile::DOMAIN_SHELTER, $serviceUsers, 0);
        $petCarePets = $this->seedPets(PetProfile::DOMAIN_PETCARE, $serviceUsers, self::PETS_PER_SERVICE);
        $this->seedShelterCases($shelterPets, $staff);
        $this->seedConsultations($petCarePets, $clinicians);
        $this->seedRecruitment($logins['hr@example.test'], $serviceUsers);
        $this->seedCallLogs($serviceUsers, $staff);
        $this->seedAttachments($apesCicTickets);
    }

    /**
     * @return array<string, User>
     */
    private function seedLogins(): array
    {
        $users = [];
        $admin = null;

        foreach (self::LOGINS as $email => $login) {
            $user = $this->upsertUser($email, $login['name'], $login['access'], $login['groups'], $this->baseTime->subDays(120));
            $users[$email] = $user;
            $admin ??= $user;

            if ($login['job_title'] !== null) {
                StaffProfile::query()->updateOrCreate(
                    ['user_id' => $user->id],
                    [
                        'job_title' => $login['job_title'],
                        'team' => $login['team'],
                        'work_phone' => $this->uk->landline($this->phoneIndex++),
                        'photo_path' => null,
                    ],
                );
            } else {
                $this->upsertUserProfile($user, 'Member of the public');
            }
        }

        foreach (self::LOGINS as $email => $login) {
            if ($login['job_role'] !== null) {
                $this->grantJobRole($admin, $users[$email], $login['job_role']);
            }
        }

        return $users;
    }

    /**
     * @return list<User>
     */
    private function seedServiceUsers(): array
    {
        $users = [];

        for ($index = 1; $index <= self::SERVICE_USER_COUNT; $index++) {
            $this->reseed("service-user:{$index}");
            $first = $this->faker->firstName();
            $last = $this->faker->lastName();
            $domain = $index % 2 === 0 ? 'example.org' : 'example.com';
            $email = sprintf(
                '%s.%s.%02d@%s',
                strtolower((string) preg_replace('/[^A-Za-z]/', '', $first)),
                strtolower((string) preg_replace('/[^A-Za-z]/', '', $last)),
                $index,
                $domain,
            );
            $joinedAt = $this->baseTime->subDays($this->faker->numberBetween(10, 360));

            $user = $this->upsertUser($email, "{$first} {$last}", User::ROLE_SERVICE_USER, [], $joinedAt, sprintf('demo.user%02d', $index));
            $this->upsertUserProfile($user, $first);
            $users[] = $user;
        }

        return $users;
    }

    /**
     * @param  list<string>  $groups
     */
    private function upsertUser(string $email, string $name, string $access, array $groups, CarbonImmutable $joinedAt, ?string $username = null): User
    {
        /** @var User $user */
        $user = User::query()->firstOrNew(['email' => $email]);
        $user->forceFill([
            'name' => $name,
            'username' => $username ?? 'demo.'.strstr($email, '@', true),
            'oidc_sub' => null,
            'identity_type' => User::IDENTITY_LOCAL,
            'ldap_groups' => $groups,
            'locale' => 'en_GB',
            'email_verified_at' => $joinedAt,
            'onboarding_completed_at' => $joinedAt,
            'suspended_at' => null,
            'suspended_by' => null,
            'suspension_reason' => null,
        ]);
        if (! is_string($user->password) || ! Hash::check(self::PASSWORD, $user->password)) {
            $user->forceFill(['password' => $this->passwordHash]);
        }
        $user->setAccessLevel($access)->save();

        $protectedRole = Role::query()
            ->where('guard_name', 'web')
            ->where('name', app(AuthorizationProfile::class)->protectedRoleForLegacy($access))
            ->firstOrFail();
        app(AuthorizationRoleMaterializer::class)->grant($user, $protectedRole, RoleSource::SOURCE_SYSTEM);

        $user->contactPreference()->firstOrCreate([], [
            'confirmed_at' => $joinedAt,
            'policy_version' => (string) config('myapes.consent.policy_version'),
        ]);
        foreach (['apes-cic', 'shelter-rescue', 'pet-care-clinic'] as $service) {
            $user->serviceSelections()->firstOrCreate(['sub_core_key' => $service]);
        }

        $this->setTimestamps($user, $joinedAt);

        return $user;
    }

    private function upsertUserProfile(User $user, string $preferredName): void
    {
        $index = $this->phoneIndex++;
        $this->reseed("profile:{$user->email}");

        UserProfile::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'preferred_name' => $preferredName,
                'phone' => $this->uk->mobile($index),
                'organization' => null,
                'support_needs' => 'Demo profile for local testing only.',
                'avatar_path' => null,
                'address_line_1' => $this->uk->streetAddress(),
                'address_line_2' => null,
                'town_city' => $this->uk->town(),
                'county' => $this->uk->county(),
                'postcode' => $this->uk->postcode(),
                'country' => 'GB',
                'mobile_number' => $this->uk->mobile($index),
                'landline_number' => $this->uk->landline($index),
            ],
        );
    }

    private function grantJobRole(User $actor, User $assignee, string $roleName): void
    {
        $role = Role::query()->where('guard_name', 'web')->where('name', $roleName)->firstOrFail();

        app(AuthorizationRoleMaterializer::class)->grant($assignee, $role, RoleSource::SOURCE_LOCAL, actor: $actor);
    }

    /**
     * @param  list<User>  $owners
     * @param  list<User>  $staff
     * @return list<SupportTicket>
     */
    private function seedApesCicTickets(array $owners, array $staff): array
    {
        $tickets = [];

        for ($index = 1; $index <= self::TICKETS_PER_SERVICE; $index++) {
            [$area, $subCategory, $website, $topic] = self::APES_CIC_TICKET_TOPICS[($index - 1) % count(self::APES_CIC_TICKET_TOPICS)];
            $tickets[] = $this->upsertTicket(SupportTicket::SUB_CORE_APES_CIC, $index, $area, $subCategory, $website, $topic, $owners, $staff);
        }

        return $tickets;
    }

    /**
     * @param  list<array{0: string, 1: string}>  $topics
     * @param  list<User>  $owners
     * @param  list<User>  $staff
     */
    private function seedServiceTickets(string $subCoreKey, array $topics, array $owners, array $staff): void
    {
        for ($index = 1; $index <= self::TICKETS_PER_SERVICE; $index++) {
            [$area, $topic] = $topics[($index - 1) % count($topics)];
            $this->upsertTicket($subCoreKey, $index, $area, null, null, $topic, $owners, $staff);
        }
    }

    /**
     * @param  list<User>  $owners
     * @param  list<User>  $staff
     */
    private function upsertTicket(string $subCoreKey, int $index, string $area, ?string $subCategory, ?string $website, string $topic, array $owners, array $staff): SupportTicket
    {
        $this->reseed("ticket:{$subCoreKey}:{$index}");
        $owner = $this->ownerFor($index, $owners, 3);
        $status = $this->faker->randomElement(['open', 'open', 'in_progress', 'in_progress', 'resolved', 'closed']);
        $assignee = $status === 'open' && $this->faker->boolean(40) ? null : $this->faker->randomElement($staff);
        $updatedAt = $this->baseTime->subHours($this->faker->numberBetween(1, 24 * 90));
        $messageCount = $this->faker->numberBetween(2, 4);

        $ticket = SupportTicket::query()->updateOrCreate(
            [
                'sub_core_key' => $subCoreKey,
                'subject' => sprintf('Demo: %s (#%02d)', $topic, $index),
            ],
            [
                'user_id' => $owner->id,
                'assigned_to' => $assignee?->id,
                'service_area' => $area,
                'sub_category' => $subCategory,
                'affected_website_key' => $website,
                'priority' => $this->faker->randomElement(['low', 'medium', 'medium', 'high', 'urgent']),
                'status' => $status,
                'description' => $this->faker->paragraph(3),
                'closed_at' => in_array($status, ['resolved', 'closed'], true) ? $updatedAt : null,
            ],
        );

        for ($message = 1; $message <= $messageCount; $message++) {
            $this->reseed("ticket:{$subCoreKey}:{$index}:message:{$message}");
            $fromStaff = $message % 2 === 0;
            $author = $fromStaff ? ($assignee ?? $staff[0]) : $owner;
            $fixture = $ticket->messages()->updateOrCreate(
                ['user_id' => $author->id, 'message' => sprintf('%s (message %d)', $this->faker->sentence(12), $message)],
                ['is_staff_note' => $fromStaff && $this->faker->boolean(30)],
            );
            $this->setTimestamps($fixture, $updatedAt->subHours(($messageCount - $message) * 3));
        }

        $this->setTimestamps($ticket, $updatedAt);

        return $ticket;
    }

    /**
     * @param  list<User>  $owners
     * @param  list<User>  $staff
     */
    private function seedCicCases(array $owners, array $staff): void
    {
        for ($index = 1; $index <= self::CASES_PER_SERVICE; $index++) {
            [$category, $subCategory, $website, $topic] = self::CIC_CASE_TOPICS[($index - 1) % count(self::CIC_CASE_TOPICS)];
            $this->reseed("cic-case:{$index}");
            $status = $this->faker->randomElement(['open', 'in_progress', 'in_progress', 'waiting_on_user', 'resolved', 'closed']);
            $openedAt = $this->baseTime->subDays($this->faker->numberBetween(2, 120));
            $updatedAt = $openedAt->addDays($this->faker->numberBetween(0, 2));

            $case = ShelterCase::query()->updateOrCreate(
                [
                    'sub_core_key' => ShelterCase::SUB_CORE_APES_CIC,
                    'title' => sprintf('Demo: %s (#%02d)', $topic, $index),
                ],
                [
                    'user_id' => $this->ownerFor($index, $owners, 2)->id,
                    'pet_profile_id' => null,
                    'assigned_to' => $this->faker->randomElement($staff)->id,
                    'case_type' => null,
                    'category' => $category,
                    'sub_category' => $subCategory,
                    'affected_website_key' => $website,
                    'priority' => $this->faker->randomElement(['low', 'medium', 'high', 'urgent']),
                    'status' => $status,
                    'details' => $this->faker->paragraph(3),
                    'opened_at' => $openedAt,
                    'resolved_at' => in_array($status, ['resolved', 'closed'], true) ? $updatedAt : null,
                    'closed_at' => $status === 'closed' ? $updatedAt : null,
                ],
            );

            $this->seedCaseUpdates($case, $staff, $updatedAt);
            $this->setTimestamps($case, $updatedAt);
        }
    }

    /**
     * @param  list<User>  $owners
     * @return list<PetProfile>
     */
    private function seedPets(string $domain, array $owners, int $nameOffset): array
    {
        $pets = [];

        for ($index = 0; $index < self::PETS_PER_SERVICE; $index++) {
            $this->reseed("pet:{$domain}:{$index}");
            $owner = $index < 2 ? $owners[0] : $owners[($index * 3 + $nameOffset) % count($owners)];
            $addedAt = $this->baseTime->subDays($this->faker->numberBetween(5, 200));
            $pet = PetProfile::query()->updateOrCreate(
                [
                    'service_domain' => $domain,
                    'name' => self::PET_NAMES[($index + $nameOffset) % count(self::PET_NAMES)],
                ],
                [
                    'user_id' => $owner->id,
                    'species' => $this->faker->randomElement(self::SPECIES),
                    'age_years' => $this->faker->numberBetween(1, 15),
                    'sex' => $this->faker->randomElement(['male', 'female', 'unknown']),
                    'neutering_status' => $this->faker->randomElement(['neutered', 'not_neutered', 'unknown']),
                    'health_issues' => $this->faker->randomElement(self::HEALTH_NOTES),
                    'photo_path' => null,
                ],
            );
            $this->setTimestamps($pet, $addedAt);
            $pets[] = $pet;
        }

        return $pets;
    }

    /**
     * @param  list<PetProfile>  $pets
     * @param  list<User>  $staff
     */
    private function seedShelterCases(array $pets, array $staff): void
    {
        for ($index = 1; $index <= self::CASES_PER_SERVICE; $index++) {
            $pet = $pets[($index - 1) % count($pets)];
            $caseType = self::SHELTER_CASE_TYPES[($index - 1) % count(self::SHELTER_CASE_TYPES)];
            $this->reseed("shelter-case:{$index}");
            $status = $this->faker->randomElement(['open', 'in_review', 'in_review', 'closed']);
            $updatedAt = $this->baseTime->subDays($this->faker->numberBetween(1, 90));

            $case = ShelterCase::query()->updateOrCreate(
                [
                    'sub_core_key' => ShelterCase::SUB_CORE_SHELTER_RESCUE,
                    'title' => sprintf('Demo: %s %s (#%02d)', $pet->name, $caseType, $index),
                ],
                [
                    'pet_profile_id' => $pet->id,
                    'user_id' => $pet->user_id,
                    'assigned_to' => $this->faker->randomElement($staff)->id,
                    'case_type' => $caseType,
                    'status' => $status,
                    'details' => $this->faker->paragraph(2),
                    'closed_at' => $status === 'closed' ? $updatedAt : null,
                ],
            );

            $this->seedCaseUpdates($case, $staff, $updatedAt);
            $this->setTimestamps($case, $updatedAt);
        }
    }

    /**
     * @param  list<User>  $staff
     */
    private function seedCaseUpdates(ShelterCase $case, array $staff, CarbonImmutable $updatedAt): void
    {
        $this->reseed("case-updates:{$case->sub_core_key}:{$case->title}");
        $count = $this->faker->numberBetween(1, 3);

        for ($update = 1; $update <= $count; $update++) {
            $this->reseed("case-update:{$case->sub_core_key}:{$case->title}:{$update}");
            $internal = $update % 2 === 0;
            $fixture = $case->updates()->updateOrCreate(
                [
                    'user_id' => $internal ? $this->faker->randomElement($staff)->id : $case->user_id,
                    'body' => sprintf('%s (update %d)', $this->faker->sentence(14), $update),
                ],
                ['visibility' => $internal ? CaseUpdate::VISIBILITY_INTERNAL : CaseUpdate::VISIBILITY_PUBLIC],
            );
            $this->setTimestamps($fixture, $updatedAt->subHours(($count - $update) * 5));
        }
    }

    /**
     * @param  list<PetProfile>  $pets
     * @param  list<User>  $clinicians
     */
    private function seedConsultations(array $pets, array $clinicians): void
    {
        $types = ['Annual health check', 'Diet and enrichment review', 'Skin and shedding check', 'Weight review', 'Post-treatment check'];

        for ($index = 1; $index <= self::CONSULTATION_COUNT; $index++) {
            $pet = $pets[($index - 1) % count($pets)];
            $this->reseed("consultation:{$index}");
            $status = $this->faker->randomElement(['open', 'in_progress', 'closed']);
            $scheduledFor = $this->baseTime->addDays($this->faker->numberBetween(-60, 30))->setTime(9 + $this->faker->numberBetween(0, 7), 0);

            $consultation = PetCareConsultation::query()->updateOrCreate(
                [
                    'pet_profile_id' => $pet->id,
                    'subject' => sprintf('Demo: %s for %s (#%02d)', $types[($index - 1) % count($types)], $pet->name, $index),
                ],
                [
                    'user_id' => $pet->user_id,
                    'assigned_to' => $this->faker->randomElement($clinicians)->id,
                    'status' => $status,
                    'notes' => $this->faker->paragraph(2),
                    'scheduled_for' => $scheduledFor,
                    'closed_at' => $status === 'closed' ? $scheduledFor->addHour() : null,
                ],
            );
            $this->setTimestamps($consultation, $scheduledFor->subDays(7));
        }
    }

    /**
     * @param  list<User>  $applicants
     */
    private function seedRecruitment(User $hr, array $applicants): void
    {
        $roles = [];
        $categories = array_keys(self::RECRUITMENT_TITLES);

        for ($index = 0; $index < self::RECRUITMENT_ROLE_COUNT; $index++) {
            $category = $categories[$index % count($categories)];
            $title = self::RECRUITMENT_TITLES[$category][intdiv($index, count($categories))];
            $status = match (true) {
                $index >= self::RECRUITMENT_ROLE_COUNT - 1 => RecruitmentRole::STATUS_CLOSED,
                $index >= self::RECRUITMENT_ROLE_COUNT - 2 => RecruitmentRole::STATUS_DRAFT,
                default => RecruitmentRole::STATUS_OPEN,
            };
            $this->reseed("recruitment-role:{$index}");
            $publishedAt = $this->baseTime->subDays($this->faker->numberBetween(3, 60));

            $role = RecruitmentRole::query()->updateOrCreate(
                ['title' => "Demo: {$title}", 'category' => $category],
                [
                    'created_by' => $hr->id,
                    'summary' => $this->faker->sentence(10),
                    'description' => $this->faker->paragraphs(2, true),
                    'status' => $status,
                    'location' => $this->faker->randomElement(['On site, '.FakeUk::TOWNS[0], 'Hybrid, '.FakeUk::TOWNS[1], 'Remote']),
                    'commitment' => $this->faker->randomElement(['Full-time', 'Part-time', 'Flexible', 'Weekends', '2 days per week']),
                    'published_at' => $status === RecruitmentRole::STATUS_DRAFT ? null : $publishedAt,
                    'closed_at' => $status === RecruitmentRole::STATUS_CLOSED ? $publishedAt->addDays(21) : null,
                ],
            );
            $this->setTimestamps($role, $publishedAt);

            if ($status !== RecruitmentRole::STATUS_DRAFT) {
                $roles[] = $role;
            }
        }

        for ($index = 0; $index < self::APPLICATION_COUNT; $index++) {
            $role = $roles[$index % count($roles)];
            $applicant = $applicants[($index * 7) % count($applicants)];
            $status = RecruitmentApplication::STATUSES[$index % count(RecruitmentApplication::STATUSES)];
            $this->reseed("recruitment-application:{$index}");
            $submittedAt = $this->baseTime->subDays($this->faker->numberBetween(1, 30));
            $reviewed = ! in_array($status, [RecruitmentApplication::STATUS_SUBMITTED, RecruitmentApplication::STATUS_WITHDRAWN], true);
            $decided = in_array($status, [RecruitmentApplication::STATUS_REJECTED, RecruitmentApplication::STATUS_ACCEPTED, RecruitmentApplication::STATUS_CLOSED], true);

            $application = RecruitmentApplication::query()->updateOrCreate(
                ['recruitment_role_id' => $role->id, 'user_id' => $applicant->id],
                [
                    'status' => $status,
                    'statement' => $this->faker->paragraph(3),
                    'staff_notes' => $reviewed ? $this->faker->sentence(10) : null,
                    'submitted_at' => $submittedAt,
                    'reviewed_at' => $reviewed ? $submittedAt->addDays(2) : null,
                    'decided_at' => $decided ? $submittedAt->addDays(5) : null,
                    'withdrawn_at' => $status === RecruitmentApplication::STATUS_WITHDRAWN ? $submittedAt->addDay() : null,
                ],
            );
            $this->setTimestamps($application, $submittedAt);
        }
    }

    /**
     * @param  list<User>  $callers
     * @param  list<User>  $staff
     */
    private function seedCallLogs(array $callers, array $staff): void
    {
        for ($index = 0; $index < self::CALL_LOG_COUNT; $index++) {
            $this->reseed("call-log:{$index}");
            $calledAt = $this->baseTime->subHours($this->faker->numberBetween(1, 24 * 60));
            $call = CallLog::query()->updateOrCreate(
                ['caller_id' => $callers[($index * 5) % count($callers)]->id, 'called_at' => $calledAt],
                [
                    'staff_id' => $this->faker->randomElement($staff)->id,
                    'direction' => $this->faker->randomElement(CallLog::DIRECTIONS),
                    'summary' => $this->faker->sentence(15),
                ],
            );
            $this->setTimestamps($call, $calledAt);
        }
    }

    /**
     * @param  list<SupportTicket>  $tickets
     */
    private function seedAttachments(array $tickets): void
    {
        foreach (array_slice($tickets, 0, self::ATTACHMENT_COUNT) as $index => $ticket) {
            $name = sprintf('demo-screenshot-%02d.png', $index + 1);
            $path = sprintf('demo/support-attachments/%d/%s', $ticket->id, $name);
            Storage::disk('public')->put($path, $this->placeholderPng());

            $attachment = SupportAttachment::query()->updateOrCreate(
                [
                    'attachable_type' => $ticket->getMorphClass(),
                    'attachable_id' => $ticket->id,
                    'original_name' => $name,
                    'kind' => 'screenshot',
                ],
                [
                    'user_id' => $ticket->user_id,
                    'disk' => 'public',
                    'path' => $path,
                    'mime_type' => 'image/png',
                    'size_bytes' => Storage::disk('public')->size($path),
                ],
            );
            $this->setTimestamps($attachment, CarbonImmutable::instance($ticket->updated_at));
        }
    }

    /**
     * The first records in each list belong to member@example.test (the first owner),
     * so the public demo login has its own records to open.
     *
     * @param  list<User>  $owners
     */
    private function ownerFor(int $index, array $owners, int $memberRecords): User
    {
        $randomOwner = $this->faker->randomElement($owners);

        return $index <= $memberRecords ? $owners[0] : $randomOwner;
    }

    /**
     * Faker shares PHP's global Mersenne Twister with the framework, so each record
     * re-seeds from its own stable key to stay identical however many records ran before.
     */
    private function reseed(string $key): void
    {
        $this->faker->seed(self::SEED + crc32($key));
    }

    private function placeholderPng(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        ) ?: '';
    }

    private function setTimestamps(Model $model, CarbonImmutable $updatedAt): void
    {
        $model->forceFill([
            'created_at' => $updatedAt->subHour(),
            'updated_at' => $updatedAt,
        ])->saveQuietly();
    }
}
