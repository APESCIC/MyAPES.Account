<?php

namespace Tests\Feature;

use Tests\TestCase;

class LocalisationPublicExtractionTest extends TestCase
{
    public function test_public_lang_keys_and_glossary_recruitment_labels(): void
    {
        $this->assertFileExists(lang_path('en_GB/public.php'));
        $this->assertSame('Open roles', __('terms.open_roles'));
        $this->assertSame('My applications', __('terms.my_applications'));
        $this->assertNotSame('public.dashboard.open_service', __('public.dashboard.open_service'));
    }

    public function test_public_inventory_section_is_clear(): void
    {
        $contents = (string) file_get_contents(base_path('docs/i18n-inventory.md'));
        $this->assertMatchesRegularExpression(
            '/\| \[Public\].*\|\s*0\s*\|/',
            $contents,
        );
    }

    public function test_public_recruitment_nav_uses_glossary_terms(): void
    {
        $nav = (string) file_get_contents(base_path('plugins/recruitment/resources/views/_navigation.blade.php'));
        $this->assertStringContainsString("__('terms.open_roles')", $nav);
        $this->assertStringContainsString("__('terms.my_applications')", $nav);
    }
}
