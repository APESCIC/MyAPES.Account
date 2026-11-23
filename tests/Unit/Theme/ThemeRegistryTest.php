<?php

namespace Tests\Unit\Theme;

use App\Support\Theme\ThemeRegistry;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Tests\TestCase;

class ThemeRegistryTest extends TestCase
{
    public function test_glassy_and_jungle_skins_are_registered(): void
    {
        $skins = ThemeRegistry::skins();

        $this->assertArrayHasKey('glassy', $skins);
        $this->assertArrayHasKey('jungle', $skins);
        $this->assertSame('Glassy', ThemeRegistry::label('glassy'));
        $this->assertSame('Jungle', ThemeRegistry::label('jungle'));
        $this->assertTrue(ThemeRegistry::hasSkin('glassy'));
        $this->assertTrue(ThemeRegistry::hasSkin('jungle'));
    }

    public function test_default_skin_and_mode_are_glassy_and_light(): void
    {
        $this->assertSame('glassy', ThemeRegistry::defaultSkin());
        $this->assertSame('light', ThemeRegistry::defaultMode());
    }

    public function test_registry_reads_extra_skins_from_config_without_code_changes(): void
    {
        Config::set('themes.skins.orchid', [
            'label' => 'Orchid',
            'css' => 'resources/css/themes/orchid.css',
        ]);

        $this->assertTrue(ThemeRegistry::hasSkin('orchid'));
        $this->assertSame('Orchid', ThemeRegistry::label('orchid'));
    }

    public function test_unknown_skin_and_mode_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ThemeRegistry::assertSkin('unknown-skin');
    }

    public function test_unknown_mode_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ThemeRegistry::assertMode('sepia');
    }
}