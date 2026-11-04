import assert from 'node:assert/strict';
import { test } from 'node:test';
import { initRichTextEditors, RICH_TEXT_PLUGINS, richTextConfig } from './rich-text-editor.js';

const PREMIUM = ['tinymcespellchecker', 'powerpaste', 'mentions', 'ai', 'comments', 'exportpdf', 'formatpainter'];

test('only open-source plugins are enabled', () => {
    for (const plugin of PREMIUM) {
        assert.equal(RICH_TEXT_PLUGINS.includes(plugin), false, plugin);
    }
    assert.ok(RICH_TEXT_PLUGINS.includes('lists'));
    assert.ok(RICH_TEXT_PLUGINS.includes('link'));
});

test('config targets the selector and follows the theme', () => {
    assert.equal(richTextConfig('textarea[data-rich-text]', false).selector, 'textarea[data-rich-text]');
    assert.equal(richTextConfig('x', true).skin, 'oxide-dark');
    assert.equal(richTextConfig('x', false).skin, 'oxide');
});

test('setup removes required once the editor is ready', () => {
    let removed = null;
    const handlers = {};
    richTextConfig('x', false).setup({
        on: (name, handler) => { handlers[name] = handler; },
        getElement: () => ({ removeAttribute: (name) => { removed = name; } }),
    });
    handlers.init();
    assert.equal(removed, 'required');
});

test('nothing initialises without TinyMCE or a rich text field', () => {
    const doc = { querySelector: () => ({}), documentElement: { dataset: {} } };
    assert.equal(initRichTextEditors({}, doc), false);

    let initialised = 0;
    const win = { tinymce: { init: () => { initialised += 1; } } };
    assert.equal(initRichTextEditors(win, { querySelector: () => null, documentElement: { dataset: {} } }), false);
    assert.equal(initRichTextEditors(win, doc), true);
    assert.equal(initialised, 1);
});
