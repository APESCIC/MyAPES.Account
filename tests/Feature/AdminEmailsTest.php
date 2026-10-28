<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use App\Core\Mail\MailTemplateRegistry;
use App\Services\AuthorizationProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminEmailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_sent_to_sign_in(): void
    {
        $this->get('/admin/emails')->assertRedirect('/login');
        $this->get('/admin/emails/auth.verify_email')->assertRedirect('/login');
        $this->get('/admin/emails/auth.verify_email/preview')->assertRedirect('/login');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function deniedRoles(): array
    {
        return [
            'service user' => [AuthorizationProfile::ROLE_SERVICE_USER],
            'staff' => [AuthorizationProfile::ROLE_STAFF],
            'volunteer' => [AuthorizationProfile::ROLE_VOLUNTEER],
        ];
    }

    #[DataProvider('deniedRoles')]
    public function test_non_admins_are_forbidden(string $role): void
    {
        $user = User::factory()->protectedRole($role)->create();

        foreach (['/admin/emails', '/admin/emails/auth.verify_email', '/admin/emails/auth.verify_email/preview'] as $path) {
            $this->actingAs($user)->get($path)->assertForbidden();
        }
    }

    public function test_staff_denial_is_audited(): void
    {
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();

        $this->actingAs($staff)->get('/admin/emails')->assertForbidden();

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'authorization.admin_denied',
            'user_id' => $staff->id,
        ]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function allowedRoles(): array
    {
        return [
            'administrator' => [AuthorizationProfile::ROLE_ADMINISTRATOR],
            'super admin' => [AuthorizationProfile::ROLE_SUPER_ADMIN],
        ];
    }

    #[DataProvider('allowedRoles')]
    public function test_admins_can_list_every_registered_template(string $role): void
    {
        $admin = User::factory()->protectedRole($role)->create();
        $registry = app(MailTemplateRegistry::class);

        $response = $this->actingAs($admin)->get('/admin/emails')
            ->assertOk()
            ->assertSeeText(__('admin.emails.heading'))
            ->assertSee('href="'.route('admin.emails.index').'"', false);

        foreach ($registry->all() as $template) {
            $response
                ->assertSee('data-mail-template="'.$template->key.'"', false)
                ->assertSeeText($template->label())
                ->assertSee('href="'.route('admin.emails.show', $template->key).'"', false);
        }

        $response
            ->assertSeeText(__('mail.categories.security'))
            ->assertSeeText(__('mail.categories.optional'))
            ->assertSeeText('Recruitment');
    }

    public function test_preview_page_and_frame_render_samples_without_sending(): void
    {
        Notification::fake();
        Mail::fake();

        $admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create(['name' => 'Preview Admin']);

        foreach (app(MailTemplateRegistry::class)->all() as $template) {
            $this->actingAs($admin)->get(route('admin.emails.show', $template->key))
                ->assertOk()
                ->assertSeeText($template->label())
                ->assertSee('src="'.route('admin.emails.preview', $template->key).'"', false)
                ->assertSee('sandbox', false);

            $this->actingAs($admin)->get(route('admin.emails.preview', $template->key))
                ->assertOk()
                ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
                ->assertHeader('Cache-Control', 'no-store, private')
                ->assertSee(url('/logos/myapes-header-light-600x128.png'), false)
                ->assertSee(__('mail.brand.footer.help'), false);
        }

        Notification::assertNothingSent();
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_preview_frame_is_locked_down_by_its_own_policy(): void
    {
        $admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_SUPER_ADMIN)->create();

        $policy = $this->actingAs($admin)
            ->get(route('admin.emails.preview', 'tickets.ticket_updated'))
            ->assertOk()
            ->headers->get('Content-Security-Policy');

        $this->assertStringContainsString('sandbox', (string) $policy);
        $this->assertStringContainsString("default-src 'none'", (string) $policy);
        $this->assertStringNotContainsString('script-src', (string) $policy);
    }

    public function test_optional_templates_say_they_respect_the_email_preference(): void
    {
        $admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();

        $this->actingAs($admin)->get(route('admin.emails.show', 'recruitment.application_status'))
            ->assertOk()
            ->assertSeeText(__('admin.emails.respects_preference'));

        $this->actingAs($admin)->get(route('admin.emails.show', 'auth.reset_password'))
            ->assertOk()
            ->assertSeeText(__('admin.emails.always_sent'));
    }

    public function test_unknown_templates_are_not_found(): void
    {
        $admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();

        $this->actingAs($admin)->get('/admin/emails/nope.missing')->assertNotFound();
        $this->actingAs($admin)->get('/admin/emails/nope.missing/preview')->assertNotFound();
    }

    public function test_emails_is_in_admin_search_for_admins_only(): void
    {
        $admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();

        $this->actingAs($admin)->get('/admin/emails')
            ->assertOk()
            ->assertSee(__('admin.search.sections.emails'), false);
    }

    public function test_email_permissions_follow_the_protected_role_matrix(): void
    {
        $profile = app(AuthorizationProfile::class);
        $matrix = $profile->permissionMatrix();

        $this->assertContains('admin.emails.view', $matrix[AuthorizationProfile::ROLE_ADMINISTRATOR]);
        $this->assertNotContains('admin.emails.manage', $matrix[AuthorizationProfile::ROLE_ADMINISTRATOR]);
        $this->assertContains('admin.emails.view', $matrix[AuthorizationProfile::ROLE_SUPER_ADMIN]);
        $this->assertContains('admin.emails.manage', $matrix[AuthorizationProfile::ROLE_SUPER_ADMIN]);
        $this->assertNotContains('admin.emails.view', $matrix[AuthorizationProfile::ROLE_STAFF]);
        $this->assertTrue($profile->isSuperAdminOnlyPermission('admin.emails.manage'));
        $this->assertDatabaseHas('permissions', ['name' => 'admin.emails.manage', 'is_code_owned' => true]);
    }
}
