<?php

namespace App\Http\Controllers\Admin;

use App\Core\Accounts\User;
use App\Core\Mail\MailTemplate;
use App\Core\Mail\MailTemplateRegistry;
use App\Core\Mail\OutboundMailLog;
use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\VerificationResendService;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

class AdminEmailController extends Controller
{
    public const TEST_SENDS_PER_WINDOW = 5;

    public const TEST_WINDOW_SECONDS = 600;

    public function index(MailTemplateRegistry $registry): View
    {
        return view('admin.emails.index', [
            'templates' => array_values($registry->all()),
        ]);
    }

    public function log(MailTemplateRegistry $registry): View
    {
        return view('admin.emails.log', [
            'entries' => OutboundMailLog::query()->latest('created_at')->latest('id')->paginate(50),
            'templates' => $registry->all(),
            'retentionDays' => (int) config('myapes.mail.log_retention_days', 90),
        ]);
    }

    public function show(Request $request, MailTemplateRegistry $registry, string $template): View
    {
        $mailTemplate = $this->findOrFail($registry, $template);
        $mail = $mailTemplate->sampleNotification($request->user())->toMail($request->user());

        return view('admin.emails.show', [
            'template' => $mailTemplate,
            'subject' => (string) $mail->subject,
            'testSendLimit' => self::TEST_SENDS_PER_WINDOW,
        ]);
    }

    /**
     * Render-only sample for the sandboxed preview frame. Never sends mail.
     */
    public function preview(Request $request, MailTemplateRegistry $registry, string $template): Response
    {
        $mailTemplate = $this->findOrFail($registry, $template);
        $html = (string) $mailTemplate->sampleNotification($request->user())->toMail($request->user())->render();

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Security-Policy' => "sandbox; default-src 'none'; img-src 'self' data: https:; style-src 'unsafe-inline'; frame-ancestors 'self'",
            'X-Frame-Options' => 'SAMEORIGIN',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Send a template's sample to the acting admin only. Sent immediately on the mail
     * channel so in-memory sample records are never queued or written to the database.
     */
    public function sendTest(Request $request, MailTemplateRegistry $registry, AuditLogger $audit, string $template): RedirectResponse
    {
        $mailTemplate = $this->findOrFail($registry, $template);
        $admin = $request->user();

        if (! RateLimiter::attempt('admin-email-test:'.$admin->id, self::TEST_SENDS_PER_WINDOW, static fn (): bool => true, self::TEST_WINDOW_SECONDS)) {
            return back()->withErrors(['send_test' => __('admin.emails.test_rate_limited')]);
        }

        Notification::sendNow($admin, $mailTemplate->sampleNotification($admin), ['mail']);

        $audit->record('admin.emails.test_sent', $admin, context: [
            'template' => $mailTemplate->key,
        ]);

        return redirect()
            ->route('admin.emails.show', $mailTemplate->key)
            ->with('status', __('admin.flash.email_test_sent'));
    }

    public function resendVerification(Request $request, VerificationResendService $resends, string $user): RedirectResponse
    {
        $managedUser = User::query()->findOrFail($user);

        try {
            $resends->resend($request->user(), $managedUser);
        } catch (DomainException $exception) {
            return redirect()
                ->route('admin.users.show', $managedUser)
                ->withErrors(['resend_verification' => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.users.show', $managedUser)
            ->with('status', __('admin.flash.verification_resent'));
    }

    private function findOrFail(MailTemplateRegistry $registry, string $key): MailTemplate
    {
        $template = $registry->find($key);
        abort_if($template === null, 404);

        return $template;
    }
}
