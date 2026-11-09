<?php

namespace App\Console\Commands;

use App\Core\Accounts\User;
use App\Core\Mail\Inbound\InboundMailSettings;
use App\Core\Mail\Inbound\InboundReplyRegistry;
use App\Core\Mail\Inbound\ReplyToken;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

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

    public function handle(InboundMailSettings $settings, InboundReplyRegistry $registry, ReplyToken $tokens): int
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

        file_put_contents($path, $this->message($settings, $prefix, $recordId, $user, (string) $this->option('body')));
        $this->components->info('Wrote '.$path);
        $this->line('Feed it in with: php artisan tickets:inbound-ingest "'.$path.'"');

        return self::SUCCESS;
    }

    private function message(InboundMailSettings $settings, string $prefix, int $recordId, User $user, string $body): string
    {
        $server = $settings->authservId() !== '' ? $settings->authservId() : 'localhost';
        $domain = substr((string) strrchr((string) $user->email, '@'), 1) ?: 'example.org';
        $lines = [
            'Return-Path: <'.$user->email.'>',
            'Authentication-Results: '.$server.'; spf=pass smtp.mailfrom='.$domain.'; dkim=pass header.d='.$domain.'; dmarc=pass header.from='.$domain,
            'From: '.$user->name.' <'.$user->email.'>',
            'To: '.$settings->replyAddress($prefix, $recordId, (int) $user->id),
            'Subject: Re: '.ReplyToken::subjectTag($prefix, $recordId),
            'Date: '.now()->toRfc2822String(),
            'Message-ID: <sample.'.Str::lower(Str::random(16)).'@'.$domain.'>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            '',
            $body,
            '',
            '> '.__('mail.reply_by_email.marker'),
            '> (quoted text from the original email)',
            '',
        ];

        return implode("\r\n", $lines);
    }
}
