<?php

namespace Tests\Unit\Theme;

use App\Support\Theme\ThemeContrast;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ThemeContrastTest extends TestCase
{
    /**
     * @return array<string, array{skin: string, mode: string, pairs: list<array{label: string, foreground: string, background: string, minimum: float, background_rgba?: string}>}>
     */
    public static function aaContrastPairsProvider(): array
    {
        $white = '#ffffff';

        return [
            'glassy-light' => [
                'skin' => 'glassy',
                'mode' => 'light',
                'pairs' => [
                    ['label' => 'ink on base', 'foreground' => '#12262c', 'background' => '#d2f4fa', 'minimum' => ThemeContrast::AA_NORMAL_TEXT],
                    ['label' => 'muted on base', 'foreground' => '#40676a', 'background' => '#d2f4fa', 'minimum' => ThemeContrast::AA_NORMAL_TEXT],
                    ['label' => 'teal link on base', 'foreground' => '#107481', 'background' => '#d2f4fa', 'minimum' => ThemeContrast::AA_NORMAL_TEXT],
                    ['label' => 'teal button on white', 'foreground' => '#107481', 'background' => $white, 'minimum' => ThemeContrast::AA_NORMAL_TEXT],
                    ['label' => 'on-primary on teal', 'foreground' => $white, 'background' => '#107481', 'minimum' => ThemeContrast::AA_NORMAL_TEXT],
                    ['label' => 'error on error mist', 'foreground' => '#a12828', 'background' => '#fde4e4', 'minimum' => ThemeContrast::AA_NORMAL_TEXT],
                    ['label' => 'warn on warn mist', 'foreground' => '#8a4b0b', 'background' => '#fdefd3', 'minimum' => ThemeContrast::AA_NORMAL_TEXT],
                    ['label' => 'ok on ok mist', 'foreground' => '#11613b', 'background' => '#dcf5e7', 'minimum' => ThemeContrast::AA_NORMAL_TEXT],
                    ['label' => 'info on info mist', 'foreground' => '#005b5d', 'background' => '#d5f4f5', 'minimum' => ThemeContrast::AA_NORMAL_TEXT],
                ],
            ],
            'glassy-dark' => [
                'skin' => 'glassy',
                'mode' => 'dark',
                'pairs' => [
                    ['label' => 'ink on base', 'foreground' => '#e3f6f9', 'background' => '#0b1a1f', 'minimum' => ThemeContrast::AA_NORMAL_TEXT],
                    ['label' => 'muted on base', 'foreground' => '#a9cbd0', 'background' => '#0b1a1f', 'minimum' => ThemeContrast::AA_NORMAL_TEXT],
                    ['label' => 'teal on base', 'foreground' => '#7fd3dd', 'background' => '#0b1a1f', 'minimum' => ThemeContrast::AA_LARGE_OR_UI],
                    ['label' => 'on-primary on accent', 'foreground' => '#0b1a1f', 'background' => '#67c3ce', 'minimum' => ThemeContrast::AA_NORMAL_TEXT],
                    ['label' => 'error on error mist', 'foreground' => '#ffb0a8', 'background' => '#0b1a1f', 'background_rgba' => 'rgba(161, 40, 40, 0.45)', 'minimum' => ThemeContrast::AA_NORMAL_TEXT],
                    ['label' => 'warn on warn mist', 'foreground' => '#f6c98a', 'background' => '#0b1a1f', 'background_rgba' => 'rgba(138, 75, 11, 0.45)', 'minimum' => ThemeContrast::AA_NORMAL_TEXT],
                    ['label' => 'ok on ok mist', 'foreground' => '#9ee0b8', 'background' => '#0b1a1f', 'background_rgba' => 'rgba(17, 97, 59, 0.45)', 'minimum' => ThemeContrast::AA_NORMAL_TEXT],
                    ['label' => 'info on info mist', 'foreground' => '#9fe3e6', 'background' => '#0b1a1f', 'background_rgba' => 'rgba(16, 116, 129, 0.4)', 'minimum' => ThemeContrast::AA_NORMAL_TEXT],
                ],
            ],
            'jungle-light' => [
                'skin' => 'jungle',
                'mode' => 'light',
                'pairs' => [
                    ['label' => 'ink on base', 'foreground' => '#142218', 'background' => '#eaf5e6', 'minimum' => ThemeContrast::AA_NORMAL_TEXT],
                    ['label' => 'muted on base', 'foreground' => '#4a6350', 'background' => '#eaf5e6', 'minimum' => ThemeContrast::AA_NORMAL_TEXT],
                    ['label' => 'teal link on base', 'foreground' => '#1f6b3c', 'background' => '#eaf5e6', 'minimum' => ThemeContrast::AA_NORMAL_TEXT],
                    ['label' => 'teal button on white', 'foreground' => '#1f6b3c', 'background' => $white, 'minimum' => ThemeContrast::AA_NORMAL_TEXT],
                    ['label' => 'on-primary on teal', 'foreground' => $white, 'background' => '#1f6b3c', 'minimum' => ThemeContrast::AA_NORMAL_TEXT],
                    ['label' => 'error on error mist', 'foreground' => '#9b2424', 'background' => '#fde0e0', 'minimum' => ThemeContrast::AA_NORMAL_TEXT],
                    ['label' => 'warn on warn mist', 'foreground' => '#7a4a0a', 'background' => '#f8ebd0', 'minimum' => ThemeContrast::AA_NORMAL_TEXT],
                    ['label' => 'ok on ok mist', 'foreground' => '#165230', 'background' => '#d4edd9', 'minimum' => ThemeContrast::AA_NORMAL_TEXT],
                    ['label' => 'info on info mist', 'foreground' => '#1a5c38', 'background' => '#d0e8da', 'minimum' => ThemeContrast::AA_NORMAL_TEXT],
                ],
            ],
            'jungle-dark' => [
                'skin' => 'jungle',
                'mode' => 'dark',
                'pairs' => [
                    ['label' => 'ink on base', 'foreground' => '#eaf5e6', 'background' => '#0c1610', 'minimum' => ThemeContrast::AA_NORMAL_TEXT],
                    ['label' => 'muted on base', 'foreground' => '#a8c4b0', 'background' => '#0c1610', 'minimum' => ThemeContrast::AA_NORMAL_TEXT],
                    ['label' => 'teal on base', 'foreground' => '#7dcb96', 'background' => '#0c1610', 'minimum' => ThemeContrast::AA_LARGE_OR_UI],
                    ['label' => 'on-primary on accent', 'foreground' => '#0c1610', 'background' => '#e8c04a', 'minimum' => ThemeContrast::AA_NORMAL_TEXT],
                    ['label' => 'error on error mist', 'foreground' => '#ffb0a8', 'background' => '#0c1610', 'background_rgba' => 'rgba(155, 36, 36, 0.45)', 'minimum' => ThemeContrast::AA_NORMAL_TEXT],
                    ['label' => 'warn on warn mist', 'foreground' => '#f6c98a', 'background' => '#0c1610', 'background_rgba' => 'rgba(122, 74, 10, 0.45)', 'minimum' => ThemeContrast::AA_NORMAL_TEXT],
                    ['label' => 'ok on ok mist', 'foreground' => '#a6e0b8', 'background' => '#0c1610', 'background_rgba' => 'rgba(22, 82, 48, 0.5)', 'minimum' => ThemeContrast::AA_NORMAL_TEXT],
                    ['label' => 'info on info mist', 'foreground' => '#9ed4b4', 'background' => '#0c1610', 'background_rgba' => 'rgba(31, 107, 60, 0.42)', 'minimum' => ThemeContrast::AA_NORMAL_TEXT],
                ],
            ],
        ];
    }

    #[DataProvider('aaContrastPairsProvider')]
    public function test_theme_token_pairs_meet_wcag_aa(
        string $skin,
        string $mode,
        array $pairs,
    ): void {
        foreach ($pairs as $pair) {
            $background = isset($pair['background_rgba'])
                ? ThemeContrast::compositeRgbaOn($pair['background_rgba'], $pair['background'])
                : $pair['background'];

            $ratio = ThemeContrast::contrastRatio($pair['foreground'], $background);

            $this->assertGreaterThanOrEqual(
                $pair['minimum'],
                $ratio,
                sprintf(
                    '%s %s — %s (%.2f:1, need %.1f:1)',
                    $skin,
                    $mode,
                    $pair['label'],
                    $ratio,
                    $pair['minimum'],
                ),
            );
        }
    }

    public function test_glassy_light_core_tokens_match_workspace_soft_glass_values(): void
    {
        $knownSoftGlass = [
            '--color-sg-ink' => '#12262c',
            '--color-sg-base' => '#d2f4fa',
            '--color-sg-teal' => '#107481',
            '--color-sg-teal-dark' => '#0c5d68',
            '--color-sg-muted' => '#40676a',
        ];

        $workspaceCss = (string) file_get_contents(base_path('resources/css/workspace.css'));

        foreach ($knownSoftGlass as $variable => $hex) {
            $pattern = '/'.preg_quote($variable, '/').':\s*'.preg_quote($hex, '/').'/i';

            $this->assertMatchesRegularExpression(
                $pattern,
                $workspaceCss,
                "{$variable} should remain {$hex} in workspace.css (Soft glass regression).",
            );
        }
    }

    public function test_contrast_ratio_orders_lighter_and_darker_correctly(): void
    {
        $ratio = ThemeContrast::contrastRatio('#ffffff', '#000000');

        $this->assertEqualsWithDelta(21.0, $ratio, 0.1);
        $this->assertTrue(ThemeContrast::meetsAaNormalText($ratio));
    }
}