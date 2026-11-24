<?php

namespace App\Http\Controllers;

use App\Services\AccountPersonalDataExport;
use App\Services\AccountPrivacyDeletionRequest;
use App\Services\AuditLogger;
use App\Services\ContactPreferenceUpdater;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AccountPrivacyController extends Controller
{
    public function updateContactPreference(
        Request $request,
        ContactPreferenceUpdater $preferences,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $validated = Validator::make($request->all(), [
            'channel' => ['required', 'string', Rule::in(ContactPreferenceUpdater::CHANNELS)],
            'granted' => ['required', 'boolean'],
        ])->validate();

        $channel = (string) $validated['channel'];
        $granted = (bool) $validated['granted'];
        $choices = array_fill_keys(ContactPreferenceUpdater::CHANNELS, false);
        $preference = $user->contactPreference()->firstOrCreate(['user_id' => $user->id]);
        foreach (ContactPreferenceUpdater::CHANNELS as $name) {
            $choices[$name] = (bool) $preference->{$name};
        }
        $choices[$channel] = $granted;

        $preferences->update($user, $user, $choices, 'account_privacy');

        $auditLogger->record('account.privacy.preference_updated', $user, $user, [
            'channel' => $channel,
            'granted' => $granted,
            'policy_version' => (string) config('myapes.consent.policy_version'),
        ]);

        return redirect()
            ->to(route('account.privacy').$this->fragmentFor($channel))
            ->with('status', __('account.settings.privacy.flash.preference_saved'));
    }

    public function download(
        Request $request,
        AccountPersonalDataExport $export,
        AuditLogger $auditLogger,
    ): StreamedResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $auditLogger->record('account.privacy.data_exported', $user, $user, [
            'policy_version' => (string) config('myapes.consent.policy_version'),
        ]);

        $filename = $export->downloadFilename($user);
        $payload = $export->jsonLines($user);

        return response()->streamDownload(
            static function () use ($payload): void {
                echo implode("\n", $payload);
            },
            $filename,
            [
                'Content-Type' => 'application/json; charset=UTF-8',
                'Cache-Control' => 'no-store',
            ],
        );
    }

    public function requestDeletion(
        Request $request,
        AccountPrivacyDeletionRequest $deletionRequests,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $request->validate([
            'confirmed' => ['accepted'],
        ]);

        $case = $deletionRequests->openCase($user);

        $auditLogger->record('account.privacy.deletion_requested', $user, $case, [
            'case_id' => $case->id,
            'policy_version' => (string) config('myapes.consent.policy_version'),
        ]);

        return redirect()
            ->route('apes-cic.cases.show', $case)
            ->with('status', __('account.settings.privacy.flash.deletion_requested'));
    }

    private function fragmentFor(string $channel): string
    {
        if (in_array($channel, ['email', 'marketing_email'], true)) {
            return '#email-opt-in';
        }

        return '#contact-preferences';
    }
}