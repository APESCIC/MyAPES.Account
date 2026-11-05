<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Dismisses the one-off notice shown after a staff account's client records were archived (#515).
 */
class ClientRecordsNoticeController extends Controller
{
    public function dismiss(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->client_records_archived_at !== null && $user->client_records_notice_dismissed_at === null) {
            $user->forceFill(['client_records_notice_dismissed_at' => now()])->save();
        }

        return redirect()->back(fallback: route('dashboard'))
            ->with('status', __('flash.client_records_notice_dismissed'));
    }
}
