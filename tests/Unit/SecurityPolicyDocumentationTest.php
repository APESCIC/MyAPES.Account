<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class SecurityPolicyDocumentationTest extends TestCase
{
    public function test_security_policy_advertises_github_private_vulnerability_reporting(): void
    {
        $policy = $this->read('SECURITY.md');

        $this->assertStringContainsString(
            'https://github.com/APESCIC/MyAPES-Account/security/advisories/new',
            $policy,
        );
        $this->assertStringContainsString('Do not open a public GitHub issue', $policy);
        $this->assertStringContainsString('Change Log Hub', $policy);
        $this->assertStringNotContainsString('@', $this->emailLikeAddresses($policy));
    }

    public function test_readme_and_issue_templates_point_at_the_private_security_path(): void
    {
        $readme = $this->read('README.md');
        $bugReport = $this->read('.github/ISSUE_TEMPLATE/bug_report.yml');
        $issueConfig = $this->read('.github/ISSUE_TEMPLATE/config.yml');

        $this->assertStringContainsString('[SECURITY.md](SECURITY.md)', $readme);
        $this->assertStringContainsString('[Report a security vulnerability privately](SECURITY.md)', $readme);
        $this->assertStringNotContainsString(
            'does not currently advertise a private vulnerability-reporting route',
            $readme,
        );
        $this->assertStringContainsString('SECURITY.md', $bugReport);
        $this->assertStringContainsString(
            'https://github.com/APESCIC/MyAPES.Account/security/advisories/new',
            $issueConfig,
        );
    }

    private function emailLikeAddresses(string $policy): string
    {
        preg_match_all('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $policy, $matches);

        return implode(' ', $matches[0] ?? []);
    }

    private function read(string $relativePath): string
    {
        $path = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            $relativePath,
        );
        $content = file_get_contents($path);

        $this->assertIsString($content, "Unable to read [{$relativePath}].");

        return str_replace("\r\n", "\n", $content);
    }
}
