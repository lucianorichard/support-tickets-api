<?php

namespace Database\Seeders;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus as S;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketWorkflow;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Demo data. Every ticket is driven through TicketWorkflow, so statuses,
 * timestamps and the status history are consistent, exactly as if the
 * tickets had gone through the API. A fixed seed keeps it reproducible.
 *
 * All accounts use the password "password".
 */
class DatabaseSeeder extends Seeder
{
    private const ISSUES = [
        ['VPN disconnects every few minutes', 'Since this morning the VPN drops every 5 to 10 minutes and I lose my session.'],
        ['Cannot print to 3rd floor printer', 'Jobs stay in the queue forever. Other printers work fine.'],
        ['Laptop battery drains very fast', 'Fully charged laptop lasts about an hour, it used to last a whole morning.'],
        ['Request access to the finance shared drive', 'I joined the finance team and need read/write access to the shared drive.'],
        ['Outlook keeps asking for my password', 'The password prompt appears every time I open Outlook, even after entering it.'],
        ['Second monitor not detected', 'The docking station charges the laptop but the external monitor shows no signal.'],
        ['Wi-Fi very slow in meeting room B', 'Video calls freeze in meeting room B; the rest of the floor is fine.'],
        ['Install Python 3.12 on my workstation', 'I need Python 3.12 for a data analysis project.'],
        ['Phishing email received', 'I received an email asking me to confirm my payroll details. I did not click anything.'],
        ['Shared calendar not syncing on mobile', 'Team calendar events do not appear on my phone, only on the desktop.'],
        ['Keyboard missing several keys', 'The keyboard at desk 14 is missing the E and R keys.'],
        ['CRM very slow when loading reports', 'Monthly sales reports take more than two minutes to load.'],
        ['New employee onboarding: laptop and accounts', 'A new analyst starts on Monday and needs a laptop, email and VPN access.'],
        ['Blue screen after Windows update', 'The PC restarted with a blue screen twice after last night\'s update.'],
        ['Reset MFA for my account', 'I changed phones and can no longer receive the authentication codes.'],
        ['Projector in room A shows washed-out colours', 'Slides look pale and the colours are hard to read.'],
        ['Disk almost full on my laptop', 'I get "low disk space" warnings and cannot save new files.'],
        ['Cannot join Teams meetings from the browser', 'Joining from Chrome shows a blank screen.'],
        ['Request a second monitor', 'A second monitor would help with spreadsheet work.'],
        ['Scanner sends emails to the wrong address', 'Scans from the copier arrive at an old email address.'],
        ['Account locked after password change', 'I changed my password and now my account is locked.'],
        ['Mouse double-clicks on single click', 'The mouse registers a double click about half of the time.'],
        ['Need software license for diagram tool', 'I need a license for the diagram tool to document our processes.'],
        ['Website contact form returns error 500', 'Customers report an error when submitting the contact form.'],
        ['Headset microphone not working', 'Colleagues cannot hear me on calls with the company headset.'],
        ['Update payroll app on my phone', 'The app says my version is no longer supported.'],
        ['Slow login on Monday mornings', 'Logging in takes several minutes every Monday morning.'],
        ['Recover deleted folder from the shared drive', 'I accidentally deleted the "Q3 contracts" folder yesterday.'],
    ];

    /** Final shape of each demo ticket, applied in order through the workflow. */
    private const SCENARIOS = [
        'unassigned', 'unassigned', 'unassigned', 'unassigned', 'unassigned',
        'assigned', 'assigned', 'assigned',
        'in_progress', 'in_progress', 'in_progress', 'in_progress', 'in_progress',
        'on_hold', 'on_hold', 'on_hold',
        'resolved', 'resolved', 'resolved',
        'closed', 'closed', 'closed', 'closed', 'closed',
        'withdrawn', 'withdrawn',
        'reopened', 'reopened',
    ];

    public function run(TicketWorkflow $workflow): void
    {
        mt_srand(42);
        fake()->seed(42);

        $technicians = collect([
            ['Alice Tech', 'alice@example.com'],
            ['Bob Tech', 'bob@example.com'],
        ])->map(fn ($u) => User::factory()->technician()->create(['name' => $u[0], 'email' => $u[1]]));

        $requesters = collect([
            ['Carol Requester', 'carol@example.com'],
            ['Dave Requester', 'dave@example.com'],
            ['Erin Requester', 'erin@example.com'],
        ])->map(fn ($u) => User::factory()->requester()->create(['name' => $u[0], 'email' => $u[1]]));

        $start = Carbon::now()->subDays(30)->setTime(9, 0);
        $priorities = TicketPriority::cases();

        try {
            foreach (self::SCENARIOS as $i => $scenario) {
                // One new ticket roughly every day, at office hours.
                $clock = $start->copy()->addDays($i)->addMinutes(mt_rand(0, 8 * 60));
                Carbon::setTestNow($clock);

                $requester = $requesters[$i % $requesters->count()];
                $tech = $technicians[$i % $technicians->count()];
                [$title, $description] = self::ISSUES[$i];

                $ticket = $workflow->open($requester, [
                    'title' => $title,
                    'description' => $description,
                    'priority' => $priorities[mt_rand(0, count($priorities) - 1)]->value,
                ]);

                $this->play($workflow, $ticket, $scenario, $requester, $tech, $clock);
            }
        } finally {
            Carbon::setTestNow();
        }
    }

    private function play(TicketWorkflow $wf, Ticket $ticket, string $scenario, User $requester, User $tech, Carbon $clock): void
    {
        $at = function (int $hours) use ($clock) {
            Carbon::setTestNow($clock->addHours($hours));
        };

        if ($scenario === 'unassigned') {
            return;
        }
        if ($scenario === 'withdrawn') {
            $at(2);
            $this->comment($ticket, $requester, 'Never mind, it started working again. Closing this.');
            $wf->transition($ticket, S::Closed, $requester);

            return;
        }

        $at(1);
        $wf->assign($ticket, $tech, $tech);
        if ($scenario === 'assigned') {
            return;
        }

        $at(1);
        $wf->transition($ticket, S::InProgress, $tech);
        $this->comment($ticket, $tech, 'Looking into this now.');
        $this->comment($ticket, $tech, 'Checked the logs, nothing obvious yet.', internal: true);
        if ($scenario === 'in_progress') {
            return;
        }

        if ($scenario === 'on_hold') {
            $at(3);
            $this->comment($ticket, $tech, 'Waiting for a replacement part from the vendor.');
            $wf->transition($ticket, S::OnHold, $tech);

            return;
        }

        $at(4);
        $this->comment($ticket, $tech, 'Fixed. Please confirm it works on your side.');
        $wf->transition($ticket, S::Resolved, $tech);
        if ($scenario === 'resolved') {
            return;
        }

        $at(20);
        if ($scenario === 'reopened') {
            $this->comment($ticket, $requester, 'It happened again this morning, reopening.');
            $wf->transition($ticket, S::InProgress, $requester);

            return;
        }

        $this->comment($ticket, $requester, 'Confirmed, all good now. Thanks!');
        $wf->transition($ticket, S::Closed, $requester);
    }

    private function comment(Ticket $ticket, User $author, string $body, bool $internal = false): void
    {
        $comment = $ticket->comments()->make(['body' => $body, 'is_internal' => $internal]);
        $comment->author()->associate($author);
        $comment->save();
    }
}
