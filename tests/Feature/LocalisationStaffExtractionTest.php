<?php

namespace Tests\Feature;

use Tests\TestCase;

class LocalisationStaffExtractionTest extends TestCase
{
    public function test_staff_and_plugin_lang_files_resolve(): void
    {
        $this->assertFileExists(lang_path('en_GB/apes_cic.php'));
        $this->assertFileExists(base_path('plugins/cases/lang/en_GB/ui.php'));
        $this->assertFileExists(base_path('plugins/tickets/lang/en_GB/ui.php'));
        $this->assertFileExists(base_path('plugins/recruitment/lang/en_GB/staff.php'));
        $this->assertFileExists(base_path('plugins/consultations/lang/en_GB/ui.php'));

        $this->assertSame('Available plugins', __('apes_cic.show.blade.available_plugins'));
        $this->assertSame('Cases', __('terms.cases'));
        $this->assertSame('Roles', __('terms.recruitment_roles'));
        $this->assertSame('Applications', __('terms.applications'));
        $this->assertNotSame('cases::ui.flash.case_updated', __('cases::ui.flash.case_updated'));
    }

    public function test_staff_inventory_sections_are_clear(): void
    {
        $contents = (string) file_get_contents(base_path('docs/i18n-inventory.md'));

        foreach ([
            '/\| \[APES CIC staff\].*\|\s*0\s*\|/',
            '/\| \[Plugin: Cases\].*\|\s*0\s*\|/',
            '/\| \[Plugin: Tickets\].*\|\s*0\s*\|/',
            '/\| \[Plugin: Recruitment\].*\|\s*0\s*\|/',
            '/\| \[Plugin: Consultations\].*\|\s*0\s*\|/',
        ] as $pattern) {
            $this->assertMatchesRegularExpression($pattern, $contents);
        }
    }

    public function test_staff_recruitment_nav_uses_glossary_roles_not_job_role(): void
    {
        $nav = (string) file_get_contents(base_path('plugins/recruitment/resources/views/_navigation.blade.php'));
        $this->assertStringContainsString("__('terms.recruitment_roles')", $nav);
        $this->assertStringContainsString("__('terms.applications')", $nav);
        $this->assertStringNotContainsString('job role', strtolower($nav));
        $this->assertStringNotContainsString('Spatie', $nav);
    }
}
