<?php

namespace App\Core\Mail\Inbound\Mailpit;

use App\Core\Mail\Inbound\InboundMailSettings;
use App\Core\Mail\Inbound\MailboxUnavailable;
use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Laragon's Mailpit REST API (#756), for the local reply loop only. Every call
 * refuses unless the settings allow Mailpit (local or testing, loopback URL).
 */
final class MailpitClient
{
    private const PAGE = 250;

    private const MAX_RESULTS = 2000;

    public function __construct(private readonly InboundMailSettings $settings) {}

    /**
     * Messages matching a Mailpit search, newest first.
     *
     * @return list<array<string, mixed>>
     *
     * @throws MailboxUnavailable
     */
    public function search(string $query, int $max = self::MAX_RESULTS): array
    {
        $messages = [];

        for ($start = 0; $start < $max; $start += self::PAGE) {
            $page = $this->json(fn (PendingRequest $http): Response => $http->get('/api/v1/search', [
                'query' => $query,
                'start' => $start,
                'limit' => min(self::PAGE, $max - $start),
            ]));
            $batch = array_values(array_filter((array) ($page['messages'] ?? []), 'is_array'));
            array_push($messages, ...$batch);

            if (count($batch) < self::PAGE || $start + count($batch) >= (int) ($page['messages_count'] ?? 0)) {
                break;
            }
        }

        return array_slice($messages, 0, $max);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws MailboxUnavailable
     */
    public function message(string $id): array
    {
        return $this->json(fn (PendingRequest $http): Response => $http->get('/api/v1/message/'.rawurlencode($id)));
    }

    /**
     * @throws MailboxUnavailable
     */
    public function raw(string $id): string
    {
        return $this->send(fn (PendingRequest $http): Response => $http->accept('text/plain')->get('/api/v1/message/'.rawurlencode($id).'/raw'))->body();
    }

    /**
     * Deletes the given messages. An empty list would delete everything in Mailpit, so it does nothing.
     *
     * @param  list<string>  $ids
     *
     * @throws MailboxUnavailable
     */
    public function delete(array $ids): void
    {
        $ids = array_values(array_unique(array_filter($ids, fn (string $id): bool => $id !== '')));

        if ($ids === []) {
            return;
        }

        $this->send(fn (PendingRequest $http): Response => $http->delete('/api/v1/messages', ['IDs' => $ids]));
    }

    /**
     * Link to a message in the Mailpit web interface.
     */
    public function webUrl(string $id): string
    {
        return $this->settings->mailpitUrl().'/view/'.rawurlencode($id);
    }

    /**
     * @param  Closure(PendingRequest): Response  $call
     * @return array<string, mixed>
     */
    private function json(Closure $call): array
    {
        $decoded = $this->send($call)->json();

        if (! is_array($decoded)) {
            throw new MailboxUnavailable('Mailpit returned an unexpected response.');
        }

        return $decoded;
    }

    /**
     * @param  Closure(PendingRequest): Response  $call
     */
    private function send(Closure $call): Response
    {
        if (! $this->settings->mailpitAllowed()) {
            throw new MailboxUnavailable('Mailpit is only read on local or testing with a loopback URL.');
        }

        $http = Http::baseUrl($this->settings->mailpitUrl())
            ->acceptJson()
            ->timeout(max(1, (int) config('inbound-mail.mailpit.timeout', 10)));

        try {
            $response = $call($http);
        } catch (Throwable $exception) {
            throw new MailboxUnavailable('Mailpit could not be reached ('.class_basename($exception).').');
        }

        if (! $response->successful()) {
            throw new MailboxUnavailable(sprintf('Mailpit answered HTTP %d.', $response->status()));
        }

        return $response;
    }
}
