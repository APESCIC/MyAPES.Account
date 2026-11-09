<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class DeploymentAuthenticationContractTest extends TestCase
{
    public function test_phase_b_activation_runs_once_in_the_fail_closed_order_as_www_data(): void
    {
        $script = $this->read('scripts/deploy/activate-release.sh');
        $activation = substr(
            $script,
            $this->position($script, 'run_artisan optimize:clear'),
        );

        $orderedCommands = [
            'optimize:clear',
            'run_artisan_with_retry_for_directory_readiness',
            'myapes:modules:preflight --no-interaction --no-ansi',
            'myapes:accounts:preflight --no-interaction --no-ansi',
            'migrate --force',
            'myapes:modules:sync --no-interaction --no-ansi',
            'config:cache',
            'route:cache',
            'view:cache',
            'permission:cache-reset --no-interaction',
            'myapes:directory-sync --source=manual --no-interaction --no-ansi',
            'myapes:authorization-sync --no-interaction --no-ansi',
            'permission:cache-reset --no-interaction',
            'myapes:modules:check --no-interaction --no-ansi',
            'myapes:accounts:check --no-interaction --no-ansi',
            'myapes:authorization-check --no-interaction --no-ansi',
            'mv -Tf "${CURRENT_LINK}.next" "$CURRENT_LINK"',
        ];
        $offset = 0;

        foreach ($orderedCommands as $command) {
            $position = strpos($activation, $command, $offset);
            $this->assertNotFalse(
                $position,
                "Expected activation to contain [{$command}] after offset {$offset}.",
            );
            $offset = $position + strlen($command);
        }

        foreach (array_slice($orderedCommands, 0, -1) as $command) {
            if ($command === 'run_artisan_with_retry_for_directory_readiness') {
                continue;
            }

            $line = $this->lineContaining($activation, $command);
            $this->assertStringContainsString(
                'run_artisan ',
                $line,
                "Expected [{$command}] to run through the production Artisan helper.",
            );
        }

        $this->assertStringContainsString(
            "run_artisan() {\n  sudo -E -u www-data env APP_ENV=production \\\n    \"\$PHP_BIN\" \"\${RELEASE_DIR}/artisan\" \"\$@\"\n}",
            $script,
        );
        $this->assertStringContainsString(
            "if ! run_artisan env --no-ansi | grep -Eq 'production'; then",
            $script,
        );
        $this->assertSame(2, substr_count(
            $script,
            'run_artisan permission:cache-reset',
        ));
        $this->assertGreaterThanOrEqual(1, substr_count($script, 'run_artisan_with_retry_for_directory_readiness'));
        $this->assertSame(1, substr_count($script, 'run_artisan myapes:modules:preflight'));
        $this->assertSame(1, substr_count($script, 'run_artisan myapes:accounts:preflight'));
        $this->assertSame(1, substr_count($script, 'run_artisan myapes:modules:sync'));
        $this->assertSame(1, substr_count($script, 'run_artisan myapes:modules:check'));
        $this->assertSame(1, substr_count($script, 'run_artisan myapes:accounts:check'));
        $this->assertSame(1, substr_count($script, 'run_artisan myapes:directory-sync'));
        $this->assertSame(1, substr_count($script, 'run_artisan myapes:authorization-sync'));
        $this->assertSame(1, substr_count($script, 'run_artisan myapes:authorization-check'));
        $this->assertStringNotContainsString('myapes:auth-check', $script);
        $this->assertStringNotContainsString('myapes:access-compatibility-sync', $script);
    }

    public function test_pre_switch_activation_failure_restores_the_current_authorization_matrix_before_reopening(): void
    {
        $result = $this->runActivationRecoveryHarness('pre-switch-failure');

        $this->assertFalse($result['process']->isSuccessful());
        $this->assertSame([
            '/usr/bin/php8.4 /release/new/artisan myapes:modules:rollback-check --target-release=/release/current --no-interaction --no-ansi',
            '/usr/bin/php8.4 /release/current/artisan permission:cache-reset --no-interaction --no-ansi',
            '/usr/bin/php8.4 /release/current/artisan myapes:authorization-sync --no-interaction --no-ansi',
            '/usr/bin/php8.4 /release/current/artisan permission:cache-reset --no-interaction --no-ansi',
            '/usr/bin/php8.4 /release/current/artisan myapes:authorization-check --no-interaction --no-ansi',
            '/usr/bin/php8.4 /release/current/artisan up --no-interaction --no-ansi',
        ], $result['commands']);
    }

    public function test_pre_mutation_activation_failure_reopens_the_quiesced_current_release_without_resynchronizing(): void
    {
        $result = $this->runActivationRecoveryHarness('before-mutation');

        $this->assertFalse($result['process']->isSuccessful());
        $this->assertSame([
            '/usr/bin/php8.4 /release/current/artisan up --no-interaction --no-ansi',
        ], $result['commands']);
    }

    public function test_activation_recovery_skips_first_same_release_and_post_switch_failures(): void
    {
        foreach ([
            'first-release',
            'same-release',
            'post-switch',
        ] as $mode) {
            $result = $this->runActivationRecoveryHarness($mode);

            $this->assertFalse($result['process']->isSuccessful(), $mode);
            $this->assertSame([], $result['commands'], $mode);
        }
    }

    public function test_activation_recovery_fails_closed_when_current_authorization_cannot_be_restored(): void
    {
        $result = $this->runActivationRecoveryHarness(
            'pre-switch-failure',
            'myapes:authorization-sync',
        );

        $this->assertFalse($result['process']->isSuccessful());
        $this->assertStringContainsString(
            'Current authorization could not be restored; maintenance mode remains active.',
            $result['process']->getOutput().$result['process']->getErrorOutput(),
        );
        $this->assertCount(3, $result['commands']);
    }

    public function test_activation_recovery_keeps_current_release_down_when_its_module_contract_is_unrepresentable(): void
    {
        $result = $this->runActivationRecoveryHarness(
            'pre-switch-failure',
            'myapes:modules:rollback-check',
        );

        $this->assertFalse($result['process']->isSuccessful());
        $this->assertStringContainsString(
            'Current release cannot represent the migrated module state; maintenance mode remains active.',
            $result['process']->getOutput().$result['process']->getErrorOutput(),
        );
        $this->assertSame([
            '/usr/bin/php8.4 /release/new/artisan myapes:modules:rollback-check --target-release=/release/current --no-interaction --no-ansi',
        ], $result['commands']);
    }

    public function test_activation_recovery_leaves_maintenance_active_when_the_current_release_cannot_reopen(): void
    {
        $result = $this->runActivationRecoveryHarness(
            'pre-switch-failure',
            ' up --no-interaction',
        );

        $this->assertFalse($result['process']->isSuccessful());
        $this->assertStringContainsString(
            'Current release could not leave maintenance mode after activation failure.',
            $result['process']->getOutput().$result['process']->getErrorOutput(),
        );
        $this->assertCount(6, $result['commands']);
    }

    public function test_activation_arms_recovery_before_database_mutation_and_commits_only_after_the_atomic_switch(): void
    {
        $script = $this->read('scripts/deploy/activate-release.sh');
        $trap = $this->position(
            $script,
            'trap restore_current_authorization_after_failure EXIT',
        );
        $maintenance = $this->position(
            $script,
            'run_current_artisan down --retry=60 --no-interaction --no-ansi',
        );
        $mutation = $this->position(
            $script,
            'PRE_SWITCH_DATABASE_MUTATED=true',
        );
        $migration = $this->position($script, 'run_artisan migrate --force');
        $switch = $this->position(
            $script,
            'mv -Tf "${CURRENT_LINK}.next" "$CURRENT_LINK"',
        );
        $committed = $this->position($script, 'ACTIVATION_SWITCHED=true');
        $reopened = $this->position(
            $script,
            'run_artisan up --no-interaction --no-ansi',
        );

        $this->assertLessThan($maintenance, $trap);
        $this->assertLessThan($mutation, $maintenance);
        $this->assertLessThan($migration, $mutation);
        $this->assertLessThan($switch, $migration);
        $this->assertLessThan($committed, $switch);
        $this->assertLessThan($reopened, $committed);
    }

    public function test_selective_media_marker_and_laravel_link_contract_expose_only_avatars(): void
    {
        $this->assertSame(
            "myapes-selective-media:v1\n",
            file_get_contents($this->path('public/storage/.myapes-selective-media')),
        );
        $filesystems = $this->read('config/filesystems.php');
        $this->assertStringContainsString(
            "public_path('storage/avatars') => storage_path('app/public/avatars')",
            $filesystems,
        );
        $this->assertStringNotContainsString(
            "public_path('storage') => storage_path('app/public')",
            $filesystems,
        );
    }

    public function test_local_media_validators_require_the_exact_lf_marker_contract(): void
    {
        $this->assertStringContainsString(
            '/public/storage/.myapes-selective-media text eol=lf',
            $this->read('.gitattributes'),
        );
        $this->assertSame(
            "myapes-selective-media:v1\n",
            file_get_contents($this->path('public/storage/.myapes-selective-media')),
        );

        foreach (['powershell', 'bash'] as $validator) {
            [$process, $root] = $this->runLocalMediaValidator(
                $validator,
                "myapes-selective-media:v1\r\n",
            );

            try {
                $process->run();
                $this->assertFalse($process->isSuccessful());
                $this->assertStringContainsString(
                    'marker',
                    strtolower($process->getErrorOutput().$process->getOutput()),
                );
            } finally {
                $this->removeTemporaryDirectory($root);
            }
        }
    }

    public function test_powershell_media_boundary_rejects_reparse_targets_wrong_links_and_unexpected_entries(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('The PowerShell reparse-point harness requires Windows.');
        }

        [$root, $publicStorage, $avatarTarget] = $this->createLocalMediaFixture();
        $avatarLink = $publicStorage.DIRECTORY_SEPARATOR.'avatars';
        $this->createWindowsJunction($avatarLink, $avatarTarget);
        try {
            $this->runPowerShellMediaValidator($root)->mustRun();
            $this->assertTrue(is_dir($avatarTarget));
        } finally {
            $this->removeWindowsJunction($avatarLink);
            $this->removeTemporaryDirectory($root);
        }

        [$root, $publicStorage] = $this->createLocalMediaFixture();
        $realPublicStorage = dirname($publicStorage).DIRECTORY_SEPARATOR.'real-storage';
        $this->assertTrue(rename($publicStorage, $realPublicStorage));
        $this->createWindowsJunction($publicStorage, $realPublicStorage);
        try {
            $process = $this->runPowerShellMediaValidator($root);
            $process->run();
            $this->assertFalse($process->isSuccessful());
            $this->assertStringContainsString(
                'reparse',
                strtolower($process->getErrorOutput().$process->getOutput()),
            );
        } finally {
            $this->removeWindowsJunction($publicStorage);
            $this->removeTemporaryDirectory($root);
        }

        [$root, $publicStorage, $avatarTarget] = $this->createLocalMediaFixture();
        $avatarParent = dirname($avatarTarget);
        $this->assertTrue(rmdir($avatarTarget));
        $this->createWindowsJunction($avatarTarget, $avatarParent);
        try {
            $process = $this->runPowerShellMediaValidator($root);
            $process->run();
            $this->assertFalse($process->isSuccessful());
            $this->assertStringContainsString(
                'reparse',
                strtolower($process->getErrorOutput().$process->getOutput()),
            );
        } finally {
            $this->removeWindowsJunction($avatarTarget);
            $this->removeTemporaryDirectory($root);
        }

        [$root, $publicStorage, $avatarTarget] = $this->createLocalMediaFixture();
        $avatarLink = $publicStorage.DIRECTORY_SEPARATOR.'avatars';
        $wrongTarget = $root.DIRECTORY_SEPARATOR.'wrong-target';
        $this->assertTrue(mkdir($wrongTarget));
        $this->createWindowsJunction($avatarLink, $wrongTarget);
        try {
            $process = $this->runPowerShellMediaValidator($root);
            $process->run();
            $this->assertFalse($process->isSuccessful());
            $this->assertStringContainsString(
                'unexpected path',
                strtolower($process->getErrorOutput().$process->getOutput()),
            );
        } finally {
            $this->removeWindowsJunction($avatarLink);
            $this->removeTemporaryDirectory($root);
        }

        [$root, $publicStorage, $avatarTarget] = $this->createLocalMediaFixture();
        $avatarLink = $publicStorage.DIRECTORY_SEPARATOR.'avatars';
        $unexpected = $publicStorage.DIRECTORY_SEPARATOR.'pet-profiles';
        $this->assertTrue(mkdir($unexpected));
        $this->createWindowsJunction($avatarLink, $avatarTarget);
        try {
            $process = $this->runPowerShellMediaValidator($root);
            $process->run();
            $this->assertFalse($process->isSuccessful());
            $this->assertDirectoryExists($unexpected);
        } finally {
            $this->removeWindowsJunction($avatarLink);
            $this->removeTemporaryDirectory($root);
        }
    }

    public function test_powershell_media_boundary_rejects_a_reparse_marker_when_file_symlinks_are_supported(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('The PowerShell reparse-point harness requires Windows.');
        }

        [$root, $publicStorage] = $this->createLocalMediaFixture();
        $marker = $publicStorage.DIRECTORY_SEPARATOR.'.myapes-selective-media';
        $markerTarget = $root.DIRECTORY_SEPARATOR.'marker-target';
        $this->assertTrue(rename($marker, $markerTarget));
        $linkProcess = new Process([
            'powershell.exe',
            '-NoProfile',
            '-Command',
            'New-Item -ItemType SymbolicLink -Path $env:MYAPES_TEST_LINK -Target $env:MYAPES_TEST_TARGET -ErrorAction Stop | Out-Null',
        ], env: [
            'MYAPES_TEST_LINK' => $marker,
            'MYAPES_TEST_TARGET' => $markerTarget,
        ]);
        $linkProcess->run();
        if (! $linkProcess->isSuccessful()) {
            $this->removeTemporaryDirectory($root);
            $this->markTestSkipped(
                'File symlink fixture unavailable: '.trim($linkProcess->getErrorOutput()),
            );
        }

        try {
            $process = $this->runPowerShellMediaValidator($root);
            $process->run();
            $this->assertFalse($process->isSuccessful());
            $this->assertStringContainsString(
                'reparse',
                strtolower($process->getErrorOutput().$process->getOutput()),
            );
        } finally {
            unlink($marker);
            $this->removeTemporaryDirectory($root);
        }
    }

    public function test_local_media_validators_reject_linked_public_ancestors_without_mutation(): void
    {
        foreach (['bash', 'powershell'] as $validator) {
            [$root, $publicStorage, $avatarTarget] = $this->createLocalMediaFixture();
            $publicRoot = dirname($publicStorage);
            $realPublicRoot = $root.DIRECTORY_SEPARATOR.'real-public';
            $avatarLink = $publicStorage.DIRECTORY_SEPARATOR.'avatars';
            $marker = $publicStorage.DIRECTORY_SEPARATOR.'.myapes-selective-media';

            $this->createDirectoryLink($avatarLink, $avatarTarget, $validator);
            $markerHash = hash_file('sha256', $marker);
            $this->assertTrue(rename($publicRoot, $realPublicRoot));
            $this->createDirectoryLink($publicRoot, $realPublicRoot, $validator);

            try {
                $process = $validator === 'powershell'
                    ? $this->runPowerShellMediaValidator($root)
                    : new Process([
                        $this->bashExecutable(),
                        $this->bashPath($this->path('scripts/local/selective-media-boundary.sh')),
                        $this->bashPath($root),
                    ], env: ['MSYS' => 'winsymlinks:sys']);
                $process->run();

                $this->assertFalse(
                    $process->isSuccessful(),
                    $process->getOutput().$process->getErrorOutput(),
                );
                $this->assertStringContainsString(
                    $validator === 'powershell' ? 'reparse' : 'unsafe',
                    strtolower($process->getErrorOutput().$process->getOutput()),
                );
                $this->assertSame(
                    $markerHash,
                    hash_file(
                        'sha256',
                        $realPublicRoot.DIRECTORY_SEPARATOR.'storage'
                            .DIRECTORY_SEPARATOR.'.myapes-selective-media',
                    ),
                );
                $this->assertDirectoryExists($avatarTarget);
            } finally {
                $this->removeDirectoryLink($publicRoot, $validator);
                $this->removeDirectoryLink(
                    $realPublicRoot.DIRECTORY_SEPARATOR.'storage'
                        .DIRECTORY_SEPARATOR.'avatars',
                    $validator,
                );
                $this->removeTemporaryDirectory($root);
            }
        }
    }

    public function test_local_media_validators_reject_linked_storage_targets_without_mutation(): void
    {
        foreach (['powershell', 'bash'] as $validator) {
            foreach (['storage', 'storage-app', 'storage-public', 'storage-avatars'] as $target) {
                [$process, $root, $externalTarget, $link] =
                    $this->runLocalMediaTargetGuardHarness($validator, $target);
                $externalSnapshot = $this->directoryTreeSnapshot($externalTarget);

                try {
                    $process->run();

                    $this->assertFalse(
                        $process->isSuccessful(),
                        $process->getOutput().$process->getErrorOutput(),
                    );
                    $this->assertStringContainsString(
                        $validator === 'powershell' ? 'reparse' : 'unsafe',
                        strtolower($process->getErrorOutput().$process->getOutput()),
                    );
                    $this->assertSame(
                        $externalSnapshot,
                        $this->directoryTreeSnapshot($externalTarget),
                        "{$validator} mutated the external {$target} target before rejection.",
                    );
                } finally {
                    $this->removeDirectoryLink($link, $validator);
                    $this->removeTemporaryDirectory($root);
                }
            }
        }
    }

    public function test_release_archive_and_apache_keep_pet_profiles_outside_public_storage(): void
    {
        $workflow = $this->read('.github/workflows/deploy-cloudron.yml');
        $packaging = $workflow."\n".$this->deploymentScripts();
        $apache = $this->read('scripts/deploy/cloudron-app.conf');

        $this->assertStringNotContainsString("--exclude '/public/storage'", $packaging);
        $this->assertStringContainsString(
            "grep -qx './public/storage/.myapes-selective-media' build/archive-list.txt",
            $packaging,
        );
        $this->assertStringContainsString(
            'validate-selective-media-archive.sh source build/release',
            $packaging,
        );
        $this->assertStringContainsString(
            'validate-selective-media-archive.sh',
            $packaging,
        );
        $this->assertStringContainsString(
            '^public/storage/$|^public/storage/\\.myapes-selective-media$',
            $packaging,
        );
        $this->assertStringContainsString(
            "grep -Fxc './public/storage/' build/archive-list.txt",
            $packaging,
        );
        $this->assertStringContainsString(
            "grep -Fxc 'public/storage/'",
            $packaging,
        );
        $this->assertStringContainsString(
            '^public/storage($|/)',
            $packaging,
        );
        $this->assertStringContainsString('RewriteEngine On', $apache);
        $this->assertStringContainsString(
            'RewriteRule ^/storage/pet-profiles(?:/|$) - [R=404,L,NC]',
            $apache,
        );
    }

    public function test_release_archive_media_validator_accepts_only_the_exact_marker(): void
    {
        [$process, $root] = $this->runArchiveMediaHarness();

        try {
            $process->mustRun();
            $this->assertStringContainsString(
                'archive-media-ok',
                $process->getOutput(),
            );
        } finally {
            $this->removeTemporaryDirectory($root);
        }
    }

    public function test_local_bootstraps_verify_marker_and_create_only_the_avatar_link(): void
    {
        $powershell = $this->read('scripts/laragon-setup.ps1');
        $bash = $this->read('scripts/local/bootstrap.sh');
        $powershellBoundary = $this->read('scripts/local/selective-media-boundary.ps1');
        $bashBoundary = $this->read('scripts/local/selective-media-boundary.sh');

        $this->assertStringContainsString('selective-media-boundary.ps1', $powershell);
        $this->assertStringContainsString('selective-media-boundary.sh', $bash);
        foreach ([$powershellBoundary, $bashBoundary] as $script) {
            $this->assertStringContainsString('.myapes-selective-media', $script);
            $this->assertStringContainsString('myapes-selective-media:v1', $script);
            $this->assertStringContainsString('avatars', $script);
            $this->assertStringContainsString('unexpected', strtolower($script));
        }
        $this->assertStringContainsString('php artisan storage:link', $bashBoundary);
        $this->assertStringContainsString('php artisan storage:link', $powershellBoundary);
        $this->assertStringNotContainsString(
            'public_path(\'storage\')',
            $bash.$powershell.$bashBoundary.$powershellBoundary,
        );
    }

    public function test_activation_and_rollback_enforce_selective_or_legacy_media_boundaries(): void
    {
        $activation = $this->read('scripts/deploy/activate-release.sh');
        $rollback = $this->read('scripts/deploy/rollback-release.sh');

        $storageLink = $this->position(
            $activation,
            'install_public_storage_boundary "$RELEASE_DIR"',
        );
        $firstArtisan = $this->position($activation, 'run_artisan optimize:clear');

        $this->assertLessThan($firstArtisan, $storageLink);
        foreach ([$activation, $rollback] as $script) {
            $boundary = $this->bashFunction(
                $script,
                'install_public_storage_boundary',
            );
            $this->assertStringContainsString(
                'SELECTIVE_MEDIA_MARKER_NAME=".myapes-selective-media"',
                $script,
            );
            $this->assertStringContainsString(
                'SELECTIVE_MEDIA_MARKER_CONTENT="myapes-selective-media:v1"',
                $script,
            );
            $this->assertStringContainsString('unexpected public storage entry', strtolower($boundary));
            $this->assertStringContainsString('shared_public_storage}/avatars', $boundary);
            $this->assertStringContainsString('cmp -s', $boundary);
            $this->assertStringContainsString('readlink -f', $boundary);
            $this->assertStringNotContainsString('rm -rf', $boundary);
        }
        $this->assertStringContainsString(
            'install_public_storage_boundary "$ROLLBACK_TARGET"',
            $rollback,
        );
        $this->assertStringNotContainsString('storage:link --force', $activation);
        $this->assertStringNotContainsString('run_artisan storage:link', $activation);
    }

    public function test_activation_and_rollback_media_helpers_preserve_selective_and_legacy_boundaries(): void
    {
        foreach ([
            'scripts/deploy/activate-release.sh',
            'scripts/deploy/rollback-release.sh',
        ] as $script) {
            foreach ([
                ['version' => '0.13.1', 'layout' => 'legacy', 'allowed' => true],
                ['version' => '0.13.1', 'layout' => 'legacy-create', 'allowed' => true],
                ['version' => '0.13.99', 'layout' => 'legacy', 'allowed' => true],
                ['version' => '0.13.1', 'layout' => 'selective', 'allowed' => false],
                ['version' => '0.14.0', 'layout' => 'legacy', 'allowed' => false],
                ['version' => '0.14.0', 'layout' => 'selective', 'allowed' => true],
                ['version' => '1.0.0', 'layout' => 'legacy', 'allowed' => false],
                ['version' => '1.0.0', 'layout' => 'selective', 'allowed' => true],
                ['version' => '8.0.0', 'layout' => 'selective', 'allowed' => true],
                ['version' => '9223372036854775808.0.0', 'layout' => 'selective', 'allowed' => true],
                ['version' => '0.9223372036854775808.0', 'layout' => 'selective', 'allowed' => true],
                ['version' => '00.14.0', 'layout' => 'selective', 'allowed' => false],
                ['version' => '0.014.0', 'layout' => 'selective', 'allowed' => false],
                ['version' => '0.14.00', 'layout' => 'selective', 'allowed' => false],
                ['version' => '01.0.0', 'layout' => 'selective', 'allowed' => false],
                ['version' => '08.0.0', 'layout' => 'selective', 'allowed' => false],
            ] as $case) {
                [$process, $root, $release, $sharedPublic] =
                    $this->runMediaBoundaryHarness(
                        $script,
                        $case['version'],
                        $case['layout'],
                    );

                try {
                    $process->run();
                    $this->assertSame(
                        $case['allowed'],
                        $process->isSuccessful(),
                        $process->getOutput().$process->getErrorOutput(),
                    );
                    if ($case['layout'] === 'selective') {
                        $entries = array_values(array_diff(
                            scandir($release.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'storage') ?: [],
                            ['.', '..'],
                        ));
                        sort($entries);
                        $this->assertSame(
                            $case['allowed']
                                ? ['.myapes-selective-media', 'avatars']
                                : ['.myapes-selective-media'],
                            $entries,
                        );
                    } else {
                        $this->assertBashSymlinkTarget(
                            $release.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'storage',
                            $sharedPublic,
                        );
                    }
                    if ($case['allowed']) {
                        $this->assertStringContainsString(
                            "{$case['version']}-{$case['layout']}-ok",
                            $process->getOutput(),
                        );
                    }
                } finally {
                    $this->removeTemporaryDirectory($root);
                }
            }

            [$process, $root, $release] = $this->runMediaBoundaryHarness(
                $script,
                '0.14.0',
                'unexpected',
            );
            try {
                $process->run();
                $this->assertFalse($process->isSuccessful());
                $this->assertDirectoryExists(
                    $release.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR
                        .'storage'.DIRECTORY_SEPARATOR.'pet-profiles',
                );
                $this->assertFileDoesNotExist(
                    $release.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR
                        .'storage'.DIRECTORY_SEPARATOR.'avatars',
                );
            } finally {
                $this->removeTemporaryDirectory($root);
            }
        }
    }

    public function test_activation_and_rollback_reject_linked_deployment_ancestors_before_mutation(): void
    {
        foreach ([
            'scripts/deploy/activate-release.sh',
            'scripts/deploy/rollback-release.sh',
        ] as $script) {
            $ancestors = [
                'data',
                'releases',
                'shared',
                'shared-storage',
                'shared-storage-app',
                'shared-storage-public',
                'shared-storage-avatars',
                'release-root',
            ];
            if ($script === 'scripts/deploy/activate-release.sh') {
                $ancestors = [
                    ...$ancestors,
                    'shared-storage-framework',
                    'shared-storage-framework-cache',
                    'shared-storage-framework-cache-data',
                    'shared-storage-framework-sessions',
                    'shared-storage-framework-views',
                    'shared-storage-logs',
                ];
            }

            foreach ($ancestors as $ancestor) {
                [$process, $root, $sentinel, $externalTarget, $links, $rootHelperSentinel] =
                    $this->runDeploymentAncestorGuardHarness($script, $ancestor);
                $sentinelHash = hash_file('sha256', $sentinel);
                $externalSnapshot = $this->directoryTreeSnapshot($externalTarget);

                try {
                    $process->run();

                    $this->assertFalse(
                        $process->isSuccessful(),
                        $process->getOutput().$process->getErrorOutput(),
                    );
                    $this->assertStringContainsString(
                        'canonical deployment path',
                        strtolower($process->getErrorOutput().$process->getOutput()),
                    );
                    $this->assertFileExists($sentinel);
                    $this->assertSame($sentinelHash, hash_file('sha256', $sentinel));
                    $this->assertSame(
                        $externalSnapshot,
                        $this->directoryTreeSnapshot($externalTarget),
                        "{$script} mutated the external {$ancestor} target before rejection.",
                    );
                    if ($rootHelperSentinel !== null) {
                        $this->assertFileDoesNotExist(
                            $rootHelperSentinel,
                            "{$script} invoked a root filesystem helper before rejecting {$ancestor}.",
                        );
                    }
                } finally {
                    usort(
                        $links,
                        static fn (string $left, string $right): int => strlen($right) <=> strlen($left),
                    );
                    foreach ($links as $link) {
                        $this->removeDirectoryLink($link, 'bash');
                    }
                    $this->removeTemporaryDirectory($root);
                }
            }
        }
    }

    public function test_activation_rejects_linked_shared_environment_before_mutation(): void
    {
        foreach (['existing', 'dangling'] as $layout) {
            [$process, $root, $externalRoot, $environmentLink, $environmentTarget, $rootHelperSentinel] =
                $this->runActivationEnvironmentGuardHarness($layout);
            $externalSnapshot = $this->directoryTreeSnapshot($externalRoot);

            try {
                $process->run();

                $this->assertFalse(
                    $process->isSuccessful(),
                    $process->getOutput().$process->getErrorOutput(),
                );
                $this->assertStringContainsString(
                    'shared environment',
                    strtolower($process->getErrorOutput().$process->getOutput()),
                );
                $this->assertSame(
                    $externalSnapshot,
                    $this->directoryTreeSnapshot($externalRoot),
                    "Activation mutated the external {$layout} environment target.",
                );
                if ($layout === 'dangling') {
                    $this->assertFileDoesNotExist($environmentTarget);
                }
                $this->assertFileDoesNotExist(
                    $rootHelperSentinel,
                    "Activation invoked a root filesystem helper before rejecting {$layout} .env.",
                );
            } finally {
                $this->removeDirectoryLink($environmentLink, 'bash');
                $this->removeTemporaryDirectory($root);
            }
        }
    }

    public function test_rollback_rejects_linked_shared_environment_before_mutation(): void
    {
        foreach (['existing', 'dangling'] as $layout) {
            [$process, $root, $externalRoot, $environmentLink, $environmentTarget, $rootHelperSentinel] =
                $this->runRollbackEnvironmentGuardHarness($layout);
            $externalSnapshot = $this->directoryTreeSnapshot($externalRoot);

            try {
                $process->run();

                $this->assertFalse(
                    $process->isSuccessful(),
                    $process->getOutput().$process->getErrorOutput(),
                );
                $this->assertStringContainsString(
                    'shared environment',
                    strtolower($process->getErrorOutput().$process->getOutput()),
                );
                $this->assertSame(
                    $externalSnapshot,
                    $this->directoryTreeSnapshot($externalRoot),
                    "Rollback mutated the external {$layout} environment target.",
                );
                if ($layout === 'dangling') {
                    $this->assertFileDoesNotExist($environmentTarget);
                }
                $this->assertFileDoesNotExist(
                    $rootHelperSentinel,
                    "Rollback invoked a root filesystem helper before rejecting {$layout} .env.",
                );
            } finally {
                $this->removeDirectoryLink($environmentLink, 'bash');
                $this->removeTemporaryDirectory($root);
            }
        }

        $rollback = $this->read('scripts/deploy/rollback-release.sh');
        $environmentGuard = '"${SHARED_DIR}/.env" "shared environment" true';
        $this->assertGreaterThanOrEqual(2, substr_count($rollback, $environmentGuard));
        $this->assertMatchesRegularExpression(
            '/assert_canonical_deployment_file \\\\\R'
                .'\s+"\$\{SHARED_DIR\}\/\.env" "shared environment" true\R'
                .'chown root:www-data "\$\{SHARED_DIR\}\/\.env"/',
            $rollback,
        );
    }

    public function test_activation_hydrates_runtime_children_as_the_application_user(): void
    {
        $activation = $this->read('scripts/deploy/activate-release.sh');
        $hydration = $this->bashFunction(
            $activation,
            'hydrate_shared_runtime_directories',
        );
        $sharedHardening = $this->bashFunction(
            $activation,
            'harden_shared_runtime_boundaries',
        );
        $ownershipRestore = $this->bashFunction(
            $activation,
            'restore_data_root_ownership',
        );

        $this->assertStringContainsString(
            'install -d -o root -g root -m 0755 "$SHARED_DIR"',
            $hydration,
        );
        $this->assertStringContainsString(
            'install -d -o www-data -g www-data -m 0770 "$shared_storage"',
            $hydration,
        );
        $this->assertStringContainsString(
            'sudo -u www-data install -d -m 0770',
            $hydration,
        );
        $this->assertSame(3, substr_count($hydration, 'install -d'));
        foreach ([
            '${shared_storage}/app/public/avatars',
            '${shared_storage}/framework/cache/data',
            '${shared_storage}/framework/sessions',
            '${shared_storage}/framework/views',
            '${shared_storage}/logs',
        ] as $applicationOwnedPath) {
            $this->assertStringContainsString($applicationOwnedPath, $hydration);
        }
        $this->assertStringContainsString(
            'assert_shared_runtime_path_boundaries true',
            $hydration,
        );
        $this->assertStringContainsString(
            'chown -hR www-data:www-data "${SHARED_DIR}/storage"',
            $sharedHardening,
        );
        $this->assertStringContainsString(
            'chown -hR www-data:www-data "${SHARED_DIR}/storage"',
            $ownershipRestore,
        );
        $this->assertStringNotContainsString(
            'chown -R www-data:www-data "${SHARED_DIR}/storage"',
            $activation,
        );
    }

    public function test_cloudron_launcher_rejects_linked_log_paths_without_external_mutation(): void
    {
        foreach (['logs', 'queue-worker.log', 'scheduler.log'] as $linkedPath) {
            [$process, $root, $externalRoot, $link] =
                $this->runLauncherLogGuardHarness($linkedPath);
            $externalSnapshot = $this->directoryTreeSnapshot($externalRoot);

            try {
                $process->run();

                $this->assertFalse(
                    $process->isSuccessful(),
                    $process->getOutput().$process->getErrorOutput(),
                );
                $this->assertStringContainsString(
                    'unsafe laravel log path',
                    strtolower($process->getErrorOutput().$process->getOutput()),
                );
                $this->assertSame(
                    $externalSnapshot,
                    $this->directoryTreeSnapshot($externalRoot),
                    "Launcher mutated the external {$linkedPath} target.",
                );
            } finally {
                $this->removeDirectoryLink($link, 'bash');
                $this->removeTemporaryDirectory($root);
            }
        }
    }

    public function test_cloudron_launcher_rejects_linked_runtime_controls_without_external_mutation(): void
    {
        foreach (['apache', 'apache-app-conf', 'run-sh'] as $linkedPath) {
            [$process, $root, $externalRoot, $link] =
                $this->runLauncherRuntimeControlGuardHarness($linkedPath);
            $externalSnapshot = $this->directoryTreeSnapshot($externalRoot);

            try {
                $process->run();

                $this->assertFalse(
                    $process->isSuccessful(),
                    $process->getOutput().$process->getErrorOutput(),
                );
                $this->assertStringContainsString(
                    'unsafe canonical runtime path',
                    strtolower($process->getErrorOutput().$process->getOutput()),
                );
                $this->assertSame(
                    $externalSnapshot,
                    $this->directoryTreeSnapshot($externalRoot),
                    "Launcher mutated external control target {$linkedPath}.",
                );
            } finally {
                $this->removeDirectoryLink($link, 'bash');
                $this->removeTemporaryDirectory($root);
            }
        }
    }

    public function test_deployment_scripts_reject_linked_runtime_control_paths_before_mutation(): void
    {
        $cases = [
            'scripts/deploy/activate-release.sh' => [
                'apache',
                'apache-app-conf',
                'run-sh',
            ],
            'scripts/deploy/rollback-release.sh' => [
                'apache',
                'apache-app-conf',
                'run-sh',
                'apache-app-conf-stage',
                'run-sh-stage',
            ],
        ];

        foreach ($cases as $script => $paths) {
            foreach ($paths as $linkedPath) {
                [$process, $root, $externalRoot, $link] =
                    $this->runRuntimeControlPathGuardHarness($script, $linkedPath);
                $externalSnapshot = $this->directoryTreeSnapshot($externalRoot);

                try {
                    $process->run();

                    $this->assertFalse(
                        $process->isSuccessful(),
                        $process->getOutput().$process->getErrorOutput(),
                    );
                    $this->assertStringContainsString(
                        'canonical deployment',
                        strtolower($process->getErrorOutput().$process->getOutput()),
                    );
                    $this->assertSame(
                        $externalSnapshot,
                        $this->directoryTreeSnapshot($externalRoot),
                        "{$script} mutated external control target {$linkedPath}.",
                    );
                } finally {
                    $this->removeDirectoryLink($link, 'bash');
                    $this->removeTemporaryDirectory($root);
                }
            }
        }
    }

    public function test_release_runtime_guards_reject_linked_bootstrap_paths_without_mutation(): void
    {
        foreach ([
            'scripts/deploy/activate-release.sh',
            'scripts/deploy/rollback-release.sh',
            'scripts/deploy/cloudron-run.sh',
        ] as $script) {
            foreach (['bootstrap', 'bootstrap-cache'] as $linkedPath) {
                [$process, $root, $externalRoot, $link] =
                    $this->runReleaseRuntimePathGuardHarness($script, $linkedPath);
                $externalSnapshot = $this->directoryTreeSnapshot($externalRoot);

                try {
                    $process->run();

                    $this->assertFalse(
                        $process->isSuccessful(),
                        $process->getOutput().$process->getErrorOutput(),
                    );
                    $this->assertStringContainsString(
                        'unsafe release runtime path',
                        strtolower($process->getErrorOutput().$process->getOutput()),
                    );
                    $this->assertSame(
                        $externalSnapshot,
                        $this->directoryTreeSnapshot($externalRoot),
                        "{$script} mutated external {$linkedPath} target.",
                    );
                } finally {
                    $this->removeDirectoryLink($link, 'bash');
                    $this->removeTemporaryDirectory($root);
                }
            }
        }
    }

    public function test_privileged_runtime_sinks_are_guarded_or_delegated_to_the_application_user(): void
    {
        $activation = $this->read('scripts/deploy/activate-release.sh');
        $rollback = $this->read('scripts/deploy/rollback-release.sh');
        $launcher = $this->read('scripts/deploy/cloudron-run.sh');
        $workflow = $this->read('.github/workflows/deploy-cloudron.yml');
        $archiveValidator = $this->read('scripts/deploy/validate-selective-media-archive.sh');
        $runtimeStart = $this->bashFunction($launcher, 'start_laravel_runtime');
        $workerStart = $this->bashFunction($launcher, 'start_laravel_worker');

        $this->assertStringNotContainsString(
            'install -d -o www-data -g www-data -m 0775 "$LOG_DIR"',
            $launcher,
        );
        $this->assertStringNotContainsString(
            'touch "${LOG_DIR}/queue-worker.log"',
            $launcher,
        );
        $this->assertStringNotContainsString(
            'chown www-data:www-data "${LOG_DIR}/queue-worker.log"',
            $launcher,
        );
        $this->assertStringContainsString(
            'sudo -E -u www-data env APP_ENV=production',
            $runtimeStart,
        );
        $this->assertStringContainsString('prepare_laravel_logs', $runtimeStart);
        $this->assertStringContainsString(
            'sudo -E -u www-data env APP_ENV=production',
            $workerStart,
        );
        $this->assertStringContainsString(
            'exec \"\$@\" >>\"\$log_path\" 2>&1',
            $workerStart,
        );
        $this->assertGreaterThanOrEqual(2, substr_count(
            $launcher,
            'assert_runtime_control_paths',
        ));

        foreach ([$activation, $rollback] as $script) {
            $this->assertStringContainsString(
                'assert_release_runtime_path_boundaries',
                $script,
            );
            $this->assertStringContainsString(
                'assert_canonical_deployment_file',
                $script,
            );
        }
        $this->assertStringContainsString(
            'assert_release_runtime_path_boundaries "$TEMP_RELEASE_DIR" false',
            $activation,
        );
        $this->assertStringContainsString(
            'assert_release_runtime_path_boundaries "$ROLLBACK_TARGET" false',
            $rollback,
        );
        $this->assertStringContainsString(
            'assert_release_runtime_path_boundaries "$release_root" false',
            $launcher,
        );
        $this->assertStringContainsString(
            'assert_release_runtime_path_boundaries "$release_root" false',
            $workflow,
        );
        $this->assertStringContainsString('local -A collision_keys=()', $archiveValidator);
        $this->assertStringContainsString(
            'unsupported filesystem entry type',
            strtolower($archiveValidator),
        );
    }

    public function test_activation_requires_the_complete_phase_b_runtime_payload(): void
    {
        $script = $this->read('scripts/deploy/activate-release.sh');
        $requiredPaths = $this->bashArray($script, 'required_paths');

        $this->assertSame([
            'VERSION',
            'REVISION',
            'artisan',
            'public/index.php',
            'public/storage/.myapes-selective-media',
            'vendor/autoload.php',
            'public/build/manifest.json',
            'resources/data/releases.json',
            'resources/data/module-runtime-contract.json',
            'config/modules.php',
            'config/permission.php',
            'database/migrations/2026_07_28_000000_create_permission_tables.php',
            'database/migrations/2026_07_28_000100_cut_over_authorization_domain.php',
            'database/migrations/2026_08_06_000000_create_module_installations_table.php',
            'app/Console/Commands/AuthorizationPreflight.php',
            'app/Console/Commands/DirectorySync.php',
            'app/Console/Commands/AuthorizationSync.php',
            'app/Console/Commands/AuthorizationCheck.php',
            'app/Console/Commands/ModulesPreflight.php',
            'app/Console/Commands/ModulesSync.php',
            'app/Console/Commands/ModulesCheck.php',
            'app/Console/Commands/ModulesRollbackCheck.php',
            'app/Services/ModuleRollbackCompatibilityChecker.php',
            'scripts/deploy/activate-release.sh',
            'scripts/deploy/rollback-release.sh',
            'scripts/deploy/cloudron-app.conf',
            'scripts/deploy/cloudron-run.sh',
            'scripts/deploy/production.env.example',
            'DEPLOYMENT-CONTROLS.sha256',
        ], $requiredPaths);
    }

    public function test_workflow_restarts_after_activation_and_rolls_back_restart_or_verification_failures(): void
    {
        $workflow = $this->read('.github/workflows/deploy-cloudron.yml');
        $deploymentSources = $workflow."\n".$this->deploymentScripts();

        $activation = $this->position($workflow, '- name: Extract and activate uploaded release');
        $restart = $this->position($workflow, '- name: Restart activated release');
        $runtimeControls = $this->position($workflow, '- name: Reconstitute authenticated runtime controls after restart');
        $verify = $this->position($workflow, '- name: Verify exact release health and OIDC redirect');
        $rollback = $this->position($workflow, '- name: Roll back code after failed release restart or verification');

        $this->assertLessThan($restart, $activation);
        $this->assertLessThan($runtimeControls, $restart);
        $this->assertLessThan($verify, $runtimeControls);
        $this->assertLessThan($rollback, $verify);
        $this->assertMatchesRegularExpression(
            '/name:\s*Restart activated release\R\s+id:\s*restart\R\s+if:\s*steps\.activate\.outcome\s*==\s*[\'"]success[\'"]\R\s+continue-on-error:\s*true/s',
            $workflow,
        );
        $this->assertMatchesRegularExpression(
            '/\bid:\s*verify\s*\R(?:(?!\bid:).){0,300}?continue-on-error:\s*true/s',
            $workflow,
        );
        $this->assertMatchesRegularExpression(
            '/\bif:\s*(?:\$\{\{\s*)?steps\.restart\.outcome\s*==\s*[\'"]success[\'"]\s*&&\s*steps\.runtime_controls\.outcome\s*==\s*[\'"]success[\'"](?:\s*\}\})?/',
            $workflow,
        );
        $this->assertStringContainsString('steps.runtime_controls.outcome', $workflow);
        $this->assertStringContainsString(
            "steps.recovery.outputs.rollback_required == 'true'",
            $workflow,
        );
        $this->assertStringContainsString(
            'bash "$remote_dir/scripts/deploy/activate-release.sh"',
            $workflow,
        );
        $this->assertStringContainsString(
            '"$release_sha" "$previous_release" "$expected_controls_sha256"',
            $workflow,
        );
        $this->assertStringContainsString(
            'bash "$control_root/scripts/deploy/rollback-release.sh"',
            $workflow,
        );
        $this->assertStringContainsString(
            '"$previous_release" "$failed_release" "$expected_controls_sha256" "$control_root"',
            $workflow,
        );
        $this->assertStringContainsString('previous_version', $workflow);
        $this->assertStringContainsString('previous_release', $workflow);
        $this->assertStringContainsString('Verify restored release health and OIDC redirect', $workflow);

        $this->assertStringContainsString('/healthz', $workflow);
        $this->assertStringContainsString('reported_version', $workflow);
        $this->assertStringContainsString('APP_VERSION', $workflow);
        $this->assertStringContainsString('reported_database', $workflow);
        $this->assertStringContainsString('reported_cache', $workflow);
        $this->assertStringContainsString('reported_environment', $workflow);
        $this->assertStringContainsString('/staff/auth/login', $deploymentSources);
        $this->assertStringContainsString('/staff/auth/callback', $deploymentSources);
        $this->assertStringContainsString('CLOUDRON_OIDC_AUTH_URL', $deploymentSources);
        $this->assertStringContainsString('response_type', $deploymentSources);
        $this->assertStringContainsString('state', $deploymentSources);
        $this->assertStringContainsString('nonce', $deploymentSources);
        $this->assertStringContainsString('code_challenge_method', $deploymentSources);
        $this->assertStringContainsString('code_challenge', $deploymentSources);
        $this->assertStringContainsString('S256', $deploymentSources);
        $this->assertStringContainsString('openid', $deploymentSources);
        $this->assertStringContainsString('profile', $deploymentSources);
        $this->assertStringContainsString('email', $deploymentSources);

        $normalizedWorkflow = preg_replace('/\\\\\R\s*/', ' ', $workflow) ?? $workflow;
        $this->assertDoesNotMatchRegularExpression(
            '/curl[^\r\n]*(?:\s-L(?:\s|$)|\s--location(?:\s|$))/m',
            $normalizedWorkflow,
        );
    }

    public function test_workflow_validates_versions_on_pull_requests_without_deploying_them(): void
    {
        $workflow = $this->test_workflow();
        $deployWorkflow = $this->deployWorkflow();

        $this->assertMatchesRegularExpression('/pull_request:\s*\R/', $workflow);
        $this->assertStringContainsString('fetch-depth: 0', $workflow);
        $this->assertStringContainsString('github.event.pull_request.base.sha', $workflow);
        $this->assertStringContainsString('github.event.before', $workflow);
        $this->assertStringContainsString('myapes:changelog-validate', $workflow);
        $this->assertStringContainsString('npm run test:frontend', $workflow);
        $this->assertStringContainsString('workflow_dispatch:', $deployWorkflow);
        $this->assertStringNotContainsString('workflow_run:', $deployWorkflow);
        $this->assertStringNotContainsString('pull_request:', $deployWorkflow);
    }

    public function test_deploy_workflow_stamps_versioned_deployments_and_github_releases(): void
    {
        $workflow = $this->deployWorkflow();

        $this->assertStringContainsString('run-name: ${{ github.event.inputs.app_version || \'from-ref\' }} Beta', $workflow);
        $this->assertStringNotContainsString('run-name: Deploy v', $workflow);
        $this->assertStringContainsString('app_version:', $workflow);
        $this->assertStringContainsString('chrnorm/deployment-action@500aa6a23c81ffa1acf71072aee3cfa2cc2e556a', $workflow);
        $this->assertStringContainsString('RELEASE_DISPLAY_TITLE:', $workflow);
        $this->assertStringContainsString('description: ${{ env.RELEASE_DISPLAY_TITLE }}', $workflow);
        $this->assertStringNotContainsString('description: v${{ env.APP_VERSION }} (${{ env.RELEASE_SHA }})', $workflow);
        $this->assertStringContainsString('deployments: write', $workflow);
        $this->assertStringContainsString('publish-github-release:', $workflow);
        $this->assertStringContainsString('needs: [resolve-release, deploy-cloudron]', $workflow);
        $this->assertStringContainsString('if: needs.deploy-cloudron.result == \'success\'', $workflow);
        $this->assertStringContainsString('scripts/deploy/github-release-notes.sh', $workflow);
        $this->assertStringContainsString('tag_name: v${{ env.APP_VERSION }}', $workflow);
        $this->assertStringContainsString('name: ${{ env.RELEASE_DISPLAY_TITLE }}', $workflow);
        $this->assertDoesNotMatchRegularExpression(
            '/^\s*name:\s*v\$\{\{\s*env\.APP_VERSION\s*\}\}/m',
            $workflow,
        );
        $this->assertStringContainsString('target_commitish: ${{ env.RELEASE_SHA }}', $workflow);
        $this->assertStringContainsString('softprops/action-gh-release@da05d552573ad5aba039eaac05058a918a7bf631', $workflow);

        $this->assertMatchesRegularExpression(
            '/publish-github-release:[\s\S]*?permissions:\s*\R\s*contents: write/m',
            $workflow,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/Create GitHub Release[\s\S]*?name:\s*\$\{\{\s*needs\.resolve-release\.outputs\.release_title/s',
            $workflow,
        );
    }

    public function test_workflow_pins_supported_node_24_first_party_actions(): void
    {
        $workflow = $this->combinedWorkflows();

        foreach ([
            'actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1' => 5,
            'actions/setup-node@820762786026740c76f36085b0efc47a31fe5020 # v7.0.0' => 2,
        ] as $reference => $expectedOccurrences) {
            $this->assertSame(
                $expectedOccurrences,
                substr_count($workflow, $reference),
                "Unexpected occurrence count for {$reference}.",
            );
        }

        $this->assertStringNotContainsString('actions/upload-artifact@', $workflow);
        $this->assertStringNotContainsString('actions/download-artifact@', $workflow);

        foreach ([
            'actions/checkout@v4',
            'actions/setup-node@v4',
            'actions/upload-artifact@v4',
            'actions/download-artifact@v4',
        ] as $deprecatedReference) {
            $this->assertStringNotContainsString($deprecatedReference, $workflow);
        }
    }

    public function test_database_compatibility_job_allows_forward_only_contract_to_finish(): void
    {
        $workflow = $this->test_workflow();
        $databaseCompatibilityStart = $this->position(
            $workflow,
            '  database-compatibility:',
        );
        $databaseCompatibilityJob = substr($workflow, $databaseCompatibilityStart);

        preg_match_all(
            '/^\s*timeout-minutes:\s*\d+\s*$/m',
            $databaseCompatibilityJob,
            $timeoutSettings,
        );

        $this->assertCount(1, $timeoutSettings[0]);
        $this->assertMatchesRegularExpression(
            '/^\s*timeout-minutes:\s*30\s*$/m',
            $databaseCompatibilityJob,
        );
    }

    public function test_workflow_runs_the_phase_b_contract_on_mysql(): void
    {
        $workflow = $this->test_workflow();
        $databaseCompatibilityJob = substr(
            $workflow,
            $this->position($workflow, '  database-compatibility:'),
        );
        $deployWorkflow = $this->deployWorkflow();

        $this->assertStringContainsString('database-compatibility:', $workflow);
        $this->assertStringContainsString('image: mysql:8.4', $workflow);
        $this->assertStringContainsString('php-version: \'8.4\'', $workflow);
        $this->assertStringContainsString('pdo_mysql', $workflow);
        $this->assertStringContainsString('pcntl', $workflow);
        $this->assertStringContainsString('MYSQL_DATABASE: myapes_test', $workflow);
        $this->assertStringContainsString('mysqladmin ping', $workflow);
        $this->assertStringContainsString('DB_CONNECTION: mysql', $databaseCompatibilityJob);
        $this->assertStringContainsString('--tmpfs /var/lib/mysql', $databaseCompatibilityJob);
        $this->assertStringContainsString('MYSQL_INITDB_SKIP_TZINFO', $databaseCompatibilityJob);
        $this->assertStringContainsString('innodb_flush_log_at_trx_commit=0', $databaseCompatibilityJob);
        $this->assertStringContainsString('APP_MAINTENANCE_DRIVER: file', $workflow);
        $this->assertStringContainsString('APP_MAINTENANCE_STORE: file', $workflow);
        $this->assertStringContainsString('CACHE_STORE: array', $databaseCompatibilityJob);
        $this->assertStringContainsString('LOG_CHANNEL: \'null\'', $databaseCompatibilityJob);
        $this->assertStringNotContainsString('image: mariadb', $workflow);
        $this->assertStringNotContainsString('MARIADB_', $databaseCompatibilityJob);
        $this->assertStringNotContainsString('mariadb:11.4', $workflow);
        $this->assertStringNotContainsString('CACHE_STORE: database', $databaseCompatibilityJob);
        $this->assertStringNotContainsString('npm run build', $databaseCompatibilityJob);

        foreach ([
            'AuthorizationSchemaTest.php',
            'AuthorizationRoleMaterializerTest.php',
            'AuthorizationCutoverGuardTest.php',
            'AuthorizationCutoverConcurrencyTest.php',
            'PermissionSchemaMigrationTest.php',
            'AccessCompatibilityMigrationTest.php',
            'AccessCompatibilityCommandTest.php',
            'RolelessApplicationCompatibilityTest.php',
            'UserAccessCompatibilityTest.php',
            'AuthorizationLifecycleCommandTest.php',
            'DirectoryCatalogueSynchronizerTest.php',
            'DirectorySyncWiringTest.php',
            'Auth/DirectoryRevalidationTest.php',
            'DirectoryGroupMappingServiceTest.php',
            'DirectoryRoleSynchronizerTest.php',
            'ModuleRegistryTest.php',
            'ModuleInstallationSynchronizationTest.php',
            'ModuleLifecycleTest.php',
            'ModuleLifecycleConcurrencyTest.php',
            'ModuleRollbackCompatibilityTest.php',
            'ProductionUpgradePreflightTest.php',
        ] as $testFile) {
            $this->assertStringContainsString(
                "tests/Feature/{$testFile}",
                $workflow,
            );
        }

        $this->assertStringContainsString(
            'tests/Support/directory-sync-timeout-probe.php prepare',
            $workflow,
        );
        $this->assertStringContainsString(
            'tests/Support/directory-sync-timeout-probe.php assert',
            $workflow,
        );
        $this->assertStringContainsString(
            '--queue=security-timeout',
            $workflow,
        );
        $this->assertStringContainsString(
            '--stop-when-empty',
            $workflow,
        );
        $this->assertStringNotContainsString(
            '--once',
            $workflow,
        );

        $this->assertStringContainsString(
            'needs: [deployment-control-authentication, resolve-release]',
            $deployWorkflow,
        );
        $this->assertStringContainsString('deploy-cloudron:', $deployWorkflow);
    }

    public function test_database_config_does_not_define_a_mariadb_connection(): void
    {
        $database = $this->read('config/database.php');

        $this->assertStringContainsString("'mysql' => [", $database);
        $this->assertStringNotContainsString("'mariadb' => [", $database);
        $this->assertStringNotContainsString("'driver' => 'mariadb'", $database);
    }

    public function test_workflow_isolates_the_destructive_foundation_migration_contract(): void
    {
        $workflow = $this->test_workflow();
        $databaseCompatibilityJob = substr(
            $workflow,
            $this->position($workflow, '  database-compatibility:'),
        );
        $foundationMigrationTest = 'tests/Feature/ApesCicFoundationMigrationTest.php';
        $standaloneFoundationCommand = "php artisan test {$foundationMigrationTest}";
        $destructiveTestWipeCommand = 'php artisan db:wipe --force --no-interaction';
        $standaloneFoundationPosition = $this->position(
            $databaseCompatibilityJob,
            $standaloneFoundationCommand,
        );
        $destructiveTestWipePosition = $this->position(
            $databaseCompatibilityJob,
            $destructiveTestWipeCommand,
        );
        $mainSuitePosition = $this->position(
            $databaseCompatibilityJob,
            'php artisan test \\',
        );
        $mainSuite = substr(
            $databaseCompatibilityJob,
            $mainSuitePosition,
            $this->position(
                $databaseCompatibilityJob,
                'php artisan migrate:fresh --seed --force --no-interaction',
            ) - $mainSuitePosition,
        );

        $this->assertSame(1, substr_count($databaseCompatibilityJob, $foundationMigrationTest));
        $this->assertMatchesRegularExpression(
            '/^\s*php artisan test\s+tests\/Feature\/ApesCicFoundationMigrationTest\.php\s*$/m',
            $databaseCompatibilityJob,
        );
        $this->assertSame(1, substr_count(
            $databaseCompatibilityJob,
            $destructiveTestWipeCommand,
        ));
        $this->assertSame(1, preg_match_all(
            '/^[\t ]*'.preg_quote($destructiveTestWipeCommand, '/').'[\t ]*\r?$/m',
            $databaseCompatibilityJob,
        ));
        $this->assertLessThan($destructiveTestWipePosition, $standaloneFoundationPosition);
        $this->assertLessThan($mainSuitePosition, $destructiveTestWipePosition);
        $this->assertStringNotContainsString($foundationMigrationTest, $mainSuite);
    }

    public function test_workflow_packages_and_verifies_semantic_and_commit_identities(): void
    {
        $workflow = $this->read('.github/workflows/deploy-cloudron.yml');
        $packaging = $workflow."\n".$this->deploymentScripts();

        $this->assertStringContainsString('app_version:', $workflow);
        $this->assertStringContainsString('app_version=', $workflow);
        $this->assertStringContainsString('scripts/deploy/package-release.sh', $workflow);
        $this->assertStringContainsString("grep -qx './VERSION' build/archive-list.txt", $packaging);
        foreach ([
            'resources/data/releases.json',
            'resources/data/module-runtime-contract.json',
            'config/modules.php',
            'config/permission.php',
            'database/migrations/2026_07_28_000000_create_permission_tables.php',
            'database/migrations/2026_07_28_000100_cut_over_authorization_domain.php',
            'database/migrations/2026_08_06_000000_create_module_installations_table.php',
            'app/Console/Commands/AuthorizationPreflight.php',
            'app/Console/Commands/DirectorySync.php',
            'app/Console/Commands/AuthorizationSync.php',
            'app/Console/Commands/AuthorizationCheck.php',
            'app/Console/Commands/ModulesPreflight.php',
            'app/Console/Commands/ModulesSync.php',
            'app/Console/Commands/ModulesCheck.php',
            'app/Console/Commands/ModulesRollbackCheck.php',
            'app/Services/ModuleRollbackCompatibilityChecker.php',
            'scripts/deploy/activate-release.sh',
            'scripts/deploy/rollback-release.sh',
            'scripts/deploy/cloudron-app.conf',
            'scripts/deploy/cloudron-run.sh',
            'scripts/deploy/production.env.example',
            'DEPLOYMENT-CONTROLS.sha256',
        ] as $requiredPath) {
            $this->assertStringContainsString(
                "grep -qx './{$requiredPath}' build/archive-list.txt",
                $packaging,
            );
        }
        $this->assertStringContainsString('reported_version', $workflow);
        $this->assertStringContainsString('reported_release', $workflow);
        $this->assertStringContainsString(
            '"$reported_version" == "$APP_VERSION"',
            $workflow,
        );
        $this->assertStringContainsString(
            '"$reported_release" == "$RELEASE_SHA"',
            $workflow,
        );
        $this->assertStringContainsString(
            '[[ "$reported_version" =~ ^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$ ]]',
            $workflow,
        );
        $this->assertStringContainsString(
            '[[ "$reported_release" =~ ^[0-9a-f]{40}$ ]]',
            $workflow,
        );
    }

    public function test_workflow_authenticates_every_deployment_control_before_execution(): void
    {
        $workflow = $this->read('.github/workflows/deploy-cloudron.yml');
        $activation = $this->read('scripts/deploy/activate-release.sh');
        $rollback = $this->read('scripts/deploy/rollback-release.sh');

        $this->assertStringContainsString(
            'deployment_controls_sha256:',
            $workflow,
        );
        $this->assertStringContainsString(
            'DEPLOYMENT-CONTROLS.sha256',
            $workflow,
        );
        $this->assertStringContainsString(
            'DEPLOYMENT_CONTROLS_SHA256',
            $workflow,
        );
        $this->assertStringContainsString(
            'sha256sum --check --strict',
            $workflow,
        );
        $this->assertGreaterThanOrEqual(
            3,
            substr_count($workflow, 'test ! -L'),
        );
        $this->assertLessThan(
            strpos(
                $workflow,
                'bash "$remote_dir/scripts/deploy/activate-release.sh"',
            ),
            strpos(
                $workflow,
                'test ! -L "$remote_dir/$control_path"',
            ),
        );
        $this->assertLessThan(
            strpos(
                $workflow,
                'bash "$control_root/scripts/deploy/rollback-release.sh"',
            ),
            strpos(
                $workflow,
                'test ! -L "$control_root/$control_path"',
            ),
        );
        $this->assertStringContainsString(
            'EXPECTED_CONTROLS_SHA256="${3:-}"',
            $activation,
        );
        $this->assertStringContainsString(
            'EXPECTED_CONTROLS_SHA256="${3:-}"',
            $rollback,
        );
        $this->assertStringContainsString(
            'verify_deployment_controls',
            $activation,
        );
        $this->assertStringContainsString(
            'verify_deployment_controls',
            $rollback,
        );
    }

    public function test_deployment_control_trust_is_derived_before_third_party_actions_and_dependencies(): void
    {
        $testWorkflow = $this->test_workflow();
        $deployWorkflow = $this->deployWorkflow();
        $authenticationStart = $this->position(
            $testWorkflow,
            'deployment-control-authentication:',
        );
        $qualityStart = $this->position($testWorkflow, '  quality:');
        $authenticationJob = substr(
            $testWorkflow,
            $authenticationStart,
            $qualityStart - $authenticationStart,
        );

        $this->assertLessThan($qualityStart, $authenticationStart);
        $this->assertStringNotContainsString('uses:', $authenticationJob);
        $this->assertStringNotContainsString('composer ', $authenticationJob);
        $this->assertStringNotContainsString('npm ', $authenticationJob);
        $this->assertStringContainsString(
            'git -C "$repository_root" show',
            $authenticationJob,
        );
        foreach ([
            'scripts/deploy/activate-release.sh',
            'scripts/deploy/rollback-release.sh',
            'scripts/deploy/cloudron-app.conf',
            'scripts/deploy/cloudron-run.sh',
        ] as $controlPath) {
            $this->assertStringContainsString($controlPath, $authenticationJob);
        }
        $this->assertStringContainsString(
            'needs: [deployment-control-authentication, resolve-release]',
            $deployWorkflow,
        );
        $this->assertStringContainsString(
            'needs.deployment-control-authentication.outputs.deployment_controls_sha256',
            $deployWorkflow,
        );
        $this->assertStringNotContainsString(
            'needs.quality.outputs.deployment_controls_sha256',
            $deployWorkflow,
        );
    }

    public function test_activation_and_rollback_consume_root_owned_control_copies(): void
    {
        $workflow = $this->read('.github/workflows/deploy-cloudron.yml');
        $activation = $this->read('scripts/deploy/activate-release.sh');
        $rollback = $this->read('scripts/deploy/rollback-release.sh');

        $this->assertStringContainsString(
            'CONTROL_RELEASES_DIR="/run/myapes-deployment-controls"',
            $activation,
        );
        $this->assertStringContainsString(
            'CONTROL_RELEASE_DIR="${CONTROL_RELEASES_DIR}/${RELEASE_SHA}"',
            $activation,
        );
        $this->assertStringContainsString(
            'install -d -o root -g root -m 0700 "$CONTROL_RELEASES_DIR"',
            $activation,
        );
        $this->assertStringContainsString(
            'assert_hardened_control_directory "$CONTROL_RELEASE_DIR"',
            $activation,
        );
        $this->assertStringNotContainsString(
            'chown -R www-data:www-data "$TEMP_RELEASE_DIR"',
            $activation,
        );
        $this->assertStringContainsString(
            'verify_deployment_controls "$CONTROL_RELEASE_DIR" "$EXPECTED_CONTROLS_SHA256"',
            $activation,
        );
        $this->assertStringContainsString(
            'CONTROL_RELEASES_DIR="/run/myapes-deployment-controls"',
            $rollback,
        );
        $this->assertStringContainsString('CONTROL_ROOT="${4:-}"', $rollback);
        $this->assertStringContainsString(
            '"$CONTROL_ROOT" != "${CONTROL_RELEASES_DIR}/${EXPECTED_CURRENT_SHA}"',
            $rollback,
        );
        $this->assertStringContainsString(
            'verify_deployment_controls "$CONTROL_ROOT" "$EXPECTED_CONTROLS_SHA256"',
            $rollback,
        );
        $this->assertStringContainsString(
            '"${CONTROL_ROOT}/scripts/deploy/cloudron-app.conf"',
            $rollback,
        );
        $this->assertStringContainsString(
            '"${CONTROL_ROOT}/scripts/deploy/cloudron-run.sh"',
            $rollback,
        );
        $this->assertStringContainsString(
            'bash "$control_root/scripts/deploy/rollback-release.sh"',
            $workflow,
        );
        $this->assertStringNotContainsString('/app/data/deployment-controls', $workflow.$activation.$rollback);
    }

    public function test_post_restart_controls_are_reauthenticated_outside_app_data_and_write_boundaries_are_checked(): void
    {
        $workflow = $this->read('.github/workflows/deploy-cloudron.yml');
        $activation = $this->read('scripts/deploy/activate-release.sh');

        $this->assertStringContainsString(
            '- name: Reconstitute authenticated runtime controls after restart',
            $workflow,
        );
        $this->assertStringContainsString('id: runtime_controls', $workflow);
        $this->assertStringContainsString(
            'control_root="/run/myapes-deployment-controls/$release_sha"',
            $workflow,
        );
        $this->assertGreaterThanOrEqual(2, substr_count(
            $workflow,
            'tar --extract --gzip',
        ));
        $this->assertGreaterThanOrEqual(2, substr_count(
            $workflow,
            'sha256sum --check --strict DEPLOYMENT-CONTROLS.sha256',
        ));
        $this->assertStringContainsString(
            'sudo -u www-data test -w "$control_root"',
            $workflow,
        );
        foreach ([
            '/app/data',
            '/app/data/current',
            '/app/data/current/public',
            '/app/data/run.sh',
            '/app/data/apache/app.conf',
        ] as $protectedPath) {
            $this->assertStringContainsString(
                'sudo -u www-data test -w '.$protectedPath,
                $workflow,
            );
        }
        $this->assertStringContainsString('chmod -R a-w -- "$release_root"', $activation);
        $this->assertStringContainsString('chmod 0555 "$DATA_DIR" "$RELEASES_DIR"', $activation);
        $this->assertStringContainsString(
            'harden_release_write_boundaries "$CURRENT_TARGET_BEFORE"',
            $activation,
        );
        $this->assertStringNotContainsString('rm -rf -- "$DEPLOY_DIR"', $activation);
    }

    public function test_cloudron_restart_restores_root_ownership_before_application_code_runs(): void
    {
        $workflow = $this->read('.github/workflows/deploy-cloudron.yml');
        $activation = $this->read('scripts/deploy/activate-release.sh');
        $rollback = $this->read('scripts/deploy/rollback-release.sh');
        $launcher = $this->read('scripts/deploy/cloudron-run.sh');

        $ownershipRestore = $this->position(
            $workflow,
            'restore_runtime_ownership "$release_sha"',
        );
        $archiveRead = $this->position(
            $workflow,
            'test -f "$archive_path"',
        );
        $this->assertLessThan($archiveRead, $ownershipRestore);
        $this->assertStringContainsString(
            'chown -hR root:root "$release_root"',
            $workflow,
        );
        $this->assertStringContainsString(
            'chown root:root /app/data /app/data/releases /app/data/shared /app/data/apache',
            $workflow,
        );
        $this->assertStringContainsString(
            'chown root:www-data /app/data/shared/.env',
            $workflow,
        );
        $this->assertStringContainsString(
            'chown -hR www-data:www-data /app/data/shared/storage',
            $workflow,
        );
        $this->assertStringContainsString(
            "if: always() && steps.restart.outcome != 'skipped'",
            $workflow,
        );

        foreach ([$workflow, $activation, $rollback, $launcher] as $source) {
            $this->assertStringContainsString(
                "stat -Lc '%U:%G'",
                $source,
            );
            $this->assertStringContainsString('root:root', $source);
        }

        foreach ([$workflow, $activation, $rollback] as $source) {
            $this->assertStringContainsString(
                '! -path "${release_root}/bootstrap/cache"',
                $source,
            );
            $this->assertStringContainsString(
                '! -path "${release_root}/bootstrap/cache/*"',
                $source,
            );
        }

        $this->assertStringContainsString(
            'chown -hR root:root "$release_root"',
            $activation,
        );
        $parentOwnership = $this->position(
            $activation,
            'chown root:root "$DATA_DIR" "$RELEASES_DIR"',
        );
        $releaseHardening = $this->position(
            $activation,
            'harden_release_write_boundaries "$RELEASE_DIR"',
        );
        $this->assertLessThan($releaseHardening, $parentOwnership);
        $this->assertStringContainsString(
            'chown -hR root:root "$ROLLBACK_TARGET"',
            $rollback,
        );
        $this->assertStringContainsString(
            'Application runtime ownership is not root-controlled.',
            $launcher,
        );
        $this->assertStringContainsString('chown() {', $launcher);
        $this->assertStringContainsString('command chown "$@"', $launcher);
        $this->assertStringContainsString(
            'restore_runtime_ownership',
            $launcher,
        );
        $this->assertStringContainsString(
            'repair_release_symlinks',
            $launcher,
        );
        $this->assertStringContainsString(
            'Ownership assert failed at boot; repairing release symlinks and restoring ownership.',
            $launcher,
        );
        $this->assertStringContainsString(
            'ln -s /app/data/shared/storage "${release_root}/storage"',
            $launcher,
        );
        $this->assertLessThan(
            $this->position($launcher, 'repair_release_symlinks'),
            $this->position($launcher, 'find /app/data -xdev'),
        );
        $this->assertStringContainsString(
            'start_laravel_runtime',
            $launcher,
        );
        $this->assertStringContainsString(
            'MYAPES_OWNERSHIP_RESTORED=true',
            $launcher,
        );
        $this->assertStringContainsString('exec() {', $launcher);
        $this->assertStringContainsString(
            'Refusing process replacement before root ownership restoration.',
            $launcher,
        );
        $this->assertStringContainsString(
            '"$3" != "/app/data" || "$4" != "/run/apache2"',
            $launcher,
        );
        foreach ([$workflow, $launcher] as $source) {
            $this->assertStringContainsString(
                "-path '/app/data/releases/*/bootstrap/cache' -prune",
                $source,
            );
        }
        $this->assertStringContainsString(
            '-path "${RELEASES_DIR}/*/bootstrap/cache" -prune',
            $activation,
        );
        $this->assertStringContainsString(
            'sudo -u www-data test -w "${CURRENT_DIR}/bootstrap/cache"',
            $launcher,
        );
        $this->assertStringContainsString(
            'sudo -u www-data test -w /app/data/shared/storage',
            $launcher,
        );
    }

    public function test_control_verifiers_reject_marker_preserving_tampering_and_unsafe_manifests(): void
    {
        $temporaryRoot = sys_get_temp_dir()
            .DIRECTORY_SEPARATOR
            .'myapes-control-verifier-'.bin2hex(random_bytes(8));
        $this->assertTrue(mkdir(
            $temporaryRoot.DIRECTORY_SEPARATOR.'scripts'.DIRECTORY_SEPARATOR.'deploy',
            0700,
            true,
        ));

        try {
            $controlPaths = [
                'scripts/deploy/activate-release.sh',
                'scripts/deploy/rollback-release.sh',
                'scripts/deploy/cloudron-app.conf',
                'scripts/deploy/cloudron-run.sh',
            ];
            foreach ($controlPaths as $relativePath) {
                $target = $temporaryRoot.DIRECTORY_SEPARATOR.str_replace(
                    '/',
                    DIRECTORY_SEPARATOR,
                    $relativePath,
                );
                $this->assertTrue(copy($this->path($relativePath), $target));
            }
            $manifestPath = $temporaryRoot.DIRECTORY_SEPARATOR
                .'DEPLOYMENT-CONTROLS.sha256';
            $this->writeControlManifest(
                $temporaryRoot,
                $manifestPath,
                $controlPaths,
            );
            $manifestHash = hash_file('sha256', $manifestPath);
            $this->assertIsString($manifestHash);

            foreach (['activate-release.sh', 'rollback-release.sh'] as $script) {
                $this->runControlVerifier(
                    $temporaryRoot,
                    $script,
                    $manifestHash,
                )->mustRun();
            }

            file_put_contents(
                $temporaryRoot.DIRECTORY_SEPARATOR.'scripts'
                    .DIRECTORY_SEPARATOR.'deploy'.DIRECTORY_SEPARATOR
                    .'cloudron-app.conf',
                "\n# marker-preserving tamper\n",
                FILE_APPEND,
            );
            foreach (['activate-release.sh', 'rollback-release.sh'] as $script) {
                $this->assertControlVerifierFails(
                    $temporaryRoot,
                    $script,
                    $manifestHash,
                );
            }

            copy(
                $this->path('scripts/deploy/cloudron-app.conf'),
                $temporaryRoot.DIRECTORY_SEPARATOR.'scripts'
                    .DIRECTORY_SEPARATOR.'deploy'.DIRECTORY_SEPARATOR
                    .'cloudron-app.conf',
            );
            $unsafeManifest = hash_file(
                'sha256',
                $temporaryRoot.DIRECTORY_SEPARATOR.'scripts'
                    .DIRECTORY_SEPARATOR.'deploy'.DIRECTORY_SEPARATOR
                    .'activate-release.sh',
            )."  ../outside-control\n";
            file_put_contents($manifestPath, $unsafeManifest);
            $unsafeManifestHash = hash_file('sha256', $manifestPath);
            $this->assertIsString($unsafeManifestHash);

            foreach (['activate-release.sh', 'rollback-release.sh'] as $script) {
                $this->assertControlVerifierFails(
                    $temporaryRoot,
                    $script,
                    $unsafeManifestHash,
                );
                $this->assertControlVerifierFails(
                    $temporaryRoot,
                    $script,
                    str_repeat('0', 64),
                );
            }
        } finally {
            $this->removeTemporaryDirectory($temporaryRoot);
        }
    }

    public function test_release_exclusion_only_rejects_the_git_directory(): void
    {
        $packaging = $this->read('.github/workflows/deploy-cloudron.yml')
            ."\n".$this->deploymentScripts();

        $this->assertStringContainsString('\.git($|/)', $packaging);
        $this->assertStringNotContainsString('^\./(\.git|', $packaging);
    }

    public function test_production_environment_has_no_legacy_group_list_inputs(): void
    {
        $environment = $this->read('scripts/deploy/production.env.example');
        $activation = $this->read('scripts/deploy/activate-release.sh');
        $runScript = $this->read('scripts/deploy/cloudron-run.sh');
        $apache = $this->read('scripts/deploy/cloudron-app.conf');

        $this->assertStringNotContainsString('OIDC_STAFF_GROUPS', $environment);
        $this->assertStringNotContainsString('OIDC_ADMIN_GROUPS', $environment);
        $this->assertStringNotContainsString('OIDC_SUPERADMIN_GROUPS', $environment);
        $this->assertStringContainsString('APP_ENV=production', $environment);
        $this->assertStringContainsString(
            'sudo -E -u www-data env APP_ENV=production',
            $activation,
        );
        $this->assertStringContainsString(
            'sudo -E -u www-data env APP_ENV=production',
            $runScript,
        );
        $this->assertStringContainsString('export APP_ENV=production', $runScript);
        $this->assertStringContainsString('SetEnv APP_ENV production', $apache);
        foreach ([
            '/app/data',
            '/app/data/releases',
            '"$CURRENT_DIR"',
            '"${CURRENT_DIR}/public"',
            '/app/data/run.sh',
            '/app/data/apache/app.conf',
        ] as $protectedPath) {
            $this->assertStringContainsString($protectedPath, $runScript);
        }
        $this->assertStringContainsString(
            'sudo -u www-data test -w "$protected_path"',
            $runScript,
        );
        $this->assertStringContainsString(
            'sudo -u www-data test -w "${CURRENT_DIR}/bootstrap/cache"',
            $runScript,
        );
    }

    public function test_activation_upserts_cloudron_redis_runtime_keys(): void
    {
        $activation = $this->read('scripts/deploy/activate-release.sh');

        $this->assertStringContainsString('ensure_cloudron_redis_runtime', $activation);
        $this->assertStringContainsString('upsert_shared_env_key', $activation);
        $this->assertStringContainsString('CLOUDRON_REDIS_HOST', $activation);
        $this->assertStringContainsString('upsert_shared_env_key CACHE_STORE redis', $activation);
        $this->assertStringContainsString('upsert_shared_env_key QUEUE_CONNECTION redis', $activation);
        $this->assertStringContainsString('upsert_shared_env_key SESSION_DRIVER redis', $activation);
        $this->assertStringContainsString('upsert_shared_env_key APP_MAINTENANCE_STORE redis', $activation);
        $this->assertStringContainsString('upsert_shared_env_key REDIS_CLIENT phpredis', $activation);
        $this->assertStringContainsString('Cloudron Redis is required.', $activation);
        $this->assertStringNotContainsString('REDIS_HOST=127.0.0.1', $activation);
        $this->assertStringNotContainsString('upsert_shared_env_key REDIS_HOST', $activation);
    }

    public function test_activation_upserts_cloudron_locale_runtime_keys(): void
    {
        $activation = $this->read('scripts/deploy/activate-release.sh');
        $environment = $this->read('scripts/deploy/production.env.example');

        $this->assertStringContainsString('ensure_cloudron_locale_runtime', $activation);
        $this->assertStringContainsString('upsert_shared_env_key APP_LOCALE en_GB', $activation);
        $this->assertStringContainsString('upsert_shared_env_key APP_FALLBACK_LOCALE en', $activation);
        $this->assertStringContainsString('upsert_shared_env_key APP_FAKER_LOCALE en_GB', $activation);
        $this->assertStringContainsString("ensure_cloudron_redis_runtime\nensure_cloudron_locale_runtime", $activation);
        $this->assertStringContainsString('APP_LOCALE=en_GB', $environment);
        $this->assertStringContainsString('APP_FALLBACK_LOCALE=en', $environment);
        $this->assertStringContainsString('APP_FAKER_LOCALE=en_GB', $environment);
    }

    public function test_laravel_defaults_to_redis_when_cloudron_redis_is_present(): void
    {
        $this->assertStringContainsString(
            "env('CACHE_STORE', env('CLOUDRON_REDIS_HOST') ? 'redis' : 'database')",
            $this->read('config/cache.php'),
        );
        $this->assertStringContainsString(
            "env('QUEUE_CONNECTION', env('CLOUDRON_REDIS_HOST') ? 'redis' : 'database')",
            $this->read('config/queue.php'),
        );
        $this->assertStringContainsString(
            "env('SESSION_DRIVER', env('CLOUDRON_REDIS_HOST') ? 'redis' : 'database')",
            $this->read('config/session.php'),
        );
    }

    public function test_retained_worker_timeout_is_safe_for_v071_without_narrowing_the_phase_b_job(): void
    {
        $runScript = $this->read('scripts/deploy/cloudron-run.sh');
        $directoryJob = $this->read('app/Jobs/RunDirectorySync.php');
        $queueConfig = $this->read('config/queue.php');

        $this->assertStringContainsString('--timeout=60', $runScript);
        $this->assertStringNotContainsString('--timeout=240', $runScript);
        $this->assertStringContainsString(
            'public const TIMEOUT_SECONDS = 240;',
            $directoryJob,
        );
        $this->assertSame(
            3,
            substr_count(
                $queueConfig,
                "env('DB_QUEUE_RETRY_AFTER', 300)",
            )
                + substr_count(
                    $queueConfig,
                    "env('BEANSTALKD_QUEUE_RETRY_AFTER', 300)",
                )
                + substr_count(
                    $queueConfig,
                    "env('REDIS_QUEUE_RETRY_AFTER', 300)",
                ),
        );
    }

    public function test_first_release_does_not_resolve_absent_release_links(): void
    {
        $script = $this->read('scripts/deploy/activate-release.sh');

        $this->assertMatchesRegularExpression(
            '/if \[\[ -L "\$CURRENT_LINK" \]\]; then\R\s+CURRENT_TARGET_BEFORE="\$\(readlink -f/',
            $script,
        );
        $this->assertMatchesRegularExpression(
            '/if \[\[ -L "\$PREVIOUS_LINK" \]\]; then\R\s+PREVIOUS_TARGET_BEFORE="\$\(readlink -f/',
            $script,
        );
        $this->assertStringContainsString('CURRENT_TARGET_BEFORE=""', $script);
        $this->assertStringContainsString('PREVIOUS_TARGET_BEFORE=""', $script);
    }

    public function test_code_rollback_quiesces_mutations_reconciles_authorization_and_never_reverses_migrations(): void
    {
        $script = $this->read('scripts/deploy/rollback-release.sh');

        $this->assertStringContainsString('ROLLBACK_TARGET="$(readlink -f "$PREVIOUS_LINK")"', $script);
        $this->assertStringContainsString(
            '"$ROLLBACK_TARGET" != "${RELEASES_DIR}/${EXPECTED_ROLLBACK_SHA}"',
            $script,
        );
        $this->assertStringContainsString(
            '"$ROLLBACK_SHA" != "$EXPECTED_ROLLBACK_SHA"',
            $script,
        );
        $this->assertStringContainsString(
            '"$CURRENT_SHA" != "$EXPECTED_CURRENT_SHA"',
            $script,
        );
        $this->assertStringContainsString(
            'Previous release lost its immutable write boundary',
            $script,
        );
        $this->assertStringContainsString(
            'install -m 0444 "${CONTROL_ROOT}/scripts/deploy/cloudron-app.conf"',
            $script,
        );
        $this->assertStringContainsString(
            'install -m 0555 "${CONTROL_ROOT}/scripts/deploy/cloudron-run.sh"',
            $script,
        );
        $this->assertStringNotContainsString(
            'install -m 0644 "${ROLLBACK_TARGET}/scripts/deploy/cloudron-app.conf"',
            $script,
        );
        $this->assertStringNotContainsString(
            'install -m 0755 "${ROLLBACK_TARGET}/scripts/deploy/cloudron-run.sh"',
            $script,
        );
        $this->assertStringContainsString(
            'SetEnv[[:space:]]+APP_ENV[[:space:]]+production',
            $script,
        );
        $this->assertStringContainsString(
            'export APP_ENV=production',
            $script,
        );
        $this->assertStringContainsString(
            'env APP_ENV=production',
            $script,
        );
        $runtimeStaging = $this->position(
            $script,
            'install -m 0444 "${CONTROL_ROOT}/scripts/deploy/cloudron-app.conf"',
        );
        $moduleCompatibility = $this->position(
            $script,
            'myapes:modules:rollback-check',
        );
        $maintenanceStart = $this->position(
            $script,
            '"${CURRENT_TARGET}/artisan" down --retry=60 --no-interaction --no-ansi',
        );
        $rollbackAuthorizationSync = $this->position(
            $script,
            '"${ROLLBACK_TARGET}/artisan" myapes:authorization-sync --no-interaction --no-ansi',
        );
        $rollbackAuthorizationCheck = $this->position(
            $script,
            '"${ROLLBACK_TARGET}/artisan" myapes:authorization-check --no-interaction --no-ansi',
        );
        $firstRollbackMutation = $this->position(
            $script,
            'rm -f -- "$target_path"',
        );
        $applicationSwitch = $this->position(
            $script,
            'mv -Tf "${CURRENT_LINK}.rollback" "$CURRENT_LINK"',
        );
        $runtimePublish = $this->position(
            $script,
            'mv -Tf "${DATA_DIR}/apache/app.conf.rollback" "${DATA_DIR}/apache/app.conf"',
        );
        $this->assertLessThan($applicationSwitch, $runtimeStaging);
        $this->assertLessThan($runtimePublish, $applicationSwitch);
        $this->assertLessThan($moduleCompatibility, $maintenanceStart);
        $this->assertLessThan(
            $rollbackAuthorizationSync,
            $moduleCompatibility,
        );
        $this->assertLessThan(
            $rollbackAuthorizationCheck,
            $rollbackAuthorizationSync,
        );
        $this->assertLessThan(
            $applicationSwitch,
            $rollbackAuthorizationCheck,
        );
        $this->assertLessThan($firstRollbackMutation, $moduleCompatibility);
        $this->assertStringContainsString(
            '"${CURRENT_TARGET}/artisan" myapes:modules:rollback-check',
            $script,
        );
        $this->assertStringContainsString(
            '--target-release="$ROLLBACK_TARGET" --no-interaction --no-ansi',
            $script,
        );
        $this->assertStringContainsString('ln -s "$ROLLBACK_TARGET" "${CURRENT_LINK}.rollback"', $script);
        $this->assertStringContainsString('mv -Tf "${CURRENT_LINK}.rollback" "$CURRENT_LINK"', $script);
        $this->assertStringContainsString('scripts/deploy/cloudron-app.conf', $script);
        $this->assertStringContainsString('scripts/deploy/cloudron-run.sh', $script);
        $this->assertStringContainsString(
            '"${ROLLBACK_TARGET}/artisan" up --no-interaction --no-ansi',
            $script,
        );
        $this->assertStringContainsString(
            '"${CURRENT_TARGET}/artisan" myapes:authorization-sync --no-interaction --no-ansi',
            $script,
        );
        $this->assertStringContainsString(
            'trap restore_current_release EXIT',
            $script,
        );
        $this->assertStringContainsString('Database migrations were retained', $script);
        $this->assertStringNotContainsString('migrate:rollback', $script);
        $this->assertStringNotContainsString('migrate:reset', $script);
        $this->assertStringNotContainsString('migrate:fresh', $script);
    }

    public function test_activation_target_classifier_covers_safe_recovery_states_and_rejects_ambiguous_links(): void
    {
        $newRelease = str_repeat('a', 40);
        $priorRelease = str_repeat('b', 40);
        $olderRelease = str_repeat('c', 40);
        $releaseRoot = '/app/data/releases';

        foreach ([
            'pre-switch' => [
                $newRelease,
                $priorRelease,
                'true',
                'false',
                "{$releaseRoot}/{$priorRelease}",
                "{$releaseRoot}/{$olderRelease}",
                $releaseRoot,
            ],
            'post-switch' => [
                $newRelease,
                $priorRelease,
                'true',
                'false',
                "{$releaseRoot}/{$newRelease}",
                "{$releaseRoot}/{$priorRelease}",
                $releaseRoot,
            ],
            'same-release' => [
                $newRelease,
                $newRelease,
                'true',
                'true',
                "{$releaseRoot}/{$newRelease}",
                "{$releaseRoot}/{$olderRelease}",
                $releaseRoot,
            ],
            'first-deployment' => [
                $newRelease,
                '',
                'false',
                'false',
                '',
                '',
                $releaseRoot,
            ],
            'unavailable-rollback' => [
                $newRelease,
                '',
                'false',
                'false',
                "{$releaseRoot}/{$newRelease}",
                '',
                $releaseRoot,
            ],
        ] as $expectedState => $arguments) {
            $process = $this->runActivationTargetClassifierHarness($arguments);

            $this->assertTrue(
                $process->isSuccessful(),
                $expectedState.': '.$process->getErrorOutput(),
            );
            $this->assertSame($expectedState, trim($process->getOutput()));
        }

        foreach ([
            [
                $newRelease,
                $priorRelease,
                'true',
                'false',
                '/tmp/untrusted-release',
                "{$releaseRoot}/{$priorRelease}",
                $releaseRoot,
            ],
            [
                $newRelease,
                $priorRelease,
                'true',
                'false',
                "{$releaseRoot}/{$newRelease}",
                '',
                $releaseRoot,
            ],
        ] as $arguments) {
            $process = $this->runActivationTargetClassifierHarness($arguments);

            $this->assertFalse($process->isSuccessful());
        }
    }

    public function test_workflow_classifies_activation_failure_before_restart_and_uses_one_recovery_decision(): void
    {
        $workflow = $this->read('.github/workflows/deploy-cloudron.yml');
        $activation = $this->position(
            $workflow,
            '- name: Extract and activate uploaded release',
        );
        $classification = $this->position(
            $workflow,
            '- name: Classify activation failure',
        );
        $restart = $this->position(
            $workflow,
            '- name: Restart activated release',
        );
        $recovery = $this->position(
            $workflow,
            '- name: Determine deployment recovery',
        );
        $rollback = $this->position(
            $workflow,
            '- name: Roll back code after failed release restart or verification',
        );

        $this->assertLessThan($classification, $activation);
        $this->assertLessThan($restart, $classification);
        $this->assertLessThan($rollback, $recovery);

        $activationBlock = substr($workflow, $activation, $classification - $activation);
        $classificationBlock = substr($workflow, $classification, $restart - $classification);
        $restartBlock = substr($workflow, $restart, $recovery - $restart);
        $recoveryBlock = substr($workflow, $recovery, $rollback - $recovery);

        $this->assertStringContainsString('id: activate', $activationBlock);
        $this->assertStringContainsString('continue-on-error: true', $activationBlock);
        $this->assertStringContainsString('id: activation_state', $classificationBlock);
        $this->assertStringContainsString(
            "if: always() && steps.activate.outcome == 'failure'",
            $classificationBlock,
        );
        $this->assertStringContainsString(
            '--classify-activation-state',
            $classificationBlock,
        );
        $this->assertStringContainsString(
            "if: steps.activate.outcome == 'success'",
            $restartBlock,
        );
        $this->assertStringContainsString('id: recovery', $recoveryBlock);
        $this->assertStringContainsString('failure_detected=', $recoveryBlock);
        $this->assertStringContainsString('rollback_required=', $recoveryBlock);
        $this->assertStringContainsString('failure_scope=', $recoveryBlock);

        $this->assertSame(3, substr_count(
            $workflow,
            "if: always() && steps.recovery.outputs.rollback_required == 'true'",
        ));
        $this->assertStringContainsString(
            "if: always() && steps.recovery.outputs.failure_detected == 'true'",
            $workflow,
        );
        $this->assertStringNotContainsString(
            "steps.previous.outputs.same_release != 'true' && (steps.restart.outcome == 'failure'",
            $workflow,
        );
    }

    public function test_deployment_staging_is_removed_only_after_rollback_verification_succeeds(): void
    {
        $workflow = $this->read('.github/workflows/deploy-cloudron.yml');
        $environmentGate = $this->position(
            $workflow,
            '- name: Verify restored release effective Laravel environment',
        );
        $healthGate = $this->position(
            $workflow,
            '- name: Verify restored release health and OIDC redirect',
        );
        $cleanup = $this->position(
            $workflow,
            '- name: Remove deployment staging after accepted outcome',
        );
        $failure = $this->position(
            $workflow,
            '- name: Fail deployment after unsuccessful outcome',
        );

        $environmentBlock = substr(
            $workflow,
            $environmentGate,
            $healthGate - $environmentGate,
        );
        $healthBlock = substr(
            $workflow,
            $healthGate,
            $cleanup - $healthGate,
        );
        $cleanupBlock = substr($workflow, $cleanup, $failure - $cleanup);

        $this->assertStringContainsString(
            'id: restored_environment',
            $environmentBlock,
        );
        $this->assertStringContainsString(
            'id: restored_verification',
            $healthBlock,
        );
        $this->assertStringContainsString(
            "steps.rollback.outcome == 'success'",
            $cleanupBlock,
        );
        $this->assertStringContainsString(
            "steps.restored_environment.outcome == 'success'",
            $cleanupBlock,
        );
        $this->assertStringContainsString(
            "steps.restored_verification.outcome == 'success'",
            $cleanupBlock,
        );
    }

    public function test_restored_release_verification_supports_the_v071_health_contract(): void
    {
        $workflow = $this->read('.github/workflows/deploy-cloudron.yml');
        $environmentGate = $this->position(
            $workflow,
            '- name: Verify restored release effective Laravel environment',
        );
        $healthGate = $this->position(
            $workflow,
            '- name: Verify restored release health and OIDC redirect',
        );
        $nextStep = $this->position(
            $workflow,
            '- name: Fail deployment after unsuccessful outcome',
        );

        $this->assertLessThan($healthGate, $environmentGate);
        $this->assertLessThan($nextStep, $healthGate);

        $environmentBlock = substr(
            $workflow,
            $environmentGate,
            $healthGate - $environmentGate,
        );
        $healthBlock = substr(
            $workflow,
            $healthGate,
            $nextStep - $healthGate,
        );

        $this->assertStringContainsString(
            'cloudron --server "$CLOUDRON_FQDN" --token "$CLOUDRON_TOKEN"',
            $environmentBlock,
        );
        $this->assertStringContainsString(
            '/app/data/current/artisan env --no-ansi',
            $environmentBlock,
        );
        $this->assertStringContainsString(
            'Restored release effective Laravel environment verification failed.',
            $environmentBlock,
        );
        $this->assertStringNotContainsString('APP_ENV=production', $environmentBlock);
        $this->assertStringContainsString(
            '"$reported_cache" == "ok"',
            $healthBlock,
        );
        $this->assertStringNotContainsString(
            'reported_environment',
            $healthBlock,
        );
    }

    public function test_deployment_controls_preserve_preexisting_operator_maintenance(): void
    {
        $activation = $this->read('scripts/deploy/activate-release.sh');
        $rollback = $this->read('scripts/deploy/rollback-release.sh');

        foreach ([$activation, $rollback] as $script) {
            $probe = $this->bashFunction($script, 'maintenance_state_for_release');
            $this->assertStringContainsString('Illuminate\\Contracts\\Console\\Kernel', $probe);
            $this->assertStringContainsString('maintenanceMode()->active()', $probe);
            $this->assertStringContainsString('active', $probe);
            $this->assertStringContainsString('inactive', $probe);
            $this->assertStringContainsString('PREEXISTING_MAINTENANCE', $script);
        }

        $this->assertLessThan(
            $this->position($activation, 'run_current_artisan down --retry=60'),
            $this->position($activation, 'maintenance_state_for_release "$CURRENT_TARGET_BEFORE"'),
        );
        $this->assertStringContainsString(
            'if [[ "$DEPLOYMENT_MAINTENANCE_ACTIVE" == true ]]; then',
            $activation,
        );
        $this->assertStringContainsString(
            'if [[ "$ROLLBACK_MAINTENANCE_ACTIVE" == true ]]; then',
            $rollback,
        );
    }

    public function test_new_release_health_requires_a_boolean_maintenance_field_without_requiring_false(): void
    {
        $workflow = $this->read('.github/workflows/deploy-cloudron.yml');
        $verification = substr(
            $workflow,
            $this->position($workflow, '- name: Verify exact release health and OIDC redirect'),
        );

        $this->assertStringContainsString(
            'reported_maintenance="$(jq -r',
            $verification,
        );
        $this->assertStringContainsString(
            '"$reported_maintenance" =~ ^(true|false)$',
            $verification,
        );
        $this->assertStringNotContainsString(
            '"$reported_maintenance" == "false"',
            $verification,
        );
    }

    public function test_rollback_health_accepts_boolean_or_legacy_absence_but_not_invalid_values(): void
    {
        $workflow = $this->read('.github/workflows/deploy-cloudron.yml');
        $verification = substr(
            $workflow,
            $this->position($workflow, '- name: Verify restored release health and OIDC redirect'),
            $this->position($workflow, '- name: Remove deployment staging after accepted outcome')
                - $this->position($workflow, '- name: Verify restored release health and OIDC redirect'),
        );

        $this->assertStringContainsString('if has("maintenance")', $verification);
        $this->assertStringContainsString('else "legacy" end', $verification);
        $this->assertStringContainsString(
            '"$reported_maintenance" =~ ^(true|false|legacy)$',
            $verification,
        );
    }

    public function test_maintenance_recovery_and_queue_contract_is_documented(): void
    {
        $readme = str_replace("\r\n", "\n", $this->read('README.md'));

        $this->assertStringContainsString('## Maintenance operations and recovery', $readme);
        $this->assertStringContainsString('APP_MAINTENANCE_DRIVER=cache', $readme);
        $this->assertStringContainsString('APP_MAINTENANCE_STORE=redis', $readme);
        $this->assertStringContainsString('without `--force`', $readme);
        $this->assertStringContainsString('queued Redis jobs remain durable', $readme);
        $this->assertStringContainsString('/admin/maintenance', $readme);
        $this->assertStringContainsString('/staff/auth/callback', $readme);
        $this->assertStringContainsString(
            "sudo -E -u www-data /usr/bin/php8.4 \\\n    /app/data/current/artisan up --no-interaction --no-ansi",
            $readme,
        );
        $this->assertStringContainsString(
            'exec --app "$CLOUDRON_APP_ID" -- \\',
            $readme,
        );
    }

    private function position(string $content, string $needle): int
    {
        $position = strpos($content, $needle);

        $this->assertNotFalse($position, "Expected deployment source to contain [{$needle}].");

        return $position;
    }

    private function lineContaining(string $content, string $needle): string
    {
        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            if (str_contains($line, $needle)) {
                return $line;
            }
        }

        $this->fail("Expected deployment source to contain a line with [{$needle}].");
    }

    /**
     * @return list<string>
     */
    private function bashArray(string $script, string $name): array
    {
        $matched = preg_match(
            '/^'.preg_quote($name, '/').'=\(\R(?<items>.*?)^\)\R/ms',
            $script,
            $matches,
        );
        $this->assertSame(1, $matched, "Expected Bash array [{$name}].");

        return array_values(array_filter(array_map(
            static fn (string $line): string => trim($line, " \t\r\n\"'"),
            preg_split('/\R/', $matches['items']) ?: [],
        )));
    }

    private function deploymentScripts(): string
    {
        $scripts = glob($this->path('scripts/deploy/*.sh')) ?: [];
        $contents = array_map(
            static fn (string $script): string => (string) file_get_contents($script),
            $scripts,
        );

        return implode("\n", $contents);
    }

    /**
     * @param  list<string>  $controlPaths
     */
    private function writeControlManifest(
        string $root,
        string $manifestPath,
        array $controlPaths,
    ): void {
        $lines = [];
        foreach ($controlPaths as $relativePath) {
            $digest = hash_file(
                'sha256',
                $root.DIRECTORY_SEPARATOR.str_replace(
                    '/',
                    DIRECTORY_SEPARATOR,
                    $relativePath,
                ),
            );
            $this->assertIsString($digest);
            $lines[] = "{$digest}  {$relativePath}";
        }

        $this->assertNotFalse(file_put_contents(
            $manifestPath,
            implode("\n", $lines)."\n",
        ));
    }

    private function runControlVerifier(
        string $root,
        string $script,
        string $manifestHash,
    ): Process {
        return new Process([
            $this->bashExecutable(),
            $this->bashPath(
                $root.DIRECTORY_SEPARATOR.'scripts'.DIRECTORY_SEPARATOR.'deploy'
                    .DIRECTORY_SEPARATOR.$script,
            ),
            '--verify-controls',
            $this->bashPath($root),
            $manifestHash,
        ]);
    }

    /**
     * @return array{process: Process, commands: list<string>}
     */
    private function runActivationRecoveryHarness(
        string $mode,
        string $failCommand = '',
    ): array {
        $temporaryRoot = sys_get_temp_dir()
            .DIRECTORY_SEPARATOR
            .'myapes-activation-recovery-'.bin2hex(random_bytes(8));
        $this->assertTrue(mkdir($temporaryRoot, 0700, true));
        $script = $this->read('scripts/deploy/activate-release.sh');
        $harnessPath = $temporaryRoot.DIRECTORY_SEPARATOR.'harness.sh';
        $logPath = $temporaryRoot.DIRECTORY_SEPARATOR.'commands.log';
        $functions = $this->bashFunction($script, 'run_current_artisan')
            ."\n"
            .$this->bashFunction($script, 'run_artisan')
            ."\n"
            .$this->bashFunction(
                $script,
                'restore_current_authorization_after_failure',
            );
        $harness = <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail

MODE="$1"
LOG_PATH="$2"
FAIL_COMMAND="${3:-}"
CURRENT_TARGET_BEFORE="/release/current"
RELEASE_DIR="/release/new"
PHP_BIN="/usr/bin/php8.4"
PRE_SWITCH_DATABASE_MUTATED=true
PRE_SWITCH_MAINTENANCE_ACTIVE=true
ACTIVATION_SWITCHED=false

sudo() {
  [[ "${1:-}" == "-E" ]] && shift
  if [[ "${1:-}" == "-u" ]]; then
    shift 2
  fi
  [[ "${1:-}" == "env" ]] && shift
  [[ "${1:-}" == "APP_ENV=production" ]] && shift
  printf '%s\n' "$*" >>"$LOG_PATH"
  if [[ -n "$FAIL_COMMAND" && "$*" == *"$FAIL_COMMAND"* ]]; then
    return 1
  fi
}

__FUNCTIONS__

case "$MODE" in
  before-mutation) PRE_SWITCH_DATABASE_MUTATED=false ;;
  first-release) CURRENT_TARGET_BEFORE=""; PRE_SWITCH_MAINTENANCE_ACTIVE=false ;;
  same-release) CURRENT_TARGET_BEFORE="$RELEASE_DIR"; PRE_SWITCH_MAINTENANCE_ACTIVE=false ;;
  post-switch) ACTIVATION_SWITCHED=true ;;
  pre-switch-failure) ;;
  *) echo "Unknown harness mode."; exit 2 ;;
