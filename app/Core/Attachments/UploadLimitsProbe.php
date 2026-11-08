<?php

namespace App\Core\Attachments;

/**
 * Reads the PHP upload limits that apply to web requests (#697).
 *
 * Commands run under the CLI, where `public/.htaccess` is not applied, so the
 * web value is the `.htaccess` override when there is one, else php.ini.
 */
class UploadLimitsProbe
{
    public const SETTINGS = ['upload_max_filesize', 'post_max_size', 'max_file_uploads'];

    /**
     * @return array<string, array{ini: ?string, htaccess: ?string, effective: ?string}>
     */
    public function limits(): array
    {
        $overrides = $this->htaccessOverrides();
        $limits = [];

        foreach (self::SETTINGS as $setting) {
            $ini = ini_get($setting);
            $ini = $ini === false ? null : (string) $ini;
            $override = $overrides[$setting] ?? null;

            $limits[$setting] = [
                'ini' => $ini,
                'htaccess' => $override,
                'effective' => $override ?? $ini,
            ];
        }

        return $limits;
    }

    /**
     * @return array<string, string>
     */
    public function htaccessOverrides(): array
    {
        $path = public_path('.htaccess');
        $contents = is_readable($path) ? (string) file_get_contents($path) : '';
        $overrides = [];

        if (preg_match_all('/^\s*php_value\s+(\S+)\s+(\S+)\s*$/mi', $contents, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $overrides[strtolower($match[1])] = $match[2];
            }
        }

        return $overrides;
    }

    /**
     * Converts php.ini shorthand such as "52M" or "2G" to bytes.
     */
    public static function toBytes(?string $value): ?int
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        $number = (int) $value;

        return match (strtoupper(substr($value, -1))) {
            'G' => $number * 1024 * 1024 * 1024,
            'M' => $number * 1024 * 1024,
            'K' => $number * 1024,
            default => $number,
        };
    }
}
