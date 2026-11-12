<?php

namespace App\Core\Integrations;

use App\Core\Settings\SettingDefinition;

/**
 * One setting of an integration (#634): a key, an API key or a threshold.
 * Required fields decide whether the integration counts as set up.
 */
final class IntegrationField
{
    /**
     * @param  list<mixed>  $rules  Laravel validation rules for a submitted value.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $type = SettingDefinition::STRING,
        public readonly bool $secret = false,
        public readonly ?string $env = null,
        public readonly mixed $default = null,
        public readonly array $rules = [],
        public readonly bool $required = true,
    ) {}

    public function definition(string $group): SettingDefinition
    {
        return new SettingDefinition($group, $this->key, $this->type, $this->secret, $this->env, $this->default, $this->rules);
    }
}
