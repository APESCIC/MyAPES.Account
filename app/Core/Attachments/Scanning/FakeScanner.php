<?php

namespace App\Core\Attachments\Scanning;

/**
 * Test scanner: flags files containing TEST_SIGNATURE as infected. It is not
 * the real EICAR string, which desktop antivirus removes from temp folders.
 */
final class FakeScanner implements VirusScanner
{
    public const TEST_SIGNATURE = 'MYAPES-FAKE-SCANNER-TEST-VIRUS';

    public bool $unavailable = false;

    public function enabled(): bool
    {
        return true;
    }

    public function ping(): bool
    {
        return ! $this->unavailable;
    }

    public function scan($stream): ScanResult
    {
        if ($this->unavailable) {
            throw new ScannerUnavailable('Fake scanner set to unavailable.');
        }

        $contents = stream_get_contents($stream);

        return is_string($contents) && str_contains($contents, self::TEST_SIGNATURE)
            ? ScanResult::infected('MyAPES-Fake-Test-Signature')
            : ScanResult::clean();
    }
}
