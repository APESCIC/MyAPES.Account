<?php

namespace Plugins\Tickets\Search;

use App\Core\Accounts\User;
use App\Core\Extensions\Plugins\PluginEnablement;
use App\Core\Search\RecordHit;
use App\Core\Search\RecordRanker;
use App\Core\Search\RecordSearchProvider;
use Plugins\Tickets\Models\SupportTicket;
use Plugins\Tickets\TicketsArea;

/**
 * Ticket hits for workspace record search (#527).
 */
final class TicketsRecordSearchProvider implements RecordSearchProvider
{
    /** @var list<string> */
    private const MODULES = ['apes-cic', 'shelter-rescue', 'pet-care-clinic'];

    public function __construct(
        private readonly PluginEnablement $plugins,
    ) {}

    public function type(): string
    {
        return 'tickets';
    }

    public function label(): string
    {
        return __('workspace.palette.records.tickets');
    }

    public function search(User $actor, string $q, ?string $status = null): array
    {
        $hits = [];
        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%';
        $numericId = ctype_digit(ltrim($q, '#')) ? (int) ltrim($q, '#') : null;

        foreach (self::MODULES as $module) {
            if (! $this->plugins->isEnabled($module, 'tickets')) {
                continue;
            }

            $area = TicketsArea::forModule($module);
            if (! $actor->can($area->permission('view-all')) && ! $actor->can($area->permission('view-own'))) {
                continue;
            }

            $tickets = SupportTicket::query()
                ->forSubCore($module)
                ->visibleTo($actor, $module)
                ->with('user')
                ->when(
                    $status !== null && $status !== '',
                    static fn ($query) => $query->where('status', $status),
                )
                ->where(static function ($query) use ($like, $numericId): void {
                    $query
                        ->where('subject', 'like', $like)
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

            foreach ($tickets as $ticket) {
                $reference = '#'.$ticket->id;
                $rank = RecordRanker::bestScore(
                    $q,
                    reference: $reference,
                    partialFields: [
                        (string) $ticket->id,
                        $ticket->subject,
                        $ticket->user?->name,
                        $ticket->user?->email,
                        $ticket->status,
                    ],
                    recordStatus: $ticket->status,
                    statusFilter: $status,
                );

                if ($rank === null) {
                    continue;
                }

                $hits[] = new RecordHit(
                    type: $this->type(),
                    id: (string) $ticket->id,
                    label: (string) ($ticket->subject ?: $reference),
                    url: route($area->showRouteName(), $ticket),
                    rank: $rank,
                    subtitle: $reference.' · '.$module,
                    status: (string) $ticket->status,
                    reference: $reference,
                );
            }
        }

        return $hits;
    }
}
