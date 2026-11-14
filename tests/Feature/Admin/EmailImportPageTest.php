<?php

namespace Tests\Feature\Admin;

use App\Core\Accounts\User;
use App\Services\AdminSearchCatalogue;
use App\Services\AuthorizationProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Admin → Emails → Import: its own page and menu item for administrators (#774).
 */
class EmailImportPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_administrator_opens_the_import_page_from_the_menu_and_the_emails_tabs(): void
    {
        $admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();

        $this->actingAs($admin)->get(route('admin.emails.inbound.index'))
            ->assertOk()
            ->assertSee('data-email-import-tab', false)
            ->assertSeeInOrder([__('admin.emails.tabs.inbound'), __('admin.emails.tabs.import')])
            ->assertSee('>'.__('admin.nav.email_import').'</span>', false);

        $page = $this->actingAs($admin)->get(route('admin.emails.import.index'))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee(__('admin.emails.import.heading'))
            ->assertSee('data-mailbox-connection', false)
            ->assertSee('data-import-status', false);
        $this->assertMatchesRegularExpression('/data-email-import-tab\s+aria-current="page"/', (string) $page->getContent());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function otherRoles(): array
    {
        return [
            'service user' => [AuthorizationProfile::ROLE_SERVICE_USER],
            'student' => [AuthorizationProfile::ROLE_STUDENT],
            'volunteer' => [AuthorizationProfile::ROLE_VOLUNTEER],
            'staff' => [AuthorizationProfile::ROLE_STAFF],
        ];
    }

    #[DataProvider('otherRoles')]
    public function test_only_administrators_see_or_open_email_import(string $role): void
    {
        $user = User::factory()->protectedRole($role)->create();

        $this->actingAs($user)->get(route('admin.emails.import.index'))->assertForbidden();
        $this->actingAs($user)->put(route('admin.emails.import.update'), ['host' => 'mail.example.test'])->assertForbidden();
        $this->actingAs($user)->post(route('admin.emails.import.test'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.emails.import.probe'))->assertForbidden();

        $dashboard = $this->actingAs($user)->get(route('dashboard'));
        $dashboard->assertDontSee(route('admin.emails.import.index'), false);

        if ($user->can('admin.emails.view')) {
            $this->actingAs($user)->get(route('admin.emails.inbound.index'))
                ->assertOk()
                ->assertDontSee('data-email-import-tab', false);
        }
    }

    public function test_the_search_keywords_point_at_the_import_page(): void
    {
        $admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();
        $this->actingAs($admin)->get(route('admin.emails.import.index'))->assertOk();

        $entries = collect(app(AdminSearchCatalogue::class)->entriesFor($admin))->keyBy('id');

        $this->assertSame(route('admin.emails.import.index'), $entries['admin.emails.import']['url']);
        foreach (['imap', 'mailbox', 'reply mailbox', 'import', 'communications'] as $keyword) {
            $this->assertContains($keyword, $entries['admin.emails.import']['keywords']);
            $this->assertNotContains($keyword, $entries['admin.settings']['keywords']);
        }
        $this->assertSame(route('admin.settings.show', 'general'), $entries['admin.settings']['url']);
    }
}
