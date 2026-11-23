<?php

namespace Tests\Unit\Theme;

use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JungleDecorTest extends TestCase
{
    use RefreshDatabase;

    public function test_glassy_workspace_html_does_not_reference_jungle_decor_assets(): void
    {
        $user = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create([
            'theme_skin' => 'glassy',
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-skin="glassy"', false)
            ->assertDontSee('/decor/jungle/canopy', false)
            ->assertDontSee('/decor/jungle/leaf-corner', false)
            ->assertDontSee('/decor/jungle/vine-divider', false);
    }
    public function test_jungle_decor_css_is_gated_by_skin_and_hides_for_calm_and_print(): void
    {
        $decorCss = (string) file_get_contents(base_path('resources/css/themes/jungle-decor.css'));

        $this->assertStringContainsString("[data-skin='jungle']", $decorCss);
        $this->assertStringContainsString(':not([data-calm=\'true\'])', $decorCss);
        $this->assertStringContainsString('[data-skin=\'jungle\'][data-calm=\'true\']', $decorCss);
        $this->assertMatchesRegularExpression('/@media\s+print/', $decorCss);
        $this->assertStringContainsString('content: none', $decorCss);
        $this->assertStringContainsString('/decor/jungle/canopy.svg', $decorCss);
        $this->assertStringContainsString('/decor/jungle/leaf-corner.svg', $decorCss);
        $this->assertStringContainsString('/decor/jungle/vine-divider.svg', $decorCss);
    }

    public function test_jungle_decor_is_imported_only_from_jungle_skin_css(): void
    {
        $jungleCss = (string) file_get_contents(base_path('resources/css/themes/jungle.css'));
        $glassyCss = (string) file_get_contents(base_path('resources/css/themes/glassy.css'));
        $workspaceCss = (string) file_get_contents(base_path('resources/css/workspace.css'));

        $this->assertStringContainsString("@import './jungle-decor.css'", $jungleCss);
        $this->assertStringNotContainsString('jungle-decor', $glassyCss);
        $this->assertStringNotContainsString('jungle-decor', $workspaceCss);
        $this->assertStringNotContainsString('/decor/jungle/', $glassyCss);
    }
}