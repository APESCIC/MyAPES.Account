<?php

namespace App\Core\Mail\Inbound\Verification;

use App\Core\Mail\Inbound\Parsing\RawHeaders;

/**
 * SPF, DKIM and DMARC results from the receiving server's
 * Authentication-Results header (#580).
 *
 * Only one header is trusted: the topmost one from the configured server
 * (authserv-id), or the topmost one when no server is configured. Headers
 * further down were written by other servers, or by the sender, and are ignored.
 */
final readonly class AuthenticationResults
{
    public function __construct(
        public ?string $spf,
        public ?string $dkim,
        public ?string $dmarc,
    ) {}

    public static function from(RawHeaders $headers, string $authservId = ''): self
    {
        foreach ($headers->all('authentication-results') as $value) {
            $server = strtolower(trim((string) strtok($value, ';')));

            if ($authservId !== '' && $server !== strtolower($authservId)) {
                continue;
            }

            return self::parse($value);
        }

        return new self(null, null, null);
    }

    private static function parse(string $value): self
    {
        $results = [];
        preg_match_all('/\b(spf|dkim|dmarc)\s*=\s*([a-z]+)/i', $value, $matches, PREG_SET_ORDER);

        foreach ($matches as [, $method, $result]) {
            $method = strtolower($method);
            $result = strtolower($result);

            if (($results[$method] ?? null) !== 'pass') {
                $results[$method] = $result;
            }
        }

        return new self($results['spf'] ?? null, $results['dkim'] ?? null, $results['dmarc'] ?? null);
    }

    public function present(): bool
    {
        return $this->spf !== null || $this->dkim !== null || $this->dmarc !== null;
    }

    /** SPF or DKIM passed and DMARC did not fail. */
    public function passes(): bool
    {
        return ($this->spf === 'pass' || $this->dkim === 'pass') && $this->dmarc !== 'fail';
    }
}
