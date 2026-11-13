<?php

namespace App\Core\Mail\Inbound\Diagnostics;

enum StepStatus: string
{
    case Passed = 'passed';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function label(): string
    {
        return __("admin.emails.import.step_status.{$this->value}");
    }
}
