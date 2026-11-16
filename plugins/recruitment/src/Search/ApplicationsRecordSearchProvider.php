<?php

namespace Plugins\Recruitment\Search;

use App\Core\Accounts\User;
use App\Core\Extensions\Plugins\PluginEnablement;
use App\Core\Search\RecordHit;
use App\Core\Search\RecordRanker;
use App\Core\Search\RecordSearchProvider;
use Plugins\Recruitment\Models\RecruitmentApplication;

/**
 * Recruitment application hits for workspace record search (#527).
 */
final class ApplicationsRecordSearchProvider implements RecordSearchProvider
{
    public function __construct(
        private readonly PluginEnablement $plugins,
    ) {}

    public function type(): string
    {
        return 'applications';
    }

    public function label(): string
    {
        return __('workspace.palette.records.applications');
    }

    public function search(User $actor, string $q, ?string $status = null): array
    {
        if (! $this->plugins->isEnabled('apes-cic', 'recruitment')) {
            return [];
        }

        if (! $actor->can(RecruitmentApplication::PERMISSION_PREFIX.'view-all')
            && ! $actor->can(RecruitmentApplication::PERMISSION_PREFIX.'review-applications')
            && ! $actor->can(RecruitmentApplication::PERMISSION_PREFIX.'view-own')) {
            return [];
        }

        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%';
        $numericId = ctype_digit(ltrim($q, '#')) ? (int) ltrim($q, '#') : null;

        $applications = RecruitmentApplication::query()
            ->visibleTo($actor)
            ->with(['user', 'recruitmentRole'])
            ->when(
                $status !== null && $status !== '',
                static fn ($query) => $query->where('status', $status),
            )
            ->where(static function ($query) use ($like, $numericId): void {
                $query
                    ->where('status', 'like', $like)
                    ->orWhereHas('user', static function ($user) use ($like): void {
                        $user->where('name', 'like', $like)->orWhere('email', 'like', $like);
                    })
                    ->orWhereHas('recruitmentRole', static function ($role) use ($like): void {
                        $role->where('title', 'like', $like);
                    });

                if ($numericId !== null) {
                    $query->orWhere('id', $numericId);
                }
            })
            ->latest('id')
            ->limit(25)
            ->get();

        $hits = [];

        foreach ($applications as $application) {
            $reference = '#'.$application->id;
            $roleTitle = $application->recruitmentRole?->title;
            $rank = RecordRanker::bestScore(
                $q,
                reference: $reference,
                partialFields: [
                    (string) $application->id,
                    $roleTitle,
                    $application->user?->name,
                    $application->user?->email,
                    $application->status,
                ],
                recordStatus: $application->status,
                statusFilter: $status,
            );

            if ($rank === null) {
                continue;
            }

            $hits[] = new RecordHit(
                type: $this->type(),
                id: (string) $application->id,
                label: (string) ($roleTitle ?: $reference),
                url: route('apes-cic.recruitment.applications.show', $application),
                rank: $rank,
                subtitle: $reference,
                status: (string) $application->status,
                reference: $reference,
            );
        }

        return $hits;
    }
}
