<?php

namespace App\Support;

/**
 * Reads the checked-out commit from .git, for the local preview only. Release
 * bundles carry a REVISION file instead and have no .git folder.
 */
class LocalRevision
{
    public function __construct(private readonly string $basePath) {}

    public function current(): ?string
    {
        $gitDir = $this->gitDir();
        if ($gitDir === null || ! is_file($gitDir.'/HEAD')) {
            return null;
        }

        $head = trim((string) file_get_contents($gitDir.'/HEAD'));
        if (preg_match('/^[0-9a-f]{40}$/', $head) === 1) {
            return substr($head, 0, 12);
        }
        if (preg_match('#^ref: (refs/\S+)$#', $head, $match) !== 1) {
            return null;
        }

        $sha = $this->resolveRef($gitDir, $match[1]);

        return $sha === null ? null : substr($sha, 0, 12);
    }

    private function gitDir(): ?string
    {
        $dotGit = $this->basePath.'/.git';
        if (is_dir($dotGit)) {
            return $dotGit;
        }
        if (is_file($dotGit) && preg_match('/^gitdir: (.+)$/m', (string) file_get_contents($dotGit), $match) === 1) {
            $path = trim($match[1]);

            return preg_match('#^([A-Za-z]:)?[\\\\/]#', $path) === 1 ? $path : $this->basePath.'/'.$path;
        }

        return null;
    }

    private function resolveRef(string $gitDir, string $ref): ?string
    {
        foreach ([$gitDir, $this->commonDir($gitDir)] as $dir) {
            if (is_file($dir.'/'.$ref)) {
                $sha = trim((string) file_get_contents($dir.'/'.$ref));

                return preg_match('/^[0-9a-f]{40}$/', $sha) === 1 ? $sha : null;
            }
            if (is_file($dir.'/packed-refs')) {
                foreach (file($dir.'/packed-refs', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                    if (preg_match('/^([0-9a-f]{40}) '.preg_quote($ref, '/').'$/', $line, $match) === 1) {
                        return $match[1];
                    }
                }
            }
        }

        return null;
    }

    private function commonDir(string $gitDir): string
    {
        $file = $gitDir.'/commondir';

        return is_file($file) ? $gitDir.'/'.trim((string) file_get_contents($file)) : $gitDir;
    }
}
