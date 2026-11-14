<?php

namespace Tests\Feature\Admin;

use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Admin → Settings shell (#733) and its permissions (#741).
 */
class AdminSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_sent_to_sign_in(): void
    {
        $this->get('/admin/settings')->assertRedirect('/login');
        $this->get('/admin/settings/reply-mailbox')->assertRedirect('/login');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function deniedRoles(): array
    {
        return [
            'service user' => [AuthorizationProfile::ROLE_SERVICE_USER],
            'student' => [AuthorizationProfile::ROLE_STUDENT],
            'volunteer' => [AuthorizationProfile::ROLE_VOLUNTEER],
            'staff' => [AuthorizationProfile::ROLE_STAFF],
        ];
    }

    #[DataProvider('deniedRoles')]
    public function test_people_who_are_not_administrators_are_forbidden(string $role): void
    {
        $user = User::factory()->protectedRole($role)->create();

        $this->actingAs($user)->get('/admin/settings')->assertForbidden();
        $this->actingAs($user)->get('/admin/settings/reply-mailbox')->assertForbidden();
    }

    public function test_settings_opens_on_general_and_no_longer_has_a_reply_mailbox_tab(): void
    {
        $admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();

        $this->actingAs($admin)->get('/admin/emails')
            ->assertOk()
            ->assertSee(route('admin.settings.show', 'general'), false)
            ->assertSee(__('admin.nav.settings'));

        $this->actingAs($admin)->get('/admin/settings')->assertRedirect(route('admin.settings.show', 'general'));

        $this->actingAs($admin)->get(route('admin.settings.show', 'general'))
            ->assertOk()
            ->assertSee('data-settings-tabs', false)
            ->assertSee('data-settings-tab="general"', false)
            ->assertDontSee('data-reply-mailbox-form', false);
    }

    public function test_general_is_the_first_tab(): void
    {
        $admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();

        $html = (string) $this->actingAs($admin)->get(route('admin.settings.show', 'integrations'))->getContent();
        preg_match('/<nav class="admin-subnav"[^>]*data-settings-tabs>(.*?)<\/nav>/s', $html, $nav);
        preg_match_all('/href="([^"]+)"/', $nav[1] ?? '', $links);

        $this->assertSame(route('admin.settings.show', 'general'), $links[1][0] ?? null);
        $this->assertSame(route('admin.settings.show', 'integrations'), $links[1][1] ?? null);
    }

    public function test_the_old_reply_mailbox_address_moves_permanently_to_email_import(): void
    {
        $admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();

        $this->actingAs($admin)->get('/admin/settings/reply-mailbox')
            ->assertStatus(301)
            ->assertRedirect(route('admin.emails.import.index'));
    }

    public function test_an_unknown_tab_is_not_found(): void
    {
        $admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();

        $this->actingAs($admin)->get('/admin/settings/not-a-tab')->assertNotFound();
    }

    public function test_settings_permissions_are_for_administrators_only(): void
    {
        $profile = app(AuthorizationProfile::class);
        $matrix = $profile->permissionMatrix();

        foreach (['admin.settings.view', 'admin.settings.manage'] as $permission) {
            $this->assertContains($permission, $matrix[AuthorizationProfile::ROLE_ADMINISTRATOR]);
            $this->assertNotContains($permission, $matrix[AuthorizationProfile::ROLE_STAFF]);
            $this->assertTrue($profile->isAdministratorOnlyPermission($permission));
            $this->assertDatabaseHas('permissions', ['name' => $permission, 'is_code_owned' => true]);
        }
    }
}
