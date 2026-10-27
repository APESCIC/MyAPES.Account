<?php

namespace Database\Seeders;

use App\Core\Accounts\Role;
use App\Core\Accounts\RoleSource;
use App\Core\Accounts\StaffProfile;
use App\Core\Accounts\User;
use App\Core\Accounts\UserProfile;
use App\Core\Attachments\SupportAttachment;
use App\Services\AuthorizationMetadataSynchronizer;
use App\Services\AuthorizationProfile;
use App\Services\AuthorizationRoleManagementService;
use App\Services\AuthorizationRoleMaterializer;
use App\Support\DefaultJobRoles;
use Carbon\CarbonImmutable;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Plugins\Cases\Models\CaseUpdate;
use Plugins\Cases\Models\ShelterCase;
use Plugins\Consultations\Models\PetCareConsultation;
use Plugins\PetProfiles\Models\PetProfile;
use Plugins\Recruitment\Models\RecruitmentRole;
use Plugins\Tickets\Models\SupportTicket;

class LocalQaSeeder extends Seeder
{
    use WithoutModelEvents;

    public const SERVICE_USER_EMAIL = 'qa.service.user@myapes.local';

    public const STUDENT_EMAIL = 'qa.student@myapes.local';

    public const VOLUNTEER_EMAIL = 'qa.volunteer@myapes.local';

    public const STAFF_EMAIL = 'qa.staff@myapes.local';

    public const HR_EMAIL = 'qa.hr@myapes.local';

    public const ADMIN_EMAIL = 'qa.admin@myapes.local';

    public const SUPERADMIN_EMAIL = 'qa.superadmin@myapes.local';

    public const CUSTOM_ROLE_NAME = 'local-qa-reviewer';

    public const DEFAULT_PASSWORD = 'MyAPES-Local-QA-2026!';

