<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffLoginFooterOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_public_pages_have_exactly_one_staff_login_link_in_the_footer(): void
    {
        foreach (['/', '/login', '/help', '/register', '/forgot-password'] as $path) {
            $response = $this->get($path)->assertOk();
            $html = $response->getContent();

            $this->assertSame(
                1,
                substr_count($html, 'href="'.route('staff.login').'"'),
                "Expected one staff login link on {$path}",
            );
            $this->assertMatchesRegularExpression(
                '#<footer[^>]*>[\s\S]*href="'.preg_quote(route('staff.login'), '#').'"[\s\S]*</footer>#',
                $html,
                "Staff login link must sit in the footer on {$path}",
            );
        }
    }

    public function test_staff_login_page_still_loads_directly(): void
    {
        $this->get(route('staff.login'))->assertOk();
    }
}
