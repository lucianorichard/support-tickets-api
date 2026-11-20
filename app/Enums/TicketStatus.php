<?php

namespace App\Enums;

/**
 * Ticket lifecycle. The transition table is the single source of truth for
 * which status changes exist and which role may perform each one.
 *
 *   open ──────────► in_progress ◄──────► on_hold
 *    │ (requester     │      ▲
 *    │  withdraws)    ▼      │ (requester reopens)
 *    │             resolved ─┘
 *    ▼                │
 *  closed ◄───────────┘ (requester confirms, or technician closes)
 */
enum TicketStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case OnHold = 'on_hold';
    case Resolved = 'resolved';
    case Closed = 'closed';

    /** @var array<string, array<string, list<Role>>> from => [to => roles] */
    private const TRANSITIONS = [
        'open' => [
            'in_progress' => [Role::Technician],
            'closed' => [Role::Requester],
        ],
        'in_progress' => [
            'on_hold' => [Role::Technician],
            'resolved' => [Role::Technician],
        ],
        'on_hold' => [
            'in_progress' => [Role::Technician],
        ],
        'resolved' => [
            'closed' => [Role::Requester, Role::Technician],
            'in_progress' => [Role::Requester],
        ],
        'closed' => [],
    ];

    /** @return list<self> statuses this role may move a ticket to from here */
    public function allowedTransitionsFor(Role $role): array
    {
        $targets = [];
        foreach (self::TRANSITIONS[$this->value] as $to => $roles) {
            if (in_array($role, $roles, true)) {
                $targets[] = self::from($to);
            }
        }

        return $targets;
    }

    public function canTransitionTo(self $to, Role $role): bool
    {
        return in_array($to, $this->allowedTransitionsFor($role), true);
    }

    public function isFinal(): bool
    {
        return $this === self::Closed;
    }
}
