import assert from 'node:assert/strict';
import test from 'node:test';

import {
    compareVersions,
    extractPullRequestNumbers,
    sortVersionsAscending,
} from './map-release-commits.mjs';

import {
    buildReleaseNotes,
    isPublicFacing,
    planBackfill,
    tagName,
} from './backfill-github-releases.mjs';

test('compareVersions sorts semver ascending', () => {
    assert.deepEqual(
        sortVersionsAscending(['0.31.6', '0.2.0', '0.10.0', '0.1.0']),
        ['0.1.0', '0.2.0', '0.10.0', '0.31.6'],
    );
});

test('extractPullRequestNumbers reads references and provenance', () => {
    const numbers = extractPullRequestNumbers({
        references: [
            { label: 'Pull request #168', url: 'https://github.com/APESCIC/MyAPES-Account/pull/168' },
            { label: 'PR #9', url: 'https://github.com/APESCIC/MyAPES-Account/pull/9' },
        ],
        provenance: 'Defined by pull request #170 and issue #169.',
    });

    assert.deepEqual(numbers.sort((a, b) => a - b), [9, 168, 170]);
});

test('buildReleaseNotes includes public summary and changes only', () => {
    const notes = buildReleaseNotes({
        version: '0.31.3',
        date: '2026-08-29',
        channel: 'stable',
        type: 'patch',
        title: 'Manual Cloudron deploy and ship-gate workflow',
        summary: 'Cloudron deploy is operator-triggered only.',
        changes: ['Stop auto-deploy on green main.'],
        affected_areas: ['Cloudron deployment workflow'],
        audiences: ['public-facing', 'internal-only'],
        version_rationale: 'Patch release for operators.',
        references: [
            {
                label: 'Pull request #168',
                url: 'https://github.com/APESCIC/MyAPES-Account/pull/168',
            },
        ],
    });

    assert.match(notes, /Cloudron deploy is operator-triggered only/);
    assert.match(notes, /Stop auto-deploy on green main/);
    assert.match(notes, /Pull request #168/);
    assert.doesNotMatch(notes, /rollback/i);
});

test('internal-only release notes omit version rationale when not public-facing', () => {
    const notes = buildReleaseNotes({
        version: '0.31.6',
        date: '2026-08-29',
        channel: 'stable',
        type: 'patch',
        title: 'Laragon local dev testing setup',
        summary: 'Windows developers can run MyAPES Account on Laragon.',
        changes: ['Add .env.laragon.example.'],
        affected_areas: ['Local development documentation'],
        audiences: ['internal-only'],
        version_rationale: 'Patch release: local development tooling only.',
        references: [],
    });

    assert.match(notes, /Laragon/);
    assert.doesNotMatch(notes, /Version rationale/);
});

test('planBackfill respects --from version filter', () => {
    const releases = [
        { version: '0.1.0', title: 'A', audiences: ['public-facing'], summary: '', changes: [], affected_areas: [], date: '2026-01-01', channel: 'beta', type: 'minor' },
        { version: '0.2.0', title: 'B', audiences: ['public-facing'], summary: '', changes: [], affected_areas: [], date: '2026-01-02', channel: 'beta', type: 'minor' },
    ];
    const mapByVersion = new Map([
        ['0.1.0', { version: '0.1.0', commit_sha: 'a'.repeat(40), source: 'pr-merge' }],
        ['0.2.0', { version: '0.2.0', commit_sha: 'b'.repeat(40), source: 'pr-merge' }],
    ]);

    const planned = planBackfill(releases, mapByVersion, { fromVersion: '0.2.0', resume: false });
    assert.equal(planned.length, 1);
    assert.equal(planned[0].version, '0.2.0');
    assert.equal(planned[0].status, 'create');
});

test('tagName prefixes v for GitHub', () => {
    assert.equal(tagName('0.31.6'), 'v0.31.6');
});

test('isPublicFacing detects audience membership', () => {
    assert.equal(isPublicFacing({ audiences: ['internal-only'] }), false);
    assert.equal(isPublicFacing({ audiences: ['public-facing', 'internal-only'] }), true);
});

test('compareVersions handles patch ordering', () => {
    assert.ok(compareVersions('0.31.6', '0.31.5') > 0);
    assert.ok(compareVersions('0.9.2', '0.10.0') < 0);
});
