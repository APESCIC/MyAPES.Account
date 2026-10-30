#!/usr/bin/env node
/**
 * Backfill GitHub Releases and tags from releases.json + release-commit-map.json.
 *
 * Usage:
 *   node scripts/local/backfill-github-releases.mjs [--dry-run] [--from=0.1.0] [--resume] [--sleep-ms=500]
 */

import { execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

import {
    compareVersions,
    loadReleases,
    sortVersionsAscending,
} from './map-release-commits.mjs';

const __dirname = dirname(fileURLToPath(import.meta.url));
const repoRoot = join(__dirname, '..', '..');
const REPO = 'APESCIC/MyAPES-Account';

export function parseBackfillArgs(argv) {
    const options = {
        dryRun: false,
        resume: false,
        fromVersion: null,
        sleepMs: 500,
        mapPath: join(repoRoot, 'release-commit-map.json'),
        releasesPath: join(repoRoot, 'resources/data/releases.json'),
    };

    for (const arg of argv) {
        if (arg === '--dry-run') {
            options.dryRun = true;
        } else if (arg === '--resume') {
            options.resume = true;
        } else if (arg.startsWith('--from=')) {
            options.fromVersion = arg.slice('--from='.length);
        } else if (arg.startsWith('--sleep-ms=')) {
            options.sleepMs = Number(arg.slice('--sleep-ms='.length));
        } else if (arg.startsWith('--map=')) {
            options.mapPath = arg.slice('--map='.length);
        } else if (arg === '--help' || arg === '-h') {
            console.log('Usage: node scripts/local/backfill-github-releases.mjs [--dry-run] [--from=0.1.0] [--resume] [--sleep-ms=500]');
            process.exit(0);
        } else {
            throw new Error(`Unknown argument: ${arg}`);
        }
    }

    return options;
}

export function run(command, args, { allowFailure = false } = {}) {
    try {
        return execFileSync(command, args, {
            cwd: repoRoot,
            encoding: 'utf8',
            stdio: ['ignore', 'pipe', 'pipe'],
        }).trim();
    } catch (error) {
        if (allowFailure) {
            return null;
        }

        throw error;
    }
}

export function tagName(version) {
    return `v${version}`;
}

export function isPublicFacing(release) {
    return (release.audiences ?? []).includes('public-facing');
}

export function buildReleaseNotes(release) {
    const lines = [];
    lines.push(release.summary ?? '');
    lines.push('');

    if (Array.isArray(release.changes) && release.changes.length > 0) {
        lines.push('## Changes');
        for (const change of release.changes) {
            lines.push(`- ${change}`);
        }
        lines.push('');
    }

    if (Array.isArray(release.affected_areas) && release.affected_areas.length > 0) {
        lines.push('## Affected areas');
        for (const area of release.affected_areas) {
            lines.push(`- ${area}`);
        }
        lines.push('');
    }

    if (isPublicFacing(release) && release.version_rationale) {
        lines.push('## Version rationale');
        lines.push(release.version_rationale);
        lines.push('');
    }

    const refs = release.references ?? [];
    if (refs.length > 0) {
        lines.push('## References');
        for (const ref of refs) {
            lines.push(`- [${ref.label}](${ref.url})`);
        }
        lines.push('');
    }

    lines.push(`_MyAPES Account ${release.version} (${release.date}) · ${release.channel} · ${release.type}_`);

    return lines.join('\n').trim();
}

export function releaseExists(tag) {
    const output = run('gh', ['release', 'view', tag, '--repo', REPO], { allowFailure: true });
    return output !== null;
}

export function tagExists(tag) {
    const output = run('git', ['rev-parse', '--verify', `refs/tags/${tag}`], { allowFailure: true });
    return output !== null;
}

export function remoteTagExists(tag) {
    const output = run('git', ['ls-remote', '--tags', 'origin', `refs/tags/${tag}`], { allowFailure: true });
    return Boolean(output);
}

export function sleep(ms) {
    if (ms <= 0) {
        return;
    }

    Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, ms);
}

export function loadCommitMap(path) {
    const data = JSON.parse(readFileSync(path, 'utf8'));
    const byVersion = new Map(data.mappings.map((entry) => [entry.version, entry]));

    return {
        report: data,
        byVersion,
    };
}

