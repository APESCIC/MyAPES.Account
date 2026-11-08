<?php

namespace App\Core\Attachments\Scanning;

final class DisabledScanner implements VirusScanner
{
    public function enabled(): bool
    {
        return false;
    }

    public function ping(): bool
    {
        return false;
    }

    public function scan($stream): ScanResult
    {
        throw new ScannerUnavailable('Virus scanning is not configured.');
    }
}
