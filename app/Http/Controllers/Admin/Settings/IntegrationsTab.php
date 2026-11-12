<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Core\Integrations\IntegrationRegistry;
use App\Core\Settings\SettingDefinition;

/**
 * What Admin → Settings → Integrations shows (#635). A secret is never sent
 * to the page: only whether one is saved and, for long keys, its last four
 * characters.
 */
final class IntegrationsTab
{
    public function __construct(private readonly IntegrationRegistry $registry) {}

    /**
     * @return array<string, mixed>
     */
    public function viewData(): array
    {
        $integrations = [];
        foreach ($this->registry->all() as $key => $integration) {
            $fields = [];
            foreach ($integration->fields as $fieldKey => $field) {
                $fields[$fieldKey] = [
                    'input' => match (true) {
                        $field->secret => 'password',
                        $field->type === SettingDefinition::BOOLEAN => 'checkbox',
                        $field->type === SettingDefinition::INTEGER => 'number',
                        default => 'text',
                    },
                    'value' => $field->secret ? null : $this->registry->value($key, $fieldKey),
                    'env' => $this->registry->overriddenByEnv($key, $fieldKey),
                    'saved' => $field->secret && $this->registry->value($key, $fieldKey) !== null,
                    'hint' => $field->secret ? $this->registry->secretHint($key, $fieldKey) : null,
                ];
            }

            $integrations[$key] = [
                'status' => $this->registry->status($key),
                'fields' => $fields,
                'testable' => $this->registry->testable($key),
                'lastTest' => $this->registry->lastTest($key),
                'warning' => $integration->warning === null ? null : ($integration->warning)(),
            ];
        }

        return ['integrations' => $integrations];
    }
}
