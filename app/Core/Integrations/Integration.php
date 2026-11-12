<?php

namespace App\Core\Integrations;

use App\Core\Settings\SettingDefinition;
use Closure;
use InvalidArgumentException;

/**
 * An outside service MyAPES Account uses (#634), such as the TinyMCE editor
 * or Google reCAPTCHA. Its fields live in the shared settings store (#732)
 * under their own group. The tester returns null when the service answers,
 * or a short reason that never contains a secret.
 */
final class Integration
{
    /** @var array<string, IntegrationField> */
    public readonly array $fields;

    /**
     * @param  list<IntegrationField>  $fields
     * @param  (Closure(IntegrationRegistry): ?string)|null  $tester
     */
    public function __construct(
        public readonly string $key,
        array $fields,
        public readonly ?Closure $tester = null,
    ) {
        $keyed = [];
        foreach ($fields as $field) {
            $keyed[$field->key] = $field;
        }
        $this->fields = $keyed;
    }

    public function group(): string
    {
        return 'integration_'.$this->key;
    }

    public function field(string $key): IntegrationField
    {
        return $this->fields[$key] ?? throw new InvalidArgumentException(sprintf('Unknown field %s on integration %s.', $key, $this->key));
    }

    /**
     * @return list<SettingDefinition>
     */
    public function settingDefinitions(): array
    {
        return array_values(array_map(fn (IntegrationField $field) => $field->definition($this->group()), $this->fields));
    }
}