esac

trap restore_current_authorization_after_failure EXIT
false
BASH;
        $this->assertNotFalse(file_put_contents(
            $harnessPath,
            str_replace('__FUNCTIONS__', $functions, $harness),
        ));
        $process = new Process([
            $this->bashExecutable(),
            $this->bashPath($harnessPath),
            $mode,
            $this->bashPath($logPath),
            $failCommand,
        ]);

        try {
            $process->run();
            $commands = is_file($logPath)
                ? array_values(array_filter(preg_split(
                    '/\R/',
                    trim((string) file_get_contents($logPath)),
                ) ?: []))
                : [];
        } finally {
            $this->removeTemporaryDirectory($temporaryRoot);
        }

        return compact('process', 'commands');
    }

    /**
     * @param  list<string>  $arguments
     */
    private function runActivationTargetClassifierHarness(array $arguments): Process
    {
        $temporaryRoot = sys_get_temp_dir()
            .DIRECTORY_SEPARATOR
            .'myapes-activation-classifier-'.bin2hex(random_bytes(8));
        $this->assertTrue(mkdir($temporaryRoot, 0700, true));
        $harnessPath = $temporaryRoot.DIRECTORY_SEPARATOR.'harness.sh';
        $function = $this->bashFunction(
            $this->read('scripts/deploy/activate-release.sh'),
            'classify_activation_targets',
        );
        $this->assertNotFalse(file_put_contents(
            $harnessPath,
            "#!/usr/bin/env bash\nset -euo pipefail\n\n{$function}\nclassify_activation_targets \"\$@\"\n",
        ));
        $process = new Process([
            $this->bashExecutable(),
            $this->bashPath($harnessPath),
            ...$arguments,
        ]);

        try {
            $process->run();
        } finally {
            $this->removeTemporaryDirectory($temporaryRoot);
        }

        return $process;
    }

    /** @return array{Process, string, string, string} */
    private function runMediaBoundaryHarness(
        string $scriptPath,
        string $version,
        string $layout,
    ): array {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR
            .'myapes-media-boundary-'.bin2hex(random_bytes(8));
        $releases = $root.DIRECTORY_SEPARATOR.'releases';
        $release = $releases.DIRECTORY_SEPARATOR.str_repeat('a', 40);
        $public = $release.DIRECTORY_SEPARATOR.'public';
        $sharedPublic = $root.DIRECTORY_SEPARATOR.'shared'.DIRECTORY_SEPARATOR
            .'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'public';
        $this->assertTrue(mkdir($public, 0700, true));
        $this->assertTrue(mkdir(
            $sharedPublic.DIRECTORY_SEPARATOR.'avatars',
            0700,
            true,
        ));
        $this->assertNotFalse(file_put_contents(
            $release.DIRECTORY_SEPARATOR.'VERSION',
            "{$version}\n",
        ));
        if (! str_starts_with($layout, 'legacy')) {
            $storage = $public.DIRECTORY_SEPARATOR.'storage';
            $this->assertTrue(mkdir($storage, 0700, true));
            $this->assertNotFalse(file_put_contents(
                $storage.DIRECTORY_SEPARATOR.'.myapes-selective-media',
                "myapes-selective-media:v1\n",
            ));
            if ($layout === 'unexpected') {
                $this->assertTrue(mkdir(
                    $storage.DIRECTORY_SEPARATOR.'pet-profiles',
                    0700,
                ));
            }
        }

        $function = $this->bashFunction(
            $this->read($scriptPath),
            'install_public_storage_boundary',
        );
        $harness = $root.DIRECTORY_SEPARATOR.'harness.sh';
        $this->assertNotFalse(file_put_contents(
            $harness,
            "#!/usr/bin/env bash\nset -euo pipefail\n"
                .'RELEASES_DIR="$1"'."\n"
                .'SHARED_DIR="$2"'."\n"
                .'SELECTIVE_MEDIA_MARKER_NAME=".myapes-selective-media"'."\n"
                .'SELECTIVE_MEDIA_MARKER_CONTENT="myapes-selective-media:v1"'."\n"
                .'sudo() { if [[ "${4:-}" == -w && -L "${5:-}" ]]; then return 0; fi; return 1; }'."\n\n"
                .$function."\n"
                .'if [[ "$4" == legacy ]]; then'."\n"
                .'  ln -s "$2/storage/app/public" "$3/public/storage"'."\n"
                .'fi'."\n"
                .'install_public_storage_boundary "$3"'."\n"
                .'if [[ "$4" == selective ]]; then'."\n"
                .'  test -L "$3/public/storage/avatars"'."\n"
                .'  test "$(readlink -f "$3/public/storage/avatars")" = "$(readlink -f "$2/storage/app/public/avatars")"'."\n"
                .'elif [[ "$4" == legacy* ]]; then'."\n"
                .'  test -L "$3/public/storage"'."\n"
                .'  test "$(readlink -f "$3/public/storage")" = "$(readlink -f "$2/storage/app/public")"'."\n"
                .'fi'."\n"
                .'printf "%s-%s-ok\\n" "$5" "$4"'."\n",
        ));
        $process = new Process([
            $this->bashExecutable(),
            $this->bashPath($harness),
            $this->bashPath($releases),
            $this->bashPath($root.DIRECTORY_SEPARATOR.'shared'),
            $this->bashPath($release),
            $layout,
            $version,
        ], env: ['MSYS' => 'winsymlinks:sys']);

        return [$process, $root, $release, $sharedPublic];
    }

    private function assertBashSymlinkTarget(string $link, string $target): void
    {
        $process = new Process([
            $this->bashExecutable(),
            '-c',
            'test -L "$1" && test "$(readlink -f "$1")" = "$(readlink -f "$2")"',
            'myapes-media-link-check',
            $this->bashPath($link),
            $this->bashPath($target),
        ], env: ['MSYS' => 'winsymlinks:sys']);
        $process->mustRun();
        $this->addToAssertionCount(1);
    }

    /** @return array{Process, string, string, string, list<string>, ?string} */
    private function runDeploymentAncestorGuardHarness(
        string $scriptPath,
        string $ancestor,
    ): array {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR
            .'myapes-deployment-ancestor-'.bin2hex(random_bytes(8));
        $data = $root.DIRECTORY_SEPARATOR.'data';
        $releaseSha = str_repeat('a', 40);
        $currentSha = str_repeat('b', 40);
        $releases = $data.DIRECTORY_SEPARATOR.'releases';
        $release = $releases.DIRECTORY_SEPARATOR.$releaseSha;
        $currentRelease = $releases.DIRECTORY_SEPARATOR.$currentSha;
        $shared = $data.DIRECTORY_SEPARATOR.'shared';
        $sharedStorage = $shared.DIRECTORY_SEPARATOR.'storage';
        $sharedStorageApp = $sharedStorage.DIRECTORY_SEPARATOR.'app';
        $sharedStoragePublic = $sharedStorageApp.DIRECTORY_SEPARATOR.'public';
        $sharedStorageAvatars = $sharedStoragePublic.DIRECTORY_SEPARATOR.'avatars';
        $sharedStorageFramework = $sharedStorage.DIRECTORY_SEPARATOR.'framework';
        $sharedStorageFrameworkCache = $sharedStorageFramework.DIRECTORY_SEPARATOR.'cache';
        $sharedStorageFrameworkCacheData = $sharedStorageFrameworkCache.DIRECTORY_SEPARATOR.'data';
        $sharedStorageFrameworkSessions = $sharedStorageFramework.DIRECTORY_SEPARATOR.'sessions';
        $sharedStorageFrameworkViews = $sharedStorageFramework.DIRECTORY_SEPARATOR.'views';
        $sharedStorageLogs = $sharedStorage.DIRECTORY_SEPARATOR.'logs';
        $this->assertTrue(mkdir(
            $release.DIRECTORY_SEPARATOR.'public',
            0700,
            true,
        ));
        $this->assertTrue(mkdir(
            $currentRelease.DIRECTORY_SEPARATOR.'public',
            0700,
            true,
        ));
        $this->assertTrue(mkdir($sharedStorageAvatars, 0700, true));
        $this->assertTrue(mkdir($sharedStorageFrameworkCacheData, 0700, true));
        $this->assertTrue(mkdir($sharedStorageFrameworkSessions, 0700, true));
        $this->assertTrue(mkdir($sharedStorageFrameworkViews, 0700, true));
        $this->assertTrue(mkdir($sharedStorageLogs, 0700, true));
        $this->assertNotFalse(file_put_contents(
            $shared.DIRECTORY_SEPARATOR.'.env',
            "APP_ENV=production\nAPP_KEY=test\n",
        ));
        $this->assertTrue(mkdir($data.DIRECTORY_SEPARATOR.'apache', 0700, true));
        $this->assertNotFalse(file_put_contents(
            $data.DIRECTORY_SEPARATOR.'apache'.DIRECTORY_SEPARATOR.'app.conf',
            "SetEnv APP_ENV production\n",
        ));
        $this->assertNotFalse(file_put_contents(
            $data.DIRECTORY_SEPARATOR.'run.sh',
            "#!/usr/bin/env bash\nexport APP_ENV=production\n",
        ));

        $target = match ($ancestor) {
            'data' => $data,
            'releases' => $releases,
            'shared' => $shared,
            'shared-storage' => $sharedStorage,
            'shared-storage-app' => $sharedStorageApp,
            'shared-storage-public' => $sharedStoragePublic,
            'shared-storage-avatars' => $sharedStorageAvatars,
            'shared-storage-framework' => $sharedStorageFramework,
            'shared-storage-framework-cache' => $sharedStorageFrameworkCache,
            'shared-storage-framework-cache-data' => $sharedStorageFrameworkCacheData,
            'shared-storage-framework-sessions' => $sharedStorageFrameworkSessions,
            'shared-storage-framework-views' => $sharedStorageFrameworkViews,
            'shared-storage-logs' => $sharedStorageLogs,
            'release-root' => $release,
        };
        $realTarget = dirname($target).DIRECTORY_SEPARATOR
            .'real-'.basename($target);
        $this->assertTrue(rename($target, $realTarget));
        $this->createDirectoryLink($target, $realTarget, 'bash');
        $links = [$target];

        if ($scriptPath === 'scripts/deploy/rollback-release.sh') {
            $previousLink = $data.DIRECTORY_SEPARATOR.'previous';
            $currentLink = $data.DIRECTORY_SEPARATOR.'current';
            $this->createDirectoryLink($previousLink, $release, 'bash');
            $this->createDirectoryLink($currentLink, $currentRelease, 'bash');
            $links[] = $previousLink;
            $links[] = $currentLink;
        }

        $sentinel = $realTarget.DIRECTORY_SEPARATOR.'.no-mutation-sentinel';
        $this->assertNotFalse(file_put_contents($sentinel, "unchanged\n"));
        $script = str_replace(
            'DATA_DIR="/app/data"',
            'DATA_DIR="'.$this->bashPath($data).'"',
            $this->read($scriptPath),
            $replacementCount,
        );
        $this->assertSame(1, $replacementCount);
        $rootHelperSentinel = null;
        if ($scriptPath === 'scripts/deploy/activate-release.sh'
            && str_starts_with($ancestor, 'shared-storage-framework')
            || $scriptPath === 'scripts/deploy/activate-release.sh'
                && $ancestor === 'shared-storage-logs') {
            $archive = $data.DIRECTORY_SEPARATOR.'.deploy'.DIRECTORY_SEPARATOR
                .$releaseSha.DIRECTORY_SEPARATOR.'release.tar.gz';
            $this->assertTrue(mkdir(dirname($archive), 0700, true));
            $this->assertNotFalse(file_put_contents($archive, "test archive\n"));
            $rootHelperSentinel = $root.DIRECTORY_SEPARATOR.'.root-helper-invoked';
            $script = str_replace(
                'DATA_DIR="'.$this->bashPath($data).'"',
                'install() { printf \'%s\\n\' invoked >"'
                    .$this->bashPath($rootHelperSentinel).'"; return 97; }'."\n"
                    .'DATA_DIR="'.$this->bashPath($data).'"',
                $script,
                $installSpyCount,
            );
            $this->assertSame(1, $installSpyCount);
            $script = str_replace(
                'install_authenticated_controls "$DEPLOY_DIR" "$CONTROL_RELEASE_DIR"',
                ': # authenticated-control installation stubbed by the ancestor guard harness',
                $script,
                $controlStubCount,
            );
            $this->assertSame(1, $controlStubCount);
        }
        $harness = $root.DIRECTORY_SEPARATOR.basename($scriptPath);
        $this->assertNotFalse(file_put_contents($harness, $script));

        $arguments = $scriptPath === 'scripts/deploy/activate-release.sh'
            ? [$releaseSha, '', str_repeat('c', 64)]
            : [
                $releaseSha,
                $currentSha,
                str_repeat('c', 64),
                '/run/myapes-deployment-controls/'.$currentSha,
            ];

        return [
            new Process([
                $this->bashExecutable(),
                $this->bashPath($harness),
                ...array_map($this->bashPath(...), $arguments),
            ], env: ['MSYS' => 'winsymlinks:sys']),
            $root,
            $sentinel,
            $realTarget,
            $links,
            $rootHelperSentinel,
        ];
    }

    /** @return array{Process, string, string, string, string, string} */
    private function runActivationEnvironmentGuardHarness(string $layout): array
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR
            .'myapes-deployment-ancestor-'.bin2hex(random_bytes(8));
        $data = $root.DIRECTORY_SEPARATOR.'data';
        $releaseSha = str_repeat('a', 40);
        $release = $data.DIRECTORY_SEPARATOR.'releases'.DIRECTORY_SEPARATOR.$releaseSha;
        $shared = $data.DIRECTORY_SEPARATOR.'shared';
        $sharedStorage = $shared.DIRECTORY_SEPARATOR.'storage';
        $this->assertTrue(mkdir($release.DIRECTORY_SEPARATOR.'public', 0700, true));
        $this->assertTrue(mkdir(
            $sharedStorage.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR
                .'public'.DIRECTORY_SEPARATOR.'avatars',
            0700,
            true,
        ));
        $this->assertTrue(mkdir(
            $sharedStorage.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR
                .'cache'.DIRECTORY_SEPARATOR.'data',
            0700,
            true,
        ));
        $this->assertTrue(mkdir(
            $sharedStorage.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'sessions',
            0700,
            true,
        ));
        $this->assertTrue(mkdir(
            $sharedStorage.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'views',
            0700,
            true,
        ));
        $this->assertTrue(mkdir($sharedStorage.DIRECTORY_SEPARATOR.'logs', 0700, true));
        $this->assertTrue(mkdir($data.DIRECTORY_SEPARATOR.'apache', 0700, true));
        $this->assertNotFalse(file_put_contents(
            $data.DIRECTORY_SEPARATOR.'apache'.DIRECTORY_SEPARATOR.'app.conf',
            "SetEnv APP_ENV production\n",
        ));
        $this->assertNotFalse(file_put_contents(
            $data.DIRECTORY_SEPARATOR.'run.sh',
            "#!/usr/bin/env bash\nexport APP_ENV=production\n",
        ));

        $externalRoot = $root.DIRECTORY_SEPARATOR.'external-environment';
        $this->assertTrue(mkdir($externalRoot, 0700, true));
        $this->assertNotFalse(file_put_contents(
            $externalRoot.DIRECTORY_SEPARATOR.'.no-mutation-sentinel',
            "unchanged\n",
        ));
        $environmentTarget = $externalRoot.DIRECTORY_SEPARATOR.'production.env';
        if ($layout === 'existing') {
            $this->assertNotFalse(file_put_contents(
                $environmentTarget,
                "APP_ENV=production\nAPP_KEY=external\n",
            ));
        } else {
            $this->assertSame('dangling', $layout);
        }

        $environmentLink = $shared.DIRECTORY_SEPARATOR.'.env';
        $this->createDirectoryLink($environmentLink, $environmentTarget, 'bash');
        $archive = $data.DIRECTORY_SEPARATOR.'.deploy'.DIRECTORY_SEPARATOR
            .$releaseSha.DIRECTORY_SEPARATOR.'release.tar.gz';
        $this->assertTrue(mkdir(dirname($archive), 0700, true));
        $this->assertNotFalse(file_put_contents($archive, "test archive\n"));
        $rootHelperSentinel = $root.DIRECTORY_SEPARATOR.'.root-helper-invoked';
        $script = str_replace(
            'DATA_DIR="/app/data"',
            'install() { printf \'%s\\n\' invoked >"'
                .$this->bashPath($rootHelperSentinel).'"; return 97; }'."\n"
                .'DATA_DIR="'.$this->bashPath($data).'"',
            $this->read('scripts/deploy/activate-release.sh'),
            $replacementCount,
        );
        $this->assertSame(1, $replacementCount);
        $script = str_replace(
            'install_authenticated_controls "$DEPLOY_DIR" "$CONTROL_RELEASE_DIR"',
            ': # authenticated-control installation stubbed by the environment guard harness',
            $script,
            $controlStubCount,
        );
        $this->assertSame(1, $controlStubCount);
        $harness = $root.DIRECTORY_SEPARATOR.'activate-release.sh';
        $this->assertNotFalse(file_put_contents($harness, $script));

        return [
            new Process([
                $this->bashExecutable(),
                $this->bashPath($harness),
                $releaseSha,
                '',
                str_repeat('c', 64),
            ], env: ['MSYS' => 'winsymlinks:sys']),
            $root,
            $externalRoot,
            $environmentLink,
            $environmentTarget,
            $rootHelperSentinel,
        ];
    }

    /** @return array{Process, string, string, string, string, string} */
    private function runRollbackEnvironmentGuardHarness(string $layout): array
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR
            .'myapes-deployment-ancestor-'.bin2hex(random_bytes(8));
        $data = $root.DIRECTORY_SEPARATOR.'data';
        $releaseSha = str_repeat('a', 40);
        $currentSha = str_repeat('b', 40);
        $releases = $data.DIRECTORY_SEPARATOR.'releases';
        $release = $releases.DIRECTORY_SEPARATOR.$releaseSha;
        $currentRelease = $releases.DIRECTORY_SEPARATOR.$currentSha;
        $shared = $data.DIRECTORY_SEPARATOR.'shared';
        $sharedStorage = $shared.DIRECTORY_SEPARATOR.'storage';
        foreach ([$release, $currentRelease] as $releaseRoot) {
            $this->assertTrue(mkdir(
                $releaseRoot.DIRECTORY_SEPARATOR.'public',
                0700,
                true,
            ));
            $this->assertTrue(mkdir(
                $releaseRoot.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'cache',
                0700,
                true,
            ));
        }
        $this->assertTrue(mkdir(
            $sharedStorage.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR
                .'public'.DIRECTORY_SEPARATOR.'avatars',
            0700,
            true,
        ));
        $this->assertTrue(mkdir($data.DIRECTORY_SEPARATOR.'apache', 0700, true));
        $this->assertNotFalse(file_put_contents(
            $data.DIRECTORY_SEPARATOR.'apache'.DIRECTORY_SEPARATOR.'app.conf',
            "SetEnv APP_ENV production\n",
        ));
        $this->assertNotFalse(file_put_contents(
            $data.DIRECTORY_SEPARATOR.'run.sh',
            "#!/usr/bin/env bash\nexport APP_ENV=production\n",
        ));
        $this->createDirectoryLink(
            $data.DIRECTORY_SEPARATOR.'previous',
            $release,
            'bash',
        );
        $this->createDirectoryLink(
            $data.DIRECTORY_SEPARATOR.'current',
            $currentRelease,
            'bash',
        );

        $externalRoot = $root.DIRECTORY_SEPARATOR.'external-environment';
        $this->assertTrue(mkdir($externalRoot, 0700, true));
        $this->assertNotFalse(file_put_contents(
            $externalRoot.DIRECTORY_SEPARATOR.'.no-mutation-sentinel',
            "unchanged\n",
        ));
        $environmentTarget = $externalRoot.DIRECTORY_SEPARATOR.'production.env';
        if ($layout === 'existing') {
            $this->assertNotFalse(file_put_contents(
                $environmentTarget,
                "APP_ENV=production\nAPP_KEY=external\n",
            ));
        } else {
            $this->assertSame('dangling', $layout);
        }
        $environmentLink = $shared.DIRECTORY_SEPARATOR.'.env';
        $this->createDirectoryLink($environmentLink, $environmentTarget, 'bash');

        $controlReleases = $root.DIRECTORY_SEPARATOR.'control-releases';
        $controlRoot = $controlReleases.DIRECTORY_SEPARATOR.$currentSha;
        $harness = $controlRoot.DIRECTORY_SEPARATOR.'scripts'.DIRECTORY_SEPARATOR
            .'deploy'.DIRECTORY_SEPARATOR.'rollback-release.sh';
        $this->assertTrue(mkdir(dirname($harness), 0700, true));
        $rootHelperSentinel = $root.DIRECTORY_SEPARATOR.'.root-helper-invoked';
        $script = str_replace(
            'DATA_DIR="/app/data"',
            'root_helper_spy() { printf \'%s\\n\' invoked >"'
                .$this->bashPath($rootHelperSentinel).'"; return 97; }'."\n"
                .'install() { root_helper_spy; }'."\n"
                .'chown() { root_helper_spy; }'."\n"
                .'chmod() { root_helper_spy; }'."\n"
                .'cp() { root_helper_spy; }'."\n"
                .'mv() { root_helper_spy; }'."\n"
                .'rm() { root_helper_spy; }'."\n"
                .'ln() { root_helper_spy; }'."\n"
                .'DATA_DIR="'.$this->bashPath($data).'"',
            $this->read('scripts/deploy/rollback-release.sh'),
            $dataReplacementCount,
        );
        $this->assertSame(1, $dataReplacementCount);
        $script = str_replace(
            'CONTROL_RELEASES_DIR="/run/myapes-deployment-controls"',
            'CONTROL_RELEASES_DIR="'.$this->bashPath($controlReleases).'"',
            $script,
            $controlReplacementCount,
        );
        $this->assertSame(1, $controlReplacementCount);
        $this->assertNotFalse(file_put_contents($harness, $script));

        return [
            new Process([
                $this->bashExecutable(),
                $this->bashPath($harness),
                $releaseSha,
                $currentSha,
                str_repeat('c', 64),
                $this->bashPath($controlRoot),
            ], env: ['MSYS' => 'winsymlinks:sys']),
            $root,
            $externalRoot,
            $environmentLink,
            $environmentTarget,
            $rootHelperSentinel,
        ];
    }

    /** @return array{Process, string, string, string} */
    private function runLocalMediaTargetGuardHarness(
        string $validator,
        string $target,
    ): array {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR
            .'myapes-local-media-'.bin2hex(random_bytes(8));
        $publicStorage = $root.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'storage';
        $this->assertTrue(mkdir($publicStorage, 0700, true));
        $this->assertNotFalse(file_put_contents(
            $publicStorage.DIRECTORY_SEPARATOR.'.myapes-selective-media',
            "myapes-selective-media:v1\n",
        ));

        $storage = $root.DIRECTORY_SEPARATOR.'storage';
        $storageApp = $storage.DIRECTORY_SEPARATOR.'app';
        $storagePublic = $storageApp.DIRECTORY_SEPARATOR.'public';
        $storageAvatars = $storagePublic.DIRECTORY_SEPARATOR.'avatars';
        $link = match ($target) {
            'storage' => $storage,
            'storage-app' => $storageApp,
            'storage-public' => $storagePublic,
            'storage-avatars' => $storageAvatars,
        };
        if (! is_dir(dirname($link))) {
            $this->assertTrue(mkdir(dirname($link), 0700, true));
        }
        $externalTarget = $root.DIRECTORY_SEPARATOR.'external-'.$target;
        $this->assertTrue(mkdir($externalTarget, 0700, true));
        $this->assertNotFalse(file_put_contents(
            $externalTarget.DIRECTORY_SEPARATOR.'.no-mutation-sentinel',
            "unchanged\n",
        ));
        $this->createDirectoryLink($link, $externalTarget, $validator);

        $process = $validator === 'powershell'
            ? $this->runPowerShellMediaValidator($root)
            : new Process([
                $this->bashExecutable(),
                $this->bashPath($this->path('scripts/local/selective-media-boundary.sh')),
                $this->bashPath($root),
            ], env: ['MSYS' => 'winsymlinks:sys']);

        return [$process, $root, $externalTarget, $link];
    }

    /** @return array{Process, string, string, string} */
    private function runLauncherLogGuardHarness(string $linkedPath): array
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR
            .'myapes-launcher-log-'.bin2hex(random_bytes(8));
        $storage = $root.DIRECTORY_SEPARATOR.'storage';
        $logs = $storage.DIRECTORY_SEPARATOR.'logs';
        $externalRoot = $root.DIRECTORY_SEPARATOR.'external-logs';
        $this->assertTrue(mkdir($storage, 0700, true));
        $this->assertTrue(mkdir($externalRoot, 0700, true));
        $this->assertNotFalse(file_put_contents(
            $externalRoot.DIRECTORY_SEPARATOR.'.no-mutation-sentinel',
            "unchanged\n",
        ));

        if ($linkedPath === 'logs') {
            $link = $logs;
            $this->createDirectoryLink($link, $externalRoot, 'bash');
        } else {
            $this->assertTrue(mkdir($logs, 0700, true));
            $externalLog = $externalRoot.DIRECTORY_SEPARATOR.$linkedPath;
            $this->assertNotFalse(file_put_contents($externalLog, "external\n"));
            $link = $logs.DIRECTORY_SEPARATOR.$linkedPath;
            $this->createDirectoryLink($link, $externalLog, 'bash');
        }

        $launcher = $this->read('scripts/deploy/cloudron-run.sh');
        $function = $this->bashFunctionOrFailureStub(
            $launcher,
            'assert_laravel_log_file',
        ).$this->bashFunctionOrFailureStub(
            $launcher,
            'prepare_laravel_logs',
        );
        $harness = $root.DIRECTORY_SEPARATOR.'launcher-log-guard.sh';
        $this->assertNotFalse(file_put_contents(
            $harness,
            "#!/usr/bin/env bash\nset -euo pipefail\n"
                .$function."\nprepare_laravel_logs \"\$1\"\n",
        ));

        return [
            new Process([
                $this->bashExecutable(),
                $this->bashPath($harness),
                $this->bashPath($logs),
            ], env: ['MSYS' => 'winsymlinks:sys']),
            $root,
            $externalRoot,
            $link,
        ];
    }

    /** @return array{Process, string, string, string} */
    private function runLauncherRuntimeControlGuardHarness(string $linkedPath): array
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR
            .'myapes-launcher-control-'.bin2hex(random_bytes(8));
        $data = $root.DIRECTORY_SEPARATOR.'data';
        $apache = $data.DIRECTORY_SEPARATOR.'apache';
        $this->assertTrue(mkdir($apache, 0700, true));
        $this->assertNotFalse(file_put_contents(
            $apache.DIRECTORY_SEPARATOR.'app.conf',
            "SetEnv APP_ENV production\n",
        ));
        $this->assertNotFalse(file_put_contents(
            $data.DIRECTORY_SEPARATOR.'run.sh',
            "#!/usr/bin/env bash\nexport APP_ENV=production\n",
        ));
        $externalRoot = $root.DIRECTORY_SEPARATOR.'external-runtime-control';
        $this->assertTrue(mkdir($externalRoot, 0700, true));
        $this->assertNotFalse(file_put_contents(
            $externalRoot.DIRECTORY_SEPARATOR.'.no-mutation-sentinel',
            "unchanged\n",
        ));

        $link = match ($linkedPath) {
            'apache' => $apache,
            'apache-app-conf' => $apache.DIRECTORY_SEPARATOR.'app.conf',
            'run-sh' => $data.DIRECTORY_SEPARATOR.'run.sh',
        };
        if ($linkedPath === 'apache') {
            $linkTarget = $externalRoot.DIRECTORY_SEPARATOR.'apache';
            $this->assertTrue(rename($apache, $linkTarget));
        } else {
            $linkTarget = $externalRoot.DIRECTORY_SEPARATOR.basename($link);
            $this->assertTrue(rename($link, $linkTarget));
        }
        $this->createDirectoryLink($link, $linkTarget, 'bash');

        $launcher = $this->read('scripts/deploy/cloudron-run.sh');
        $functions = '';
        foreach ([
            'assert_ordinary_canonical_runtime_directory',
            'assert_ordinary_canonical_runtime_file',
            'assert_runtime_control_paths',
        ] as $functionName) {
            $functions .= $this->bashFunctionOrFailureStub($launcher, $functionName);
        }
        $harness = $root.DIRECTORY_SEPARATOR.'launcher-control-guard.sh';
        $this->assertNotFalse(file_put_contents(
            $harness,
            "#!/usr/bin/env bash\nset -euo pipefail\n"
                .$functions."\nassert_runtime_control_paths \"\$1\"\n",
        ));

        return [
            new Process([
                $this->bashExecutable(),
                $this->bashPath($harness),
                $this->bashPath($data),
            ], env: ['MSYS' => 'winsymlinks:sys']),
            $root,
            $externalRoot,
            $link,
        ];
    }

    /** @return array{Process, string, string, string} */
    private function runRuntimeControlPathGuardHarness(
        string $scriptPath,
        string $linkedPath,
    ): array {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR
            .'myapes-deployment-ancestor-'.bin2hex(random_bytes(8));
        $data = $root.DIRECTORY_SEPARATOR.'data';
        $releaseSha = str_repeat('a', 40);
        $currentSha = str_repeat('b', 40);
        $release = $data.DIRECTORY_SEPARATOR.'releases'.DIRECTORY_SEPARATOR.$releaseSha;
        $sharedStorage = $data.DIRECTORY_SEPARATOR.'shared'.DIRECTORY_SEPARATOR.'storage';
        $apache = $data.DIRECTORY_SEPARATOR.'apache';
        $this->assertTrue(mkdir(
            $release.DIRECTORY_SEPARATOR.'public',
            0700,
            true,
        ));
        $this->assertTrue(mkdir(
            $sharedStorage.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR
                .'public'.DIRECTORY_SEPARATOR.'avatars',
            0700,
            true,
        ));
        $this->assertTrue(mkdir(
            $sharedStorage.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR
                .'cache'.DIRECTORY_SEPARATOR.'data',
            0700,
            true,
        ));
        $this->assertTrue(mkdir(
            $sharedStorage.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'sessions',
            0700,
            true,
        ));
        $this->assertTrue(mkdir(
            $sharedStorage.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'views',
            0700,
            true,
        ));
        $this->assertTrue(mkdir($sharedStorage.DIRECTORY_SEPARATOR.'logs', 0700, true));
        $this->assertTrue(mkdir($apache, 0700, true));
        $this->assertNotFalse(file_put_contents(
            $apache.DIRECTORY_SEPARATOR.'app.conf',
            "SetEnv APP_ENV production\n",
        ));
        $this->assertNotFalse(file_put_contents(
            $data.DIRECTORY_SEPARATOR.'run.sh',
            "#!/usr/bin/env bash\nexport APP_ENV=production\n",
        ));

        $externalRoot = $root.DIRECTORY_SEPARATOR.'external-runtime-control';
        $this->assertTrue(mkdir($externalRoot, 0700, true));
        $this->assertNotFalse(file_put_contents(
            $externalRoot.DIRECTORY_SEPARATOR.'.no-mutation-sentinel',
            "unchanged\n",
        ));
        $target = match ($linkedPath) {
            'apache' => $apache,
            'apache-app-conf' => $apache.DIRECTORY_SEPARATOR.'app.conf',
            'run-sh' => $data.DIRECTORY_SEPARATOR.'run.sh',
            'apache-app-conf-stage' => $apache.DIRECTORY_SEPARATOR.'app.conf.rollback',
            'run-sh-stage' => $data.DIRECTORY_SEPARATOR.'run.sh.rollback',
        };
        if ($linkedPath === 'apache') {
            $this->assertTrue(rename($apache, $externalRoot.DIRECTORY_SEPARATOR.'apache'));
            $linkTarget = $externalRoot.DIRECTORY_SEPARATOR.'apache';
        } else {
            if (file_exists($target)) {
                $this->assertTrue(rename(
                    $target,
                    $externalRoot.DIRECTORY_SEPARATOR.basename($target),
                ));
            } else {
                $this->assertNotFalse(file_put_contents(
                    $externalRoot.DIRECTORY_SEPARATOR.basename($target),
                    "staged external\n",
                ));
            }
            $linkTarget = $externalRoot.DIRECTORY_SEPARATOR.basename($target);
        }
        $this->createDirectoryLink($target, $linkTarget, 'bash');

        $script = str_replace(
            'DATA_DIR="/app/data"',
            'DATA_DIR="'.$this->bashPath($data).'"',
            $this->read($scriptPath),
            $replacementCount,
        );
        $this->assertSame(1, $replacementCount);
        $harness = $root.DIRECTORY_SEPARATOR.basename($scriptPath);
        $this->assertNotFalse(file_put_contents($harness, $script));
        $arguments = $scriptPath === 'scripts/deploy/activate-release.sh'
            ? [$releaseSha, '', str_repeat('c', 64)]
            : [
                $releaseSha,
                $currentSha,
                str_repeat('c', 64),
                '/run/myapes-deployment-controls/'.$currentSha,
            ];

        return [
            new Process([
                $this->bashExecutable(),
                $this->bashPath($harness),
                ...$arguments,
            ], env: ['MSYS' => 'winsymlinks:sys']),
            $root,
            $externalRoot,
            $target,
        ];
    }

    /** @return array{Process, string, string, string} */
    private function runReleaseRuntimePathGuardHarness(
        string $scriptPath,
        string $linkedPath,
    ): array {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR
            .'myapes-release-runtime-'.bin2hex(random_bytes(8));
        $release = $root.DIRECTORY_SEPARATOR.'release';
        $bootstrap = $release.DIRECTORY_SEPARATOR.'bootstrap';
        $cache = $bootstrap.DIRECTORY_SEPARATOR.'cache';
        $externalRoot = $root.DIRECTORY_SEPARATOR.'external-bootstrap';
        $this->assertTrue(mkdir($release.DIRECTORY_SEPARATOR.'public', 0700, true));
        $this->assertTrue(mkdir($externalRoot, 0700, true));
        $this->assertNotFalse(file_put_contents(
            $externalRoot.DIRECTORY_SEPARATOR.'.no-mutation-sentinel',
            "unchanged\n",
        ));

        if ($linkedPath === 'bootstrap') {
            $this->assertTrue(mkdir($externalRoot.DIRECTORY_SEPARATOR.'cache', 0700, true));
            $link = $bootstrap;
        } else {
            $this->assertSame('bootstrap-cache', $linkedPath);
            $this->assertTrue(mkdir($bootstrap, 0700, true));
            $link = $cache;
        }
        $this->createDirectoryLink($link, $externalRoot, 'bash');

        $function = $this->bashFunctionOrFailureStub(
            $this->read($scriptPath),
            'assert_release_runtime_path_boundaries',
        );
        $harness = $root.DIRECTORY_SEPARATOR.'release-runtime-guard.sh';
        $this->assertNotFalse(file_put_contents(
            $harness,
            "#!/usr/bin/env bash\nset -euo pipefail\n"
                .$function."\nassert_release_runtime_path_boundaries \"\$1\" true\n",
        ));

        return [
            new Process([
                $this->bashExecutable(),
                $this->bashPath($harness),
                $this->bashPath($release),
            ], env: ['MSYS' => 'winsymlinks:sys']),
            $root,
            $externalRoot,
            $link,
        ];
    }

    /** @return array{Process, string} */
    private function runLocalMediaValidator(string $validator, string $marker): array
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR
            .'myapes-local-media-'.bin2hex(random_bytes(8));
        $publicStorage = $root.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'storage';
        $this->assertTrue(mkdir($publicStorage, 0700, true));
        $this->assertNotFalse(file_put_contents(
            $publicStorage.DIRECTORY_SEPARATOR.'.myapes-selective-media',
            $marker,
        ));

        $process = $validator === 'powershell'
            ? new Process([
                $this->powershellExecutable(),
                '-NoProfile',
                '-ExecutionPolicy',
                'Bypass',
                '-File',
                $this->path('scripts/local/selective-media-boundary.ps1'),
                '-RootDir',
                $root,
            ])
            : new Process([
                $this->bashExecutable(),
                $this->bashPath($this->path('scripts/local/selective-media-boundary.sh')),
                $this->bashPath($root),
            ]);

        return [$process, $root];
    }

    /** @return array{Process, string} */
    private function runArchiveMediaHarness(): array
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR
            .'myapes-archive-media-'.bin2hex(random_bytes(8));
        $this->assertTrue(mkdir($root, 0700, true));
        $harness = $root.DIRECTORY_SEPARATOR.'harness.sh';
        $this->assertNotFalse(file_put_contents(
            $harness,
            <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
validator="$1"
root="$2"

make_marker_tree() {
  local tree="$1"
  mkdir -p "$tree/public/storage" "$tree/bootstrap/cache"
  printf '%s\n' 'myapes-selective-media:v1' \
    > "$tree/public/storage/.myapes-selective-media"
}

valid="$root/valid"
make_marker_tree "$valid"
"$validator" source "$valid"
(cd "$root" && "$validator" source valid)
tar -czf "$root/valid.tar.gz" -C "$valid" .
"$validator" archive "$root/valid.tar.gz"

for linked_path in bootstrap bootstrap-cache; do
  tree="$root/linked-$linked_path"
  external="$root/external-$linked_path"
  make_marker_tree "$tree"
  mkdir "$external"
  printf 'unchanged\n' > "$external/sentinel"
  if [[ "$linked_path" == bootstrap ]]; then
    rm -rf "$tree/bootstrap"
    ln -s "$external" "$tree/bootstrap"
  else
    rm -rf "$tree/bootstrap/cache"
    ln -s "$external" "$tree/bootstrap/cache"
  fi
  if "$validator" source "$tree"; then
    echo "source validator accepted linked $linked_path" >&2
    exit 1
  fi
  tar -czf "$root/linked-$linked_path.tar.gz" -C "$tree" .
  if "$validator" archive "$root/linked-$linked_path.tar.gz"; then
    echo "archive validator accepted linked $linked_path" >&2
    exit 1
  fi
  test "$(cat "$external/sentinel")" = unchanged
done

for collision_order in file-directory directory-file; do
  tree="$root/collision-$collision_order"
  make_marker_tree "$tree"
  printf 'collision\n' > "$tree/collision-file"
  mkdir "$tree/collision-directory"
  archive="$root/collision-$collision_order.tar"
  tar -cf "$archive" --no-recursion -C "$tree" \
    ./public/storage/ ./bootstrap/ ./bootstrap/cache/
  tar -rf "$archive" -C "$tree" ./public/storage/.myapes-selective-media
  if [[ "$collision_order" == file-directory ]]; then
    tar -rf "$archive" \
      --transform='s#^collision-file$#./collision#' \
      -C "$tree" collision-file
    tar -rf "$archive" --no-recursion \
      --transform='s#^collision-directory#./collision#' \
      -C "$tree" collision-directory
  else
    tar -rf "$archive" --no-recursion \
      --transform='s#^collision-directory#./collision#' \
      -C "$tree" collision-directory
    tar -rf "$archive" \
      --transform='s#^collision-file$#./collision#' \
      -C "$tree" collision-file
  fi
  test "$(tar -tf "$archive" | grep -Ec '^\./collision/?$')" = 2
  gzip -c "$archive" > "$archive.gz"
  if "$validator" archive "$archive.gz"; then
    echo "archive validator accepted $collision_order collision" >&2
    exit 1
  fi
done

for schema in missing-parent duplicate-parent alias-parent regular-parent symlink-parent; do
  schema_root="$root/schema-$schema"
  tree="$schema_root/tree"
  make_marker_tree "$tree"
  archive="$root/schema-$schema.tar"
  case "$schema" in
    missing-parent)
      tar -cf "$archive" -C "$tree" ./public/storage/.myapes-selective-media
      ;;
    duplicate-parent)
      tar -cf "$archive" --no-recursion -C "$tree" ./public/storage/
      tar -rf "$archive" --no-recursion -C "$tree" ./public/storage/
      tar -rf "$archive" -C "$tree" ./public/storage/.myapes-selective-media
      ;;
    alias-parent)
      tar -cf "$archive" --no-recursion -C "$tree" ./public/storage/
      tar -rf "$archive" --no-recursion -C "$tree" public/storage/
      tar -rf "$archive" -C "$tree" ./public/storage/.myapes-selective-media
      ;;
    regular-parent)
      printf 'not-a-directory\n' > "$schema_root/parent-file"
      tar -cf "$archive" \
        --transform='s#^parent-file$#./public/storage#' \
        -C "$schema_root" parent-file
      tar -rf "$archive" -C "$tree" ./public/storage/.myapes-selective-media
      ;;
    symlink-parent)
      mkdir "$schema_root/elsewhere"
      ln -s "$schema_root/elsewhere" "$schema_root/parent-link"
      tar -cf "$archive" \
        --transform='s#^parent-link$#./public/storage#' \
        -C "$schema_root" parent-link
      tar -rf "$archive" -C "$tree" ./public/storage/.myapes-selective-media
      ;;
  esac
  tar -rf "$archive" --no-recursion -C "$tree" ./bootstrap/ ./bootstrap/cache/
  archive_list="$(tar -tf "$archive")"
  test "$(printf '%s\n' "$archive_list" \
    | sed -E 's#^(\./)+##' \
    | grep -cx 'public/storage/\.myapes-selective-media')" = 1
  case "$schema" in
    missing-parent)
      test "$(printf '%s\n' "$archive_list" | grep -Fxc './public/storage/' || true)" = 0
      ;;
    duplicate-parent)
      test "$(printf '%s\n' "$archive_list" | grep -Fxc './public/storage/')" = 2
      ;;
    alias-parent)
      test "$(printf '%s\n' "$archive_list" | grep -Fxc './public/storage/')" = 1
      test "$(printf '%s\n' "$archive_list" \
        | sed -E 's#^(\./)+##' \
        | grep -Fxc 'public/storage/')" = 2
      ;;
    regular-parent)
      test "$(tar -tvf "$archive" \
        | grep -E ' (\./)?public/storage$' \
        | head -n 1 \
        | cut -c 1)" = -
      ;;
    symlink-parent)
      test "$(tar -tvf "$archive" \
        | grep -E ' (\./)?public/storage -> ' \
        | head -n 1 \
        | cut -c 1)" = l
      ;;
  esac
  gzip -c "$archive" > "$archive.gz"
  if "$validator" archive "$archive.gz"; then
    echo "archive validator accepted $schema" >&2
    exit 1
  fi
  test -e "$tree/public/storage/.myapes-selective-media"
