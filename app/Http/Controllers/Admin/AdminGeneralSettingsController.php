<?php

namespace App\Http\Controllers\Admin;

use App\Core\Settings\AppSettings;
use App\Core\Settings\GeneralSettings;
use App\Core\Settings\SettingDefinition;
use App\Http\Controllers\Controller;
use App\Http\Controllers\SitemapController;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Saves Admin → Settings → General (#734). The audit lists the changed keys
 * only, never the values.
 */
class AdminGeneralSettingsController extends Controller
{
    public function update(Request $request, AppSettings $settings, AuditLogger $audit): RedirectResponse
    {
        $definitions = $settings->definitions(GeneralSettings::GROUP);

        $request->validate(
            [
                ...array_map(static fn (SettingDefinition $definition): array => ['nullable', ...$definition->rules], $definitions),
                'banner_text' => ['nullable', 'required_if_accepted:banner_enabled', ...$definitions['banner_text']->rules],
            ],
            [
                'support_email.email' => __('admin.settings.general.validation.support_email'),
                'support_phone.regex' => __('admin.settings.general.validation.support_phone'),
                'banner_text.required_if_accepted' => __('admin.settings.general.validation.banner_text_required'),
            ],
            __('admin.settings.general.attributes'),
        );

        $values = [];
        foreach ($definitions as $key => $definition) {
            if ($settings->overriddenByEnv(GeneralSettings::GROUP, $key)) {
                continue;
            }

            $values[$key] = $definition->type === SettingDefinition::BOOLEAN
                ? $request->boolean($key)
                : $request->input($key);
        }

        $changed = $settings->set(GeneralSettings::GROUP, $values, (int) $request->user()->id);

        if ($changed !== []) {
            $audit->record('admin.settings.general_saved', $request->user(), context: ['changed' => $changed]);
        }

        if (in_array('registration_enabled', $changed, true)) {
            Cache::forget(SitemapController::CACHE_KEY);
        }

        return redirect()
            ->route('admin.settings.show', AdminSettingsController::TAB_GENERAL)
            ->with('status', $changed === [] ? __('admin.settings.general.no_changes') : __('admin.settings.general.saved'));
    }
}
