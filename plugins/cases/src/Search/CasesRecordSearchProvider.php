<?php

namespace Plugins\Cases\Search;

use App\Core\Accounts\User;
use App\Core\Extensions\Plugins\PluginEnablement;
use App\Core\Search\RecordHit;
use App\Core\Search\RecordRanker;
use App\Core\Search\RecordSearchProvider;
use Plugins\Cases\CasesArea;
use Plugins\Cases\Models\ShelterCase;

/**
 * Case hits for workspace record search (#527).
 */
final class CasesRecordSearchProvider implements RecordSearchProvider
{
    /** @var list<string> */
    private const MODULES = ['apes-cic', 'shelter-rescue'];

    public function __construct(
        private readonly PluginEnablement $plugins,
    ) {}

    public function type(): string
    {
        return 'cases';
    }

    public function label(): string
    {
        return __('workspace.palette.records.cases');
    }

    public function search(User $actor, string $q, ?string $status = null): array
    {
        $hits = [];
        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%';
        $numericId = ctype_digit(ltrim($q, '#')) ? (int) ltrim($q, '#') : null;

        foreach (self::MODULES as $module) {
            if (! $this->plugins->isEnabled($module, 'cases')) {
                continue;
            }

            $area = CasesArea::forModule($module);
            if (! $actor->can($area->permission('view-all')) && ! $actor->can($area->permission('view-own'))) {
                continue;
            }

            $cases = ShelterCase::query()
                ->forSubCore($module)
                ->visibleTo($actor, $module)
                ->with('user')
                ->when(
                    $status !== null && $status !== '',
                    static fn ($query) => $query->where('status', $status),
                )
                ->where(static function ($query) use ($like, $numericId): void {
                    $query
                        ->where('title', 'like', $like)
                        ->orWhere('status', 'like', $like)
                        ->orWhereHas('user', static function ($user) use ($like): void {
                            $user->where('name', 'like', $like)->orWhere('email', 'like', $like);
                        });

                    if ($numericId !== null) {
                        $query->orWhere('id', $numericId);
                    }
                })
                ->latest('id')
                ->limit(25)
                ->get();

            foreach ($cases as $case) {
                $reference = '#'.$case->id;
                $rank = RecordRanker::bestScore(
                    $q,
                    reference: $reference,
                    partialFields: [
                        (string) $case->id,
                        $case->title,
                        $case->user?->name,
                        $case->user?->email,
                        $case->status,
                    ],
                    recordStatus: $case->status,
                    statusFilter: $status,
                );

                if ($rank === null) {
                    continue;
                }

                $hits[] = new RecordHit(
                    type: $this->type(),
                    id: (string) $case->id,
                    label: (string) ($case->title ?: $reference),
                    url: route($area->showRouteName(), $case),
                    rank: $rank,
                    subtitle: $reference.' · '.$module,
                    status: (string) $case->status,
                    reference: $reference,
                );
            }
        }

        return $hits;
    }
}
