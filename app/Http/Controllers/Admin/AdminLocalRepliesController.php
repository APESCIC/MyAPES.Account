<?php

namespace App\Http\Controllers\Admin;

use App\Core\Mail\Inbound\InboundEmail;
use App\Core\Mail\Inbound\InboundMailPoller;
use App\Core\Mail\Inbound\InboundReplyRegistry;
use App\Core\Mail\Inbound\Local\ClientReplyBuilder;
use App\Core\Mail\Inbound\Local\LocalReplies;
use App\Core\Mail\Inbound\MailboxUnavailable;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Local test replies on Admin → Emails → Inbound (#758): reply to a ticket or
 * case email in a local Mailpit as the client, then fetch replies now.
 * Routes are behind EnsureLocalReplyLoop, so they are not found elsewhere.
 */
class AdminLocalRepliesController extends Controller
{
    public function reply(Request $request, LocalReplies $replies): RedirectResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:100'],
            'body' => ['required', 'string', 'max:5000'],
            'as' => ['nullable', 'email', 'max:255'],
            'variants' => ['array'],
            'variants.*' => ['string', Rule::in(ClientReplyBuilder::VARIANTS)],
        ]);

        try {
            $sent = $replies->reply($validated['message'], $validated['body'], $validated['as'] ?? null, array_values($validated['variants'] ?? []));
        } catch (MailboxUnavailable|RuntimeException $exception) {
            return $this->back()->withErrors(['local_reply' => $exception->getMessage()])->withInput();
        }

        return $this->back()->with('status', __('admin.emails.inbound.local.sent', $sent));
    }

    public function fetch(InboundMailPoller $poller, InboundReplyRegistry $registry): RedirectResponse
    {
        $before = (int) InboundEmail::query()->max('id');
        $result = $poller->poll(force: true);

        if ($result['result'] !== InboundMailPoller::RESULT_POLLED) {
            return $this->back()->withErrors(['local_reply' => match ($result['result']) {
                InboundMailPoller::RESULT_DISABLED => __('admin.emails.inbound.local.fetch_disabled'),
                InboundMailPoller::RESULT_LOCKED => __('admin.emails.inbound.local.fetch_busy'),
                default => __('admin.emails.inbound.local.fetch_unavailable'),
            }]);
        }

        $fetched = InboundEmail::query()->where('id', '>', $before)->orderBy('id')->get()->map(function (InboundEmail $email) use ($registry): array {
            $email->refresh();
            $handler = $email->record_prefix === null ? null : $registry->handler($email->record_prefix);
            $record = $handler?->find((int) $email->record_id);

            return [
                'label' => $email->subject ?: '#'.$email->id,
                'status' => $email->statusLabel(),
                'url' => route('admin.emails.inbound.show', $email),
                'record_url' => $handler !== null && $record !== null ? $handler->url($record) : null,
            ];
        })->all();

        return $this->back()
            ->with('status', __('admin.emails.inbound.local.fetched', $result))
            ->with('local_fetched', $fetched);
    }

    private function back(): RedirectResponse
    {
        return redirect()->to(route('admin.emails.inbound.index').'#local-replies');
    }
}
