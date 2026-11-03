<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Plugins\Recruitment\Models\RecruitmentApplication;
use Plugins\Recruitment\Models\RecruitmentRole;
use Plugins\Recruitment\Models\RecruitmentRoleQuestion;
use Tests\TestCase;

/**
 * Per-role application questions (#469).
 */
class RecruitmentApplicationFormBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
    }

    public function test_admin_adds_a_question_and_it_appears_on_the_form(): void
    {
        $admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();
        $role = $this->openRole($admin);

        $this->actingAs($admin)
            ->get(route('apes-cic.recruitment.show', $role))
            ->assertOk()
            ->assertSee('data-recruitment-questions', false)
            ->assertSee('action="'.route('apes-cic.recruitment.questions.store', $role).'"', false);

        $this->actingAs($admin)
            ->post(route('apes-cic.recruitment.questions.store', $role), [
                'label' => 'Do you hold a full UK driving licence?',
                'help_text' => 'Some placements involve driving.',
                'answer_type' => 'short',
                'required' => '1',
            ])
            ->assertRedirect(route('apes-cic.recruitment.show', $role).'#questions');

        $question = $role->questions()->sole();
        $this->assertSame('Do you hold a full UK driving licence?', $question->label);
        $this->assertTrue($question->required);

        $applicant = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create();
        $this->actingAs($applicant)
            ->get(route('recruitment.show', $role))
            ->assertOk()
            ->assertSeeText('Do you hold a full UK driving licence?')
            ->assertSeeText('Some placements involve driving.')
            ->assertSee('name="answers['.$question->id.']"', false);
    }

    public function test_answers_are_required_and_stored_with_the_application(): void
    {
        $admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();
        $role = $this->openRole($admin);
        $required = $role->questions()->create(['label' => 'Why this role?', 'answer_type' => 'long', 'required' => true, 'position' => 1]);
        $optional = $role->questions()->create(['label' => 'Anything else?', 'answer_type' => 'short', 'required' => false, 'position' => 2]);
        $applicant = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create();

        $this->actingAs($applicant)
            ->post(route('recruitment.apply', $role), ['statement' => 'Hello'])
            ->assertSessionHasErrors('answers.'.$required->id);

        $this->actingAs($applicant)
            ->post(route('recruitment.apply', $role), [
                'statement' => 'Hello',
                'answers' => [$required->id => 'I care about exotic animals.', $optional->id => ''],
            ])
            ->assertRedirect();

        $application = RecruitmentApplication::query()->where('user_id', $applicant->id)->sole();
        $this->assertSame([
            ['question_id' => $required->id, 'label' => 'Why this role?', 'answer' => 'I care about exotic animals.'],
        ], $application->answers);

        $required->update(['label' => 'Renamed later']);

        $this->actingAs($admin)
            ->get(route('apes-cic.recruitment.applications.show', $application))
            ->assertOk()
            ->assertSeeText('Why this role?')
            ->assertSeeText('I care about exotic animals.')
            ->assertDontSeeText('Renamed later');

        $this->actingAs($applicant)
            ->get(route('recruitment.applications.show', $application))
            ->assertOk()
            ->assertSeeText('I care about exotic animals.');
    }

    public function test_admin_can_reorder_and_remove_questions(): void
    {
        $admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();
        $role = $this->openRole($admin);
        $first = $role->questions()->create(['label' => 'First', 'answer_type' => 'short', 'required' => false, 'position' => 1]);
        $second = $role->questions()->create(['label' => 'Second', 'answer_type' => 'short', 'required' => false, 'position' => 2]);

        $this->actingAs($admin)
            ->post(route('apes-cic.recruitment.questions.move', [$role, $second]), ['direction' => 'up'])
            ->assertRedirect();
        $this->assertSame(['Second', 'First'], $role->questions()->pluck('label')->all());

        $this->actingAs($admin)
            ->delete(route('apes-cic.recruitment.questions.destroy', [$role, $first]))
            ->assertRedirect();
        $this->assertSame(['Second'], $role->questions()->pluck('label')->all());
    }

    public function test_people_who_cannot_edit_the_role_cannot_change_questions(): void
    {
        $admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();
        $role = $this->openRole($admin);
        $question = $role->questions()->create(['label' => 'Keep me', 'answer_type' => 'short', 'required' => false, 'position' => 1]);
        $applicant = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create();
        $otherRole = $this->openRole($admin);

        $this->actingAs($applicant)
            ->post(route('apes-cic.recruitment.questions.store', $role), ['label' => 'Nope', 'answer_type' => 'short'])
            ->assertForbidden();
        $this->actingAs($applicant)
            ->delete(route('apes-cic.recruitment.questions.destroy', [$role, $question]))
            ->assertForbidden();
        $this->actingAs($admin)
            ->delete(route('apes-cic.recruitment.questions.destroy', [$otherRole, $question]))
            ->assertNotFound();

        $this->assertTrue(RecruitmentRoleQuestion::query()->whereKey($question->id)->exists());
    }

    private function openRole(User $creator): RecruitmentRole
    {
        return RecruitmentRole::factory()->open()->category('volunteer')->create([
            'created_by' => $creator->id,
            'title' => 'Question builder role',
        ]);
    }
}
