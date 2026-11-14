<?php

namespace Tests\Support;

use App\Core\Mail\Inbound\Herd\HerdMailRunner;
use App\Core\Mail\Inbound\MailboxUnavailable;

/**
 * Fake `herd mail:*` runner for inbound mail tests (#816, #817).
 */
final class FakeHerdMailRunner implements HerdMailRunner
{
    /** @var array<string, array{to: string, date: string, raw: string}> */
    public array $mail = [];

    /** @var array<string, array<string, mixed>> */
    public array $jsonMessages = [];

    /** @var array<string, string> */
    public array $listSubjects = [];

    /** @var list<string> */
    public array $deleted = [];

    /** @var list<list<string>> */
    public array $calls = [];

    public function put(string $id, string $to, string $date, string $raw): void
    {
        $this->mail[$id] = ['to' => $to, 'date' => $date, 'raw' => $raw];
    }

    public function run(array $arguments): string
    {
        $this->calls[] = $arguments;
        $command = $arguments[0] ?? '';

        return match ($command) {
            'mail:list' => $this->list($arguments),
            'mail:show' => $this->show($arguments),
            'mail:delete' => $this->delete($arguments),
            default => throw new MailboxUnavailable('Unexpected herd command: '.$command),
        };
    }

    /**
     * @param  list<string>  $arguments
     */
    private function list(array $arguments): string
    {
        $toFilter = null;
        $subjectFilter = null;
        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--to=')) {
                $toFilter = substr($argument, 5);
            }
            if (str_starts_with($argument, '--subject=')) {
                $subjectFilter = substr($argument, 10);
            }
        }

        $rows = [];
        foreach ($this->mail as $id => $message) {
            if (in_array($id, $this->deleted, true)) {
                continue;
            }
            if ($toFilter !== null && ! str_contains(strtolower($message['to']), strtolower($toFilter))) {
                continue;
            }
            $subject = $this->listSubjects[$id] ?? 'Re: Ticket';
            if ($subjectFilter !== null && ! str_contains($subject, $subjectFilter)) {
                continue;
            }
            $rows[] = [
                'id' => $id,
                'date' => $message['date'],
                'mailbox' => 'Default',
                'subject' => $subject,
                'from' => [['address' => 'owner@example.org', 'name' => '']],
                'to' => [['address' => $message['to'], 'name' => '']],
                'cc' => [],
                'attachments' => 0,
            ];
        }

        return json_encode($rows, JSON_THROW_ON_ERROR);
    }

    /**
     * @param  list<string>  $arguments
     */
    private function show(array $arguments): string
    {
        $id = $arguments[1] ?? '';
        if (in_array('--format=json', $arguments, true)) {
            if (isset($this->jsonMessages[$id])) {
                return json_encode($this->jsonMessages[$id], JSON_THROW_ON_ERROR);
            }

            throw new MailboxUnavailable('Herd mail:show missing json mail '.$id);
        }
        if (! isset($this->mail[$id]) || ! in_array('--format=raw', $arguments, true)) {
            throw new MailboxUnavailable('Herd mail:show missing raw mail '.$id);
        }

        return $this->mail[$id]['raw'];
    }

    /**
     * @param  list<string>  $arguments
     */
    private function delete(array $arguments): string
    {
        $id = $arguments[1] ?? '';
        $this->deleted[] = $id;

        return "Deleted mail {$id}.\n";
    }
}
