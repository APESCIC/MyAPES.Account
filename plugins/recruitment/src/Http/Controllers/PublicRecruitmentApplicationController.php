<?php

namespace Plugins\Recruitment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\ModuleSettingsService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Plugins\Recruitment\Models\RecruitmentApplication;
use Plugins\Recruitment\Models\RecruitmentRole;
use Plugins\Recruitment\Notifications\RecruitmentApplicationReceivedNotification;

class PublicRecruitmentApplicationController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewOwnAny', RecruitmentApplication::class);

        $applications = RecruitmentApplication::query()
            ->ownedBy($request->user())
            ->with('recruitmentRole')
            ->latest('submitted_at')
            ->latest('id')
            ->paginate(20);

        return view('recruitment::public.applications.index', [
            'applications' => $applications,
            'statusLabels' => $this->statusLabels(),
        ]);
    }

    public function show(RecruitmentApplication $recruitmentApplication): View
    {
        Gate::authorize('viewOwn', $recruitmentApplication);
        $recruitmentApplication->loadMissing('recruitmentRole');

        return view('recruitment::public.applications.show', [
            'application' => $recruitmentApplication,
            'statusLabels' => $this->statusLabels(),
            'canWithdraw' => Gate::allows('withdraw', $recruitmentApplication),
        ]);
    }

    public function store(
        Request $request,
        RecruitmentRole $recruitmentRole,
        AuditLogger $auditLogger,
        ModuleSettingsService $moduleSettings,
    ): RedirectResponse {
        abort_unless($moduleSettings->recruitmentPublicApplyEnabled(), 404);
        abort_unless($recruitmentRole->isOpen(), 404);
        Gate::authorize('create', [RecruitmentApplication::class, $recruitmentRole]);

        $existing = RecruitmentApplication::query()
            ->where('recruitment_role_id', $recruitmentRole->id)
            ->where('user_id', $request->user()->id)
            ->first();

        if ($existing !== null) {
            throw ValidationException::withMessages([
                'statement' => __('recruitment::public.validation.you_have_already_applied_for_this_role_each_person_may_'),
            ]);
        }

        $validated = $request->validate([
            'statement' => ['required', 'string', 'max:5000'],
        ]);

        $application = RecruitmentApplication::submitAgainstOpenRole(
            $recruitmentRole,
            $request->user(),
            ['statement' => $validated['statement']],
        );

        $auditLogger->record(
            'apes-cic.recruitment_application.submitted',
            $request->user(),
            $application,
        );

        $request->user()->notify(new RecruitmentApplicationReceivedNotification($application));

        return redirect()
            ->route('recruitment.applications.show', $application)
            ->with('status', __('recruitment::public.PublicRecruitmentApplicationController.your_application_has_been_submitted'));
    }

    public function withdraw(
        Request $request,
        RecruitmentApplication $recruitmentApplication,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        Gate::authorize('withdraw', $recruitmentApplication);

        $recruitmentApplication->transitionTo(RecruitmentApplication::STATUS_WITHDRAWN);
        $auditLogger->record(
            'apes-cic.recruitment_application.withdrawn',
            $request->user(),
            $recruitmentApplication,
        );

        return redirect()
            ->route('recruitment.applications.show', $recruitmentApplication)
            ->with('status', __('recruitment::public.PublicRecruitmentApplicationController.your_application_has_been_withdrawn'));
    }

    /**
     * @return array<string, string>
     */
    private function statusLabels(): array
    {
        return [
            RecruitmentApplication::STATUS_SUBMITTED => 'Submitted',
            RecruitmentApplication::STATUS_UNDER_REVIEW => 'Under review',
            RecruitmentApplication::STATUS_SHORTLISTED => 'Shortlisted',
            RecruitmentApplication::STATUS_REJECTED => 'Rejected',
            RecruitmentApplication::STATUS_ACCEPTED => 'Accepted',
            RecruitmentApplication::STATUS_WITHDRAWN => 'Withdrawn',
            RecruitmentApplication::STATUS_CLOSED => 'Closed',
        ];
    }
}
