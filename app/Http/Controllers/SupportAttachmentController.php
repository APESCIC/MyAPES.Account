<?php

namespace App\Http\Controllers;

use App\Core\Attachments\SupportAttachment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupportAttachmentController extends Controller
{
    public function download(
        Request $request,
        SupportAttachment $attachment,
    ): StreamedResponse {
        Gate::authorize('download', $attachment);

        abort_unless(
            Storage::disk($attachment->disk)->exists($attachment->path),
            404,
        );

        return Storage::disk($attachment->disk)->download(
            $attachment->path,
            $attachment->original_name,
            [
                'Content-Type' => $attachment->mime_type,
            ],
        );
    }

    public function destroy(
        Request $request,
        SupportAttachment $attachment,
    ): RedirectResponse {
        Gate::authorize('delete', $attachment);

        $attachment->forceFill(['deleted_by' => $request->user()->id])->save();
        $attachment->delete();

        return back()->with('status', __('attachments.flash.deleted', ['name' => $attachment->original_name]));
    }
}
