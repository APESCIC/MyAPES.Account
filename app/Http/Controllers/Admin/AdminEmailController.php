<?php

namespace App\Http\Controllers\Admin;

use App\Core\Mail\MailTemplate;
use App\Core\Mail\MailTemplateRegistry;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AdminEmailController extends Controller
{
    public function index(MailTemplateRegistry $registry): View
    {
        return view('admin.emails.index', [
            'templates' => array_values($registry->all()),
        ]);
    }

    public function show(Request $request, MailTemplateRegistry $registry, string $template): View
    {
        $mailTemplate = $this->findOrFail($registry, $template);
        $mail = $mailTemplate->sampleNotification($request->user())->toMail($request->user());

        return view('admin.emails.show', [
            'template' => $mailTemplate,
            'subject' => (string) $mail->subject,
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

    private function findOrFail(MailTemplateRegistry $registry, string $key): MailTemplate
    {
        $template = $registry->find($key);
        abort_if($template === null, 404);

        return $template;
    }
}
