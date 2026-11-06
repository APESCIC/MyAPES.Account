<?php

namespace Plugins\Consultations\Support;

use App\Core\Accounts\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * Reads Pet Care Clinic tickets without depending on the Tickets plugin (#540).
 */
final class PetCareTicketLookup
{
    public const SUB_CORE_KEY = 'pet-care-clinic';

    public const VIEW_ALL_TICKETS = 'pet-care-clinic.tickets.view-all';

    /**
     * @return object{id: int, subject: string, user_id: int}|null
     */
    public function findForStaff(User $user, mixed $ticketId): ?object
    {
        if (filter_var($ticketId, FILTER_VALIDATE_INT) === false
            || ! $user->can(self::VIEW_ALL_TICKETS)
            || ! Schema::hasTable('support_tickets')) {
            return null;
        }

        return DB::table('support_tickets')
            ->where('id', (int) $ticketId)
            ->where('sub_core_key', self::SUB_CORE_KEY)
            ->first(['id', 'subject', 'user_id']);
    }

    public function showUrl(User $user, int $ticketId, int $ownerId): ?string
    {
        if (! Route::has('petcare.tickets.show')
            || ($user->id !== $ownerId && ! $user->can(self::VIEW_ALL_TICKETS))) {
            return null;
        }

        return route('petcare.tickets.show', $ticketId);
    }
}
