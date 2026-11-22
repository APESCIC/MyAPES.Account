<?php

namespace App\Http\Controllers;

use App\Support\Notifications\AccountNotificationPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * In-app notification bell list and read state (#801).
 */
class AccountNotificationController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        $notifications = $user
            ->notifications()
            ->latest()
            ->paginate(25)
            ->through(fn ($notification) => AccountNotificationPresenter::present($notification));

        return view('notifications.index', [
            'notifications' => $notifications,
            'unreadCount' => $user->unreadNotifications()->count(),
        ]);
    }

    public function open(Request $request, string $notification): RedirectResponse
    {
        $item = $request->user()->notifications()->whereKey($notification)->firstOrFail();

        if ($item->read_at === null) {
            $item->markAsRead();
        }

        $url = AccountNotificationPresenter::url($item->data) ?? route('dashboard');

        return redirect()->to($url);
    }

    public function markRead(Request $request, string $notification): RedirectResponse
    {
        $item = $request->user()->notifications()->whereKey($notification)->firstOrFail();

        if ($item->read_at === null) {
            $item->markAsRead();
        }

        return back()->with('status', __('notifications.marked_read'));
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return back()->with('status', __('notifications.marked_all_read'));
    }
}