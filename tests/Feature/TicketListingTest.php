<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TicketListingTest extends TestCase
{
    #[Test]
    public function filters_by_several_statuses_and_priorities(): void
    {
        $carol = $this->requester();
        $alice = $this->technician();
        $this->ticketFor($carol, $alice, TicketStatus::InProgress)->update(['priority' => 'high']);
        $this->ticketFor($carol, $alice, TicketStatus::OnHold)->update(['priority' => 'urgent']);
        $this->ticketFor($carol, $alice, TicketStatus::InProgress)->update(['priority' => 'low']);
        $this->ticketFor($carol);

        $this->as($carol)->getJson('/api/tickets?status=in_progress,on_hold&priority=high,urgent')
            ->assertOk()->assertJsonPath('meta.total', 2);
    }

    #[Test]
    public function technicians_filter_their_queue(): void
    {
        $alice = $this->technician();
        $requester = $this->requester();
        $this->ticketFor($requester, $alice);
        $this->ticketFor($requester);
        $this->ticketFor($requester);

        $this->as($alice);
        $this->getJson('/api/tickets?assignee=me')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/tickets?assignee=none')->assertJsonPath('meta.total', 2);
    }

    #[Test]
    public function search_is_case_insensitive_and_treats_wildcards_literally(): void
    {
        $carol = $this->requester();
        Ticket::factory()->for($carol, 'requester')->create(['title' => 'Disk 100% full on laptop']);
        Ticket::factory()->for($carol, 'requester')->create(['title' => 'Printer offline']);

        $this->as($carol);
        $this->getJson('/api/tickets?q=DISK')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/tickets?q='.urlencode('100%'))->assertJsonPath('meta.total', 1);
        $this->getJson('/api/tickets?q='.urlencode('%'))->assertJsonPath('meta.total', 1);
    }

    #[Test]
    public function sorts_by_business_priority_not_alphabetically(): void
    {
        $carol = $this->requester();
        foreach (['medium', 'urgent', 'low', 'high'] as $priority) {
            Ticket::factory()->for($carol, 'requester')->create(['priority' => $priority]);
        }

        $this->as($carol)->getJson('/api/tickets?sort=-priority')
            ->assertJsonPath('data.*.priority', ['urgent', 'high', 'medium', 'low']);
    }

    #[Test]
    public function paginates_with_metadata_and_links(): void
    {
        $carol = $this->requester();
        Ticket::factory()->count(7)->for($carol, 'requester')->create();

        $this->as($carol)->getJson('/api/tickets?per_page=3&page=3&status=open')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 7)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('links.prev', fn ($url) => str_contains($url, 'status=open') && str_contains($url, 'page=2'));
    }

    #[Test]
    public function invalid_filters_are_rejected(): void
    {
        $this->as($this->requester());

        $this->getJson('/api/tickets?per_page=101')->assertStatus(422)->assertJsonValidationErrors('per_page');
        $this->getJson('/api/tickets?status=open,deleted')->assertStatus(422)->assertJsonValidationErrors('status.1');
        $this->getJson('/api/tickets?sort=password')->assertStatus(422)->assertJsonValidationErrors('sort');
        $this->getJson('/api/tickets?assignee=42')->assertStatus(422)->assertJsonValidationErrors('assignee');
    }
}
