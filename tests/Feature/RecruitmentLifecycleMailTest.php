<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use App\Core\Mail\MailCategory;
use App\Core\Mail\MailTemplateRegistry;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Plugins\Recruitment\Models\RecruitmentApplication;
use Plugins\Recruitment\Models\RecruitmentRole;
use Plugins\Recruitment\Notifications\RecruitmentApplicationReceivedNotification;
use Plugins\Recruitment\Notifications\RecruitmentApplicationStatusNotification;
use Tests\Concerns\CreatesRecruitmentPersonas;
use Tests\TestCase;

class RecruitmentLifecycleMailTest extends TestCase
{
    use CreatesRecruitmentPersonas;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
    }

    public function test_applicant_gets_an_acknowledgement_when_they_apply(): void
    {
        Notification::fake();

        $role = RecruitmentRole::factory()->open()->create(['title' => 'Animal care volunteer']);
        $applicant = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create();

        $this->actingAs($applicant)
            ->post(route('recruitment.apply', $role), ['statement' => 'Private statement text.'])
            ->assertRedirect();

        $application = RecruitmentApplication::query()->where('user_id', $applicant->id)->sole();

        Notification::assertSentTo(
            $applicant,
            RecruitmentApplicationReceivedNotification::class,
            function (RecruitmentApplicationReceivedNotification $notification) use ($applicant, $application): bool {
                $mail = $notification->toMail($applicant);
                $html = $mail->render();

                $this->assertStringContainsString('Animal care volunteer', (string) $mail->subject);
                $this->assertSame(route('recruitment.applications.show', $application), $mail->actionUrl);
                $this->assertSame(__('terms.app_name'), $mail->from[1] ?? null);
                $this->assertStringContainsString(__('mail.brand.footer.help'), $html);
                $this->assertStringNotContainsString('Private statement text.', $html);

                return true;
            },
        );
        Notification::assertSentToTimes($applicant, RecruitmentApplicationReceivedNotification::class, 1);
    }

    public function test_decisions_email_the_applicant_without_staff_notes(): void
    {
        Notification::fake();

        $staff = $this->hrStaff();
        $applicant = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create();
        $application = RecruitmentApplication::factory()
            ->forRole(RecruitmentRole::factory()->open()->create(['title' => 'Education officer']))
            ->forUser($applicant)
            ->create();

        $this->actingAs($staff)
            ->put(route('apes-cic.recruitment.applications.update', $application), [
                'status' => RecruitmentApplication::STATUS_UNDER_REVIEW,
                'staff_notes' => 'Internal first impression.',
            ])
            ->assertRedirect();

        Notification::assertNothingSentTo($applicant);

        $this->actingAs($staff)
            ->put(route('apes-cic.recruitment.applications.update', $application), [
                'status' => RecruitmentApplication::STATUS_SHORTLISTED,
                'staff_notes' => 'Internal shortlist reasoning.',
            ])
            ->assertRedirect();

        $this->actingAs($staff)
            ->put(route('apes-cic.recruitment.applications.update', $application), [
                'status' => RecruitmentApplication::STATUS_ACCEPTED,
                'staff_notes' => 'Internal offer details.',
            ])
            ->assertRedirect();

        $sentStatuses = [];
        Notification::assertSentTo(
            $applicant,
            RecruitmentApplicationStatusNotification::class,
            function (RecruitmentApplicationStatusNotification $notification) use ($applicant, &$sentStatuses): bool {
                $sentStatuses[] = $notification->status;
                $html = $notification->toMail($applicant)->render();

                $this->assertStringContainsString('Education officer', $html);
                $this->assertStringNotContainsString('Internal', $html);

                return true;
            },
        );
        $this->assertSame(
            [RecruitmentApplication::STATUS_SHORTLISTED, RecruitmentApplication::STATUS_ACCEPTED],
            $sentStatuses,
        );
        Notification::assertNotSentTo($staff, RecruitmentApplicationStatusNotification::class);
    }

    public function test_rejected_and_closed_decisions_each_have_their_own_copy(): void
    {
        $applicant = User::factory()->create();
        $role = RecruitmentRole::factory()->open()->create(['title' => 'Keeper']);
        $application = RecruitmentApplication::factory()->forRole($role)->forUser($applicant)->create();
        $subjects = [];

        foreach (RecruitmentApplicationStatusNotification::NOTIFIED_STATUSES as $status) {
            $mail = (new RecruitmentApplicationStatusNotification($application, $status))->toMail($applicant);
            $subjects[$status] = (string) $mail->subject;
            $this->assertStringContainsString('Keeper', $subjects[$status]);
        }

        $this->assertCount(4, array_unique($subjects));
    }

    public function test_non_decision_statuses_cannot_build_a_decision_email(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RecruitmentApplicationStatusNotification(
            RecruitmentApplication::factory()->create(),
            RecruitmentApplication::STATUS_UNDER_REVIEW,
        );
    }

    public function test_recruitment_templates_are_registered_queued_and_categorised(): void
    {
        $registry = app(MailTemplateRegistry::class);

        $received = $registry->find('recruitment.application_received');
        $status = $registry->find('recruitment.application_status');

        $this->assertSame(MailCategory::Service, $received?->category);
        $this->assertSame(MailCategory::Optional, $status?->category);
        $this->assertSame('recruitment', $status?->owner);
        $this->assertTrue(is_subclass_of($received->notification, ShouldQueue::class));
        $this->assertTrue(is_subclass_of($status->notification, ShouldQueue::class));
    }
}
