<?php

namespace App\Http\Controllers;

use App\Core\Attachments\SupportAttachment;
use App\Services\AuditLogger;
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
        AuditLogger $audit,
    ): StreamedResponse {
        Gate::authorize('download', $attachment);

        abort_unless(
            Storage::disk($attachment->disk)->exists($attachment->path),
            404,
        );

        $audit->record('support_attachment.downloaded', $request->user(), $attachment, $this->context($attachment));

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
        AuditLogger $audit,
    ): RedirectResponse {
        Gate::authorize('delete', $attachment);

        $attachment->forceFill(['deleted_by' => $request->user()->id])->save();
        $attachment->delete();
        $audit->record('support_attachment.deleted', $request->user(), $attachment, $this->context($attachment));

        return back()->with('status', __('attachments.flash.deleted', ['name' => $attachment->original_name]));
    }

    /**
     * @return array<string, mixed>
     */
    private function context(SupportAttachment $attachment): array
    {
        return [
            'kind' => $attachment->kind,
            'attachable_type' => $attachment->attachable_type,
            'attachable_id' => $attachment->attachable_id,
        ];
    }
}
