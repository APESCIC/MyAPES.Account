<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Settings\ReplyMailboxTab;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * Admin → Settings (#733): one page per tab. The Reply mailbox tab ships in
 * v0.61.0; add a tab by adding its key, label and view here.
 */
class AdminSettingsController extends Controller
{
    public const TAB_REPLY_MAILBOX = 'reply-mailbox';

    public function index(): RedirectResponse
    {
        return redirect()->route('admin.settings.show', self::TAB_REPLY_MAILBOX);
    }

    public function show(string $tab, ReplyMailboxTab $replyMailbox): View
    {
        $tabs = $this->tabs();
        abort_unless(isset($tabs[$tab]), 404);

        return view('admin.settings.show', [
            'tabs' => $tabs,
            'activeTab' => $tab,
            'tabView' => 'admin.settings.tabs.'.$tab,
            'tabData' => match ($tab) {
                self::TAB_REPLY_MAILBOX => $replyMailbox->viewData(),
            },
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function tabs(): array
    {
        return [
            self::TAB_REPLY_MAILBOX => __('admin.settings.tabs.reply_mailbox'),
        ];
    }
}
