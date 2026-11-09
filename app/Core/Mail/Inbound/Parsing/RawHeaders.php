<?php

namespace App\Core\Mail\Inbound\Parsing;

/**
 * The top-level header block of a raw email, in order, with repeated
 * headers kept apart (#580). Authentication-Results and Received depend on
 * order and must never be merged.
 */
final readonly class RawHeaders
{
    /**
     * @param  list<array{name: string, value: string}>  $headers
     */
    private function __construct(private array $headers) {}

    public static function fromRaw(string $raw): self
    {
        $raw = str_replace("\r\n", "\n", $raw);
        $end = strpos($raw, "\n\n");
        $block = $end === false ? $raw : substr($raw, 0, $end);
        $unfolded = (string) preg_replace("/\n[ \t]+/", ' ', $block);
        $headers = [];

        foreach (explode("\n", $unfolded) as $line) {
            $colon = strpos($line, ':');

            if ($colon === false || $colon === 0) {
                continue;
            }

            $headers[] = [
                'name' => strtolower(trim(substr($line, 0, $colon))),
                'value' => trim(substr($line, $colon + 1)),
            ];
        }

        return new self($headers);
    }

    public function first(string $name): ?string
    {
        foreach ($this->headers as $header) {
            if ($header['name'] === strtolower($name)) {
                return $header['value'];
            }
        }

        return null;
    }

    /**
     * @return list<string> every value of the header, top first
     */
    public function all(string $name): array
    {
        $name = strtolower($name);

        return array_values(array_map(
            static fn (array $header): string => $header['value'],
            array_filter($this->headers, static fn (array $header): bool => $header['name'] === $name),
        ));
    }

    public function has(string $name): bool
    {
        return $this->first($name) !== null;
    }

    /**
     * Message ids from Message-ID, In-Reply-To or References, without angle brackets.
     *
     * @return list<string>
     */
    public function messageIds(string $name): array
    {
        $ids = [];

        foreach ($this->all($name) as $value) {
            preg_match_all('/<([^<>\s]+)>/', $value, $matches);
            $ids = [...$ids, ...$matches[1]];
        }

        return array_values(array_unique(array_map('strtolower', $ids)));
    }
}
