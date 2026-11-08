<?php

namespace App\Core\Attachments\Scanning;

/**
 * Virus scanner for uploaded attachments (#550).
 */
interface VirusScanner
{
    /**
     * False when no scanner is configured; uploads are then marked "not scanned".
     */
    public function enabled(): bool;

    /**
     * @param  resource  $stream
     *
     * @throws ScannerUnavailable when the scanner cannot give an answer.
     */
    public function scan($stream): ScanResult;
}
