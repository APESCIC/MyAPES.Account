<?php

namespace App\Core\Mail\Inbound\Diagnostics;

final class StepResult
{
    public function __construct(
        public readonly DiagnosticStep $step,
        public readonly StepStatus $status,
        public readonly int $milliseconds = 0,
    ) {}

    /**
     * @return array{step: string, status: string, ms: int}
     */
    public function toArray(): array
    {
        return ['step' => $this->step->value, 'status' => $this->status->value, 'ms' => $this->milliseconds];
    }

    /**
     * @param  array{step: string, status: string, ms?: int}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(DiagnosticStep::from($data['step']), StepStatus::from($data['status']), (int) ($data['ms'] ?? 0));
    }
}
