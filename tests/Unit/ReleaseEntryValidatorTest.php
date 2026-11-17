<?php

namespace Tests\Unit;

use App\Models\Release;
use App\Support\Releases\ReleaseEntryValidator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReleaseEntryValidatorTest extends TestCase
{
    private ReleaseEntryValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new ReleaseEntryValidator;
    }

    #[Test]
    public function valid_payload_passes(): void
    {
        $errors = $this->validator->validatePayload($this->sample(), 'v0.1.0.json');

        $this->assertSame([], $errors);
    }

    #[Test]
    public function filename_must_match_version(): void
    {
        $errors = $this->validator->validatePayload($this->sample(), 'v0.2.0.json');

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Filename must match version', $errors[0]);
    }

    #[Test]
    public function invalid_date_fails(): void
    {
        $payload = $this->sample();
        $payload['date'] = '2026-02-30';

        $errors = $this->validator->validatePayload($payload, 'v0.1.0.json');

        $this->assertTrue(collect($errors)->contains(fn (string $e) => str_contains($e, 'date must be a real')));
    }

    #[Test]
    public function unknown_enum_fails(): void
    {
        $payload = $this->sample();
        $payload['channel'] = 'nightly';

        $errors = $this->validator->validatePayload($payload, 'v0.1.0.json');

        $this->assertTrue(collect($errors)->contains(fn (string $e) => str_contains($e, 'channel must be')));
    }

    #[Test]
    public function slug_keeps_semver_components_distinct(): void
    {
        $this->assertSame('release-v1-1-10', Release::slugFromVersion('1.1.10'));
        $this->assertSame('release-v1-11-0', Release::slugFromVersion('1.11.0'));
        $this->assertSame('release-v1-1-10', Release::slugFromVersion('v1.1.10'));
        $this->assertNotSame(
            Release::slugFromVersion('1.1.10'),
            Release::slugFromVersion('1.11.0'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function sample(): array
    {
        return [
            'version' => '0.1.0',
            'date' => '2026-01-02',
            'channel' => 'stable',
            'type' => 'minor',
            'title' => 'Sample',
            'summary' => 'A sample release.',
            'changes' => ['Did a thing.'],
            'affected_areas' => ['Tests'],
            'categories' => ['added'],
            'audiences' => ['public-facing'],
            'version_rationale' => 'Minor for a sample.',
            'validation' => ['phpunit'],
            'known_limitations' => [],
            'rollback' => 'Redeploy previous tag.',
            'provenance' => 'Issue #0.',
            'references' => [
                ['label' => 'Issue #0', 'url' => 'https://github.com/APESCIC/MyAPES-Account/issues/0'],
            ],
        ];
    }
}
