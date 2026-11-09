<?php

namespace Tests\Unit\MilestoneVersion;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Scripts\MilestoneVersion\Milestones;
use Scripts\MilestoneVersion\SemVer;

class SemVerTest extends TestCase
{
    public function test_minor_and_patch_compare_numerically_not_as_text(): void
    {
        $this->assertSame(1, SemVer::compare(SemVer::parse('v1.10.0'), SemVer::parse('v1.9.0')));
        $this->assertSame(1, SemVer::compare(SemVer::parse('v0.58.10'), SemVer::parse('v0.58.9')));
        $this->assertSame(-1, SemVer::compare(SemVer::parse('v0.9.0'), SemVer::parse('v1.0.0')));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function lowerThanProvider(): array
    {
        return [
            'pre-release below its release' => ['v1.0.0-beta.1', 'v1.0.0'],
            'numeric identifiers compare as numbers' => ['v0.2.0-beta.2', 'v0.2.0-beta.10'],
            'alpha below beta' => ['v1.0.0-alpha', 'v1.0.0-beta'],
            'numeric below alphanumeric' => ['v1.0.0-1', 'v1.0.0-alpha'],
            'shorter set of identifiers is lower' => ['v1.0.0-alpha', 'v1.0.0-alpha.1'],
            'next patch above a pre-release of the minor' => ['v0.2.0-beta.3', 'v0.2.1'],
        ];
    }

    #[DataProvider('lowerThanProvider')]
    public function test_pre_release_suffixes_order_by_semver_rules(string $lower, string $higher): void
    {
        $this->assertSame(-1, SemVer::compare(SemVer::parse($lower), SemVer::parse($higher)));
        $this->assertSame(1, SemVer::compare(SemVer::parse($higher), SemVer::parse($lower)));
    }

    public function test_build_metadata_is_ignored_when_comparing(): void
    {
        $this->assertTrue(SemVer::parse('v1.2.3+build.5')->equals(SemVer::parse('1.2.3')));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidProvider(): array
    {
        return [
            'two parts' => ['v1.3'],
            'leading zero' => ['v01.2.3'],
            'text' => ['latest'],
            'wildcard patch' => ['v1.3.x'],
            'empty pre-release' => ['v1.3.0-'],
        ];
    }

    #[DataProvider('invalidProvider')]
    public function test_invalid_versions_do_not_parse(string $value): void
    {
        $this->assertNull(SemVer::parse($value));
    }

    public function test_reads_the_version_from_the_start_of_a_milestone_title(): void
    {
        $this->assertSame('v0.58.3', SemVer::fromMilestoneTitle('v0.58.3 Dev: Laragon local development')?->tag());
        $this->assertSame('v1.31.0', SemVer::fromMilestoneTitle('v1.31.0: SEO')?->tag());
        $this->assertSame('v1.0.0-beta.1', SemVer::fromMilestoneTitle('v1.0.0-beta.1 Launch')?->tag());
        $this->assertNull(SemVer::fromMilestoneTitle('Ship once per milestone'));
        $this->assertNull(SemVer::fromMilestoneTitle('Later: ActivityPub (Fediverse)'));
        $this->assertNull(SemVer::fromMilestoneTitle('Release v1.2.0'));
    }

    public function test_slug_replaces_dots_for_branch_names(): void
    {
        $this->assertSame('v0-58-3', SemVer::parse('0.58.3')->slug());
    }

    public function test_latest_tag_uses_semver_order(): void
    {
        $tags = ['v1.9.0', 'v1.10.0', 'v1.10.0-beta.2', 'not-a-version', 'v1.2.0'];

        $this->assertSame('v1.10.0', Milestones::latest($tags)?->tag());
    }
}
