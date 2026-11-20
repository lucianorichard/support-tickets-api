<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TicketLifecycleTest extends TestCase
{
    /**
     * The specification, written independently of the code: every allowed
     * (from, to, actor) triple. Anything not listed must be rejected.
     */
    private const ALLOWED = [
        ['open', 'in_progress', 'technician'],
        ['open', 'closed', 'requester'],
        ['in_progress', 'on_hold', 'technician'],
        ['in_progress', 'resolved', 'technician'],
        ['on_hold', 'in_progress', 'technician'],
        ['resolved', 'closed', 'requester'],
        ['resolved', 'closed', 'technician'],
        ['resolved', 'in_progress', 'requester'],
    ];

    #[Test]
    public function full_lifecycle_records_every_step(): void
    {
        $carol = $this->requester();
        $alice = $this->technician();

        $id = $this->as($carol)->postJson('/api/tickets', [
            'title' => 'VPN keeps disconnecting',
            'description' => 'Drops every few minutes.',
            'priority' => 'high',
        ])->assertCreated()->json('data.id');

        $this->as($alice)->putJson("/api/tickets/{$id}/assignee", ['assignee_id' => $alice->id])->assertOk();
        $this->move($id, 'in_progress')->assertJsonPath('data.allowed_transitions', ['on_hold', 'resolved']);
        $this->move($id, 'on_hold');
        $this->move($id, 'in_progress');
        $this->move($id, 'resolved')->assertJsonPath('data.resolved_at', fn ($v) => $v !== null);

        $this->as($carol);
        $response = $this->move($id, 'closed')
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.closed_at', fn ($v) => $v !== null)
            ->assertJsonPath('data.allowed_transitions', []);

        $history = collect($response->json('data.history'));
        $this->assertSame(
            [[null, 'open'], ['open', 'in_progress'], ['in_progress', 'on_hold'], ['on_hold', 'in_progress'],
                ['in_progress', 'resolved'], ['resolved', 'closed']],
            $history->map(fn ($h) => [$h['from'], $h['to']])->all(),
        );
        $this->assertSame(
            [$carol->id, $alice->id, $alice->id, $alice->id, $alice->id, $carol->id],
            $history->pluck('by.id')->all(),
        );
    }

    #[Test]
    public function a_requester_can_reopen_a_resolved_ticket(): void
    {
        $carol = $this->requester();
        $ticket = $this->ticketFor($carol, $this->technician(), TicketStatus::Resolved);

        $this->as($carol)->move($ticket->id, 'in_progress')
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.resolved_at', null);
    }

    #[Test]
    public function a_requester_can_withdraw_an_open_ticket(): void
    {
        $carol = $this->requester();
        $ticket = $this->ticketFor($carol);

        $this->as($carol)->move($ticket->id, 'closed')->assertJsonPath('data.status', 'closed');
    }

    /** Every from x to x actor combination: 5 x 5 x 3 = 75 cases. */
    public static function allCombinations(): iterable
    {
        foreach (TicketStatus::cases() as $from) {
            foreach (TicketStatus::cases() as $to) {
                foreach (['requester', 'technician', 'other_technician'] as $actor) {
                    yield "{$from->value} -> {$to->value} by {$actor}" => [$from->value, $to->value, $actor];
                }
            }
        }
    }

    #[Test]
    #[DataProvider('allCombinations')]
    public function transitions_follow_the_specification(string $from, string $to, string $actor): void
    {
        $carol = $this->requester();
        $alice = $this->technician();
        $ticket = $this->ticketFor($carol, $alice, TicketStatus::from($from));
        $user = match ($actor) {
            'requester' => $carol,
            'technician' => $alice,
            // Assigned to Alice, so Bob cannot even see it.
            'other_technician' => $this->technician(),
        };

        $response = $this->as($user)->postJson("/api/tickets/{$ticket->id}/transitions", ['status' => $to]);

        if ($actor === 'other_technician') {
            $response->assertNotFound();
        } elseif (in_array([$from, $to, $actor], self::ALLOWED, true)) {
            $response->assertOk()->assertJsonPath('data.status', $to);
            $this->assertDatabaseHas('ticket_status_changes', [
                'ticket_id' => $ticket->id, 'from_status' => $from, 'to_status' => $to, 'user_id' => $user->id,
            ]);

            return;
        } else {
            $response->assertStatus(422)->assertJsonValidationErrors('status');
        }

        $this->assertSame($from, $ticket->fresh()->status->value);
        $this->assertDatabaseCount('ticket_status_changes', 0);
    }

    #[Test]
    public function the_error_explains_what_is_allowed(): void
    {
        $alice = $this->technician();
        $ticket = $this->ticketFor($this->requester(), $alice, TicketStatus::OnHold);

        $this->as($alice)->move($ticket->id, 'closed', 422)
            ->assertJsonPath('errors.status.0', 'A technician cannot move a ticket from "on_hold" to "closed". Allowed: in_progress.');
    }

    #[Test]
    public function an_unknown_status_is_rejected(): void
    {
        $alice = $this->technician();
        $ticket = $this->ticketFor($this->requester(), $alice);

        $this->as($alice)->move($ticket->id, 'done', 422)->assertJsonValidationErrors('status');
    }

    #[Test]
    public function closed_tickets_are_frozen(): void
    {
        $carol = $this->requester();
        $alice = $this->technician();
        $ticket = $this->ticketFor($carol, $alice, TicketStatus::Closed);

        $this->as($alice)->patchJson("/api/tickets/{$ticket->id}", ['priority' => 'low'])->assertForbidden();
        $this->putJson("/api/tickets/{$ticket->id}/assignee", ['assignee_id' => $this->technician()->id])
            ->assertStatus(422)->assertJsonValidationErrors('assignee_id');
        $this->postJson("/api/tickets/{$ticket->id}/comments", ['body' => 'late'])->assertForbidden();
        $this->as($carol)->postJson("/api/tickets/{$ticket->id}/comments", ['body' => 'late'])->assertForbidden();
    }

    #[Test]
    public function the_database_rejects_values_outside_the_enums(): void
    {
        $ticket = $this->ticketFor($this->requester());

        $this->expectException(QueryException::class);
        \DB::table('tickets')->where('id', $ticket->id)->update(['status' => 'deleted']);
    }

    private function move(int $id, string $status, int $expect = 200)
    {
        return $this->postJson("/api/tickets/{$id}/transitions", ['status' => $status])->assertStatus($expect);
    }
}
