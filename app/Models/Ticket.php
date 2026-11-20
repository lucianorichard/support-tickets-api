<?php

namespace App\Models;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// status, assignee, requester and timestamps change only through TicketWorkflow.
#[Fillable(['title', 'description', 'priority'])]
class Ticket extends Model
{
    /** @use HasFactory<TicketFactory> */
    use HasFactory;

    protected $attributes = [
        'priority' => 'medium',
        'status' => 'open',
    ];

    protected function casts(): array
    {
        return [
            'priority' => TicketPriority::class,
            'status' => TicketStatus::class,
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /** @return HasMany<TicketComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(TicketComment::class);
    }

    /** @return HasMany<TicketStatusChange, $this> */
    public function statusChanges(): HasMany
    {
        return $this->hasMany(TicketStatusChange::class);
    }

    /**
     * Row-level visibility, applied to every query that returns tickets:
     * requesters see their own tickets; technicians see the tickets assigned
     * to them plus the unassigned queue they can pick work from.
     *
     * @param  Builder<Ticket>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->isTechnician()) {
            $query->where(fn (Builder $q) => $q->where('assignee_id', $user->id)->orWhereNull('assignee_id'));

            return;
        }

        $query->where('requester_id', $user->id);
    }

    public function isAssignedTo(User $user): bool
    {
        return $this->assignee_id === $user->id;
    }
}
