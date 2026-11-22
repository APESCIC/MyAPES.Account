<?php

namespace App\Core\Push;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Http;
use Minishlink\WebPush\WebPush;
use Psr\Http\Client\ClientInterface;

/**
 * Builds the Minishlink client with MyAPES push timeouts (#800).
 */
final class WebPushFactory
{
    public function make(): WebPush
    {
        $timeout = (float) config('myapes.push.http_timeout_seconds', 10);
        $options = array_merge((array) config('webpush.client_options', []), ['timeout' => $timeout]);
        $auth = $this->auth();

        return (new WebPush($auth, [], $this->client($options), new HttpFactory, new HttpFactory))
            ->setReuseVAPIDHeaders(true)
            ->setAutomaticPadding((bool) config('webpush.automatic_padding', true));
    }

    /**
     * @return array<string, mixed>
     */
    private function auth(): array
    {
        $publicKey = config('webpush.vapid.public_key');
        $privateKey = config('webpush.vapid.private_key');

        if (! is_string($publicKey) || $publicKey === '' || ! is_string($privateKey) || $privateKey === '') {
            return [];
        }

        $subject = config('webpush.vapid.subject');

        return [
            'VAPID' => [
                'publicKey' => $publicKey,
                'privateKey' => $privateKey,
                'subject' => is_string($subject) && $subject !== '' ? $subject : url('/'),
            ],
        ];
    }

    /**
     * @param  array<mixed>  $options
     */
    private function client(array $options): ClientInterface
    {
        // @phpstan-ignore-next-line Laravel 12 remains supported and requires the Guzzle fallback.
        if (version_compare(Application::VERSION, '13.13.0', '<')) {
            return new Client($options);
        }

        return Http::timeout((int) max(1, (int) ($options['timeout'] ?? 10)))
            ->withOptions($options)
            ->buildClient();
    }
}