export function planBackfill(releases, mapByVersion, options = {}) {
    const versions = sortVersionsAscending(releases.map((release) => release.version));
    const fromVersion = options.fromVersion;
    const releaseByVersion = new Map(releases.map((release) => [release.version, release]));
    const planned = [];

    for (const version of versions) {
        if (fromVersion && compareVersions(version, fromVersion) < 0) {
            continue;
        }

        const mapping = mapByVersion.get(version);
        const release = releaseByVersion.get(version);
        if (!mapping || !release) {
            planned.push({ version, status: 'missing-mapping' });
            continue;
        }

        const tag = tagName(version);
        const exists = options.resume && releaseExists(tag);

        planned.push({
            version,
            tag,
            commit_sha: mapping.commit_sha,
            title: release.title,
            notes: buildReleaseNotes(release),
            status: exists ? 'skip-existing' : 'create',
            source: mapping.source,
        });
    }

    return planned;
}

export function createTag(tag, sha, title, dryRun) {
    if (dryRun) {
        console.log(`[dry-run] git tag -a ${tag} ${sha} -m "${title}"`);
        return;
    }

    if (tagExists(tag)) {
        console.log(`Tag ${tag} already exists locally; skipping tag create.`);
        return;
    }

    run('git', ['tag', '-a', tag, sha, '-m', title]);

    if (!remoteTagExists(tag)) {
        run('git', ['push', 'origin', tag]);
    }
}

export function createRelease(tag, sha, title, notes, dryRun) {
    if (dryRun) {
        console.log(`[dry-run] gh release create ${tag} --target ${sha} --title "${title}"`);
        return;
    }

    const tempDir = mkdtempSync(join(tmpdir(), 'myapes-release-'));
    const notesPath = join(tempDir, `${tag}.md`);

    try {
        writeFileSync(notesPath, `${notes}\n`, 'utf8');
        run('gh', [
            'release',
            'create',
            tag,
            '--repo',
            REPO,
            '--target',
            sha,
            '--title',
            title,
            '--notes-file',
            notesPath,
        ]);
    } finally {
        rmSync(tempDir, { recursive: true, force: true });
    }
}

export async function executeBackfill(planned, options) {
    let created = 0;
    let skipped = 0;
    let failed = 0;

    for (const item of planned) {
        if (item.status === 'missing-mapping') {
            console.error(`Missing mapping for ${item.version}`);
            failed += 1;
            continue;
        }

        if (item.status === 'skip-existing') {
            console.log(`Skipping existing release ${item.tag}`);
            skipped += 1;
            continue;
        }

        try {
            console.log(`Creating ${item.tag} at ${item.commit_sha.slice(0, 12)} (${item.source})`);
            createTag(item.tag, item.commit_sha, item.title, options.dryRun);
            createRelease(item.tag, item.commit_sha, item.title, item.notes, options.dryRun);
            created += 1;
            sleep(options.sleepMs);
        } catch (error) {
            console.error(`Failed ${item.tag}: ${error.message}`);
            failed += 1;
            if (!options.resume) {
                throw error;
            }
        }
    }

    return { created, skipped, failed };
}

async function main() {
    const options = parseBackfillArgs(process.argv.slice(2));
    const releases = loadReleases(options.releasesPath);
    const { report, byVersion } = loadCommitMap(options.mapPath);

    if (report.errors?.length) {
        throw new Error(`Commit map has errors: ${report.errors.join('; ')}`);
    }

    const planned = planBackfill(releases, byVersion, options);
    const summary = {
        dry_run: options.dryRun,
        total: planned.length,
        to_create: planned.filter((item) => item.status === 'create').length,
        to_skip: planned.filter((item) => item.status === 'skip-existing').length,
    };

    console.log(JSON.stringify(summary, null, 2));

    const result = await executeBackfill(planned, options);
    console.log(JSON.stringify({ ...summary, result }, null, 2));

    if (result.failed > 0) {
        process.exitCode = 1;
    }
}

if (process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1]) {
    main().catch((error) => {
        console.error(error.message);
        process.exit(1);
    });
}
