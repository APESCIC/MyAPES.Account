<?php

namespace Plugins\Consultations\View;

use App\Core\Accounts\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Plugins\Consultations\Models\PetCareConsultation;
use Plugins\Consultations\Support\PetCareTicketLookup;

/**
 * Adds "Create consultation" and links to linked consultations on Pet Care Clinic tickets (#540).
 */
final class TicketConsultationsComposer
{
    public function compose(View $view): void
    {
        $data = $view->getData();
        $ticket = $data['ticket'] ?? null;
        $user = auth()->user();

        if (! is_object($ticket)
            || ($ticket->sub_core_key ?? null) !== PetCareTicketLookup::SUB_CORE_KEY
            || ! $user instanceof User
            || ! Gate::forUser($user)->allows('viewAny', PetCareConsultation::class)) {
            return;
        }

        $actions = $data['ticketRelatedActions'] ?? [];
        $links = $data['ticketRelatedLinks'] ?? [];

        if (Gate::forUser($user)->allows('create', PetCareConsultation::class) && $user->can(PetCareTicketLookup::VIEW_ALL_TICKETS)) {
            $actions[] = [
                'label' => __('consultations::ui.ticket.create_consultation'),
                'url' => route('petcare.consultations.index', ['ticket' => $ticket->id]).'#create',
            ];
        }

        PetCareConsultation::query()
            ->forPetCareDomain()
            ->visibleTo($user)
            ->where('support_ticket_id', $ticket->id)
            ->orderBy('id')
            ->get(['id', 'subject'])
            ->each(function (PetCareConsultation $consultation) use (&$links): void {
                $links[] = [
                    'label' => __('consultations::ui.ticket.consultation_link', ['id' => $consultation->id, 'subject' => $consultation->subject]),
                    'url' => route('petcare.consultations.show', $consultation),
                ];
            });

        $view->with([
            'ticketRelatedActions' => $actions,
            'ticketRelatedLinks' => $links,
        ]);
    }
}