    /**
     * @return list<string>
     */
    public static function emails(): array
    {
        return [
            self::SERVICE_USER_EMAIL,
            self::STUDENT_EMAIL,
            self::VOLUNTEER_EMAIL,
            self::STAFF_EMAIL,
            self::HR_EMAIL,
            self::ADMIN_EMAIL,
            self::SUPERADMIN_EMAIL,
        ];
    }

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException(
                'Local QA fixtures are unavailable outside local and testing environments.',
            );
        }

        app(AuthorizationMetadataSynchronizer::class)->synchronize();
        $seededAt = CarbonImmutable::parse('2026-07-24 09:00:00', 'Europe/London')->utc();

        $serviceUser = $this->upsertUser(
            self::SERVICE_USER_EMAIL,
            'QA Service User',
            User::ROLE_SERVICE_USER,
            $seededAt
        );
        $studentUser = $this->upsertUser(
            self::STUDENT_EMAIL,
            'QA Student User',
            User::ROLE_STUDENT,
            $seededAt
        );
        $volunteerUser = $this->upsertUser(
            self::VOLUNTEER_EMAIL,
            'QA Volunteer User',
            User::ROLE_VOLUNTEER,
            $seededAt
        );
        $staffUser = $this->upsertUser(
            self::STAFF_EMAIL,
            'QA Staff User',
            User::ROLE_STAFF,
            $seededAt
        );
        $hrUser = $this->upsertUser(
            self::HR_EMAIL,
            'QA HR User',
            User::ROLE_STAFF,
            $seededAt
        );
        $adminUser = $this->upsertUser(
            self::ADMIN_EMAIL,
            'QA Admin User',
            User::ROLE_ADMIN,
            $seededAt
        );
        $superAdminUser = $this->upsertUser(
            self::SUPERADMIN_EMAIL,
            'QA Superadmin User',
            User::ROLE_SUPERADMIN,
            $seededAt
        );
        $this->seedDirectoryGroupsForQaStaff(
            $studentUser,
            $volunteerUser,
            $staffUser,
            $hrUser,
            $adminUser,
            $superAdminUser,
        );
        $this->upsertCustomRole($superAdminUser, $staffUser);
        $this->grantHumanResourcesJobRole($superAdminUser, $hrUser);

        $this->upsertProfile($serviceUser, 'Service User', '+44 7700 900101', 'APES CIC Service User');
        $this->upsertStaffProfile($studentUser, 'Placement student', StaffProfile::TEAM_SHELTER_RESCUE, '+447700900105');
        $this->upsertStaffProfile($volunteerUser, 'Shelter volunteer', StaffProfile::TEAM_SHELTER_RESCUE, '+447700900106');
        $this->upsertStaffProfile($staffUser, 'Rescue coordinator', StaffProfile::TEAM_SHELTER_RESCUE, '+447700900102');
        $this->upsertStaffProfile($hrUser, 'People and recruitment lead', StaffProfile::TEAM_OPERATIONS, '+447700900107');
        $this->upsertStaffProfile($adminUser, 'Operations manager', StaffProfile::TEAM_OPERATIONS, '+447700900103');
        $this->upsertStaffProfile($superAdminUser, 'Director', StaffProfile::TEAM_OPERATIONS, '+447700900104');

        $openTicket = SupportTicket::query()->updateOrCreate(
            [
                'sub_core_key' => SupportTicket::SUB_CORE_APES_CIC,
                'user_id' => $serviceUser->id,
                'subject' => 'QA Seed: IT account support request',
            ],
            [
                'assigned_to' => $staffUser->id,
                'service_area' => 'it_systems',
                'sub_category' => 'email_cloudron',
                'priority' => 'high',
                'status' => 'in_progress',
                'description' => 'Service user cannot access a shared mailbox from home.',
                'closed_at' => null,
            ]
        );
        $this->upsertTicketMessage(
            $openTicket,
            $serviceUser,
            'Initial issue report for remote mailbox access.',
            false,
            $seededAt->addMinutes(20),
        );
        $this->upsertTicketMessage(
            $openTicket,
            $staffUser,
            'Investigating SMTP relay and mailbox permissions.',
            true,
            $seededAt->addMinutes(30),
        );
        $this->setTimestamps($openTicket, $seededAt->addMinutes(40));

        $followUpTicket = SupportTicket::query()->updateOrCreate(
            [
                'sub_core_key' => SupportTicket::SUB_CORE_APES_CIC,
                'user_id' => $serviceUser->id,
                'subject' => 'QA Seed: Documentation follow-up',
            ],
            [
                'assigned_to' => $staffUser->id,
                'service_area' => 'governance_legal',
                'sub_category' => 'policy_query',
                'priority' => 'medium',
                'status' => 'open',
                'description' => 'Supporting documents are ready for a final service review.',
                'closed_at' => null,
            ]
        );
        $this->upsertTicketMessage(
            $followUpTicket,
            $serviceUser,
            'The requested supporting documents have been uploaded.',
            false,
            $seededAt->addMinutes(5),
        );
        $this->upsertTicketMessage(
            $followUpTicket,
            $staffUser,
            'Ready for final document review.',
            true,
            $seededAt->addMinutes(10),
        );
        $this->setTimestamps($followUpTicket, $seededAt->addMinutes(20));

        $closedTicket = SupportTicket::query()->updateOrCreate(
            [
                'sub_core_key' => SupportTicket::SUB_CORE_APES_CIC,
                'user_id' => $serviceUser->id,
                'subject' => 'QA Seed: HR policy clarification',
            ],
            [
                'assigned_to' => $adminUser->id,
                'service_area' => 'human_resources',
                'sub_category' => 'staff_query',
                'priority' => 'medium',
                'status' => 'resolved',
                'description' => 'Clarification requested on volunteer rota policy updates.',
                'closed_at' => $seededAt->subDay(),
            ]
        );
        $this->upsertTicketMessage(
            $closedTicket,
            $serviceUser,
            'Please confirm the rota policy for evenings.',
            false,
            $seededAt->subDays(3)->subMinutes(20),
        );
        $this->upsertTicketMessage(
            $closedTicket,
            $adminUser,
            'Policy shared and confirmed with service user.',
            true,
            $seededAt->subDays(3)->subMinutes(10),
        );
        $this->setTimestamps($closedTicket, $seededAt->subDays(3));

        $websiteIssueTicket = SupportTicket::query()->updateOrCreate(
            [
                'sub_core_key' => SupportTicket::SUB_CORE_APES_CIC,
                'user_id' => $serviceUser->id,
                'subject' => 'QA Seed: Homepage layout broken on mobile',
            ],
            [
                'assigned_to' => $staffUser->id,
                'service_area' => 'web_development',
                'sub_category' => 'website_issue',
                'affected_website_key' => 'apes_org_uk',
                'priority' => 'high',
                'status' => 'open',
                'description' => 'The APES main website hero and route finder overlap on narrow screens.',
                'closed_at' => null,
            ],
        );
        $this->upsertTicketMessage(
            $websiteIssueTicket,
            $serviceUser,
            'Attached screenshots from an iPhone and Android browser.',
            false,
            $seededAt->addMinutes(50),
        );
        $this->upsertTicketMessage(
            $websiteIssueTicket,
            $staffUser,
            'Reproduced on mobile viewport; queued for digital team review.',
            true,
            $seededAt->addMinutes(55),
        );
        $this->setTimestamps($websiteIssueTicket, $seededAt->addHour());
        $this->upsertDemoAttachment(
            $websiteIssueTicket,
            $serviceUser,
            'screenshot',
            'homepage-mobile-overlap.png',
            $seededAt->addMinutes(50),
        );
        $this->upsertDemoAttachment(
            $websiteIssueTicket,
            $serviceUser,
            'screenshot',
            'homepage-route-finder.png',
            $seededAt->addMinutes(51),
        );

        $loginIssueTicket = SupportTicket::query()->updateOrCreate(
            [
                'sub_core_key' => SupportTicket::SUB_CORE_APES_CIC,
                'user_id' => $serviceUser->id,
                'subject' => 'QA Seed: MyAPES login redirect loop',
            ],
            [
                'assigned_to' => $adminUser->id,
                'service_area' => 'web_development',
                'sub_category' => 'login_issue',
                'affected_website_key' => 'myapes_account',
                'priority' => 'urgent',
                'status' => 'in_progress',
                'description' => 'Signing in returns to the login screen instead of the dashboard.',
                'closed_at' => null,
            ],
        );
        $this->upsertTicketMessage(
            $loginIssueTicket,
            $serviceUser,
            'Screencast shows the redirect loop after password entry.',
            false,
            $seededAt->addMinutes(70),
        );
        $this->upsertTicketMessage(
            $loginIssueTicket,
            $adminUser,
            'Checking session cookie and local auth path.',
            true,
            $seededAt->addMinutes(80),
        );
        $this->setTimestamps($loginIssueTicket, $seededAt->addMinutes(85));
        $this->upsertDemoAttachment(
            $loginIssueTicket,
            $serviceUser,
            'screenshot',
            'myapes-login-loop.png',
            $seededAt->addMinutes(70),
        );
        $this->upsertDemoAttachment(
            $loginIssueTicket,
            $serviceUser,
            'screencast',
            'myapes-login-loop.webm',
            $seededAt->addMinutes(71),
        );

        $shelterOpenTicket = SupportTicket::query()->updateOrCreate(
            [
                'sub_core_key' => ShelterCase::SUB_CORE_SHELTER_RESCUE,
                'user_id' => $serviceUser->id,
                'subject' => 'QA Seed: Shelter adoption enquiry',
            ],
            [
                'assigned_to' => null,
                'service_area' => 'adoption',
                'priority' => 'low',
                'status' => 'open',
                'description' => 'Service user is preparing for the adoption home-check process.',
                'closed_at' => null,
            ],
        );
        $this->upsertTicketMessage(
            $shelterOpenTicket,
            $serviceUser,
            'Could you explain the adoption home-check steps?',
            false,
            $seededAt->addMinutes(15),
        );
        $this->upsertTicketMessage(
            $shelterOpenTicket,
            $staffUser,
            'Internal note: confirm adopter information before follow-up.',
            true,
            $seededAt->addMinutes(25),
        );
        $this->setTimestamps($shelterOpenTicket, $seededAt->addMinutes(35));

        $shelterAssignedTicket = SupportTicket::query()->updateOrCreate(
            [
                'sub_core_key' => ShelterCase::SUB_CORE_SHELTER_RESCUE,
                'user_id' => $serviceUser->id,
                'subject' => 'QA Seed: Shelter rescue coordination',
            ],
            [
                'assigned_to' => $staffUser->id,
                'service_area' => 'rescue',
                'priority' => 'high',
                'status' => 'in_progress',
                'description' => 'Coordinating transport and temporary foster space for a rescue intake.',
                'closed_at' => null,
            ],
        );
        $this->upsertTicketMessage(
            $shelterAssignedTicket,
            $serviceUser,
            'A transport volunteer is available for the rescue intake.',
            false,
            $seededAt->subMinutes(5),
        );
        $this->upsertTicketMessage(
            $shelterAssignedTicket,
            $staffUser,
            'Internal note: coordinating transport and temporary foster space.',
            true,
            $seededAt->addMinutes(5),
        );
        $this->setTimestamps($shelterAssignedTicket, $seededAt->addMinutes(15));

        $shelterClosedTicket = SupportTicket::query()->updateOrCreate(
            [
                'sub_core_key' => ShelterCase::SUB_CORE_SHELTER_RESCUE,
                'user_id' => $serviceUser->id,
                'subject' => 'QA Seed: Shelter welfare follow-up',
            ],
            [
                'assigned_to' => $adminUser->id,
                'service_area' => 'animal_welfare',
                'priority' => 'medium',
                'status' => 'closed',
                'description' => 'The planned animal-welfare follow-up has been completed.',
                'closed_at' => $seededAt->subDays(4),
            ],
        );
        $this->upsertTicketMessage(
            $shelterClosedTicket,
            $serviceUser,
            'Thank you for confirming the welfare follow-up.',
            false,
            $seededAt->subDays(4)->subMinutes(20),
        );
        $this->upsertTicketMessage(
            $shelterClosedTicket,
            $adminUser,
            'Internal note: welfare follow-up is complete.',
            true,
            $seededAt->subDays(4)->subMinutes(10),
        );
        $this->setTimestamps($shelterClosedTicket, $seededAt->subDays(4));

        $petCareAppointmentTicket = SupportTicket::query()->updateOrCreate(
            [
                'sub_core_key' => 'pet-care-clinic',
                'user_id' => $serviceUser->id,
                'subject' => 'QA Seed: APES Pet Care Clinic appointment request',
            ],
            [
                'assigned_to' => null,
                'service_area' => 'appointment',
                'priority' => 'low',
                'status' => 'open',
                'description' => 'Service user would like to arrange Pico\'s next clinic appointment.',
                'closed_at' => null,
            ],
        );
        $this->upsertTicketMessage(
            $petCareAppointmentTicket,
            $serviceUser,
            'Please help me arrange Pico\'s next clinic appointment.',
            false,
            $seededAt->addMinutes(45),
        );
        $this->upsertTicketMessage(
            $petCareAppointmentTicket,
            $staffUser,
            'Internal note: check clinician availability before replying.',
            true,
            $seededAt->addMinutes(50),
        );
        $this->setTimestamps($petCareAppointmentTicket, $seededAt->addMinutes(55));

        $petCarePrescriptionTicket = SupportTicket::query()->updateOrCreate(
            [
                'sub_core_key' => 'pet-care-clinic',
                'user_id' => $serviceUser->id,
                'subject' => 'QA Seed: APES Pet Care Clinic prescription question',
            ],
            [
                'assigned_to' => $staffUser->id,
                'service_area' => 'prescription',
                'priority' => 'high',
                'status' => 'in_progress',
                'description' => 'Service user needs clarification about consultation prescription instructions.',
                'closed_at' => null,
            ],
        );
        $this->upsertTicketMessage(
            $petCarePrescriptionTicket,
            $serviceUser,
            'Could you confirm the instructions from Pico\'s prescription?',
            false,
            $seededAt->subMinutes(15),
        );
        $this->upsertTicketMessage(
            $petCarePrescriptionTicket,
            $staffUser,
            'Internal note: verify the consultation advice before responding.',
            true,
            $seededAt->subMinutes(10),
        );
        $this->setTimestamps($petCarePrescriptionTicket, $seededAt->subMinutes(5));

        $petCareBillingTicket = SupportTicket::query()->updateOrCreate(
            [
                'sub_core_key' => 'pet-care-clinic',
                'user_id' => $serviceUser->id,
                'subject' => 'QA Seed: APES Pet Care Clinic billing follow-up',
            ],
            [
                'assigned_to' => $adminUser->id,
                'service_area' => 'billing',
                'priority' => 'medium',
                'status' => 'closed',
                'description' => 'A completed clinic charge was explained to the service user.',
                'closed_at' => $seededAt->subDays(2),
            ],
        );
        $this->upsertTicketMessage(
            $petCareBillingTicket,
            $serviceUser,
            'Thank you for explaining the completed clinic charge.',
            false,
            $seededAt->subDays(2)->subMinutes(20),
        );
        $this->upsertTicketMessage(
            $petCareBillingTicket,
            $adminUser,
            'Internal note: billing explanation confirmed and ticket closed.',
            true,
            $seededAt->subDays(2)->subMinutes(10),
        );
        $this->setTimestamps($petCareBillingTicket, $seededAt->subDays(2));

        $reopenedApesCase = ShelterCase::query()->updateOrCreate(
            [
                'sub_core_key' => ShelterCase::SUB_CORE_APES_CIC,
                'user_id' => $serviceUser->id,
                'title' => 'QA Seed: Reopened membership support review',
            ],
            [
                'pet_profile_id' => null,
                'assigned_to' => $staffUser->id,
                'case_type' => null,
                'category' => 'membership_casework',
                'sub_category' => 'member_dispute',
                'priority' => 'high',
                'status' => 'in_progress',
                'details' => 'Additional information reopened this membership support review.',
                'opened_at' => $seededAt->subDays(5),
                'resolved_at' => null,
                'closed_at' => null,
            ],
        );
        $this->upsertCaseUpdate(
            $reopenedApesCase,
            $serviceUser,
            'Additional information received; case reopened for review.',
            CaseUpdate::VISIBILITY_PUBLIC,
            $seededAt->subMinutes(30),
        );
        $this->upsertCaseUpdate(
            $reopenedApesCase,
            $staffUser,
            'Internal triage note for local visibility testing.',
            CaseUpdate::VISIBILITY_INTERNAL,
            $seededAt->subMinutes(20),
        );
        $this->setTimestamps($reopenedApesCase, $seededAt->subMinutes(10));

        $waitingApesCase = ShelterCase::query()->updateOrCreate(
            [
                'sub_core_key' => ShelterCase::SUB_CORE_APES_CIC,
                'user_id' => $serviceUser->id,
                'title' => 'QA Seed: Welfare response required',
            ],
            [
                'pet_profile_id' => null,
                'assigned_to' => $staffUser->id,
                'case_type' => null,
                'category' => 'welfare_concern',
                'sub_category' => 'animal_welfare',
                'priority' => 'urgent',
                'status' => 'waiting_on_user',
                'details' => 'Waiting for the service user to confirm the requested welfare information.',
                'opened_at' => $seededAt->subDays(3),
                'resolved_at' => null,
                'closed_at' => null,
            ],
        );
        $this->setTimestamps($waitingApesCase, $seededAt->subMinutes(20));

        $closedApesCase = ShelterCase::query()->updateOrCreate(
            [
                'sub_core_key' => ShelterCase::SUB_CORE_APES_CIC,
                'user_id' => $serviceUser->id,
                'title' => 'QA Seed: Closed operations complaint',
            ],
            [
                'pet_profile_id' => null,
                'assigned_to' => $adminUser->id,
                'case_type' => null,
                'category' => 'formal_complaint',
                'sub_category' => 'service_complaint',
                'priority' => 'low',
                'status' => 'closed',
                'details' => 'The operations complaint was reviewed and the outcome was shared.',
                'opened_at' => $seededAt->subDays(8),
                'resolved_at' => $seededAt->subDays(3),
                'closed_at' => $seededAt->subDays(2),
            ],
        );
        $this->upsertCaseUpdate(
            $closedApesCase,
            $adminUser,
            'Outcome shared with the service user.',
            CaseUpdate::VISIBILITY_PUBLIC,
            $seededAt->subDays(2),
        );
        $this->setTimestamps($closedApesCase, $seededAt->subDays(2));

        $sarCase = ShelterCase::query()->updateOrCreate(
            [
                'sub_core_key' => ShelterCase::SUB_CORE_APES_CIC,
                'user_id' => $serviceUser->id,
                'title' => 'QA Seed: Subject access request for MyAPES account',
            ],
            [
                'pet_profile_id' => null,
                'assigned_to' => $adminUser->id,
                'case_type' => null,
                'category' => 'data_access_request',
                'sub_category' => 'copy_of_data',
                'affected_website_key' => 'myapes_account',
                'priority' => 'high',
                'status' => 'in_progress',
                'details' => 'Service user requests a copy of personal data held in MyAPES Account.',
                'opened_at' => $seededAt->subDays(2),
                'resolved_at' => null,
                'closed_at' => null,
            ],
        );
        $this->upsertCaseUpdate(
            $sarCase,
            $serviceUser,
            'Please include profile, ticket and case history associated with my account.',
            CaseUpdate::VISIBILITY_PUBLIC,
            $seededAt->subDay()->addMinutes(10),
        );
        $this->upsertCaseUpdate(
            $sarCase,
            $adminUser,
            'Identity verified; compiling export pack.',
            CaseUpdate::VISIBILITY_INTERNAL,
            $seededAt->subDay()->addMinutes(25),
        );
        $this->setTimestamps($sarCase, $seededAt->subDay()->addMinutes(30));
        $this->upsertDemoAttachment(
            $sarCase,
            $serviceUser,
            'screenshot',
            'sar-identity-evidence.png',
            $seededAt->subDay()->addMinutes(10),
        );

        $privacyCase = ShelterCase::query()->updateOrCreate(
            [
                'sub_core_key' => ShelterCase::SUB_CORE_APES_CIC,
                'user_id' => $serviceUser->id,
                'title' => 'QA Seed: Privacy rectification of contact details',
            ],
            [
                'pet_profile_id' => null,
                'assigned_to' => $staffUser->id,
                'case_type' => null,
                'category' => 'privacy_request',
                'sub_category' => 'rectification',
                'affected_website_key' => null,
                'priority' => 'medium',
                'status' => 'open',
                'details' => 'Please correct the stored mobile number and postal town on the member profile.',
                'opened_at' => $seededAt->subHours(6),
                'resolved_at' => null,
                'closed_at' => null,
            ],
        );
        $this->upsertCaseUpdate(
            $privacyCase,
            $serviceUser,
            'Current number should be +44 7700 900101 and town should be St Helens.',
            CaseUpdate::VISIBILITY_PUBLIC,
            $seededAt->subHours(5),
        );
        $this->setTimestamps($privacyCase, $seededAt->subHours(4));

        $shelterPet = PetProfile::query()->updateOrCreate(
            [
                'user_id' => $serviceUser->id,
                'service_domain' => PetProfile::DOMAIN_SHELTER,
                'name' => 'Mango',
            ],
            [
                'species' => 'Cockatiel',
                'age_years' => 4,
                'sex' => 'female',
                'neutering_status' => 'unknown',
                'health_issues' => 'Mild feather plucking when stressed.',
                'photo_path' => null,
            ]
        );

        $petCarePet = PetProfile::query()->updateOrCreate(
            [
                'user_id' => $serviceUser->id,
                'service_domain' => PetProfile::DOMAIN_PETCARE,
                'name' => 'Pico',
            ],
            [
                'species' => 'African Grey',
                'age_years' => 7,
                'sex' => 'male',
                'neutering_status' => 'unknown',
                'health_issues' => 'Requires periodic beak health checks.',
                'photo_path' => null,
            ]
        );

        $rescueCase = ShelterCase::query()->updateOrCreate(
            [
                'sub_core_key' => ShelterCase::SUB_CORE_SHELTER_RESCUE,
                'pet_profile_id' => $shelterPet->id,
                'title' => 'QA Seed: Mango rescue intake',
            ],
            [
                'user_id' => $serviceUser->id,
                'assigned_to' => $staffUser->id,
                'case_type' => 'rescue',
                'status' => 'in_review',
                'details' => 'Assessing behaviour and temporary foster placement.',
                'closed_at' => null,
            ]
        );
        $this->setTimestamps($rescueCase, $seededAt->subMinutes(5));
        $this->upsertCaseUpdate(
            $rescueCase,
            $serviceUser,
            'Mango is settled and ready for the next rescue assessment.',
            CaseUpdate::VISIBILITY_PUBLIC,
            $seededAt->subMinutes(8),
        );
        $this->upsertCaseUpdate(
            $rescueCase,
            $staffUser,
            'Internal note: confirm foster placement before closing intake.',
            CaseUpdate::VISIBILITY_INTERNAL,
            $seededAt->subMinutes(7),
        );

        $fosterCase = ShelterCase::query()->updateOrCreate(
            [
                'sub_core_key' => ShelterCase::SUB_CORE_SHELTER_RESCUE,
                'pet_profile_id' => $shelterPet->id,
                'title' => 'QA Seed: Mango foster placement review',
            ],
            [
                'user_id' => $serviceUser->id,
                'assigned_to' => $adminUser->id,
                'case_type' => 'fostering',
                'status' => 'open',
                'details' => 'Confirming a calm temporary placement and transport plan.',
                'closed_at' => null,
            ]
        );
        $this->setTimestamps($fosterCase, $seededAt->subDay()->addHours(3));

        $closedShelterCase = ShelterCase::query()->updateOrCreate(
            [
                'sub_core_key' => ShelterCase::SUB_CORE_SHELTER_RESCUE,
                'pet_profile_id' => $shelterPet->id,
                'title' => 'QA Seed: Mango adoption follow-up',
            ],
            [
                'user_id' => $serviceUser->id,
                'assigned_to' => $adminUser->id,
                'case_type' => 'adoption',
                'status' => 'closed',
                'details' => 'Home check and post-placement follow-up completed.',
                'closed_at' => $seededAt->subDays(2),
            ]
        );
        $this->setTimestamps($closedShelterCase, $seededAt->subDays(2));

        $wellnessConsultation = PetCareConsultation::query()->updateOrCreate(
            ['pet_profile_id' => $petCarePet->id, 'subject' => 'QA Seed: Pico annual wellness consult'],
            [
                'user_id' => $serviceUser->id,
                'assigned_to' => $staffUser->id,
                'status' => 'in_progress',
                'notes' => 'Diet review and behavioural enrichment plan in progress.',
                'scheduled_for' => $seededAt->addDays(2),
                'closed_at' => null,
            ]
        );
        $this->setTimestamps($wellnessConsultation, $seededAt->subDays(2)->addHours(4));

        $enrichmentConsultation = PetCareConsultation::query()->updateOrCreate(
            ['pet_profile_id' => $petCarePet->id, 'subject' => 'QA Seed: Pico nutrition enrichment review'],
            [
                'user_id' => $serviceUser->id,
                'assigned_to' => $staffUser->id,
                'status' => 'open',
                'notes' => 'Review foraging activities and seasonal food variety.',
                'scheduled_for' => $seededAt->addDays(5),
                'closed_at' => null,
            ]
        );
        $this->setTimestamps($enrichmentConsultation, $seededAt->subDays(3)->addHours(2));

        $closedConsultation = PetCareConsultation::query()->updateOrCreate(
            ['pet_profile_id' => $petCarePet->id, 'subject' => 'QA Seed: Pico beak health follow-up'],
            [
                'user_id' => $serviceUser->id,
                'assigned_to' => $adminUser->id,
                'status' => 'closed',
                'notes' => 'Follow-up completed with maintenance advice provided.',
                'scheduled_for' => $seededAt->subDays(4),
                'closed_at' => $seededAt->subDays(3),
            ]
        );
        $this->setTimestamps($closedConsultation, $seededAt->subDays(3));

        $this->seedRecruitmentRoles($staffUser, $seededAt);
    }

    private function seedRecruitmentRoles(User $staffUser, CarbonImmutable $seededAt): void
    {
        $openRoles = [
            'staff' => [
                'title' => 'QA Seed: Operations coordinator',
                'summary' => 'Coordinate day-to-day APES CIC operations and partner liaison.',
                'description' => "Support service delivery across APES CIC teams.\n\nIdeal for someone who enjoys clear follow-up and calm coordination.",
                'location' => 'Hybrid — London',
                'commitment' => 'Part-time',
            ],
            'volunteer' => [
                'title' => 'QA Seed: Community outreach volunteer',
                'summary' => 'Help with events, awareness days, and community conversations.',
                'description' => "Join outreach activities that introduce people to APES CIC work.\n\nTraining and shadowing are provided.",
                'location' => 'Community venues',
                'commitment' => 'Flexible',
            ],
            'student' => [
                'title' => 'QA Seed: Placement student — administration',
                'summary' => 'A structured placement supporting admin and communications tasks.',
                'description' => "Work alongside staff on documentation, scheduling, and public information.\n\nSuitable for students seeking supervised experience.",
                'location' => 'Office / remote mix',
                'commitment' => '2 days per week',
            ],
        ];

        foreach ($openRoles as $category => $payload) {
            $role = RecruitmentRole::query()->updateOrCreate(
                [
                    'title' => $payload['title'],
                    'category' => $category,
                ],
                [
                    'created_by' => $staffUser->id,
                    'summary' => $payload['summary'],
                    'description' => $payload['description'],
                    'status' => RecruitmentRole::STATUS_OPEN,
                    'location' => $payload['location'],
                    'commitment' => $payload['commitment'],
                    'published_at' => $seededAt->subDays(3),
                    'closed_at' => null,
                ],
            );
            $this->setTimestamps($role, $seededAt->subDays(3));
        }

        $draftRole = RecruitmentRole::query()->updateOrCreate(
            [
                'title' => 'QA Seed: Draft communications lead',
                'category' => 'staff',
            ],
            [
                'created_by' => $staffUser->id,
                'summary' => 'Internal draft — must not appear on the public board.',
                'description' => 'Draft role used for local visibility tests. Guests must never see this content.',
                'status' => RecruitmentRole::STATUS_DRAFT,
                'location' => 'Remote',
                'commitment' => 'Full-time',
                'published_at' => null,
                'closed_at' => null,
            ],
        );
        $this->setTimestamps($draftRole, $seededAt->subDay());

        $closedRole = RecruitmentRole::query()->updateOrCreate(
            [
                'title' => 'QA Seed: Closed weekend volunteer',
                'category' => 'volunteer',
            ],
            [
                'created_by' => $staffUser->id,
                'summary' => 'Closed role — must not appear on the public board.',
                'description' => 'Closed role used for local visibility tests. Guests must never see this content.',
                'status' => RecruitmentRole::STATUS_CLOSED,
                'location' => 'On site',
                'commitment' => 'Weekends',
                'published_at' => $seededAt->subWeeks(2),
                'closed_at' => $seededAt->subDays(5),
            ],
        );
        $this->setTimestamps($closedRole, $seededAt->subDays(5));
    }

    private function seedDirectoryGroupsForQaStaff(
        User $studentUser,
        User $volunteerUser,
        User $staffUser,
        User $hrUser,
        User $adminUser,
        User $superAdminUser,
    ): void {
        $studentUser->forceFill([
            'ldap_groups' => [
                'myapesaccount.student',
            ],
        ])->save();

        $volunteerUser->forceFill([
            'ldap_groups' => [
                'myapesaccount.volunteer',
            ],
        ])->save();

        $staffUser->forceFill([
            'ldap_groups' => [
                'board-of-directors',
                'department.animal.care',
                'department.client.services',
                'department.developers',
                'department.directors',
                'department.finance',
                'intranet.managers',
                'myapesaccount.staff',
                'position.staff',
            ],
        ])->save();

        $hrUser->forceFill([
            'ldap_groups' => [
                'myapesaccount.hr',
                'myapesaccount.staff',
                'position.staff',
            ],
        ])->save();

        $adminUser->forceFill([
            'ldap_groups' => [
                'department.directors',
                'intranet.managers',
                'myapesaccount.admin',
                'position.staff',
            ],
        ])->save();

        $superAdminUser->forceFill([
            'ldap_groups' => [
                'board-of-directors',
                'department.developers',
                'department.directors',
                'intranet.superadmin',
                'myapesaccount.superadmin',
                'myapesaccount.superadmin',
                'newsroom.superadmin',
                'position.staff',
            ],
        ])->save();
    }

    private function upsertUser(
        string $email,
        string $name,
        string $role,
        CarbonImmutable $seededAt
    ): User {
        /** @var User $user */
        $user = User::query()->firstOrNew(['email' => $email]);
        $user->forceFill([
            'name' => $name,
            'username' => str_replace('@myapes.local', '', $email),
            'password' => self::DEFAULT_PASSWORD,
            'oidc_sub' => null,
            'identity_type' => User::IDENTITY_LOCAL,
            'ldap_groups' => [],
            'email_verified_at' => $seededAt,
            'onboarding_completed_at' => $seededAt,
            'suspended_at' => null,
            'suspended_by' => null,
            'suspension_reason' => null,
        ]);
        $user->setAccessLevel($role)->save();

        $profile = app(AuthorizationProfile::class);
        $protectedRoleName = $profile->protectedRoleForLegacy($role);
        $protectedRole = Role::query()
            ->where('guard_name', 'web')
            ->where('name', $protectedRoleName)
            ->firstOrFail();
        app(AuthorizationRoleMaterializer::class)->grant(
            $user,
            $protectedRole,
            RoleSource::SOURCE_SYSTEM,
        );

        $user->contactPreference()->firstOrCreate([], [
            'confirmed_at' => $seededAt,
            'policy_version' => (string) config('myapes.consent.policy_version'),
        ]);
        foreach (['apes-cic', 'shelter-rescue', 'pet-care-clinic'] as $service) {
            $user->serviceSelections()->firstOrCreate(['sub_core_key' => $service]);
        }

        return $user;
    }

    private function upsertCustomRole(User $actor, User $assignee): void
    {
        $management = app(AuthorizationRoleManagementService::class);
        $role = Role::query()
            ->where('guard_name', 'web')
            ->where('name', self::CUSTOM_ROLE_NAME)
            ->first();
        $permissions = [];

        $role = $management->runAsLocalQa(
            $actor,
            static fn (): Role => $role === null
                ? $management->create(
                    $actor,
                    self::CUSTOM_ROLE_NAME,
                    $permissions,
                )
                : $management->update(
                    $actor,
                    $role,
                    self::CUSTOM_ROLE_NAME,
                    $permissions,
                ),
        );

        app(AuthorizationRoleMaterializer::class)->grant(
            $assignee,
            $role,
            RoleSource::SOURCE_LOCAL,
            actor: $actor,
        );
    }

    private function grantHumanResourcesJobRole(User $actor, User $assignee): void
    {
        $role = Role::query()
            ->where('guard_name', 'web')
            ->where('name', DefaultJobRoles::HUMAN_RESOURCES)
            ->firstOrFail();

        app(AuthorizationRoleMaterializer::class)->grant(
            $assignee,
            $role,
            RoleSource::SOURCE_LOCAL,
            actor: $actor,
        );
    }

    private function upsertProfile(User $user, string $preferredName, string $phone, string $organization): void
    {
        UserProfile::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'preferred_name' => $preferredName,
                'phone' => $phone,
                'organization' => $organization,
                'support_needs' => 'QA seed profile for local testing.',
                'avatar_path' => null,
                'address_line_1' => '1 QA Test Street',
                'town_city' => 'London',
                'postcode' => 'SW1A 1AA',
                'country' => 'GB',
                'mobile_number' => '+447400123456',
            ]
        );
    }

    private function upsertStaffProfile(
        User $user,
        string $jobTitle,
        string $team,
        string $workPhone,
    ): void {
        StaffProfile::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'job_title' => $jobTitle,
                'team' => $team,
                'work_phone' => $workPhone,
                'photo_path' => null,
            ],
        );
    }

    private function upsertTicketMessage(
        SupportTicket $ticket,
        User $author,
        string $message,
        bool $isStaffNote,
        CarbonImmutable $updatedAt,
    ): void {
        $fixture = $ticket->messages()->updateOrCreate(
            [
                'user_id' => $author->id,
                'is_staff_note' => $isStaffNote,
            ],
            ['message' => $message],
        );

        $this->setTimestamps($fixture, $updatedAt);
    }

    private function upsertCaseUpdate(
        ShelterCase $case,
        User $author,
        string $body,
        string $visibility,
        CarbonImmutable $updatedAt,
    ): void {
        $fixture = $case->updates()->updateOrCreate(
            [
                'user_id' => $author->id,
                'visibility' => $visibility,
            ],
            ['body' => $body],
        );

        $this->setTimestamps($fixture, $updatedAt);
    }

    private function upsertDemoAttachment(
        Model $attachable,
        User $uploader,
        string $kind,
        string $originalName,
        CarbonImmutable $updatedAt,
    ): void {
        $directory = sprintf(
            'support-attachments/%s/%s/%s',
            method_exists($attachable, 'getAttribute')
                ? (string) ($attachable->getAttribute('sub_core_key') ?? 'apes-cic')
                : 'apes-cic',
            $updatedAt->format('Y'),
            $attachable->getKey(),
        );
        $path = $directory.'/'.$originalName;

        if ($kind === 'screencast') {
            Storage::disk('public')->put($path, $this->minimalWebmBytes());
            $mime = 'video/webm';
        } else {
            Storage::disk('public')->put($path, $this->minimalPngBytes());
            $mime = 'image/png';
        }

        $attachment = SupportAttachment::query()->updateOrCreate(
            [
                'attachable_type' => $attachable->getMorphClass(),
                'attachable_id' => $attachable->getKey(),
                'original_name' => $originalName,
                'kind' => $kind,
            ],
            [
                'user_id' => $uploader->id,
                'disk' => 'public',
                'path' => $path,
                'mime_type' => $mime,
                'size_bytes' => Storage::disk('public')->size($path),
            ],
        );

        $this->setTimestamps($attachment, $updatedAt);
    }

    private function minimalPngBytes(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        ) ?: '';
    }

    private function minimalWebmBytes(): string
    {
        // Tiny placeholder bytes labelled as WebM for local QA download checks only.
        return "\x1a\x45\xdf\xa3QA Seed screencast placeholder";
    }

    private function setTimestamps(Model $model, CarbonImmutable $updatedAt): void
    {
        $model->forceFill([
            'created_at' => $updatedAt->subHour(),
            'updated_at' => $updatedAt,
        ])->saveQuietly();
    }
}
