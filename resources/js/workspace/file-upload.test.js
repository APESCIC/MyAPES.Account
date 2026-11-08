import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    checkFiles,
    formatBytes,
    initFileUploads,
    matchesAccept,
    messageFor,
    perFileProgress,
    withoutDuplicates,
} from './file-upload.js';

const MB = 1024 * 1024;
const file = (name, size, type = '') => ({ name, size, type });
const documents = { maxFiles: 10, maxBytes: 10 * MB, accept: '.pdf,.docx,.xlsx,.odt,.ods,.txt,.csv', maxTotalBytes: 100 * MB };
const screenshots = { maxFiles: 10, maxBytes: 5 * MB, accept: 'image/jpeg,image/png,image/webp', maxTotalBytes: 100 * MB };

test('formatBytes shows kilobytes and megabytes', () => {
    assert.equal(formatBytes(512), '512 bytes');
    assert.equal(formatBytes(1536), '1.5 KB');
    assert.equal(formatBytes(2048), '2 KB');
    assert.equal(formatBytes(5 * MB), '5 MB');
    assert.equal(formatBytes(1.5 * MB), '1.5 MB');
});

test('matchesAccept checks extensions and MIME types', () => {
    assert.equal(matchesAccept(file('report.PDF', 1), documents.accept), true);
    assert.equal(matchesAccept(file('macro.docm', 1), documents.accept), false);
    assert.equal(matchesAccept(file('legacy.doc', 1), documents.accept), false);
    assert.equal(matchesAccept(file('shot.png', 1, 'image/png'), screenshots.accept), true);
    assert.equal(matchesAccept(file('shot.gif', 1, 'image/gif'), screenshots.accept), false);
    assert.equal(matchesAccept(file('clip.mp4', 1, 'video/mp4'), 'video/*'), true);
    assert.equal(matchesAccept(file('anything.bin', 1), ''), true);
});

test('checkFiles refuses the wrong type and files over the size limit', () => {
    const { accepted, rejected } = checkFiles(
        [file('ok.pdf', MB), file('virus.exe', MB), file('huge.pdf', 11 * MB)],
        documents,
    );

    assert.deepEqual(accepted.map((entry) => entry.name), ['ok.pdf']);
    assert.deepEqual(rejected.map(({ file: entry, reason }) => [entry.name, reason]), [
        ['virus.exe', 'type'],
        ['huge.pdf', 'size'],
    ]);
});

test('checkFiles stops at the per-field count, counting files already chosen', () => {
    const current = Array.from({ length: 9 }, (_, index) => file(`doc-${index}.pdf`, 10));
    const { accepted, rejected } = checkFiles([file('ten.pdf', 10), file('eleven.pdf', 10)], documents, current);

    assert.deepEqual(accepted.map((entry) => entry.name), ['ten.pdf']);
    assert.deepEqual(rejected.map(({ reason }) => reason), ['count']);
});

test('checkFiles enforces the submission total across fields', () => {
    const { accepted, rejected } = checkFiles([file('a.pdf', 6 * MB), file('b.pdf', 6 * MB)], documents, [], 90 * MB);

    assert.deepEqual(accepted.map((entry) => entry.name), ['a.pdf']);
    assert.deepEqual(rejected.map(({ reason }) => reason), ['total']);
});

test('perFileProgress spreads the bytes sent across files in order', () => {
    assert.deepEqual(perFileProgress([100, 200, 100], 0), [0, 0, 0]);
    assert.deepEqual(perFileProgress([100, 200, 100], 150), [100, 25, 0]);
    assert.deepEqual(perFileProgress([100, 200, 100], 1000), [100, 100, 100]);
    assert.deepEqual(perFileProgress([0], 0), [100]);
});

test('withoutDuplicates ignores a file chosen twice', () => {
    const first = { name: 'a.pdf', size: 10, lastModified: 1 };
    const again = { name: 'a.pdf', size: 10, lastModified: 1 };
    const other = { name: 'b.pdf', size: 10, lastModified: 1 };

    assert.deepEqual(withoutDuplicates([again, other, other], [first]).map((entry) => entry.name), ['b.pdf']);
});

test('messageFor fills in the file name and limits', () => {
    const labels = {
        size: ':name was not added: it is larger than :size.',
        count: ':name was not added: you can attach up to :max files here.',
        total: ':name was not added: all files together must be :total or less.',
        type: ':name was not added.',
    };

    assert.equal(messageFor('size', file('big.pdf', 1), documents, labels), 'big.pdf was not added: it is larger than 10 MB.');
    assert.equal(messageFor('count', file('more.pdf', 1), documents, labels), 'more.pdf was not added: you can attach up to 10 files here.');
    assert.equal(messageFor('total', file('last.pdf', 1), documents, labels), 'last.pdf was not added: all files together must be 100 MB or less.');
});

test('initFileUploads does nothing on pages without upload fields', () => {
    let listened = false;
    const root = {
        querySelectorAll: () => [],
        addEventListener: () => { listened = true; },
    };

    assert.deepEqual(initFileUploads(root), []);
    assert.equal(listened, false);
});