done

for alias in absolute backslash empty-component dot-component dotdot-component control-character; do
  tree="$root/alias-$alias"
  make_marker_tree "$tree"
  printf 'private\n' > "$tree/shadow"
  archive="$root/alias-$alias.tar"
  tar -cf "$archive" --no-recursion -C "$tree" ./public/storage/
  tar -rf "$archive" -C "$tree" ./public/storage/.myapes-selective-media
  case "$alias" in
    absolute)
      tar -P -rf "$archive" \
        --transform='s#^shadow$#/public/storage/private#' \
        -C "$tree" shadow
      tar -P -tf "$archive" | grep -Fqx '/public/storage/private'
      ;;
    backslash)
      tar -rf "$archive" \
        --transform='s#^shadow$#./public\\storage\\private#' \
        -C "$tree" shadow
      tar --quoting-style=escape -tf "$archive" | grep -Fqx './public\\storage\\private'
      ;;
    empty-component)
      tar -rf "$archive" \
        --transform='s#^shadow$#./public//storage/private#' \
        -C "$tree" shadow
      tar -tf "$archive" | grep -Fqx './public//storage/private'
      ;;
    dot-component)
      tar -rf "$archive" \
        --transform='s#^shadow$#./public/./storage/private#' \
        -C "$tree" shadow
      tar -tf "$archive" | grep -Fqx './public/./storage/private'
      ;;
    dotdot-component)
      tar -rf "$archive" \
        --transform='s#^shadow$#./private/../public/storage/.myapes-selective-media#' \
        -C "$tree" shadow
      tar -tf "$archive" \
        | grep -Fqx './private/../public/storage/.myapes-selective-media'
      ;;
    control-character)
      control_name=$'./private\tcopy'
      tar -rf "$archive" \
        --transform="s#^shadow\$#${control_name}#" \
        -C "$tree" shadow
      tar --quoting-style=escape -tf "$archive" | grep -Fqx './private\tcopy'
      ;;
  esac
  tar -rf "$archive" --no-recursion -C "$tree" ./bootstrap/ ./bootstrap/cache/
  gzip -c "$archive" > "$archive.gz"
  if "$validator" archive "$archive.gz"; then
    echo "archive validator accepted $alias alias" >&2
    exit 1
  fi
  test -e "$tree/public/storage/.myapes-selective-media"
