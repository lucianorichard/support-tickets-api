<?php

namespace App\Services;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only place where a ticket's status or assignee changes.
 *
 * Each operation runs in a transaction and re-reads the ticket with a row lock
 * (SELECT ... FOR UPDATE), so two concurrent requests cannot both act on the
 * same stale state, e.g. two technicians claiming the same ticket.
 */
class TicketWorkflow
{
    /** @param  array{title: string, description: string, priority?: string}  $data */
    public function open(User $requester, array $data): Ticket
    {
        return DB::transaction(function () use ($requester, $data) {
            $ticket = new Ticket($data);
            $ticket->requester()->associate($requester);
            $ticket->status = TicketStatus::Open;
            $ticket->save();

            $this->record($ticket, null, TicketStatus::Open, $requester);

            return $ticket;
        });
    }

    public function transition(Ticket $ticket, TicketStatus $to, User $actor): Ticket
    {
        return DB::transaction(function () use ($ticket, $to, $actor) {
            $ticket = $this->lock($ticket);

            // The policy already checked this, but the assignee may have changed
            // between that check and this lock.
            if ($actor->isTechnician() && ! $ticket->isAssignedTo($actor)) {
                throw new AuthorizationException('Assign the ticket to yourself before changing its status.');
            }

            $from = $ticket->status;
            if (! $from->canTransitionTo($to, $actor->role)) {
                $allowed = array_map(fn (TicketStatus $s) => $s->value, $from->allowedTransitionsFor($actor->role));

                throw ValidationException::withMessages([
                    'status' => [sprintf(
                        'A %s cannot move a ticket from "%s" to "%s". Allowed: %s.',
                        $actor->role->value,
                        $from->value,
                        $to->value,
                        $allowed ? implode(', ', $allowed) : 'none',
                    )],
                ]);
            }

            $ticket->status = $to;
            match ($to) {
                TicketStatus::Resolved => $ticket->resolved_at = now(),
                TicketStatus::Closed => $ticket->closed_at = now(),
                // Reopened by the requester: the previous resolution did not hold.
                TicketStatus::InProgress => $ticket->resolved_at = null,
                default => null,
            };
            $ticket->save();

            $this->record($ticket, $from, $to, $actor);

            return $ticket;
        });
    }

    public function assign(Ticket $ticket, User $technician, User $actor): Ticket
    {
        return DB::transaction(function () use ($ticket, $technician, $actor) {
            $ticket = $this->lock($ticket);

            if ($ticket->assignee_id !== null && ! $ticket->isAssignedTo($actor)) {
                throw new AuthorizationException('This ticket is already assigned to another technician.');
            }
            if ($ticket->status->isFinal()) {
                throw ValidationException::withMessages(['assignee_id' => ['Closed tickets cannot be reassigned.']]);
            }
            if (! $technician->isTechnician()) {
                throw ValidationException::withMessages(['assignee_id' => ['Tickets can only be assigned to technicians.']]);
            }

            $ticket->assignee()->associate($technician);
            $ticket->save();

            return $ticket;
        });
    }

    private function lock(Ticket $ticket): Ticket
    {
        return Ticket::query()->lockForUpdate()->findOrFail($ticket->getKey());
    }

    private function record(Ticket $ticket, ?TicketStatus $from, TicketStatus $to, User $actor): void
    {
        $ticket->statusChanges()->create([
            'user_id' => $actor->id,
            'from_status' => $from,
            'to_status' => $to,
            'created_at' => now(),
        ]);
    }
}
