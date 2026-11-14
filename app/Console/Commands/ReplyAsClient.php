<?php

namespace App\Console\Commands;

use App\Core\Mail\Inbound\Local\ClientReplyBuilder;
use App\Core\Mail\Inbound\Local\LocalReplies;
use App\Core\Mail\Inbound\MailboxUnavailable;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;

/**
 * Replies to a ticket or case email in Herd or Mailpit as the client would
 * (#757, #817), so the reply loop can be tried locally end to end.
 */
class ReplyAsClient extends Command
{
    protected $signature = 'tickets:inbound-reply-as
        {message? : The Herd or Mailpit ID of the ticket or case email to reply to}
        {--latest= : Reply to the latest email for a ticket or case instead, e.g. T12 or C4}
        {--body=Thanks, this is a reply sent by email. : The reply text}
        {--as= : The account the reply comes from (default: the email\'s recipient)}
        {--variant=* : no-tag, no-references, edited-subject, html-only or attachment}';

    protected $description = 'Reply to a ticket or case email in Herd or Mailpit as the client (local only)';

    public function handle(LocalReplies $replies): int
    {
        if (! $this->laravel->environment('local')) {
            $this->components->error('Replying as a client is for local testing only.');

            return self::FAILURE;
        }

        try {
            $id = (string) $this->argument('message');
            if ($this->option('latest')) {
                $id = (string) $replies->latestFor((string) $this->option('latest'));
                if ($id === '') {
                    $this->components->error('No email for '.strtoupper((string) $this->option('latest')).' is in the local mail catcher.');

                    return self::FAILURE;
                }
            }

            if ($id === '') {
                $this->components->error('Give a mail catcher message ID or --latest=T12.');

                return self::FAILURE;
            }

            $sent = $replies->reply($id, (string) $this->option('body'), $this->option('as') ?: null, array_values((array) $this->option('variant')));
        } catch (MailboxUnavailable $exception) {
            $this->components->error('The local mail catcher could not be read: '.$exception->getMessage());

            return self::FAILURE;
        } catch (RuntimeException|InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());
            $this->line('Variants: '.implode(', ', ClientReplyBuilder::VARIANTS));

            return self::FAILURE;
        }

        $this->components->info(sprintf('Sent "%s" from %s to %s.', $sent['subject'], $sent['from'], $sent['to']));
        $this->line('Fetch it with: php artisan tickets:inbound-poll (INBOUND_MAIL_DRIVER=herd or mailpit)');

        return self::SUCCESS;
    }
}
