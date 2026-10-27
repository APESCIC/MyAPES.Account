<?php

namespace Plugins\Recruitment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\ModuleSettingsService;
use Plugins\Recruitment\Models\RecruitmentApplication;
use Plugins\Recruitment\Models\RecruitmentRole;
use Plugins\Recruitment\RecruitmentAudience;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class RecruitmentBoardController extends Controller
{
    public function index(Request $request, ModuleSettingsService $moduleSettings): View
    {
        abort_unless($moduleSettings->recruitmentPublicBoardEnabled(), 404);
        $this->denyExcludedAudience($request);

        $category = $request->query('category');
        if (is_string($category) && $category !== '' && ! in_array($category, RecruitmentRole::CATEGORIES, true)) {
            abort(404);
        }

        $selectedCategory = is_string($category) && $category !== ''
            ? $category
            : null;

        $roles = RecruitmentRole::query()
            ->open()
            ->when(
                $selectedCategory !== null,
                static fn ($query) => $query->where('category', $selectedCategory),
            )
            ->latest('published_at')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('recruitment::public.index', [
            'roles' => $roles,
            'categories' => RecruitmentRole::CATEGORIES,
            'selectedCategory' => $selectedCategory,
            'categoryLabels' => $this->categoryLabels(),
            'publicApplyEnabled' => $moduleSettings->recruitmentPublicApplyEnabled(),
        ]);
    }

    public function show(Request $request, RecruitmentRole $recruitmentRole, ModuleSettingsService $moduleSettings): View
    {
        abort_unless($moduleSettings->recruitmentPublicBoardEnabled(), 404);
        $this->denyExcludedAudience($request);
        abort_unless($recruitmentRole->isOpen(), 404);

        $user = $request->user();
        $existingApplication = null;
        $canApply = false;
        $publicApplyEnabled = $moduleSettings->recruitmentPublicApplyEnabled();

        if ($user !== null && $publicApplyEnabled) {
            $existingApplication = RecruitmentApplication::query()
                ->where('recruitment_role_id', $recruitmentRole->id)
                ->where('user_id', $user->id)
                ->first();
            $canApply = $existingApplication === null
                && Gate::forUser($user)->allows('create', [RecruitmentApplication::class, $recruitmentRole]);
        } elseif ($user !== null) {
            $existingApplication = RecruitmentApplication::query()
                ->where('recruitment_role_id', $recruitmentRole->id)
                ->where('user_id', $user->id)
                ->first();
        }

        return view('recruitment::public.show', [
            'role' => $recruitmentRole,
            'categoryLabels' => $this->categoryLabels(),
            'existingApplication' => $existingApplication,
            'canApply' => $canApply,
            'publicApplyEnabled' => $publicApplyEnabled,
            'statusLabels' => [
                RecruitmentApplication::STATUS_SUBMITTED => 'Submitted',
                RecruitmentApplication::STATUS_UNDER_REVIEW => 'Under review',
                RecruitmentApplication::STATUS_SHORTLISTED => 'Shortlisted',
                RecruitmentApplication::STATUS_REJECTED => 'Rejected',
                RecruitmentApplication::STATUS_ACCEPTED => 'Accepted',
                RecruitmentApplication::STATUS_WITHDRAWN => 'Withdrawn',
                RecruitmentApplication::STATUS_CLOSED => 'Closed',
            ],
        ]);
    }

    private function denyExcludedAudience(Request $request): void
    {
        abort_if(app(RecruitmentAudience::class)->denies($request->user()), 403);
    }

    /**
     * @return array<string, string>
     */
    private function categoryLabels(): array
    {
        return [
            'staff' => 'Staff',
            'volunteer' => 'Volunteer',
            'student' => 'Student',
        ];
    }
}
