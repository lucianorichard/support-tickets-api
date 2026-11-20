<?php

namespace Tests;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * Safety net: RefreshDatabase drops every table. Refuse to run unless the
     * connection points at a dedicated test database.
     */
    protected function beforeRefreshingDatabase(): void
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');

        if (! str_ends_with($database, '_test')) {
            throw new \RuntimeException("Refusing to run tests against database \"{$database}\": its name must end in _test.");
        }
    }

    protected function requester(): User
    {
        return User::factory()->requester()->create();
    }

    protected function technician(): User
    {
        return User::factory()->technician()->create();
    }

    protected function as(User $user): static
    {
        Sanctum::actingAs($user);

        return $this;
    }

    protected function ticketFor(User $requester, ?User $assignee = null, TicketStatus $status = TicketStatus::Open): Ticket
    {
        $factory = Ticket::factory()->for($requester, 'requester')->status($status);

        return ($assignee ? $factory->assignedTo($assignee) : $factory)->create();
    }
}
