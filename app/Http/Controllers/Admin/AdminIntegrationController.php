<?php

namespace App\Http\Controllers\Admin;

use App\Core\Integrations\IntegrationRegistry;
use App\Core\Settings\SettingDefinition;
use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Saves and tests Admin → Settings → Integrations (#635). Fields set in .env
 * are not saved, a blank secret keeps the saved one, and secrets are never
 * echoed back, logged or audited: the audit names the changed fields only.
 */
class AdminIntegrationController extends Controller
{
    public function update(Request $request, string $integration, IntegrationRegistry $registry, AuditLogger $audit): RedirectResponse
    {
        abort_unless($registry->has($integration), 404);
        $definition = $registry->get($integration);

        $values = [];
        foreach ($definition->fields as $key => $field) {
            $values[$key] = $field->type === SettingDefinition::BOOLEAN ? $request->boolean($key) : $request->input($key);
        }

        $changed = $registry->save($integration, $values, (int) $request->user()->id);

        if ($changed !== []) {
            $secrets = array_values(array_filter($changed, static fn (string $key): bool => $definition->field($key)->secret));
            $audit->record('admin.settings.integration_saved', $request->user(), context: [
                'integration' => $integration,
                'changed' => array_values(array_diff($changed, $secrets)),
                'secrets_replaced' => $secrets,
            ]);
        }

        return $this->back()->with('status', $changed === []
            ? __('admin.settings.integrations.no_changes')
            : __('admin.settings.integrations.saved', ['name' => $this->name($integration)]));
    }

    public function test(Request $request, string $integration, IntegrationRegistry $registry, AuditLogger $audit): RedirectResponse
    {
        abort_unless($registry->has($integration) && $registry->testable($integration), 404);

        $error = $registry->test($integration);
        $audit->record('admin.settings.integration_tested', $request->user(), context: [
            'integration' => $integration,
            'result' => $error === null ? 'ok' : 'failed',
        ]);

        $name = $this->name($integration);

        return $error === null
            ? $this->back()->with('status', __('admin.settings.integrations.test_ok', ['name' => $name]))
            : $this->back()->withErrors(['integration' => __('admin.settings.integrations.test_failed', ['name' => $name, 'reason' => $error])]);
    }

    private function name(string $integration): string
    {
        $key = 'admin.settings.integrations.'.$integration.'.name';

        return __($key);
    }

    private function back(): RedirectResponse
    {
        return redirect()->route('admin.settings.show', AdminSettingsController::TAB_INTEGRATIONS);
    }
}