done

for kind in avatars pet-profiles arbitrary-file arbitrary-dir arbitrary-symlink changed-marker symlink-marker; do
  tree="$root/$kind"
  make_marker_tree "$tree"
  case "$kind" in
    avatars|pet-profiles|arbitrary-dir)
      entry="${kind/arbitrary-dir/private-export}"
      mkdir "$tree/public/storage/$entry"
      ;;
    arbitrary-file)
      printf 'private\n' > "$tree/public/storage/debug.txt"
      ;;
    arbitrary-symlink)
      mkdir "$tree/elsewhere"
      ln -s "$tree/elsewhere" "$tree/public/storage/debug-link"
      ;;
    changed-marker)
      printf '%s\r\n' 'myapes-selective-media:v1' \
        > "$tree/public/storage/.myapes-selective-media"
      ;;
    symlink-marker)
      rm "$tree/public/storage/.myapes-selective-media"
      printf '%s\n' 'myapes-selective-media:v1' > "$tree/marker-target"
      ln -s "$tree/marker-target" "$tree/public/storage/.myapes-selective-media"
      ;;
  esac

  if "$validator" source "$tree"; then
    echo "source validator accepted $kind" >&2
    exit 1
  fi
  test -e "$tree/public/storage/.myapes-selective-media" \
    || test -L "$tree/public/storage/.myapes-selective-media"
  tar -czf "$root/$kind.tar.gz" -C "$tree" .
  if "$validator" archive "$root/$kind.tar.gz"; then
    echo "archive validator accepted $kind" >&2
    exit 1
  fi
