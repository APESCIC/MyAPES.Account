import assert from 'node:assert/strict';
import test from 'node:test';
import { OWNER_SEARCH_MIN, shouldSearchOwners } from './owner-picker.js';

test('owner search waits for at least two characters', () => {
    assert.equal(OWNER_SEARCH_MIN, 2);
    assert.equal(shouldSearchOwners('a'), false);
    assert.equal(shouldSearchOwners(' a '), false);
    assert.equal(shouldSearchOwners('al'), true);
});
