<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Writes a dedicated reply-token key to .env (#587). The key is never printed.
 * Production keys are set on Cloudron as app environment settings instead.
 */
class GenerateInboundMailKey extends Command
{
    protected $signature = 'inbound-mail:key
        {--enable : Also set INBOUND_MAIL_ENABLED=true}
        {--force : Replace an existing key, or run outside local and testing}';

    protected $description = 'Write a new INBOUND_MAIL_TOKEN_KEY to the .env file for replies by email';

    public function handle(): int
    {
        if (! $this->laravel->environment(['local', 'testing']) && ! $this->option('force')) {
            $this->components->error('Set INBOUND_MAIL_TOKEN_KEY in the app environment instead (see docs/mail.md). Use --force to write .env anyway.');

            return self::FAILURE;
        }

        $path = $this->laravel->environmentFilePath();
        $contents = is_file($path) ? (string) file_get_contents($path) : '';

        if (preg_match('/^INBOUND_MAIL_TOKEN_KEY=\S+/m', $contents) === 1 && ! $this->option('force')) {
            $this->components->info('INBOUND_MAIL_TOKEN_KEY is already set; nothing changed. Use --force to replace it.');

            return self::SUCCESS;
        }

        $contents = $this->set($contents, 'INBOUND_MAIL_TOKEN_KEY', bin2hex(random_bytes(32)));

        if ($this->option('enable')) {
            $contents = $this->set($contents, 'INBOUND_MAIL_ENABLED', 'true');
        }

        file_put_contents($path, $contents);
        $this->components->info('Wrote a new INBOUND_MAIL_TOKEN_KEY to '.basename($path).'. Run php artisan config:clear if config is cached.');

        return self::SUCCESS;
    }

    private function set(string $contents, string $key, string $value): string
    {
        $line = $key.'='.$value;

        if (preg_match('/^'.$key.'=.*$/m', $contents) === 1) {
            return (string) preg_replace('/^'.$key.'=.*$/m', $line, $contents);
        }

        return rtrim($contents, "\r\n").PHP_EOL.$line.PHP_EOL;
    }
}
