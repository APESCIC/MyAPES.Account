<?php

namespace App\Console\Commands;

use App\Core\Accounts\User;
use App\Core\Mail\Inbound\InboundEmail;
use App\Core\Mail\Inbound\InboundMailPoller;
use App\Core\Mail\Inbound\InboundMailSettings;
use App\Core\Mail\Inbound\Local\ClientReplyBuilder;
use App\Core\Mail\Inbound\Local\LocalReplies;
use App\Core\Mail\Inbound\MailboxUnavailable;
use App\Jobs\ProcessInboundEmail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Plugins\Tickets\Models\SupportTicket;
use Plugins\Tickets\Models\SupportTicketMessage;
use Plugins\Tickets\Notifications\TicketUpdatedNotification;
use RuntimeException;
use Throwable;

/**
 * End-to-end reply loop on a local install (#818): ticket email → client
 * reply in Herd/Mailpit → poll → posted on the right ticket.
 */
class InboundLoopCheck extends Command
{
    protected $signature = 'tickets:inbound-loop-check
        {--ticket= : Optional ticket id to use (default: newest open APES CIC ticket with an owner)}';

    protected $description = 'Prove the full inbound reply loop locally (Herd or Mailpit; APP_ENV=local only)';

    public function handle(
        InboundMailSettings $settings,
        LocalReplies $replies,
        InboundMailPoller $poller,
    ): int {
        if (! $this->laravel->environment('local')) {
            $this->components->error('The inbound loop check is for local testing only (APP_ENV=local).');

            return self::FAILURE;
        }

        if (! $settings->usesLocalCatcher()) {
            $this->components->error('Set INBOUND_MAIL_DRIVER=herd or mailpit for the local loop check.');

            return self::FAILURE;
        }

        $started = hrtime(true);

        try {
            $ticket = $this->resolveTicket();
            $owner = $ticket->user;
            if (! $owner instanceof User) {
                throw new RuntimeException('The chosen ticket has no owner account.');
            }

            $this->step('Send ticket update email to the owner', function () use ($ticket, $owner): void {
                Notification::sendNow(
                    $owner,
                    new TicketUpdatedNotification($ticket, User::factory()->create(), 'updated', 'apes-cic', 'apes-cic.tickets.show', 'APES CIC'),
                    ['mail'],
                );
            });

            $originalId = null;
            $this->step('Find ticket email in the local catcher', function () use ($replies, $ticket, &$originalId): void {
                $originalId = $replies->latestFor('T'.$ticket->id);
                if ($originalId === null || $originalId === '') {
                    throw new RuntimeException('No outbound ticket email for T'.$ticket->id.' is in the local catcher yet.');
                }
            });

            $this->step('Reply as the client', function () use ($replies, &$originalId, $owner): void {
                $replies->reply((string) $originalId, 'Loop check reply from the client.', (string) $owner->email);
            });

            $this->step('Poll and process inbound mail', function () use ($poller): void {
                $result = $poller->poll(force: true);
                if ($result['result'] !== InboundMailPoller::RESULT_POLLED) {
                    throw new RuntimeException('Poll failed: '.$result['result']);
                }
                InboundEmail::query()
                    ->where('status', InboundEmail::STATUS_RECEIVED)
                    ->orderBy('id')
                    ->each(static fn (InboundEmail $email) => ProcessInboundEmail::dispatchSync((int) $email->id));
            });

            $this->step('Reply posted on the same ticket only', function () use ($ticket): void {
                $message = SupportTicketMessage::query()
                    ->where('support_ticket_id', $ticket->id)
                    ->where('via_email', true)
                    ->latest('id')
                    ->first();
                if ($message === null || ! str_contains((string) $message->message, 'Loop check reply from the client.')) {
                    throw new RuntimeException('No via-email reply was posted on T'.$ticket->id.'.');
                }
                if (SupportTicketMessage::query()
                    ->where('via_email', true)
                    ->where('support_ticket_id', '!=', $ticket->id)
                    ->where('message', 'like', '%Loop check reply from the client.%')
                    ->exists()) {
                    throw new RuntimeException('The reply was posted on another ticket.');
                }
            });

            $this->step('Staff notification names the ticket', function () use ($ticket): void {
                $mail = (new TicketUpdatedNotification(
                    $ticket,
                    $ticket->user ?? User::factory()->make(),
                    'updated',
                    (string) $ticket->sub_core_key,
                    'apes-cic.tickets.show',
                    'APES CIC',
                ))->toMail(User::factory()->make(['email' => 'staff-check@example.test']));
                $blob = strtolower($mail->subject.' '.implode(' ', $mail->introLines));
                if (! str_contains($blob, (string) $ticket->id)) {
                    throw new RuntimeException('Staff notification does not mention the ticket reference.');
                }
            });
        } catch (MailboxUnavailable|RuntimeException|Throwable $exception) {
            $this->components->error('Loop check failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Inbound loop check passed in %d ms (driver %s).',
            (int) round((hrtime(true) - $started) / 1_000_000),
            $settings->driver(),
        ));
        $this->line('Variants available via tickets:inbound-reply-as --variant: '.implode(', ', ClientReplyBuilder::VARIANTS));

        return self::SUCCESS;
    }

    private function resolveTicket(): SupportTicket
    {
        $id = $this->option('ticket');
        if (is_string($id) && $id !== '') {
            return SupportTicket::query()->with('user')->findOrFail((int) $id);
        }

        $ticket = SupportTicket::query()
            ->with('user')
            ->where('sub_core_key', 'apes-cic')
            ->where('status', '!=', 'closed')
            ->whereNotNull('user_id')
            ->latest('id')
            ->first();

        if ($ticket === null) {
            throw new RuntimeException('No open APES CIC ticket with an owner was found. Run composer demo:reset first.');
        }

        return $ticket;
    }

    /**
     * @param  callable(): void  $callback
     */
    private function step(string $label, callable $callback): void
    {
        $start = hrtime(true);
        $callback();
        $this->components->info(sprintf(
            '[pass] %s (%d ms)',
            $label,
            (int) round((hrtime(true) - $start) / 1_000_000),
        ));
    }
}
