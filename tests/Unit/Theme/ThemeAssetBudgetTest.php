<?php

namespace Tests\Unit\Theme;

use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use App\Support\Theme\ThemeDecorBudget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Theme decorative asset budget (#906).
 */
class ThemeAssetBudgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_glassy_representative_page_stays_within_decorative_budget(): void
    {
        $bytes = ThemeDecorBudget::glassyDecorativeBytes();

        $this->assertGreaterThan(0, $bytes);
        $this->assertLessThanOrEqual(
            ThemeDecorBudget::MAX_BYTES_PER_PAGE,
            $bytes,
            'Glassy decorative bytes: '.$bytes
        );
    }

    public function test_jungle_representative_page_stays_within_decorative_budget(): void
    {
        $bytes = ThemeDecorBudget::jungleDecorativeBytes();

        $this->assertGreaterThan(ThemeDecorBudget::glassyDecorativeBytes(), $bytes);
        $this->assertLessThanOrEqual(
            ThemeDecorBudget::MAX_BYTES_PER_PAGE,
            $bytes,
            'Jungle decorative bytes: '.$bytes
        );
    }

    public function test_glassy_budget_excludes_jungle_decor_paths(): void
    {
        foreach (ThemeDecorBudget::jungleDecorRelativePaths() as $path) {
            $this->assertFileExists(public_path($path));
        }

        $this->assertSame(
            ThemeDecorBudget::sumBytes(ThemeDecorBudget::representativePageCastPaths()),
            ThemeDecorBudget::glassyDecorativeBytes()
        );
    }

    public function test_jungle_budget_includes_all_jungle_decor_assets(): void
    {
        $expected = ThemeDecorBudget::sumBytes([
            ...ThemeDecorBudget::representativePageCastPaths(),
            ...ThemeDecorBudget::jungleDecorRelativePaths(),
        ]);

        $this->assertSame($expected, ThemeDecorBudget::jungleDecorativeBytes());
    }

    public function test_workspace_preloads_brand_mark_only(): void
    {
        $user = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create();

        $html = $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertIsString($html);
        $this->assertMatchesRegularExpression('/rel="preload"[^>]+as="image"/s', $html);
        $this->assertStringContainsString('logos/myapes-mark-40x40.png', $html);
        $this->assertEquals(
            1,
            preg_match_all('/rel="preload"/', $html),
            'Only the brand mark should be preloaded'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/rel="preload"[^>]+mascot\/cast\//',
            $html,
            'Cast SVGs must not be preloaded'
        );
    }

    public function test_mascot_tip_empty_variant_defers_critter_image_loading(): void
    {
        $view = $this->blade(
            '<x-mascot-tip variant="empty" title="Nothing here" body="Yet." critter="dot" pose="thinking" />',
            []
        );

        $view->assertSee('mascot-tip--empty', false);
        $view->assertSee('loading="lazy"', false);
        $view->assertSee('mascot/cast/dot-thinking.svg', false);
    }
}