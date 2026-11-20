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
