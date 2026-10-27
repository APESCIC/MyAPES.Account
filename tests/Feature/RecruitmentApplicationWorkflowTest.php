<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use App\Core\Extensions\Models\ModuleInstallation;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Plugins\Recruitment\Models\RecruitmentApplication;
use Plugins\Recruitment\Models\RecruitmentRole;
use Tests\Concerns\CreatesRecruitmentPersonas;
use Tests\TestCase;

class RecruitmentApplicationWorkflowTest extends TestCase
{
    use CreatesRecruitmentPersonas;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
    }

    public function test_factory_creates_application_against_open_role(): void
    {
        $application = RecruitmentApplication::factory()->create();

        $this->assertDatabaseHas('recruitment_applications', [
            'id' => $application->id,
            'status' => RecruitmentApplication::STATUS_SUBMITTED,
        ]);
        $this->assertTrue($application->recruitmentRole->isOpen());
        $this->assertNotNull($application->submitted_at);
        $this->assertSame(
            $application->id,
            $application->recruitmentRole->applications()->firstOrFail()->id,
        );
    }

    public function test_submit_against_open_role_succeeds(): void
    {
        $role = RecruitmentRole::factory()->open()->create();
        $applicant = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)
            ->create();

        $application = RecruitmentApplication::submitAgainstOpenRole($role, $applicant, [
            'statement' => 'I would like to help.',
        ]);

        $this->assertSame(RecruitmentApplication::STATUS_SUBMITTED, $application->status);
        $this->assertSame($role->id, $application->recruitment_role_id);
        $this->assertSame($applicant->id, $application->user_id);
        $this->assertSame('I would like to help.', $application->statement);
        $this->assertNotNull($application->submitted_at);
    }

    public function test_submit_against_draft_or_closed_role_is_blocked(): void
    {
        $applicant = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)
            ->create();

        foreach (['draft', 'closed'] as $state) {
            $role = RecruitmentRole::factory()->{$state}()->create();

            try {
                RecruitmentApplication::submitAgainstOpenRole($role, $applicant);
                $this->fail("Expected submit against {$state} role to fail.");
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('open recruitment roles', $exception->getMessage());
            }

            $this->assertDatabaseMissing('recruitment_applications', [
                'recruitment_role_id' => $role->id,
                'user_id' => $applicant->id,
            ]);
        }
    }

    public function test_status_workflow_allows_expected_transitions(): void
    {
        $application = RecruitmentApplication::factory()->create();

        $this->assertTrue($application->canTransitionTo(RecruitmentApplication::STATUS_UNDER_REVIEW));
        $this->assertTrue($application->canTransitionTo(RecruitmentApplication::STATUS_WITHDRAWN));
        $this->assertFalse($application->canTransitionTo(RecruitmentApplication::STATUS_ACCEPTED));

        $application->transitionTo(RecruitmentApplication::STATUS_UNDER_REVIEW);
        $application->refresh();
        $this->assertSame(RecruitmentApplication::STATUS_UNDER_REVIEW, $application->status);
        $this->assertNotNull($application->reviewed_at);

        $application->transitionTo(RecruitmentApplication::STATUS_SHORTLISTED);
        $application->refresh();
        $this->assertSame(RecruitmentApplication::STATUS_SHORTLISTED, $application->status);

        $application->transitionTo(RecruitmentApplication::STATUS_ACCEPTED);
        $application->refresh();
        $this->assertSame(RecruitmentApplication::STATUS_ACCEPTED, $application->status);
        $this->assertNotNull($application->decided_at);
        $this->assertTrue($application->isTerminal());
        $this->assertFalse($application->canTransitionTo(RecruitmentApplication::STATUS_WITHDRAWN));
    }

    public function test_invalid_status_transition_is_rejected(): void
    {
        $application = RecruitmentApplication::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $application->transitionTo(RecruitmentApplication::STATUS_ACCEPTED);
    }

    public function test_withdraw_and_reject_paths_are_terminal(): void
    {
        $withdrawn = RecruitmentApplication::factory()->create();
        $withdrawn->transitionTo(RecruitmentApplication::STATUS_WITHDRAWN);
        $withdrawn->refresh();
        $this->assertSame(RecruitmentApplication::STATUS_WITHDRAWN, $withdrawn->status);
        $this->assertNotNull($withdrawn->withdrawn_at);
        $this->assertTrue($withdrawn->isTerminal());

        $rejected = RecruitmentApplication::factory()->underReview()->create();
        $rejected->transitionTo(RecruitmentApplication::STATUS_REJECTED);
        $rejected->refresh();
        $this->assertSame(RecruitmentApplication::STATUS_REJECTED, $rejected->status);
        $this->assertNotNull($rejected->decided_at);
        $this->assertTrue($rejected->isTerminal());
    }

    public function test_applicant_owns_row_and_hr_staff_can_review(): void
    {
        $applicant = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)
            ->create();
        $other = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)
            ->create();
        $staff = $this->hrStaff();
        $openRole = RecruitmentRole::factory()->open()->create();
        $application = RecruitmentApplication::factory()
            ->forRole($openRole)
            ->forUser($applicant)
            ->create();

        $this->actingAs($applicant);
        $this->assertTrue(Gate::allows('view', $application));
        $this->assertTrue(Gate::allows('withdraw', $application));
        $this->assertFalse(Gate::allows('review', $application));

        $this->actingAs($other);
        $this->assertFalse(Gate::allows('view', $application));
        $this->assertFalse(Gate::allows('withdraw', $application));

        $this->actingAs($staff);
        $this->assertTrue(Gate::allows('view', $application));
        $this->assertTrue(Gate::allows('review', $application));
        $this->assertFalse(Gate::allows('withdraw', $application));
    }

    public function test_create_policy_requires_open_role_when_provided(): void
    {
        $applicant = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)
            ->create();
        $open = RecruitmentRole::factory()->open()->create();
        $draft = RecruitmentRole::factory()->draft()->create();
        $closed = RecruitmentRole::factory()->closed()->create();

        $this->actingAs($applicant);
        $this->assertTrue(Gate::allows('create', [RecruitmentApplication::class, $open]));
        $this->assertFalse(Gate::allows('create', [RecruitmentApplication::class, $draft]));
        $this->assertFalse(Gate::allows('create', [RecruitmentApplication::class, $closed]));
    }

    public function test_visible_to_scope_limits_applicants_to_own_rows(): void
    {
        $applicant = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)
            ->create();
        $other = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)
            ->create();
        $staff = $this->hrStaff();

        $own = RecruitmentApplication::factory()->forUser($applicant)->create();
        RecruitmentApplication::factory()->forUser($other)->create();

        $this->actingAs($applicant);
        $this->assertSame(
            [$own->id],
            RecruitmentApplication::query()->visibleTo($applicant)->pluck('id')->all(),
        );

        $this->actingAs($staff);
        $this->assertCount(2, RecruitmentApplication::query()->visibleTo($staff)->get());
    }

    public function test_review_policy_denies_terminal_applications(): void
    {
        $staff = $this->hrStaff();
        $application = RecruitmentApplication::factory()->accepted()->create();

        $this->actingAs($staff);
        $this->assertFalse(Gate::allows('review', $application));
        $this->assertFalse(Gate::allows('update', $application));
    }

    public function test_policy_denies_when_module_disabled(): void
    {
        $applicant = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)
            ->create();
        $application = RecruitmentApplication::factory()
            ->forUser($applicant)
            ->create();

        ModuleInstallation::query()
            ->where('sub_core_key', 'apes-cic')
            ->where('module_key', 'recruitment')
            ->update(['enabled' => false, 'disabled_at' => now()]);

        $this->actingAs($applicant);
        $this->assertFalse(Gate::allows('view', $application));
        $this->assertFalse(Gate::allows('viewAny', RecruitmentApplication::class));
        $this->assertFalse(Gate::allows('create', RecruitmentApplication::class));
        $this->assertFalse(Gate::allows('withdraw', $application));

        $this->expectException(AuthorizationException::class);
        Gate::authorize('view', $application);
    }
}
