<?php

namespace Plugins\Recruitment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Plugins\Recruitment\Models\RecruitmentRole;
use Plugins\Recruitment\Models\RecruitmentRoleQuestion;

/**
 * Application-form builder (#469): staff who can edit a role manage the
 * extra questions applicants answer.
 */
class RecruitmentRoleQuestionController extends Controller
{
    public function store(Request $request, RecruitmentRole $recruitmentRole, AuditLogger $auditLogger): RedirectResponse
    {
        Gate::authorize('update', $recruitmentRole);

        $validated = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'help_text' => ['nullable', 'string', 'max:500'],
            'answer_type' => ['required', Rule::in(RecruitmentRoleQuestion::TYPES)],
            'required' => ['nullable', 'boolean'],
        ]);

        $question = $recruitmentRole->questions()->create([
            'label' => $validated['label'],
            'help_text' => $validated['help_text'] ?? null,
            'answer_type' => $validated['answer_type'],
            'required' => (bool) ($validated['required'] ?? false),
            'position' => (int) $recruitmentRole->questions()->max('position') + 1,
        ]);

        $auditLogger->record('apes-cic.recruitment_role.question_added', $request->user(), $recruitmentRole, [
            'question_id' => $question->id,
        ]);

        return $this->back($recruitmentRole, 'recruitment::staff.flash.question_added');
    }

    public function move(
        Request $request,
        RecruitmentRole $recruitmentRole,
        RecruitmentRoleQuestion $question,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        Gate::authorize('update', $recruitmentRole);
        $this->assertBelongs($recruitmentRole, $question);

        $direction = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]])['direction'];
        $ordered = $recruitmentRole->questions()->get()->values();
        $index = $ordered->search(static fn (RecruitmentRoleQuestion $item): bool => $item->is($question));
        $swapWith = $direction === 'up' ? $index - 1 : $index + 1;

        if ($index !== false && isset($ordered[$swapWith])) {
            $items = $ordered->all();
            [$items[$index], $items[$swapWith]] = [$items[$swapWith], $items[$index]];

            DB::transaction(static function () use ($items): void {
                foreach ($items as $position => $item) {
                    $item->update(['position' => $position + 1]);
                }
            });

            $auditLogger->record('apes-cic.recruitment_role.question_moved', $request->user(), $recruitmentRole, [
                'question_id' => $question->id,
                'direction' => $direction,
            ]);
        }

        return $this->back($recruitmentRole, 'recruitment::staff.flash.question_moved');
    }

    public function destroy(
        Request $request,
        RecruitmentRole $recruitmentRole,
        RecruitmentRoleQuestion $question,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        Gate::authorize('update', $recruitmentRole);
        $this->assertBelongs($recruitmentRole, $question);

        $question->delete();
        $auditLogger->record('apes-cic.recruitment_role.question_removed', $request->user(), $recruitmentRole, [
            'question_id' => $question->id,
        ]);

        return $this->back($recruitmentRole, 'recruitment::staff.flash.question_removed');
    }

    private function assertBelongs(RecruitmentRole $role, RecruitmentRoleQuestion $question): void
    {
        abort_unless($question->recruitment_role_id === $role->id, 404);
    }

    private function back(RecruitmentRole $role, string $statusKey): RedirectResponse
    {
        return redirect()
            ->to(route('apes-cic.recruitment.show', $role).'#questions')
            ->with('status', __($statusKey));
    }
}
