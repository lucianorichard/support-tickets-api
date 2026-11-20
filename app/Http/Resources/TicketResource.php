<?php

namespace App\Http\Resources;

use App\Enums\TicketStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Ticket */
class TicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'priority' => $this->priority,
            'status' => $this->status,
            // What *this* user may do next, so clients never hard-code the rules.
            'allowed_transitions' => $user->can('transition', $this->resource)
                ? array_map(fn (TicketStatus $s) => $s->value, $this->status->allowedTransitionsFor($user->role))
                : [],
            'requester' => new UserSummaryResource($this->whenLoaded('requester')),
            'assignee' => new UserSummaryResource($this->whenLoaded('assignee')),
            'comments_count' => $this->whenCounted('comments'),
            'history' => $this->whenLoaded('statusChanges', fn () => $this->statusChanges->map(fn ($change) => [
                'from' => $change->from_status,
                'to' => $change->to_status,
                'by' => new UserSummaryResource($change->user),
                'at' => $change->created_at,
            ])),
            'resolved_at' => $this->resolved_at,
            'closed_at' => $this->closed_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
