<?php

namespace App\Core\Mail\Inbound\Diagnostics;

/**
 * The outcome of one connection test (#771, #772): each step, the failure
 * type if any, and what a successful test learned. No secrets, no server text.
 */
final class DiagnosticReport
{
    /**
     * @param  list<StepResult>  $steps
     * @param  array{messages?: ?int, unseen?: ?int, uid_validity?: ?int, capabilities?: list<string>, missing_features?: list<string>, certificate_name?: ?string, certificate_expires_at?: ?int, folders?: list<string>}  $details
     */
    public function __construct(
        public readonly array $steps,
        public readonly ?FailureType $failure = null,
        public readonly ?DiagnosticStep $failedStep = null,
        public readonly array $details = [],
    ) {}

    public function ok(): bool
    {
        return $this->failure === null;
    }

    public function milliseconds(): int
    {
        return array_sum(array_map(static fn (StepResult $step): int => $step->milliseconds, $this->steps));
    }

    /**
     * @return list<FailureType>
     */
    public function warnings(): array
    {
        return ($this->details['missing_features'] ?? []) !== [] ? [FailureType::FeatureMissing] : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'steps' => array_map(static fn (StepResult $step): array => $step->toArray(), $this->steps),
            'failure' => $this->failure?->value,
            'failed_step' => $this->failedStep?->value,
            'details' => $this->details,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            array_values(array_map(static fn (array $step): StepResult => StepResult::fromArray($step), (array) ($data['steps'] ?? []))),
            FailureType::tryFrom((string) ($data['failure'] ?? '')),
            DiagnosticStep::tryFrom((string) ($data['failed_step'] ?? '')),
            (array) ($data['details'] ?? []),
        );
    }
}
