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

    public function test_an_administrator_sees_settings_in_admin_navigation_and_the_reply_mailbox_tab(): void
    {
        $admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();

        $this->actingAs($admin)->get('/admin/emails')
            ->assertOk()
            ->assertSee(route('admin.settings.index'), false)
            ->assertSee(__('admin.nav.settings'));

        $this->actingAs($admin)->get('/admin/settings')->assertRedirect(route('admin.settings.show', 'reply-mailbox'));

        $this->actingAs($admin)->get('/admin/settings/reply-mailbox')
            ->assertOk()
            ->assertSee('data-settings-tabs', false)
            ->assertSee('data-settings-tab="reply-mailbox"', false)
            ->assertSee(__('admin.settings.reply_mailbox.heading'));
    }

    public function test_an_unknown_tab_is_not_found(): void
    {
        $admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();

        $this->actingAs($admin)->get('/admin/settings/general')->assertNotFound();
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
