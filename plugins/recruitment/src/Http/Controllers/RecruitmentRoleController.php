<?php

namespace Plugins\Recruitment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Plugins\Recruitment\Models\RecruitmentRole;

class RecruitmentRoleController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        Gate::authorize('viewAny', RecruitmentRole::class);

        $roles = RecruitmentRole::query()
            ->visibleTo($user)
            ->with('creator')
            ->latest()
            ->paginate(20)
            ->fragment('list');

        return view('recruitment::staff.index', [
            'roles' => $roles,
            'canCreate' => $user->can(RecruitmentRole::PERMISSION_PREFIX.'create'),
            'categories' => RecruitmentRole::CATEGORIES,
        ]);
    }

    public function store(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        Gate::authorize(RecruitmentRole::PERMISSION_PREFIX.'create');

        $validated = $this->validatedRole($request);
        $validated['created_by'] = $request->user()->id;
        $validated['status'] = RecruitmentRole::STATUS_DRAFT;

        $role = RecruitmentRole::query()->create($validated);
        $auditLogger->record('apes-cic.recruitment_role.created', $request->user(), $role);

        return redirect()
            ->route('apes-cic.recruitment.show', $role)
            ->with('status', __('recruitment::staff.flash.recruitment_role_saved'));
    }

    public function show(RecruitmentRole $recruitmentRole): View
    {
        Gate::authorize('view', $recruitmentRole);
        $recruitmentRole->loadMissing('creator');
        $user = request()->user();

        return view('recruitment::staff.show', [
            'role' => $recruitmentRole,
            'canUpdate' => Gate::allows('update', $recruitmentRole),
            'canPublish' => Gate::allows('publish', $recruitmentRole),
            'canClose' => Gate::allows('close', $recruitmentRole),
            'categories' => RecruitmentRole::CATEGORIES,
            'revealCreator' => $user->can(RecruitmentRole::PERMISSION_PREFIX.'view-all'),
        ]);
    }

    public function update(
        Request $request,
        RecruitmentRole $recruitmentRole,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        Gate::authorize('update', $recruitmentRole);

        $validated = $this->validatedRole($request);
        $recruitmentRole->update($validated);
        $auditLogger->record(
            'apes-cic.recruitment_role.updated',
            $request->user(),
            $recruitmentRole,
        );

        return redirect()
            ->route('apes-cic.recruitment.show', $recruitmentRole)
            ->with('status', __('recruitment::staff.flash.recruitment_role_saved'));
    }

    public function publish(
        Request $request,
        RecruitmentRole $recruitmentRole,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        Gate::authorize('publish', $recruitmentRole);

        $recruitmentRole->update([
            'status' => RecruitmentRole::STATUS_OPEN,
            'published_at' => $recruitmentRole->published_at ?? now(),
            'closed_at' => null,
        ]);
        $auditLogger->record(
            'apes-cic.recruitment_role.published',
            $request->user(),
            $recruitmentRole,
        );

        return redirect()
            ->route('apes-cic.recruitment.show', $recruitmentRole)
            ->with('status', __('recruitment::staff.flash.recruitment_role_is_now_open'));
    }

    public function close(
        Request $request,
        RecruitmentRole $recruitmentRole,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        Gate::authorize('close', $recruitmentRole);

        $recruitmentRole->update([
            'status' => RecruitmentRole::STATUS_CLOSED,
            'closed_at' => now(),
        ]);
        $auditLogger->record(
            'apes-cic.recruitment_role.closed',
            $request->user(),
            $recruitmentRole,
        );

        return redirect()
            ->route('apes-cic.recruitment.show', $recruitmentRole)
            ->with('status', __('recruitment::staff.flash.recruitment_role_closed'));
    }

    /**
     * @return array{
     *     title: string,
     *     summary: ?string,
     *     description: string,
     *     category: string,
     *     location: ?string,
     *     commitment: ?string
     * }
     */
    private function validatedRole(Request $request): array
    {
        /** @var array{
         *     title: string,
         *     summary: ?string,
         *     description: string,
         *     category: string,
         *     location: ?string,
         *     commitment: ?string
         * } $validated
         */
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'category' => ['required', Rule::in(RecruitmentRole::CATEGORIES)],
            'location' => ['nullable', 'string', 'max:255'],
            'commitment' => ['nullable', 'string', 'max:255'],
        ]);

        return $validated;
    }
}
