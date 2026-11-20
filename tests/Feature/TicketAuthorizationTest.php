<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use App\Models\TicketComment;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TicketAuthorizationTest extends TestCase
{
    // ---- Visibility -------------------------------------------------------

    #[Test]
    public function a_requester_only_lists_their_own_tickets(): void
    {
        $carol = $this->requester();
        $mine = $this->ticketFor($carol);
        $this->ticketFor($this->requester());

        $this->as($carol)->getJson('/api/tickets')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $mine->id);
    }

    #[Test]
    public function another_requesters_ticket_is_a_404_on_every_endpoint(): void
    {
        $theirs = $this->ticketFor($this->requester());
        $this->as($this->requester());

        $this->getJson("/api/tickets/{$theirs->id}")->assertNotFound()->assertExactJson(['message' => 'Not found.']);
        $this->patchJson("/api/tickets/{$theirs->id}", ['title' => 'Hijacked title'])->assertNotFound();
        $this->postJson("/api/tickets/{$theirs->id}/transitions", ['status' => 'closed'])->assertNotFound();
        $this->getJson("/api/tickets/{$theirs->id}/comments")->assertNotFound();
        $this->postJson("/api/tickets/{$theirs->id}/comments", ['body' => 'hi'])->assertNotFound();
    }

    #[Test]
    public function a_technician_sees_assigned_and_unassigned_tickets_but_not_a_colleagues(): void
    {
        $alice = $this->technician();
        $bob = $this->technician();
        $requester = $this->requester();
        $assigned = $this->ticketFor($requester, $alice);
        $queue = $this->ticketFor($requester);
        $colleagues = $this->ticketFor($requester, $bob);

        $ids = collect($this->as($alice)->getJson('/api/tickets')->assertOk()->json('data'))->pluck('id');

        $this->assertEqualsCanonicalizing([$assigned->id, $queue->id], $ids->all());
        $this->getJson("/api/tickets/{$colleagues->id}")->assertNotFound();
    }

    #[Test]
    public function non_numeric_ticket_ids_are_404(): void
    {
        $this->as($this->requester())->getJson('/api/tickets/abc')->assertNotFound();
    }

    // ---- Creating and editing --------------------------------------------

    #[Test]
    public function only_requesters_open_tickets(): void
    {
        $payload = ['title' => 'Printer on fire', 'description' => 'Smoke everywhere'];

        $this->as($this->technician())->postJson('/api/tickets', $payload)
            ->assertForbidden()
            ->assertJsonPath('message', 'Only requesters can open tickets.');

        $this->as($this->requester())->postJson('/api/tickets', $payload)
            ->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.priority', 'medium');
    }

    #[Test]
    public function forbidden_users_get_403_before_any_validation(): void
    {
        $this->as($this->technician())->postJson('/api/tickets', [])->assertForbidden();
    }

    #[Test]
    public function requesters_edit_text_and_technicians_edit_priority(): void
    {
        $carol = $this->requester();
        $alice = $this->technician();
        $ticket = $this->ticketFor($carol, $alice);

        $this->as($carol)->patchJson("/api/tickets/{$ticket->id}", ['title' => 'Better title here'])
            ->assertOk()->assertJsonPath('data.title', 'Better title here');
        $this->as($carol)->patchJson("/api/tickets/{$ticket->id}", ['priority' => 'urgent'])
            ->assertStatus(422)->assertJsonValidationErrors('priority');

        $this->as($alice)->patchJson("/api/tickets/{$ticket->id}", ['priority' => 'urgent'])
            ->assertOk()->assertJsonPath('data.priority', 'urgent');
        $this->as($alice)->patchJson("/api/tickets/{$ticket->id}", ['priority' => 'high', 'title' => 'Mine now'])
            ->assertStatus(422)->assertJsonValidationErrors('title');
    }

    #[Test]
    public function requesters_cannot_edit_once_work_has_started(): void
    {
        $carol = $this->requester();
        $ticket = $this->ticketFor($carol, $this->technician(), TicketStatus::InProgress);

        $this->as($carol)->patchJson("/api/tickets/{$ticket->id}", ['title' => 'Changed my mind'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Tickets can only be edited while they are open.');
    }

    #[Test]
    public function an_unassigned_technician_cannot_update_or_transition(): void
    {
        $ticket = $this->ticketFor($this->requester());
        $this->as($this->technician());

        $this->patchJson("/api/tickets/{$ticket->id}", ['priority' => 'high'])->assertForbidden();
        $this->postJson("/api/tickets/{$ticket->id}/transitions", ['status' => 'in_progress'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Assign the ticket to yourself before changing its status.');
    }

    // ---- Assignment -------------------------------------------------------

    #[Test]
    public function a_technician_claims_an_unassigned_ticket_and_can_hand_it_over(): void
    {
        $alice = $this->technician();
        $bob = $this->technician();
        $ticket = $this->ticketFor($this->requester());

        $this->as($alice)->putJson("/api/tickets/{$ticket->id}/assignee", ['assignee_id' => $alice->id])
            ->assertOk()->assertJsonPath('data.assignee.id', $alice->id);

        $this->putJson("/api/tickets/{$ticket->id}/assignee", ['assignee_id' => $bob->id])
            ->assertOk()->assertJsonPath('data.assignee.id', $bob->id);

        // Handed over: Alice no longer sees it.
        $this->getJson("/api/tickets/{$ticket->id}")->assertNotFound();
    }

    #[Test]
    public function requesters_cannot_assign(): void
    {
        $carol = $this->requester();
        $ticket = $this->ticketFor($carol);

        $this->as($carol)->putJson("/api/tickets/{$ticket->id}/assignee", ['assignee_id' => $this->technician()->id])
            ->assertForbidden();
    }

    #[Test]
    public function tickets_can_only_be_assigned_to_technicians(): void
    {
        $alice = $this->technician();
        $ticket = $this->ticketFor($this->requester());

        $this->as($alice)->putJson("/api/tickets/{$ticket->id}/assignee", ['assignee_id' => $ticket->requester_id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('assignee_id');
    }

    #[Test]
    public function only_technicians_list_technicians(): void
    {
        $this->as($this->requester())->getJson('/api/technicians')->assertForbidden();
        $this->as($this->technician())->getJson('/api/technicians')->assertOk()->assertJsonStructure(['data' => [['id', 'name', 'active_tickets']]]);
    }

    // ---- Comments ---------------------------------------------------------

    #[Test]
    public function internal_notes_are_hidden_from_requesters(): void
    {
        $carol = $this->requester();
        $alice = $this->technician();
        $ticket = $this->ticketFor($carol, $alice);
        TicketComment::factory()->for($ticket)->for($alice, 'author')->create(['body' => 'Public reply']);
        TicketComment::factory()->for($ticket)->for($alice, 'author')->internal()->create(['body' => 'Internal note']);

        $this->as($carol)->getJson("/api/tickets/{$ticket->id}/comments")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.body', 'Public reply');
        $this->getJson('/api/tickets')->assertJsonPath('data.0.comments_count', 1);

        $this->as($alice)->getJson("/api/tickets/{$ticket->id}/comments")->assertJsonCount(2, 'data');
        $this->getJson('/api/tickets')->assertJsonPath('data.0.comments_count', 2);
    }

    #[Test]
    public function requesters_cannot_write_internal_notes(): void
    {
        $carol = $this->requester();
        $ticket = $this->ticketFor($carol);

        $this->as($carol)->postJson("/api/tickets/{$ticket->id}/comments", ['body' => 'Hi', 'is_internal' => true])
            ->assertStatus(422)->assertJsonValidationErrors('is_internal');
        $this->postJson("/api/tickets/{$ticket->id}/comments", ['body' => 'Hi'])
            ->assertCreated()->assertJsonPath('data.author.role', 'requester');
    }

    #[Test]
    public function technicians_write_internal_notes(): void
    {
        $alice = $this->technician();
        $ticket = $this->ticketFor($this->requester(), $alice);

        $this->as($alice)->postJson("/api/tickets/{$ticket->id}/comments", ['body' => 'Check RAID', 'is_internal' => true])
            ->assertCreated()->assertJsonPath('data.is_internal', true);
    }
}
