<?php

namespace Tests\Feature;

use App\Support\ReleaseHistoryRepository;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ReleaseHistoryCommandTest extends TestCase
{
    public function test_source_controlled_history_contains_the_required_release_sequence(): void
    {
        if (! class_exists(ReleaseHistoryRepository::class)) {
            $this->fail('ReleaseHistoryRepository has not been implemented.');
        }

        $repository = app(ReleaseHistoryRepository::class);
        $releases = $repository->all();

        $this->assertSame('0.40.2', $repository->version());
        $this->assertSame('0.40.2', $repository->current()['version']);
        $this->assertSame(
            ['0.40.2', '0.40.1', '0.40.0', '0.39.13', '0.39.12', '0.39.11', '0.39.10', '0.39.9', '0.39.8', '0.39.7', '0.39.6', '0.39.5', '0.39.4', '0.39.3', '0.39.2', '0.39.1', '0.39.0', '0.38.9', '0.38.8', '0.38.7', '0.38.6', '0.38.5', '0.38.4', '0.38.3', '0.38.2', '0.38.1', '0.38.0', '0.37.11', '0.37.10', '0.37.9', '0.37.8', '0.37.7', '0.37.6', '0.37.5', '0.37.4', '0.37.3', '0.37.2', '0.37.1', '0.37.0', '0.36.2', '0.36.1', '0.36.0', '0.35.1', '0.35.0', '0.34.4', '0.34.3', '0.34.2', '0.34.1', '0.34.0', '0.33.7', '0.33.6', '0.33.5', '0.33.4', '0.33.3', '0.33.2', '0.33.1', '0.33.0', '0.32.14', '0.32.13', '0.32.12', '0.32.11', '0.32.10', '0.32.9', '0.32.8', '0.32.7', '0.32.6', '0.32.5', '0.32.4', '0.32.3', '0.32.2', '0.32.1', '0.32.0', '0.31.12', '0.31.11', '0.31.10', '0.31.9', '0.31.8', '0.31.7', '0.31.6', '0.31.5', '0.31.4', '0.31.3', '0.31.2', '0.31.1', '0.31.0', '0.30.0', '0.29.0', '0.28.0', '0.27.2', '0.27.1', '0.27.0', '0.26.1', '0.26.0', '0.25.9', '0.25.8', '0.25.7', '0.25.6', '0.25.5', '0.25.4', '0.25.3', '0.25.2', '0.25.1', '0.25.0', '0.24.1', '0.24.0', '0.23.1', '0.23.0', '0.22.0', '0.21.1', '0.21.0', '0.20.0', '0.19.4', '0.19.3', '0.19.2', '0.19.1', '0.19.0', '0.18.2', '0.18.1', '0.18.0', '0.17.0', '0.16.3', '0.16.1', '0.16.0', '0.15.0', '0.14.0', '0.13.1', '0.13.0', '0.12.1', '0.12.0', '0.11.0', '0.10.0', '0.9.2', '0.9.1', '0.9.0', '0.8.3', '0.8.2', '0.8.1', '0.8.0', '0.7.1', '0.7.0', '0.6.1', '0.6.0', '0.5.0', '0.4.2', '0.4.1', '0.4.0', '0.3.0', '0.2.1', '0.2.0', '0.1.0'],
            array_column($releases, 'version'),
        );
        $this->assertSame('2026-10-27', $releases[0]['date']);
        $this->assertSame('2026-10-27', $releases[1]['date']);
        $this->assertSame('2026-10-27', $releases[2]['date']);
        $this->assertSame('2026-10-27', $releases[3]['date']);
        $this->assertSame('2026-10-27', $releases[4]['date']);
        $this->assertSame('2026-10-27', $releases[5]['date']);
        $this->assertSame('2026-10-08', $releases[6]['date']);
        $this->assertSame('2026-09-30', $releases[7]['date']);
        $this->assertSame('2026-09-29', $releases[8]['date']);
        $this->assertSame('2026-09-29', $releases[9]['date']);
        $this->assertSame('2026-09-29', $releases[10]['date']);
        $this->assertSame('2026-09-29', $releases[11]['date']);
        $this->assertSame('2026-09-29', $releases[12]['date']);
        $this->assertSame('2026-09-29', $releases[13]['date']);
        $this->assertSame('2026-09-29', $releases[14]['date']);
        $this->assertSame('2026-09-29', $releases[15]['date']);
        $this->assertSame('2026-09-29', $releases[16]['date']);
        $this->assertSame('2026-09-29', $releases[17]['date']);
        $this->assertSame('2026-09-29', $releases[18]['date']);
        $this->assertSame('2026-09-29', $releases[19]['date']);
        $this->assertSame('2026-09-29', $releases[20]['date']);
        $this->assertSame('2026-09-29', $releases[21]['date']);
        $this->assertSame('2026-09-29', $releases[22]['date']);
        $this->assertSame('2026-09-29', $releases[23]['date']);
        $this->assertSame('2026-09-29', $releases[24]['date']);
        $this->assertSame('2026-09-29', $releases[25]['date']);
        $this->assertSame('2026-09-29', $releases[26]['date']);
        $this->assertSame('2026-09-28', $releases[27]['date']);
        $this->assertSame('2026-09-28', $releases[28]['date']);
        $this->assertSame('2026-09-28', $releases[29]['date']);
        $this->assertSame('2026-09-28', $releases[30]['date']);
        $this->assertSame('2026-09-28', $releases[31]['date']);
        $this->assertSame('2026-09-28', $releases[32]['date']);
        $this->assertSame('2026-09-28', $releases[33]['date']);
        $this->assertSame('2026-09-28', $releases[34]['date']);
        $this->assertSame('2026-09-28', $releases[35]['date']);
        $this->assertSame('2026-09-28', $releases[36]['date']);
        $this->assertSame('2026-09-28', $releases[37]['date']);
        $this->assertSame('2026-09-28', $releases[38]['date']);
        $this->assertSame('2026-09-25', $releases[39]['date']);
        $this->assertSame('2026-09-25', $releases[40]['date']);
        $this->assertSame('2026-09-25', $releases[41]['date']);
        $this->assertSame('2026-09-24', $releases[42]['date']);
        $this->assertSame('2026-09-24', $releases[43]['date']);
        $this->assertSame('2026-09-24', $releases[44]['date']);
        $this->assertSame('2026-09-24', $releases[45]['date']);
        $this->assertSame('2026-09-24', $releases[46]['date']);
        $this->assertSame('2026-09-24', $releases[47]['date']);
        $this->assertSame('2026-09-24', $releases[48]['date']);
        $this->assertSame('2026-09-23', $releases[49]['date']);
        $this->assertSame('2026-09-23', $releases[50]['date']);
        $this->assertSame('2026-09-23', $releases[51]['date']);
        $this->assertSame('2026-09-23', $releases[52]['date']);
        $this->assertSame('2026-09-23', $releases[53]['date']);
        $this->assertSame('2026-09-23', $releases[54]['date']);
        $this->assertSame('2026-09-14', $releases[55]['date']);
        $this->assertSame('2026-09-13', $releases[56]['date']);
        $this->assertSame('2026-09-12', $releases[57]['date']);
        $this->assertSame('2026-09-12', $releases[58]['date']);
        $this->assertSame('2026-09-12', $releases[59]['date']);
        $this->assertSame('2026-09-12', $releases[60]['date']);
        $this->assertSame('2026-09-12', $releases[61]['date']);
        $this->assertSame('2026-09-12', $releases[62]['date']);
        $this->assertSame('2026-09-12', $releases[63]['date']);
        $this->assertSame('2026-09-12', $releases[64]['date']);
        $this->assertSame('2026-09-12', $releases[65]['date']);
        $this->assertSame('2026-09-12', $releases[66]['date']);
        $this->assertSame('2026-09-12', $releases[67]['date']);
        $this->assertSame('2026-09-06', $releases[68]['date']);
        $this->assertSame('2026-09-06', $releases[69]['date']);
        $this->assertSame('2026-09-06', $releases[70]['date']);
        $this->assertSame('2026-09-03', $releases[71]['date']);
        $this->assertSame('2026-08-30', $releases[72]['date']);
        $this->assertSame('2026-08-29', $releases[73]['date']);
        $this->assertSame('2026-08-29', $releases[74]['date']);
        $this->assertSame('2026-08-29', $releases[75]['date']);
        $this->assertSame('2026-08-29', $releases[76]['date']);
        $this->assertSame('2026-08-29', $releases[77]['date']);
        $this->assertSame('2026-08-29', $releases[78]['date']);
        $this->assertSame('2026-08-29', $releases[79]['date']);
        $this->assertSame('2026-08-29', $releases[80]['date']);
        $this->assertSame('2026-08-29', $releases[81]['date']);
        $this->assertSame('2026-08-28', $releases[82]['date']);
        $this->assertSame('2026-08-28', $releases[83]['date']);
        $this->assertSame('2026-08-28', $releases[84]['date']);
        $this->assertSame('2026-08-28', $releases[85]['date']);
        $this->assertSame('2026-08-28', $releases[86]['date']);
        $this->assertSame('2026-08-28', $releases[87]['date']);
        $this->assertSame('2026-08-28', $releases[88]['date']);
        $this->assertSame('2026-08-28', $releases[89]['date']);
        $this->assertSame('2026-08-28', $releases[90]['date']);
        $this->assertSame('2026-08-28', $releases[91]['date']);
        $this->assertSame('2026-08-25', $releases[92]['date']);
        $this->assertSame('2026-08-25', $releases[93]['date']);
        $this->assertSame('2026-08-25', $releases[94]['date']);
        $this->assertSame('2026-08-25', $releases[95]['date']);
        $this->assertSame('2026-08-25', $releases[96]['date']);
        $this->assertSame('2026-08-25', $releases[97]['date']);
        $this->assertSame('2026-08-25', $releases[98]['date']);
        $this->assertSame('2026-08-24', $releases[99]['date']);
        $this->assertSame('2026-08-24', $releases[100]['date']);
        $this->assertSame('2026-08-24', $releases[101]['date']);
        $this->assertSame('2026-08-24', $releases[102]['date']);
        $this->assertSame('2026-08-24', $releases[103]['date']);
        $this->assertSame('2026-08-24', $releases[104]['date']);
        $this->assertSame('2026-08-23', $releases[105]['date']);
        $this->assertSame('2026-08-22', $releases[106]['date']);
        $this->assertSame('2026-08-22', $releases[107]['date']);
        $this->assertSame('2026-08-22', $releases[108]['date']);
        $this->assertSame('2026-08-20', $releases[109]['date']);
        $this->assertSame('2026-08-20', $releases[110]['date']);
        $this->assertSame('2026-08-20', $releases[111]['date']);
        $this->assertSame('2026-08-20', $releases[112]['date']);
        $this->assertSame('2026-08-20', $releases[113]['date']);
        $this->assertSame('2026-08-20', $releases[114]['date']);
        $this->assertSame('2026-08-20', $releases[115]['date']);
        $this->assertSame('2026-08-20', $releases[116]['date']);
        $this->assertSame('2026-08-20', $releases[117]['date']);
        $this->assertSame('2026-08-20', $releases[118]['date']);
        $this->assertSame('2026-08-19', $releases[119]['date']);
        $this->assertSame('2026-08-19', $releases[120]['date']);
        $this->assertSame('2026-08-19', $releases[121]['date']);
        $this->assertSame('2026-08-19', $releases[122]['date']);
        $this->assertSame('2026-08-14', $releases[123]['date']);
        $this->assertSame('2026-08-14', $releases[124]['date']);
        $this->assertSame('2026-08-11', $releases[125]['date']);
        $this->assertSame('2026-08-10', $releases[126]['date']);
        $this->assertSame('2026-08-10', $releases[127]['date']);
        $this->assertSame('2026-08-10', $releases[128]['date']);
        $this->assertSame('2026-08-09', $releases[129]['date']);
        $this->assertSame('2026-08-09', $releases[130]['date']);
        $this->assertSame('2026-08-09', $releases[131]['date']);
        $this->assertSame('2026-08-08', $releases[132]['date']);
        $this->assertSame('2026-08-07', $releases[133]['date']);
        $this->assertSame('2026-08-06', $releases[134]['date']);
        $this->assertSame('2026-08-05', $releases[135]['date']);
        $this->assertSame('2026-08-05', $releases[136]['date']);
        $this->assertSame('2026-08-05', $releases[137]['date']);
        $this->assertSame('2026-07-28', $releases[138]['date']);
        $this->assertSame('2026-07-27', $releases[139]['date']);
        $this->assertSame('2026-07-27', $releases[140]['date']);
        $this->assertSame('2026-07-27', $releases[141]['date']);
        $this->assertSame('2026-07-27', $releases[142]['date']);

        foreach (array_slice($releases, 143) as $release) {
            $this->assertSame('2026-07-24', $release['date']);
        }

        foreach (array_slice($releases, 143) as $release) {
            $this->assertStringContainsString('reconstructed from merged pull request', $release['provenance']);
        }

        $current = $repository->current();
        $this->assertSame('stable', $current['channel']);
        $this->assertSame('patch', $current['type']);
        $this->assertSame(
            [
                [
                    'label' => 'Issue #336',
                    'url' => 'https://github.com/APESCIC/MyAPES-Account/issues/336',
                ],
                [
                    'label' => 'Pull request #408',
                    'url' => 'https://github.com/APESCIC/MyAPES-Account/pull/408',
                ],
            ],
            $current['references'],
        );
        $currentText = strtolower(json_encode($current, JSON_THROW_ON_ERROR));
        foreach ([
            'roomier dashboard and service hubs',
            'issue #336',
            'pull request #408',
        ] as $requiredReleaseText) {
            $this->assertStringContainsString($requiredReleaseText, $currentText);
        }

        $desertRelease = collect($releases)->firstWhere('version', '0.16.0');
        $this->assertSame('minor', $desertRelease['type']);
        $desertText = strtolower(json_encode($desertRelease, JSON_THROW_ON_ERROR));
        foreach ([
            'desert',
            'spike',
            'bearded dragon',
            'cartoon',
            'dismissible',
            'no database migration',
            'local browser preference',
            'issue #47',
        ] as $requiredReleaseText) {
            $this->assertStringContainsString($requiredReleaseText, $desertText);
        }
        $this->assertStringNotContainsString('pull request', $desertText);

        $apesCicRelease = collect($releases)->firstWhere('version', '0.13.0');
        $this->assertSame('minor', $apesCicRelease['type']);
        $this->assertSame(
            [[
                'label' => 'Issue #12',
                'url' => 'https://github.com/APESCIC/MyAPES-Account/issues/12',
            ]],
            $apesCicRelease['references'],
        );
        $this->assertStringContainsString(
            'database recovery',
            strtolower(implode(' ', $apesCicRelease['known_limitations'])),
        );

        $phaseB = collect($releases)->firstWhere('version', '0.8.0');
        $this->assertStringContainsString(
            'issue #11',
            strtolower(implode(' ', $phaseB['known_limitations'])),
        );
        $this->assertStringContainsString(
            'issue #19',
            strtolower(implode(' ', $phaseB['known_limitations'])),
        );
        $this->assertStringNotContainsString(
            'pull request',
            strtolower(json_encode($phaseB, JSON_THROW_ON_ERROR)),
        );
    }

    public function test_validation_command_accepts_the_bootstrap_history(): void
    {
        $this->artisan('myapes:changelog-validate')
            ->expectsOutputToContain('Release history is valid at v0.40.2')
            ->assertSuccessful();
    }

    public function test_validation_command_treats_a_base_without_release_files_as_bootstrap(): void
    {
        $rootCommit = new Process(
            ['git', 'rev-list', '--max-parents=0', 'HEAD'],
            base_path(),
        );
        $rootCommit->mustRun();

        $this->artisan('myapes:changelog-validate', ['--base-ref' => trim($rootCommit->getOutput())])
            ->expectsOutputToContain('Base release contract is absent; structural validation only.')
            ->assertSuccessful();
    }

    public function test_validation_command_rejects_an_unknown_base_ref(): void
    {
        $this->artisan('myapes:changelog-validate', ['--base-ref' => 'refs/heads/not-a-real-release-base'])
            ->expectsOutputToContain('Unable to resolve base ref')
            ->assertFailed();
    }
}
