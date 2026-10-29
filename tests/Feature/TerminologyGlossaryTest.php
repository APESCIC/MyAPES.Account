<?php

namespace Tests\Feature;

use Tests\TestCase;

class TerminologyGlossaryTest extends TestCase
{
    public function test_glossary_documentation_and_terms_lang_file_exist(): void
    {
        $this->assertFileExists(base_path('docs/glossary.md'));
        $this->assertFileExists(lang_path('en_GB/terms.php'));
        $this->assertFileExists(lang_path('en/terms.php'));
        $this->assertFileExists(config_path('glossary.php'));
    }

    public function test_canonical_terms_resolve_from_en_gb(): void
    {
        $this->assertSame('MyAPES Account', __('terms.app_name'));
        $this->assertSame('MyAPES Core', __('terms.platform_name'));
        $this->assertSame('Plugin', trans_choice('terms.plugin', 1));
        $this->assertSame('Plugins', trans_choice('terms.plugin', 2));
        $this->assertSame('Public user', trans_choice('terms.public_user', 1));
        $this->assertSame('Public users', trans_choice('terms.public_user', 2));
        $this->assertSame('Staff', __('terms.staff'));
        $this->assertSame('Role', trans_choice('terms.recruitment_role', 1));
        $this->assertSame('Roles', trans_choice('terms.recruitment_role', 2));
        $this->assertSame('Job role', trans_choice('terms.job_role', 1));
        $this->assertSame('Open roles', __('terms.open_roles'));
        $this->assertSame('My applications', __('terms.my_applications'));
        $this->assertSame('Admin', __('terms.admin'));
        $this->assertSame('APES CIC', __('terms.apes_cic'));
        $this->assertSame('Pet Care Clinic', __('terms.pet_care_clinic'));
        $this->assertSame('Shelter and Rescue', __('terms.shelter_and_rescue'));
        $this->assertSame('Tickets', __('terms.tickets'));
        $this->assertSame('Cases', __('terms.cases'));
        $this->assertSame('Recruitment', __('terms.recruitment'));
        $this->assertSame('Consultations', __('terms.consultations'));
        $this->assertSame('Pet Profiles', __('terms.pet_profiles'));
        $this->assertSame('Submitted', __('terms.status.submitted'));
        $this->assertSame('Shortlisted', __('terms.status.shortlisted'));
        $this->assertSame('Accepted', __('terms.status.accepted'));
        $this->assertSame('Rejected', __('terms.status.rejected'));
    }

    public function test_workflow_terms_are_title_case_for_every_stored_value(): void
    {
        $groups = [
            'status' => [
                'submitted' => 'Submitted',
                'under_review' => 'Under Review',
                'shortlisted' => 'Shortlisted',
                'accepted' => 'Accepted',
                'rejected' => 'Rejected',
                'withdrawn' => 'Withdrawn',
                'draft' => 'Draft',
                'open' => 'Open',
                'in_progress' => 'In Progress',
                'in_review' => 'In Review',
                'waiting_on_user' => 'Waiting on User',
                'resolved' => 'Resolved',
                'closed' => 'Closed',
            ],
            'priority' => ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'urgent' => 'Urgent'],
            'case_type' => ['adoption' => 'Adoption', 'surrender' => 'Surrender', 'rescue' => 'Rescue', 'fostering' => 'Fostering'],
            'recruitment_category' => ['staff' => 'Staff', 'volunteer' => 'Volunteer', 'student' => 'Student'],
            'sex' => ['male' => 'Male', 'female' => 'Female', 'unknown' => 'Unknown'],
            'neutering_status' => ['neutered' => 'Neutered', 'not_neutered' => 'Not Neutered', 'unknown' => 'Unknown'],
        ];

        $glossary = file_get_contents(base_path('docs/glossary.md'));

        foreach ($groups as $group => $labels) {
            $this->assertSame("terms.{$group}", config("glossary.canonical.{$group}"));
            $this->assertSame($labels, __("terms.{$group}"), "terms.{$group} labels drifted.");
            $this->assertStringContainsString("`terms.{$group}.", $glossary);
        }
    }

    public function test_en_fallback_terms_match_en_gb(): void
    {
        $this->assertSame(require lang_path('en_GB/terms.php'), require lang_path('en/terms.php'));
    }

    public function test_deprecated_synonyms_are_machine_readable_for_ci(): void
    {
        $synonyms = config('glossary.deprecated_synonyms');

        $this->assertIsArray($synonyms);
        $this->assertNotEmpty($synonyms);

        $patterns = array_column($synonyms, 'pattern');

        $this->assertContains('MyAPES Core', $patterns);
        $this->assertContains('Super Admin', $patterns);
        $this->assertContains('sub-core', $patterns);
        $this->assertContains('module type', $patterns);
        $this->assertContains('Service user', $patterns);
        $this->assertContains('Recruit manage', $patterns);
        $this->assertNull(config('glossary.canonical.recruit_manage'));

        foreach ($synonyms as $entry) {
            $this->assertArrayHasKey('pattern', $entry);
            $this->assertArrayHasKey('canonical', $entry);
            $this->assertArrayHasKey('contexts', $entry);
            $this->assertIsArray($entry['contexts']);
            $this->assertNotSame('', $entry['pattern']);
            $this->assertNotSame('', $entry['canonical']);
        }

        $this->assertSame('terms.app_name', config('glossary.canonical.app_name'));
        $this->assertSame('terms.recruitment_role', config('glossary.canonical.recruitment_role'));
    }

    public function test_admin_primary_nav_already_avoids_super_admin_door(): void
    {
        $navigation = file_get_contents(resource_path('views/admin/_navigation.blade.php'));

        $this->assertIsString($navigation);
        $this->assertStringContainsString("__('admin.nav.plugins')", $navigation);
        $this->assertStringContainsString("__('admin.nav.modules')", $navigation);
        $this->assertSame('Plugins', __('admin.nav.plugins'));
        $this->assertSame('Modules', __('admin.nav.modules'));
        $this->assertStringNotContainsString('Super Admin', $navigation);
    }
}
