<?php

namespace App\Http\Controllers\Api;

use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\TransitionTicketRequest;
use App\Http\Resources\TicketResource;
use App\Models\Ticket;
use App\Services\TicketWorkflow;

class TicketTransitionController extends Controller
{
    public function store(TransitionTicketRequest $request, Ticket $ticket, TicketWorkflow $workflow): TicketResource
    {
        $ticket = $workflow->transition($ticket, TicketStatus::from($request->validated('status')), $request->user());

        return new TicketResource($ticket->load(['requester', 'assignee', 'statusChanges.user']));
    }
}
