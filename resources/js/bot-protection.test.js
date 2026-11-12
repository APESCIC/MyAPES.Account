import assert from 'node:assert/strict';
import { test } from 'node:test';
import { initBotProtection } from './bot-protection.js';

const fakeForm = () => {
    const listeners = [];
    const form = {
        submitted: 0,
        addEventListener: (type, listener) => listeners.push(listener),
        requestSubmit: () => {
            const event = { defaultPrevented: false, submitter: undefined, preventDefault() { this.defaultPrevented = true; } };
            listeners.forEach((listener) => listener(event));
            if (!event.defaultPrevented) {
                form.submitted += 1;
            }
        },
    };
    const input = { value: '', form, dataset: { recaptchaSiteKey: 'site', recaptchaAction: 'register', recaptchaUnavailable: 'unavailable' } };
    const doc = { querySelectorAll: () => [input] };

    return { form, input, doc };
};

const win = (grecaptcha) => ({ grecaptcha, setTimeout, clearTimeout });

const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

test('the token is fetched on submit for the form action, then the form is sent', async () => {
    const { form, input, doc } = fakeForm();
    const calls = [];
    initBotProtection(doc, win({ ready: (callback) => callback(), execute: (key, options) => { calls.push([key, options.action]); return Promise.resolve('google-token'); } }));

    form.requestSubmit();
    assert.equal(form.submitted, 0);
    await settle();

    assert.deepEqual(calls, [['site', 'register']]);
    assert.equal(input.value, 'google-token');
    assert.equal(form.submitted, 1);
});

test('without Google the form is sent with the unavailable marker', async () => {
    const { form, input, doc } = fakeForm();
    initBotProtection(doc, win(undefined));

    form.requestSubmit();
    await settle();

    assert.equal(input.value, 'unavailable');
    assert.equal(form.submitted, 1);
});
