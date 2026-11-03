<?php

namespace App\Http\Controllers;

use App\Core\Mail\ReplyLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Opens the item behind a "Log in to reply" email link (#468), sending
 * signed-out readers through the right sign-in first.
 */
class MailReplyLinkController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $to = (string) $request->query('to', '');
        abort_unless(str_starts_with($to, '/') && ! str_starts_with($to, '//') && ! str_contains($to, '\\'), 404);

        $target = url($to);

        if ($request->user() !== null) {
            return redirect()->to($target);
        }

        $request->session()->put('url.intended', $target);

        return redirect()->route($request->query('via') === ReplyLink::VIA_STAFF ? 'staff.login' : 'public.login');
    }
}
