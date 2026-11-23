<?php

namespace Tests\Unit\Theme;

use Tests\TestCase;

/**
 * Gentle critter motion (#903).
 */
class CritterMotionTest extends TestCase
{
    public function test_critter_motion_css_respects_calm_reduced_motion_and_forms(): void
    {
        $motionCss = (string) file_get_contents(base_path('resources/css/themes/critter-motion.css'));

        $this->assertStringContainsString('@keyframes critter-blink', $motionCss);
        $this->assertStringContainsString('@keyframes critter-tail-flick', $motionCss);
        $this->assertStringContainsString('@keyframes jungle-leaf-sway', $motionCss);
        $this->assertMatchesRegularExpression('/critter-blink\s+1\.\d+s/', $motionCss);
        $this->assertMatchesRegularExpression('/critter-tail-flick\s+1\.\d+s/', $motionCss);
        $this->assertStringContainsString("[data-calm='true']", $motionCss);
        $this->assertStringContainsString('body.is-auth-page .critter img', $motionCss);
        $this->assertStringContainsString('form .critter img', $motionCss);
        $this->assertMatchesRegularExpression('/@media\s*\(\s*prefers-reduced-motion:\s*reduce\s*\)/', $motionCss);
        $this->assertStringContainsString('[data-skin=\'jungle\'] .sg-mega__heading::before', $motionCss);
    }

    public function test_critter_motion_is_loaded_from_workspace_css(): void
    {
        $workspaceCss = (string) file_get_contents(base_path('resources/css/workspace.css'));

        $this->assertStringContainsString("@import './themes/critter-motion.css'", $workspaceCss);
    }
}