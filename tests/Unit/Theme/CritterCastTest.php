<?php

namespace Tests\Unit\Theme;

use App\Support\Theme\CritterCast;
use InvalidArgumentException;
use Tests\TestCase;

class CritterCastTest extends TestCase
{
    public function test_cast_has_seven_critters_with_three_poses_each(): void
    {
        $cast = CritterCast::all();

        $this->assertCount(7, $cast);
        $this->assertSame(
            ['spike', 'cleo', 'dot', 'speck', 'moss', 'noodle', 'rio'],
            CritterCast::names()
        );

        foreach ($cast as $name => $entry) {
            $this->assertArrayHasKey('poses', $entry);
            $this->assertCount(3, $entry['poses']);
            $this->assertSame(CritterCast::poses(), array_keys($entry['poses']));

            foreach (CritterCast::poses() as $pose) {
                $asset = $entry['poses'][$pose];
                $this->assertNotSame('', $asset['alt']);
                $svgPath = CritterCast::svgPublicPath($name, $pose);
                $this->assertFileExists($svgPath, "Missing SVG for {$name}-{$pose}");

                $contents = file_get_contents($svgPath);
                $this->assertIsString($contents);
                $this->assertStringContainsString('<svg', $contents);
                $this->assertStringContainsString('<path', $contents);
            }
        }
    }

    public function test_resolve_returns_primary_svg_path_and_alt_text(): void
    {
        $asset = CritterCast::resolve('spike', 'welcome');

        $this->assertSame('mascot/cast/spike-welcome.svg', $asset['svg']);
        $this->assertStringContainsString('Spike', $asset['alt']);
        $this->assertStringContainsString('welcome', strtolower($asset['alt']));
    }

    public function test_unknown_critter_or_pose_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CritterCast::resolve('unknown', 'welcome');
    }
}