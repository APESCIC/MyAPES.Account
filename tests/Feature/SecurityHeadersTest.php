<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Content Security Policy and security headers (#485).
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_headers_are_present_on_every_response(): void
    {
        foreach (['/', '/help', '/login', '/healthz', '/robots.txt', '/does-not-exist'] as $path) {
            $response = $this->get($path);

            $response->assertHeader('X-Content-Type-Options', 'nosniff');
            $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
            $this->assertTrue(
                $response->headers->has('Content-Security-Policy-Report-Only'),
                "{$path} has no CSP header.",
            );
        }
    }

    public function test_the_policy_allows_tinymce_and_bookings_and_reports_violations(): void
    {
        $policy = (string) $this->get('/')->headers->get('Content-Security-Policy-Report-Only');

        $this->assertStringContainsString("default-src 'self'", $policy);
        $this->assertMatchesRegularExpression("/script-src 'self' https:\/\/cdn\.tiny\.cloud 'nonce-[A-Za-z0-9+\/=]+'/", $policy);
        $this->assertStringContainsString('frame-src https://bookings.cloud.microsoft https://outlook.office365.com', $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertStringContainsString("frame-ancestors 'self'", $policy);
        $this->assertStringContainsString('report-uri /csp-report', $policy);
    }

    public function test_inline_scripts_carry_the_policy_nonce(): void
    {
        $response = $this->get('/');
        preg_match("/'nonce-([^']+)'/", (string) $response->headers->get('Content-Security-Policy-Report-Only'), $match);

        $this->assertNotEmpty($match[1] ?? null);
        $response->assertSee('<script nonce="'.$match[1].'">', false);
    }

    public function test_enforcement_switches_the_header(): void
    {
        config(['myapes.csp.enforce' => true]);

        $response = $this->get('/');

        $this->assertTrue($response->headers->has('Content-Security-Policy'));
        $this->assertFalse($response->headers->has('Content-Security-Policy-Report-Only'));
    }

    public function test_violation_reports_are_accepted_and_logged_without_csrf(): void
    {
        Log::spy();

        $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], json_encode([
            'csp-report' => [
                'document-uri' => 'https://myaccount.myapes.me.uk/dashboard?secret=1',
                'violated-directive' => 'script-src',
                'blocked-uri' => 'https://evil.example/x.js',
            ],
        ]))->assertNoContent();

        Log::shouldHaveReceived('warning')->withArgs(static fn (string $message, array $context): bool => $message === 'csp.violation'
            && $context['page'] === '/dashboard'
            && $context['directive'] === 'script-src');
    }

    public function test_views_have_no_inline_event_handlers(): void
    {
        $offenders = [];
        foreach ([resource_path('views'), base_path('plugins'), base_path('modules')] as $root) {
            foreach (File::allFiles($root) as $file) {
                if (str_ends_with($file->getFilename(), '.blade.php')
                    && preg_match('/\son(click|change|submit|load|input|error|focus|blur)\s*=/i', $file->getContents()) === 1) {
                    $offenders[] = $file->getRelativePathname();
                }
            }
        }

        $this->assertSame([], $offenders);
    }
}
