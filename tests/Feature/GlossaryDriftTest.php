<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Fails when deprecated glossary terms reappear in live lang values or Blade (#875).
 */
class GlossaryDriftTest extends TestCase
{
    public function test_language_files_and_views_avoid_deprecated_display_terms(): void
    {
        // Focus on the v0.73.0 account-type renames (#875), not every historic synonym.
        $synonyms = [
            'Public user',
            'Public users',
            'Staff user',
            'Staff users',
            'Service user',
            'service-user',
        ];

        $allowList = [
            // Deprecated keys themselves may still hold the old labels.
            'lang'.DIRECTORY_SEPARATOR.'en'.DIRECTORY_SEPARATOR.'terms.php',
            'lang'.DIRECTORY_SEPARATOR.'en_GB'.DIRECTORY_SEPARATOR.'terms.php',
            'config'.DIRECTORY_SEPARATOR.'glossary.php',
            'docs'.DIRECTORY_SEPARATOR,
            'changelog'.DIRECTORY_SEPARATOR,
            'resources'.DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'releases.json',
            'tests'.DIRECTORY_SEPARATOR,
        ];

        $roots = [
            base_path('lang'),
            resource_path('views'),
        ];

        $hits = [];

        foreach ($roots as $root) {
            foreach (File::allFiles($root) as $file) {
                $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
                $skip = false;
                foreach ($allowList as $allowed) {
                    if (str_starts_with($relative, $allowed)) {
                        $skip = true;
                        break;
                    }
                }
                if ($skip) {
                    continue;
                }

                $contents = $file->getContents();
                foreach ($synonyms as $pattern) {
                    if ($pattern === '') {
                        continue;
                    }
                    $quoted = preg_quote($pattern, '/');
                    $regex = $pattern === 'service-user'
                        ? "/(?<!['\\w]){$quoted}(?!['\\w-])/"
                        : "/\\b{$quoted}\\b/i";
                    if (! preg_match($regex, $contents)) {
                        continue;
                    }
                    // Stored role slug keys (e.g. 'service-user' => ...) are not display copy.
                    if ($pattern === 'service-user' && preg_match("/['\"]service-user['\"]\\s*=>/", $contents)) {
                        continue;
                    }
                    $hits[] = "{$relative} contains deprecated term [{$pattern}]";
                }
            }
        }

        $this->assertSame([], $hits, implode(PHP_EOL, array_slice($hits, 0, 30)));
    }
}