done

printf 'archive-media-ok\n'
BASH
        ));

        return [
            new Process([
                $this->bashExecutable(),
                $this->bashPath($harness),
                $this->bashPath($this->path('scripts/deploy/validate-selective-media-archive.sh')),
                $this->bashPath($root),
            ], env: ['MSYS' => 'winsymlinks:sys']),
            $root,
        ];
    }

    /** @return array{string, string, string} */
    private function createLocalMediaFixture(): array
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR
            .'myapes-local-media-'.bin2hex(random_bytes(8));
        $publicStorage = $root.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'storage';
        $avatarTarget = $root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR
            .'app'.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'avatars';
        $this->assertTrue(mkdir($publicStorage, 0700, true));
        $this->assertTrue(mkdir($avatarTarget, 0700, true));
        $this->assertNotFalse(file_put_contents(
            $publicStorage.DIRECTORY_SEPARATOR.'.myapes-selective-media',
            "myapes-selective-media:v1\n",
        ));

        return [$root, $publicStorage, $avatarTarget];
    }

    private function runPowerShellMediaValidator(string $root): Process
    {
        return new Process([
            $this->powershellExecutable(),
            '-NoProfile',
            '-ExecutionPolicy',
            'Bypass',
            '-File',
            $this->path('scripts/local/selective-media-boundary.ps1'),
            '-RootDir',
            $root,
        ]);
    }

    private function createWindowsJunction(string $link, string $target): void
    {
        $process = new Process([
            $this->powershellExecutable(),
            '-NoProfile',
            '-Command',
            'New-Item -ItemType Junction -Path $env:MYAPES_TEST_LINK -Target $env:MYAPES_TEST_TARGET -ErrorAction Stop | Out-Null',
        ], env: [
            'MYAPES_TEST_LINK' => $link,
            'MYAPES_TEST_TARGET' => $target,
        ]);
        $process->mustRun();
    }

    private function createDirectoryLink(
        string $link,
        string $target,
        string $validator,
    ): void {
        if ($validator === 'powershell' && PHP_OS_FAMILY === 'Windows') {
            $this->createWindowsJunction($link, $target);

            return;
        }

        $process = new Process([
            $this->bashExecutable(),
            '-c',
            'ln -s "$1" "$2"',
            'myapes-local-media-ancestor-link',
            $this->bashPath($target),
            $this->bashPath($link),
        ], env: ['MSYS' => 'winsymlinks:sys']);
        $process->mustRun();
    }

    private function removeDirectoryLink(string $link, string $validator): void
    {
        if ($validator === 'powershell' && PHP_OS_FAMILY === 'Windows') {
            $this->removeWindowsJunction($link);

            return;
        }

        if (file_exists($link) || is_link($link)) {
            $process = new Process([
                $this->bashExecutable(),
                '-c',
                'test -L "$1" && rm -f -- "$1"',
                'myapes-local-media-ancestor-unlink',
                $this->bashPath($link),
            ], env: ['MSYS' => 'winsymlinks:sys']);
            $process->mustRun();
        }
    }

    private function powershellExecutable(): string
    {
        return PHP_OS_FAMILY === 'Windows' ? 'powershell.exe' : 'pwsh';
    }

    private function removeWindowsJunction(string $link): void
    {
        if (file_exists($link) || is_link($link)) {
            $this->assertTrue(rmdir($link));
        }
    }

    private function bashFunction(string $script, string $name): string
    {
        $matched = preg_match(
            '/^'.preg_quote($name, '/').'\(\) \{\R.*?^\}\R/ms',
            $script,
            $matches,
        );
        $this->assertSame(1, $matched, "Expected Bash function [{$name}].");

        return $matches[0];
    }

    private function bashFunctionOrFailureStub(string $script, string $name): string
    {
        $matched = preg_match(
            '/^'.preg_quote($name, '/').'\(\) \{\R.*?^\}\R/ms',
            $script,
            $matches,
        );
        if ($matched === 1) {
            return $matches[0];
        }

        return $name.'() { echo "missing '.$name.'" >&2; return 1; }'."\n";
    }

    private function assertControlVerifierFails(
        string $root,
        string $script,
        string $manifestHash,
    ): void {
        $process = $this->runControlVerifier($root, $script, $manifestHash);
        $process->run();

        $this->assertFalse(
            $process->isSuccessful(),
            "Deployment control verifier unexpectedly succeeded:\n"
                .$process->getOutput().$process->getErrorOutput(),
        );
    }

    private function bashPath(string $path): string
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return $path;
        }

        $normalized = str_replace('\\', '/', $path);
        if (preg_match('/^(?<drive>[A-Za-z]):(?<rest>\/.*)$/', $normalized, $matches) === 1) {
            return '/'.strtolower($matches['drive']).$matches['rest'];
        }

        return $normalized;
    }

    private function bashExecutable(): string
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return 'bash';
        }

        $gitBash = 'C:\\Program Files\\Git\\bin\\bash.exe';
        $this->assertFileExists(
            $gitBash,
            'Git Bash is required for deployment-control verification tests.',
        );

        return $gitBash;
    }

    /** @return list<string> */
    private function directoryTreeSnapshot(string $path): array
    {
        $snapshot = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $path,
                \FilesystemIterator::SKIP_DOTS,
            ),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $item) {
            $relativePath = substr($item->getPathname(), strlen($path) + 1);
            if ($item->isLink()) {
                $snapshot[] = 'link:'.$relativePath.'->'.readlink($item->getPathname());
            } elseif ($item->isDir()) {
                $snapshot[] = 'directory:'.$relativePath;
            } else {
                $snapshot[] = 'file:'.$relativePath.':'.hash_file('sha256', $item->getPathname());
            }
        }
        sort($snapshot);

        return $snapshot;
    }

    private function removeTemporaryDirectory(string $path): void
    {
        if (! is_dir($path)
            || (! str_starts_with(
                basename($path),
                'myapes-control-verifier-',
            ) && ! str_starts_with(
                basename($path),
                'myapes-activation-recovery-',
            ) && ! str_starts_with(
                basename($path),
                'myapes-media-boundary-',
            ) && ! str_starts_with(
                basename($path),
                'myapes-local-media-',
            ) && ! str_starts_with(
                basename($path),
                'myapes-archive-media-',
            ) && ! str_starts_with(
                basename($path),
                'myapes-deployment-ancestor-',
            ) && ! str_starts_with(
                basename($path),
                'myapes-launcher-log-',
            ) && ! str_starts_with(
                basename($path),
                'myapes-launcher-control-',
            ) && ! str_starts_with(
                basename($path),
                'myapes-release-runtime-',
            ))) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $path,
                \FilesystemIterator::SKIP_DOTS,
            ),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isLink()) {
                unlink($item->getPathname());
            } elseif ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($path);
    }

    private function test_workflow(): string
    {
        return $this->read('.github/workflows/test-cloudron.yml');
    }

    private function deployWorkflow(): string
    {
        return $this->read('.github/workflows/deploy-cloudron.yml');
    }

    private function combinedWorkflows(): string
    {
        return $this->test_workflow()."\n".$this->deployWorkflow();
    }

    private function read(string $relativePath): string
    {
        $content = file_get_contents($this->path($relativePath));

        $this->assertIsString($content, "Unable to read [{$relativePath}].");

        return $content;
    }

    private function path(string $relativePath): string
    {
        return dirname(__DIR__, 2).DIRECTORY_SEPARATOR.str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            $relativePath,
        );
    }
}
