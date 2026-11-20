<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TechnicianController extends Controller
{
    /** Technicians and their open workload, to pick whom to hand a ticket to. */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->isTechnician(), 403, 'Only technicians can list technicians.');

        $technicians = User::query()
            ->select(['id', 'name'])
            ->where('role', Role::Technician)
            ->withCount(['assignedTickets as active_tickets' => fn ($q) => $q->whereIn('status', [
                TicketStatus::Open, TicketStatus::InProgress, TicketStatus::OnHold,
            ])])
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $technicians]);
    }
}
