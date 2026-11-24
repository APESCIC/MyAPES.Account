<?php

namespace Tests\Unit\Navigation;

use App\Support\Navigation\WorkspaceUrl;
use Tests\TestCase;

class WorkspaceUrlTest extends TestCase
{
    public function test_relative_and_same_origin_urls_are_not_external(): void
    {
        config(['app.url' => 'https://myapes-account.test']);

        $this->assertFalse(WorkspaceUrl::isExternal('/profile'));
        $this->assertFalse(WorkspaceUrl::isExternal('https://myapes-account.test/profile'));
        $this->assertFalse(WorkspaceUrl::isExternal('https://myapes-account.test/help'));
    }

    public function test_different_hosts_are_external(): void
    {
        config(['app.url' => 'https://myapes-account.test']);

        $this->assertTrue(WorkspaceUrl::isExternal('https://github.com/APESCIC/MyAPES-Account'));
        $this->assertTrue(WorkspaceUrl::isExternal('http://example.com/path'));
    }
}
