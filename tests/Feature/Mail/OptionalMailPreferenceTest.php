<?php

namespace Tests\Feature\Mail;

use App\Core\Accounts\User;
use App\Core\Mail\MailCategory;
use App\Core\Mail\MailTemplateRegistry;
use App\Core\Mail\OutboundMailLog;
use App\Notifications\Auth\PasswordChangedNotification;
use App\Services\AuthorizationProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Plugins\Recruitment\Models\RecruitmentApplication;
use Plugins\Recruitment\Notifications\RecruitmentApplicationReceivedNotification;
use Plugins\Recruitment\Notifications\RecruitmentApplicationStatusNotification;
use Tests\TestCase;

class OptionalMailPreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_optional_mail_sends_when_marketing_email_preference_is_on(): void
    {
        $user = $this->userWithMarketingPreference(true);

        $user->notify($this->decision($user));

        $this->assertSame(1, $this->logged('recruitment.application_status'));
    }

    public function test_optional_mail_is_skipped_when_marketing_email_preference_is_off_or_never_set(): void
    {
        $off = $this->userWithMarketingPreference(false);
        $unset = User::factory()->create();

        $off->notify($this->decision($off));
        $unset->notify($this->decision($unset));

        $this->assertSame(0, $this->logged('recruitment.application_status'));
    }

    public function test_mandatory_mail_still_sends_when_email_preference_is_off(): void
    {
        $user = $this->userWithEmailPreference(false);
        $application = RecruitmentApplication::factory()->forUser($user)->create();

        $user->notify(new PasswordChangedNotification);
        $user->notify(new RecruitmentApplicationReceivedNotification($application));

        $this->assertSame(1, $this->logged('auth.password_changed'));
        $this->assertSame(1, $this->logged('recruitment.application_received'));
    }

    public function test_every_registered_template_follows_its_category(): void
    {
        $off = $this->userWithEmailPreference(false);
        $on = $this->userWithEmailPreference(true);

        foreach (app(MailTemplateRegistry::class)->all() as $template) {
            $mandatory = $template->category->isMandatory();

            $this->assertSame(
                $mandatory,
                in_array('mail', $template->sampleNotification($off)->via($off), true),
                "{$template->key} with Email preference off",
            );
            $this->assertContains('mail', $template->sampleNotification($on)->via($on), "{$template->key} with Email preference on");
        }

        $this->assertNotEmpty(array_filter(
            app(MailTemplateRegistry::class)->all(),
            static fn ($template): bool => $template->category === MailCategory::Optional,
        ));
    }

    public function test_admin_send_test_reaches_the_admin_even_with_their_preference_off(): void
    {
        $superAdmin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();
        $superAdmin->contactPreference()->updateOrCreate([], ['email' => false, 'policy_version' => 'test', 'confirmed_at' => now()]);

        $this->actingAs($superAdmin)
            ->post(route('admin.emails.test', 'recruitment.application_status'))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $this->logged('recruitment.application_status'));
    }

    public function test_privacy_page_explains_optional_email_opt_in(): void
    {
        $user = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create();

        $this->actingAs($user)
            ->get(route('account.privacy'))
            ->assertOk()
            ->assertSeeText(__('account.settings.privacy.email_opt_in.staff_email_help'))
            ->assertSeeText(__('account.settings.privacy.email_opt_in.marketing_email_help'));
    }

    private function userWithEmailPreference(bool $email): User
    {
        $user = User::factory()->create();
        $user->contactPreference()->updateOrCreate([], [
            'email' => $email,
            'marketing_email' => $email,
            'policy_version' => 'test',
            'confirmed_at' => now(),
        ]);

        return $user->fresh();
    }

    private function userWithMarketingPreference(bool $marketingEmail): User
    {
        $user = User::factory()->create();
        $user->contactPreference()->updateOrCreate([], [
            'marketing_email' => $marketingEmail,
            'policy_version' => 'test',
            'confirmed_at' => now(),
        ]);

        return $user->fresh();
    }

    private function decision(User $user): RecruitmentApplicationStatusNotification
    {
        return new RecruitmentApplicationStatusNotification(
            RecruitmentApplication::factory()->forUser($user)->create(),
            RecruitmentApplication::STATUS_SHORTLISTED,
        );
    }

    private function logged(string $key): int
    {
        return OutboundMailLog::query()->where('template_key', $key)->count();
    }
}
