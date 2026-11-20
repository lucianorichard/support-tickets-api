<?php

namespace App\Policies;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Who may do what with a ticket.
 *
 * Visibility is enforced earlier, when the ticket is resolved from the URL
 * (see Ticket::scopeVisibleTo): a ticket you cannot see is a 404, not a 403,
 * so the API never confirms that someone else's ticket exists. The checks
 * below decide what you may do with a ticket you can see.
 */
class TicketPolicy
{
    public function view(User $user, Ticket $ticket): bool
    {
        if ($user->isTechnician()) {
            return $ticket->assignee_id === null || $ticket->isAssignedTo($user);
        }

        return $ticket->requester_id === $user->id;
    }

    public function create(User $user): Response
    {
        return $user->isRequester()
            ? Response::allow()
            : Response::deny('Only requesters can open tickets.');
    }

    /** Requesters edit the text while the ticket is open; the assigned technician triages the priority. */
    public function update(User $user, Ticket $ticket): Response
    {
        if ($user->isRequester()) {
            if ($ticket->requester_id !== $user->id) {
                return Response::deny('You can only edit your own tickets.');
            }

            return $ticket->status === TicketStatus::Open
                ? Response::allow()
                : Response::deny('Tickets can only be edited while they are open.');
        }

        if (! $ticket->isAssignedTo($user)) {
            return Response::deny('Only the assigned technician can update this ticket.');
        }

        return $ticket->status->isFinal()
            ? Response::deny('Closed tickets cannot be changed.')
            : Response::allow();
    }

    /** Which target statuses are allowed is decided by TicketStatus; this only checks who may try. */
    public function transition(User $user, Ticket $ticket): Response
    {
        if ($user->isRequester()) {
            return $ticket->requester_id === $user->id
                ? Response::allow()
                : Response::deny('You can only change the status of your own tickets.');
        }

        return $ticket->isAssignedTo($user)
            ? Response::allow()
            : Response::deny('Assign the ticket to yourself before changing its status.');
    }

    /** Technicians claim unassigned tickets or hand over their own; they cannot take a colleague's. */
    public function assign(User $user, Ticket $ticket): Response
    {
        if (! $user->isTechnician()) {
            return Response::deny('Only technicians can assign tickets.');
        }

        return $ticket->assignee_id === null || $ticket->isAssignedTo($user)
            ? Response::allow()
            : Response::deny('This ticket is already assigned to another technician.');
    }

    public function comment(User $user, Ticket $ticket): Response
    {
        if (! $this->view($user, $ticket)) {
            return Response::deny('You cannot comment on this ticket.');
        }

        return $ticket->status->isFinal()
            ? Response::deny('Closed tickets do not accept new comments.')
            : Response::allow();
    }
}
