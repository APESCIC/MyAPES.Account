<?php

namespace App\Console\Commands;

use App\Core\Accounts\User;
use App\Core\Mail\Inbound\InboundMailSettings;
use App\Core\Mail\Inbound\InboundReplyRegistry;
use App\Core\Mail\Inbound\Local\ClientReplyBuilder;
use App\Core\Mail\Inbound\Local\OriginalEmail;
use App\Core\Mail\Inbound\MailReplyThread;
use App\Core\Mail\Inbound\ReplyToken;
use Illuminate\Console\Command;

/**
 * Writes a realistic reply .eml for local testing (#587), so the whole
 * replies-by-email flow can be tried in Laragon without a mailbox.
 */
class MakeInboundSampleReply extends Command
{
    protected $signature = 'tickets:inbound-sample
        {record : T for a ticket or C for a case, then the id, e.g. T12}
        {email : The account the reply comes from}
        {--body=Thanks, this is a reply sent by email. : The reply text}
        {--output= : Where to write the .eml (default storage/app/inbound-samples)}';

    protected $description = 'Write a sample email reply to a ticket or case for local testing';

    public function handle(InboundMailSettings $settings, InboundReplyRegistry $registry, ClientReplyBuilder $builder): int
    {
        if (! $this->laravel->environment(['local', 'testing'])) {
            $this->components->error('Sample replies are for local testing only.');

            return self::FAILURE;
        }

        if (! $settings->repliesEnabled()) {
            $this->components->error('Replies by email are off. Run php artisan inbound-mail:key --enable first.');

            return self::FAILURE;
        }

        if (preg_match('/^([A-Za-z])(\d+)$/', (string) $this->argument('record'), $matches) !== 1 || $registry->handler($matches[1]) === null) {
            $this->components->error('Give the record as T or C followed by its id, for example T12.');

            return self::FAILURE;
        }

        $prefix = strtoupper($matches[1]);
        $recordId = (int) $matches[2];
        $user = User::query()->whereRaw('LOWER(email) = ?', [strtolower((string) $this->argument('email'))])->first();

        if (! $user instanceof User) {
            $this->components->error('No account has that email address.');

            return self::FAILURE;
        }

        $path = (string) ($this->option('output') ?: storage_path('app/inbound-samples/reply-'.$prefix.$recordId.'-'.now()->format('YmdHis').'.eml'));
        $dir = dirname($path);

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $original = new OriginalEmail(
            messageId: (string) MailReplyThread::query()
                ->where('record_prefix', $prefix)
                ->where('record_id', $recordId)
                ->where('user_id', $user->id)
                ->latest('id')
                ->value('message_id'),
            subject: ReplyToken::subjectTag($prefix, $recordId),
            replyTo: $settings->replyAddress($prefix, $recordId, (int) $user->id),
            recipient: (string) $user->email,
        );

        file_put_contents($path, $builder->raw($builder->build($original, $user, (string) $this->option('body'))));
        $this->components->info('Wrote '.$path);
        $this->line('Feed it in with: php artisan tickets:inbound-ingest "'.$path.'"');

        return self::SUCCESS;
    }
}
