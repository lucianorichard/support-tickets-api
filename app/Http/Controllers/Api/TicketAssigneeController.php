<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssignTicketRequest;
use App\Http\Resources\TicketResource;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketWorkflow;

class TicketAssigneeController extends Controller
{
    public function update(AssignTicketRequest $request, Ticket $ticket, TicketWorkflow $workflow): TicketResource
    {
        $technician = User::findOrFail($request->validated('assignee_id'));
        $ticket = $workflow->assign($ticket, $technician, $request->user());

        return new TicketResource($ticket->load(['requester', 'assignee']));
    }
}
