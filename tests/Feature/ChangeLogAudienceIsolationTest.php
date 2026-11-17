<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use App\Support\ChangeLogPresenter;
use App\Support\ReleaseHistoryRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChangeLogAudienceIsolationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function public_projection_strips_internal_fields_and_keeps_markup_inert_in_html(): void
    {
        $releases = app(ReleaseHistoryRepository::class)->all();
        $public = ChangeLogPresenter::forPublicAudience($releases);

        foreach ($public as $release) {
            $this->assertSame([], $release['validation']);
            $this->assertSame([], $release['known_limitations']);
            $this->assertSame('', $release['rollback']);
            $this->assertSame('', $release['provenance']);
            $this->assertSame([], $release['references']);
            $this->assertSame(['public-facing'], $release['audiences']);
        }

        $response = $this->get('/change-log');
        $response
            ->assertOk()
            ->assertDontSeeText('Rollback notes')
            ->assertDontSee('data-change-log-filter="internal-only"', false);

        $html = $response->getContent();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('>Internal-only<', $html);
    }

    #[Test]
    public function staff_can_see_internal_notes_while_guest_cannot(): void
    {
        $this->get('/change-log')
            ->assertOk()
            ->assertDontSeeText('Rollback notes');

        $staff = User::factory()->accessLevel(User::ROLE_STAFF)->create();

        $this->actingAs($staff)
            ->get('/change-log')
            ->assertOk()
            ->assertSeeText('Rollback notes')
            ->assertSee('data-change-log-filter="internal-only"', false);
    }
}
