<?php

namespace Tests\Feature\Attachments;

use App\Core\Attachments\Scanning\ClamdScanner;
use App\Core\Attachments\Scanning\DisabledScanner;
use App\Core\Attachments\Scanning\FakeScanner;
use App\Core\Attachments\Scanning\VirusScanner;
use App\Core\Attachments\UploadLimitsProbe;
use Tests\TestCase;

class AttachmentDiagnosticsTest extends TestCase
{
    public function test_a_disabled_scanner_is_reported_but_only_fails_when_required(): void
    {
        $this->app->instance(VirusScanner::class, new DisabledScanner);
        $this->fakeLimits(['upload_max_filesize' => '52M', 'post_max_size' => '110M', 'max_file_uploads' => '30']);

        $this->artisan('attachments:diagnose')
            ->expectsOutputToContain('OFF (files are marked not scanned)')
            ->expectsOutputToContain('Upload set-up looks fine.')
            ->assertSuccessful();

        $this->artisan('attachments:diagnose', ['--require-scanner' => true])
            ->expectsOutputToContain('1 problem(s) found.')
            ->assertFailed();
    }

    public function test_a_reachable_scanner_and_sufficient_limits_pass(): void
    {
        config(['myapes.attachments.scanner' => 'fake']);
        $this->app->instance(VirusScanner::class, new FakeScanner);
        $this->fakeLimits(['upload_max_filesize' => '52M', 'post_max_size' => '110M', 'max_file_uploads' => '30']);

        $this->artisan('attachments:diagnose', ['--require-scanner' => true])
            ->expectsTable(['Check', 'Value', 'Needed', 'Result'], [
                ['Virus scanner', 'fake', 'answers', 'OK'],
                ['upload_max_filesize', '52M (.htaccess; php.ini 2M)', '50 MB', 'OK'],
                ['post_max_size', '110M (.htaccess; php.ini 8M)', '104 MB', 'OK'],
                ['max_file_uploads', '30 (.htaccess; php.ini 20)', '23', 'OK'],
            ])
            ->assertSuccessful();
    }

    public function test_an_unreachable_scanner_fails(): void
    {
        $scanner = new FakeScanner;
        $scanner->unavailable = true;
        config(['myapes.attachments.scanner' => 'fake']);
        $this->app->instance(VirusScanner::class, $scanner);
        $this->fakeLimits(['upload_max_filesize' => '52M', 'post_max_size' => '110M', 'max_file_uploads' => '30']);

        $this->artisan('attachments:diagnose')
            ->expectsOutputToContain('PROBLEM: no answer')
            ->assertFailed();
    }

    public function test_limits_that_are_too_low_fail(): void
    {
        $this->app->instance(VirusScanner::class, new DisabledScanner);
        $this->fakeLimits([], ['upload_max_filesize' => '2M', 'post_max_size' => '8M', 'max_file_uploads' => '20']);

        $this->artisan('attachments:diagnose')
            ->expectsOutputToContain('PROBLEM: too low')
            ->expectsOutputToContain('3 problem(s) found.')
            ->assertFailed();
    }

    public function test_php_size_shorthand_is_converted_to_bytes(): void
    {
        $this->assertSame(52 * 1024 * 1024, UploadLimitsProbe::toBytes('52M'));
        $this->assertSame(2 * 1024 * 1024 * 1024, UploadLimitsProbe::toBytes('2g'));
        $this->assertSame(512 * 1024, UploadLimitsProbe::toBytes('512K'));
        $this->assertSame(1000, UploadLimitsProbe::toBytes('1000'));
        $this->assertNull(UploadLimitsProbe::toBytes(''));
    }

    public function test_the_repository_htaccess_sets_the_upload_limits(): void
    {
        $overrides = (new UploadLimitsProbe)->htaccessOverrides();

        $this->assertSame('52M', $overrides['upload_max_filesize'] ?? null);
        $this->assertSame('110M', $overrides['post_max_size'] ?? null);
        $this->assertSame('30', $overrides['max_file_uploads'] ?? null);
    }

    public function test_clamd_ping_fails_quietly_when_nothing_listens(): void
    {
        $this->assertFalse((new ClamdScanner('', 3310))->ping());
        $this->assertFalse((new ClamdScanner('127.0.0.1', 1, 1))->ping());
    }

    /**
     * @param  array<string, string>  $htaccess
     * @param  array<string, string>  $ini
     */
    private function fakeLimits(array $htaccess, array $ini = ['upload_max_filesize' => '2M', 'post_max_size' => '8M', 'max_file_uploads' => '20']): void
    {
        $this->app->instance(UploadLimitsProbe::class, new class($htaccess, $ini) extends UploadLimitsProbe
        {
            /**
             * @param  array<string, string>  $htaccess
             * @param  array<string, string>  $ini
             */
            public function __construct(private readonly array $htaccess, private readonly array $ini) {}

            public function limits(): array
            {
                $limits = [];
                foreach (self::SETTINGS as $setting) {
                    $override = $this->htaccess[$setting] ?? null;
                    $limits[$setting] = [
                        'ini' => $this->ini[$setting] ?? null,
                        'htaccess' => $override,
                        'effective' => $override ?? ($this->ini[$setting] ?? null),
                    ];
                }

                return $limits;
            }
        });
    }
}
