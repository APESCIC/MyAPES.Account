<?php

namespace App\Http\Controllers\Admin;

use App\Core\Settings\AppSettings;
use App\Core\Settings\SecuritySettings;
use App\Core\Settings\SettingDefinition;
use App\Http\Controllers\Admin\Settings\KeysAndSecretsTab;
use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Validator;

/**
 * Admin → Security (#947): hub, Keys and secrets, and Sign-in policy.
 * Overview KPIs land in #948; the audit viewer in #949.
 */
class AdminSecurityController extends Controller
{
    public function index(): Response
    {
        return response()->view('admin.security.index', [
            'links' => [
                [
                    'label' => __('admin.nav.access'),
                    'url' => route('admin.access.index'),
                    'description' => __('admin.security.hub.access'),
                ],
                [
                    'label' => __('admin.nav.keys_and_secrets'),
                    'url' => route('admin.security.keys-and-secrets'),
                    'description' => __('admin.security.hub.keys'),
                ],
                [
                    'label' => __('admin.nav.sign_in_policy'),
                    'url' => route('admin.security.sign-in-policy'),
                    'description' => __('admin.security.hub.sign_in_policy'),
                ],
                [
                    'label' => __('admin.settings.tabs.api_clients'),
                    'url' => route('admin.settings.show', AdminSettingsController::TAB_API_CLIENTS),
                    'description' => __('admin.security.hub.api_clients'),
                ],
                [
                    'label' => __('admin.settings.tabs.webhooks'),
                    'url' => route('admin.settings.show', AdminSettingsController::TAB_WEBHOOKS),
                    'description' => __('admin.security.hub.webhooks'),
                ],
            ],
        ])->header('Cache-Control', 'no-store, private');
    }

    public function keysAndSecrets(KeysAndSecretsTab $keysAndSecrets): Response
    {
        return response()->view('admin.security.keys-and-secrets', $keysAndSecrets->viewData())
            ->header('Cache-Control', 'no-store, private');
    }

    public function signInPolicy(SecuritySettings $security): Response
    {
        return response()->view('admin.security.sign-in-policy', [
            'temporaryPasswordTtlHours' => $security->temporaryPasswordTtlHours(),
            'phonePinMinLength' => $security->phonePinMinLength(),
            'phonePinMaxLength' => $security->phonePinMaxLength(),
            'staffTwoFactorRequired' => $security->staffTwoFactorRequired(),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function updateSignInPolicy(Request $request, AppSettings $settings, AuditLogger $audit): RedirectResponse
    {
        $definitions = $settings->definitions(SecuritySettings::GROUP);

        $validator = validator(
            $request->all(),
            array_map(static fn (SettingDefinition $definition): array => ['nullable', ...$definition->rules], $definitions),
            [],
            __('admin.security.sign_in_policy.attributes'),
        );
        $validator->after(function (Validator $validator) use ($request): void {
            $min = (int) $request->input('phone_pin_min_length');
            $max = (int) $request->input('phone_pin_max_length');
            if ($min > 0 && $max > 0 && $min > $max) {
                $validator->errors()->add('phone_pin_min_length', __('admin.security.sign_in_policy.validation.pin_range'));
            }
        });
        $validator->validate();

        $values = [];
        foreach ($definitions as $key => $definition) {
            if ($settings->overriddenByEnv(SecuritySettings::GROUP, $key)) {
                continue;
            }

            $values[$key] = $definition->type === SettingDefinition::BOOLEAN
                ? $request->boolean($key)
                : $request->input($key);
        }

        $changed = $settings->set(SecuritySettings::GROUP, $values, (int) $request->user()->id);

        if ($changed !== []) {
            $audit->record('admin.security.sign_in_policy_saved', $request->user(), context: ['changed' => $changed]);
        }

        return redirect()
            ->route('admin.security.sign-in-policy')
            ->with('status', $changed === []
                ? __('admin.security.sign_in_policy.no_changes')
                : __('admin.security.sign_in_policy.saved'));
    }
}
