<?php

namespace App\Http\Controllers\Api;

use App\Enums\TicketPriority;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListTicketsRequest;
use App\Http\Requests\StoreTicketRequest;
use App\Http\Requests\UpdateTicketRequest;
use App\Http\Resources\TicketResource;
use App\Models\Ticket;
use App\Services\TicketWorkflow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TicketController extends Controller
{
    public function index(ListTicketsRequest $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $filters = $request->validated();

        $query = Ticket::query()
            ->visibleTo($user)
            ->with(['requester', 'assignee'])
            ->withCount(['comments' => fn (Builder $q) => $user->isTechnician() ? $q : $q->where('is_internal', false)])
            ->when($filters['status'] ?? null, fn (Builder $q, array $v) => $q->whereIn('status', $v))
            ->when($filters['priority'] ?? null, fn (Builder $q, array $v) => $q->whereIn('priority', $v))
            ->when(($filters['assignee'] ?? null) === 'me', fn (Builder $q) => $q->where('assignee_id', $user->id))
            ->when(($filters['assignee'] ?? null) === 'none', fn (Builder $q) => $q->whereNull('assignee_id'))
            // ILIKE is PostgreSQL's case-insensitive LIKE; escape the user's wildcards.
            ->when($filters['q'] ?? null, fn (Builder $q, string $term) => $q->where(
                'title', 'ilike', '%'.addcslashes($term, '%_\\').'%'
            ));

        $this->applySort($query, $filters['sort'] ?? '-created_at');

        return TicketResource::collection(
            $query->paginate($filters['per_page'] ?? 15)->withQueryString()
        );
    }

    public function store(StoreTicketRequest $request, TicketWorkflow $workflow): JsonResponse
    {
        $ticket = $workflow->open($request->user(), $request->validated());

        return (new TicketResource($ticket->load(['requester', 'assignee', 'statusChanges.user'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Ticket $ticket): TicketResource
    {
        return new TicketResource($ticket->load(['requester', 'assignee', 'statusChanges.user']));
    }

    public function update(UpdateTicketRequest $request, Ticket $ticket): TicketResource
    {
        $ticket->update($request->validated());

        return new TicketResource($ticket->load(['requester', 'assignee']));
    }

    /** @param  Builder<Ticket>  $query */
    private function applySort(Builder $query, string $sort): void
    {
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');

        if ($column === 'priority') {
            // Sort by business weight (urgent > high > ...), not alphabetically.
            $cases = collect(TicketPriority::cases())
                ->map(fn (TicketPriority $p) => "WHEN '{$p->value}' THEN {$p->rank()}")
                ->implode(' ');
            $query->orderByRaw("CASE priority {$cases} END {$direction}");
        } else {
            $query->orderBy($column, $direction);
        }

        // Tie-breaker: keeps pagination stable when values are equal.
        $query->orderBy('id', $direction);
    }
}
