<?php

namespace Plugins\Recruitment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Plugins\Recruitment\Models\RecruitmentApplication;
use Plugins\Recruitment\Models\RecruitmentRole;

class RecruitmentApplicationController extends Controller
{
    /**
     * Staff review outcomes (applicant withdraw is a separate public path).
     *
     * @var list<string>
     */
    private const STAFF_REVIEW_STATUSES = [
        RecruitmentApplication::STATUS_UNDER_REVIEW,
        RecruitmentApplication::STATUS_SHORTLISTED,
        RecruitmentApplication::STATUS_REJECTED,
        RecruitmentApplication::STATUS_ACCEPTED,
        RecruitmentApplication::STATUS_CLOSED,
    ];

    public function index(Request $request): View
    {
        $user = $request->user();
        Gate::authorize('manageAny', RecruitmentApplication::class);

        $status = $request->query('status');
        if (is_string($status) && $status !== '' && ! in_array($status, RecruitmentApplication::STATUSES, true)) {
            abort(404);
        }

        $category = $request->query('category');
        if (is_string($category) && $category !== '' && ! in_array($category, RecruitmentRole::CATEGORIES, true)) {
            abort(404);
        }

        $roleId = $request->query('role');
        $selectedRoleId = is_numeric($roleId) ? (int) $roleId : null;

        $selectedStatus = is_string($status) && $status !== '' ? $status : null;
        $selectedCategory = is_string($category) && $category !== '' ? $category : null;

        $applications = RecruitmentApplication::query()
            ->visibleTo($user)
            ->with(['recruitmentRole', 'user'])
            ->when(
                $selectedStatus !== null,
                static fn ($query) => $query->where('status', $selectedStatus),
            )
            ->when(
                $selectedCategory !== null,
                static fn ($query) => $query->whereHas(
                    'recruitmentRole',
                    static fn ($roleQuery) => $roleQuery->where('category', $selectedCategory),
                ),
            )
            ->when(
                $selectedRoleId !== null,
                static fn ($query) => $query->where('recruitment_role_id', $selectedRoleId),
            )
            ->latest('submitted_at')
            ->latest('id')
            ->paginate(20)
            ->withQueryString()
            ->fragment('list');

        $roles = RecruitmentRole::query()
            ->orderBy('title')
            ->get(['id', 'title', 'category', 'status']);

        return view('recruitment::staff.applications.index', [
            'applications' => $applications,
            'roles' => $roles,
            'categories' => RecruitmentRole::CATEGORIES,
            'statuses' => RecruitmentApplication::STATUSES,
            'selectedStatus' => $selectedStatus,
            'selectedCategory' => $selectedCategory,
            'selectedRoleId' => $selectedRoleId,
            'statusLabels' => $this->statusLabels(),
            'canReview' => $user->can(RecruitmentApplication::PERMISSION_PREFIX.'review-applications'),
        ]);
    }

    public function show(RecruitmentApplication $recruitmentApplication): View
    {
        Gate::authorize('manageAny', RecruitmentApplication::class);
        $recruitmentApplication->loadMissing(['recruitmentRole', 'user']);

        $allowedTransitions = array_values(array_filter(
            RecruitmentApplication::ALLOWED_TRANSITIONS[$recruitmentApplication->status] ?? [],
            static fn (string $status): bool => in_array($status, self::STAFF_REVIEW_STATUSES, true),
        ));

        return view('recruitment::staff.applications.show', [
            'application' => $recruitmentApplication,
            'statusLabels' => $this->statusLabels(),
            'canReview' => Gate::allows('review', $recruitmentApplication),
            'allowedTransitions' => $allowedTransitions,
        ]);
    }

    public function update(
        Request $request,
        RecruitmentApplication $recruitmentApplication,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        Gate::authorize('review', $recruitmentApplication);

        $allowedTransitions = array_values(array_filter(
            RecruitmentApplication::ALLOWED_TRANSITIONS[$recruitmentApplication->status] ?? [],
            static fn (string $status): bool => in_array($status, self::STAFF_REVIEW_STATUSES, true),
        ));

        if ($allowedTransitions === []) {
            throw ValidationException::withMessages([
                'status' => __('recruitment::staff.validation.this_application_can_no_longer_be_reviewed'),
            ]);
        }

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in($allowedTransitions)],
            'staff_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $recruitmentApplication->transitionTo($validated['status']);
        $recruitmentApplication->forceFill([
            'staff_notes' => $validated['staff_notes'] ?? $recruitmentApplication->staff_notes,
        ])->save();

        $auditLogger->record(
            'apes-cic.recruitment_application.reviewed',
            $request->user(),
            $recruitmentApplication,
            ['status' => $validated['status']],
        );

        return redirect()
            ->route('apes-cic.recruitment.applications.show', $recruitmentApplication)
            ->with('status', __('recruitment::staff.flash.application_review_saved'));
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
