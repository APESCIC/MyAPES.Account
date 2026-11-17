import assert from 'node:assert/strict';
import test from 'node:test';

import { filterReleases, hashToReleaseId, matchesRelease } from './change-log.js';

const releases = [
    {
        version: '0.6.0',
        current: true,
        channel: 'stable',
        categories: ['added', 'accessibility'],
        audiences: ['public-facing'],
        searchText: '0.6.0 Change Log Hub semantic version footer Accessibility Public-facing',
    },
    {
        version: '0.4.0',
        current: false,
        channel: 'beta',
        categories: ['added', 'changed', 'security'],
        audiences: ['internal-only'],
        searchText: '0.4.0 Staff authentication directory eligibility Security Internal-only',
    },
    {
        version: '0.4.1',
        current: false,
        channel: 'beta',
        categories: ['fixed', 'security'],
        audiences: ['internal-only'],
        searchText: '0.4.1 Continuous integration application key correction Fixed Security Internal-only',
    },
    {
        version: '0.3.0',
        current: false,
        channel: 'stable',
        categories: ['removed', 'compliance'],
        audiences: ['public-facing'],
        searchText: '0.3.0 Retired legacy wording Compliance Public-facing',
    },
];

test('search matching is trimmed and case-insensitive across indexed release text', () => {
    assert.equal(matchesRelease(releases[0], '  SEMANTIC  ', 'all'), true);
    assert.equal(matchesRelease(releases[1], 'directory eligibility', 'all'), true);
    assert.equal(matchesRelease(releases[2], 'APPLICATION KEY', 'all'), true);
    assert.equal(matchesRelease(releases[3], 'legacy wording', 'all'), true);
    assert.equal(matchesRelease(releases[0], 'not present', 'all'), false);
});

test('every supported filter class selects only matching releases', () => {
    const expectedVersions = {
        current: ['0.6.0'],
        beta: ['0.4.0', '0.4.1'],
        added: ['0.6.0', '0.4.0'],
        changed: ['0.4.0'],
        fixed: ['0.4.1'],
        removed: ['0.3.0'],
        security: ['0.4.0', '0.4.1'],
        compliance: ['0.3.0'],
        accessibility: ['0.6.0'],
        'public-facing': ['0.6.0', '0.3.0'],
        'internal-only': ['0.4.0', '0.4.1'],
    };

    for (const [filter, versions] of Object.entries(expectedVersions)) {
        assert.deepEqual(
            filterReleases(releases, '', filter).map((release) => release.version),
            versions,
            `Unexpected result for ${filter}`,
        );
    }
});

test('search and the selected filter combine without broadening either condition', () => {
    assert.deepEqual(
        filterReleases(releases, 'authentication', 'security').map((release) => release.version),
        ['0.4.0'],
    );
    assert.deepEqual(filterReleases(releases, 'footer', 'beta'), []);
});

test('all releases resets filtering while preserving the active search', () => {
    assert.deepEqual(
        filterReleases(releases, '', 'all').map((release) => release.version),
        ['0.6.0', '0.4.0', '0.4.1', '0.3.0'],
    );
    assert.deepEqual(
        filterReleases(releases, 'security', 'all').map((release) => release.version),
        ['0.4.0', '0.4.1'],
    );
});

test('an unmatched search produces an empty result', () => {
    assert.deepEqual(filterReleases(releases, 'consultation scheduling', 'all'), []);
});

test('hashToReleaseId accepts release article hashes and rejects everything else', () => {
    assert.equal(hashToReleaseId('#release-v0-69-0'), 'release-v0-69-0');
    assert.equal(hashToReleaseId('release-v1-11-0'), 'release-v1-11-0');
    assert.equal(hashToReleaseId('#RELEASE-V0-6-0'), 'release-v0-6-0');
    assert.equal(hashToReleaseId(''), null);
    assert.equal(hashToReleaseId('#'), null);
    assert.equal(hashToReleaseId('#not-a-release'), null);
    assert.equal(hashToReleaseId('#release-v'), null);
});
