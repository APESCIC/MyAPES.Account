<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use App\Notifications\PendingFirstLoginChaseNotification;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Plugins\Recruitment\Models\RecruitmentApplication;
use Tests\TestCase;

class UserLocalePreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_locale_column_defaults_to_en_gb(): void
    {
        $user = User::factory()->create();

        $this->assertSame('en_GB', $user->fresh()->locale);
        $this->assertSame('en_GB', $user->preferredLocale());
        $this->assertSame(['en_GB' => 'English (UK)'], config('app.supported_locales'));
    }

    public function test_set_locale_middleware_applies_authenticated_user_locale(): void
    {
        $user = User::factory()->create(['locale' => 'en_GB']);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk();

        $this->assertSame('en_GB', app()->getLocale());
        $this->assertSame('en_GB', Carbon::getLocale());
    }

    public function test_unsupported_user_locale_falls_back_to_en_gb(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['locale' => 'xx_XX'])->saveQuietly();

        $this->actingAs($user->fresh())
            ->get(route('dashboard'))
            ->assertOk();

        $this->assertSame('en_GB', app()->getLocale());
    }

    public function test_guest_session_locale_is_applied_when_supported(): void
    {
        config()->set('app.supported_locales', [
            'en_GB' => 'English (UK)',
            'cy' => 'Cymraeg',
        ]);

        $this->withSession(['locale' => 'cy'])
            ->get(route('home'))
            ->assertOk();

        $this->assertSame('cy', app()->getLocale());
    }

    public function test_locale_switcher_hidden_with_single_supported_locale(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('account.show'))
            ->assertOk()
            ->assertDontSee('locale-switcher', false)
            ->assertDontSee(__('public.locale.switcher_label'));
    }

    public function test_locale_switcher_renders_when_second_locale_configured(): void
    {
        config()->set('app.supported_locales', [
            'en_GB' => 'English (UK)',
            'cy' => 'Cymraeg',
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('account.show'))
            ->assertOk()
            ->assertSee('locale-switcher', false)
            ->assertSee(__('public.locale.switcher_label'))
            ->assertSee('Cymraeg');
    }

    public function test_guest_locale_store_requires_second_supported_locale(): void
    {
        $this->post(route('locale.store'), ['locale' => 'en_GB'])
            ->assertNotFound();
    }

    public function test_guest_locale_store_persists_session_when_multiple_locales(): void
    {
        config()->set('app.supported_locales', [
            'en_GB' => 'English (UK)',
            'cy' => 'Cymraeg',
        ]);

        $this->from(route('home'))
            ->post(route('locale.store'), ['locale' => 'cy'])
            ->assertRedirect(route('home'));

        $this->assertSame('cy', session('locale'));
    }

    public function test_oidc_style_directory_update_does_not_wipe_locale(): void
    {
        $user = User::factory()
            ->cloudronIdentity('oidc-locale-preserve')
            ->create(['locale' => 'en_GB']);

        $user->forceFill([
            'name' => 'Updated From Directory',
            'email' => $user->email,
            'email_verified_at' => now(),
        ])->save();

        $this->assertSame('en_GB', $user->fresh()->locale);
    }

    public function test_notifications_honour_preferred_locale(): void
    {
        Notification::fake();

        $actor = User::factory()->create();
        $user = User::factory()->create(['locale' => 'en_GB']);

        $user->notify(new PendingFirstLoginChaseNotification($actor));

        Notification::assertSentTo(
            $user,
            PendingFirstLoginChaseNotification::class,
            function (PendingFirstLoginChaseNotification $notification, array $channels, object $notifiable): bool {
                return $notifiable instanceof User
                    && $notifiable->preferredLocale() === 'en_GB';
            },
        );
    }

    public function test_recruitment_application_status_labels_cover_all_statuses(): void
    {
        $statuses = [
            RecruitmentApplication::STATUS_SUBMITTED,
            RecruitmentApplication::STATUS_UNDER_REVIEW,
            RecruitmentApplication::STATUS_SHORTLISTED,
            RecruitmentApplication::STATUS_REJECTED,
            RecruitmentApplication::STATUS_ACCEPTED,
            RecruitmentApplication::STATUS_WITHDRAWN,
            RecruitmentApplication::STATUS_CLOSED,
        ];

        foreach ($statuses as $status) {
            $this->assertContains($status, RecruitmentApplication::STATUSES);
        }

        $this->assertSame(count($statuses), count(array_unique(RecruitmentApplication::STATUSES)));
    }
}
