import assert from 'node:assert/strict';
import { test } from 'node:test';
import { buildSteps, firstVisibleTarget, popoverDescription, readTourConfig, saveTourResult } from './guided-tour.js';

const fakeRoot = (elementsBySelector, scriptText = null) => ({
    querySelectorAll: (selector) => elementsBySelector[selector] ?? [],
    querySelector: (selector) => (selector === 'script[data-guided-tour]' && scriptText !== null ? { textContent: scriptText } : null),
});

test('firstVisibleTarget falls back to the next selector when the first is hidden', () => {
    const desktopNav = { id: 'nav', visible: false };
    const menuButton = { id: 'menu', visible: true };
    const root = fakeRoot({ '.nav': [desktopNav], '.menu': [menuButton] });

    assert.equal(firstVisibleTarget(['.nav', '.menu'], root, (element) => element.visible), menuButton);
    assert.equal(firstVisibleTarget(['.missing'], root, () => true), null);
});

test('buildSteps highlights visible targets and leaves an unmatched step centred', () => {
    const brand = { visible: true };
    const menu = { visible: false };
    const root = fakeRoot({ '.brand': [brand], '.menu': [menu] });
    const steps = buildSteps({
        image: '/mascot/spike-tip.png',
        steps: [
            { targets: ['.menu', '.brand'], title: 'Hello', body: 'Welcome <b>in</b>' },
            { targets: ['.gone'], title: 'Gone', body: 'Nothing here' },
        ],
    }, root, (element) => element.visible);

    assert.equal(steps[0].element(), brand);
    menu.visible = true;
    assert.equal(steps[0].element(), menu, 'the target is re-resolved when the step shows');
    assert.equal(steps[0].popover.title, 'Hello');
    assert.match(steps[0].popover.description, /Welcome &lt;b&gt;in&lt;\/b&gt;/);
    assert.match(steps[0].popover.description, /src="\/mascot\/spike-tip.png"/);
    assert.equal('element' in steps[1], false);
});

test('popoverDescription escapes text when there is no image', () => {
    assert.equal(popoverDescription('a < b', null), 'a &lt; b');
});

test('readTourConfig ignores missing, broken or empty configs', () => {
    assert.equal(readTourConfig(fakeRoot({})), null);
    assert.equal(readTourConfig(fakeRoot({}, '{not json')), null);
    assert.equal(readTourConfig(fakeRoot({}, '{"steps":[]}')), null);
    assert.deepEqual(readTourConfig(fakeRoot({}, '{"steps":[{"targets":[]}]}')).steps.length, 1);
});

test('saveTourResult posts the skipped flag with the CSRF token', async () => {
    const calls = [];
    await saveTourResult(
        { completeUrl: '/tour/staff/complete', csrfToken: 'token-123' },
        true,
        (url, options) => {
            calls.push([url, options]);

            return Promise.resolve();
        },
    );

    assert.equal(calls.length, 1);
    assert.equal(calls[0][0], '/tour/staff/complete');
    assert.equal(calls[0][1].method, 'POST');
    assert.equal(calls[0][1].headers['X-CSRF-TOKEN'], 'token-123');
    assert.deepEqual(JSON.parse(calls[0][1].body), { skipped: true });
});
