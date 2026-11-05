<?php

namespace Tests\Feature\AccountSeparation;

use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffAccountHelpAndNoticeTest extends TestCase
{
    use RefreshDatabase;

    public function test_help_page_explains_staff_and_personal_accounts(): void
    {
        $this->get(route('help'))
            ->assertOk()
            ->assertSee('id="staff-and-personal-accounts"', false)
            ->assertSeeText(__('public.legal.help.staff_accounts.heading'))
            ->assertSeeText('conflict of interest')
            ->assertSee('href="'.route('public.register').'"', false);
    }

    public function test_affected_staff_see_the_notice_until_they_dismiss_it(): void
    {
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create([
            'client_records_archived_at' => now()->subHour(),
        ]);

        $this->actingAs($staff)
            ->get(route('help'))
            ->assertOk()
            ->assertSee('data-client-records-notice', false)
            ->assertSeeText(__('public.client_records_notice.title'));

        $this->actingAs($staff)
            ->from(route('help'))
            ->post(route('client-records-notice.dismiss'))
            ->assertRedirect(route('help'))
            ->assertSessionHas('status', __('flash.client_records_notice_dismissed'));

        $this->assertNotNull($staff->fresh()->client_records_notice_dismissed_at);

        $this->actingAs($staff->fresh())
            ->get(route('help'))
            ->assertOk()
            ->assertDontSee('data-client-records-notice', false)
            ->assertSeeText(__('flash.client_records_notice_dismissed'), false);
    }

    public function test_unaffected_accounts_never_see_the_notice(): void
    {
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();

        $this->actingAs($staff)
            ->get(route('help'))
            ->assertOk()
            ->assertDontSee('data-client-records-notice', false);
        $this->get(route('help'))->assertOk()->assertDontSee('data-client-records-notice', false);
    }

    public function test_dismissing_requires_sign_in(): void
    {
        $this->post(route('client-records-notice.dismiss'))->assertRedirect();
        $this->assertGuest();
    }
}
