import assert from 'node:assert/strict';
import test from 'node:test';
import { shouldSearchUsers, USER_SEARCH_MIN } from './message-filter.js';

test('staff message user search waits for at least two characters', () => {
    assert.equal(USER_SEARCH_MIN, 2);
    assert.equal(shouldSearchUsers('a'), false);
    assert.equal(shouldSearchUsers(' a '), false);
    assert.equal(shouldSearchUsers('ab'), true);
});
