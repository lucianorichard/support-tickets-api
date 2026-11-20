<?php

namespace Database\Factories;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Builds tickets directly in a given state (for tests). The demo seeder goes
 * through TicketWorkflow instead, so its history is realistic.
 *
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    public function definition(): array
    {
        return [
            'requester_id' => User::factory()->requester(),
            'assignee_id' => null,
            'title' => rtrim(fake()->sentence(6), '.'),
            'description' => fake()->paragraph(),
            'priority' => fake()->randomElement(TicketPriority::cases()),
            'status' => TicketStatus::Open,
        ];
    }

    public function assignedTo(User $technician): static
    {
        return $this->state(['assignee_id' => $technician->id]);
    }

    public function status(TicketStatus $status): static
    {
        return $this->state(fn () => [
            'status' => $status,
            'resolved_at' => in_array($status, [TicketStatus::Resolved, TicketStatus::Closed], true) ? now() : null,
            'closed_at' => $status === TicketStatus::Closed ? now() : null,
        ]);
    }
}
