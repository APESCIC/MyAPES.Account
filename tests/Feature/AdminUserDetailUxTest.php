<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserDetailUxTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_detail_shows_access_summary_with_advanced_permissions(): void
    {
        $administrator = $this->userWithAccess(User::ROLE_ADMIN);
        $staff = $this->userWithAccess(User::ROLE_STAFF);

        $response = $this->actingAs($administrator)
            ->get(route('admin.staff-accounts.show', ['user' => $staff, 'tab' => 'access']))
            ->assertOk()
            ->assertSee('Effective access')
            ->assertSee('Job roles')
            ->assertSee('Capability packs')
            ->assertSee('Advanced permissions')
            ->assertSee('data-effective-permissions-advanced', false)
            ->assertDontSee('<h2 id="effective-permissions-title">Effective permissions</h2>', false);

        $content = $response->getContent();
        $advancedPos = strpos($content, 'data-effective-permissions-advanced');
        $this->assertNotFalse($advancedPos);
        $this->assertStringContainsString(
            'staff.access',
            substr($content, $advancedPos),
        );
        $this->assertSame(
            0,
            preg_match_all('/<ul>\s*<li><code>[a-z0-9.*_-]+<\/code><\/li>/', $content),
        );
    }

    public function test_suspend_requires_explicit_confirm(): void
    {
        $administrator = $this->userWithAccess(User::ROLE_ADMIN);
        $staff = $this->userWithAccess(User::ROLE_STAFF);

        $this->actingAs($administrator)
            ->get(route('admin.staff-accounts.show', ['user' => $staff, 'tab' => 'access']))
            ->assertOk()
            ->assertSee('id="suspend-user"', false)
            ->assertSee('I confirm I want to suspend this account')
            ->assertSee('name="confirm_suspend"', false)
            ->assertSee('#suspend-user', false);

        $this->actingAs($administrator)
            ->from(route('admin.staff-accounts.show', $staff))
            ->post(route('admin.users.suspension.store', $staff), [
                'reason' => 'Temporary access review',
            ])
            ->assertRedirect(route('admin.staff-accounts.show', $staff))
            ->assertSessionHasErrors('confirm_suspend');
        $this->assertNull($staff->fresh()->suspended_at);

        $this->actingAs($administrator)
            ->post(route('admin.users.suspension.store', $staff), [
                'reason' => 'Temporary access review',
                'confirm_suspend' => '1',
            ])
            ->assertRedirect(route('admin.staff-accounts.show', $staff));
        $this->assertNotNull($staff->fresh()->suspended_at);
    }

    private function userWithAccess(string $accessLevel): User
    {
        return User::factory()
            ->accessLevel($accessLevel)
            ->create()
            ->refresh();
    }
}
