// Google reCAPTCHA v3 on the public forms (#637). The token is fetched when
// the form is submitted, because tokens expire after two minutes. If Google's
// script does not load or answer, the form is sent with the "unavailable"
// marker and the server applies its unverified fallback instead.

const GOOGLE_WAIT_MS = 5000;

const fetchToken = (win, siteKey, action) => new Promise((resolve, reject) => {
    const grecaptcha = win.grecaptcha;
    if (!grecaptcha || typeof grecaptcha.ready !== 'function') {
        reject(new Error('reCAPTCHA is not loaded'));

        return;
    }

    const timer = win.setTimeout(() => reject(new Error('reCAPTCHA timed out')), GOOGLE_WAIT_MS);
    grecaptcha.ready(() => {
        grecaptcha.execute(siteKey, { action }).then((token) => {
            win.clearTimeout(timer);
            resolve(token);
        }, (error) => {
            win.clearTimeout(timer);
            reject(error);
        });
    });
});

export const initBotProtection = (doc = document, win = window) => {
    const inputs = [...doc.querySelectorAll('input[data-recaptcha-token]')];

    for (const input of inputs) {
        const form = input.form;
        if (!form) {
            continue;
        }

        form.addEventListener('submit', (event) => {
            if (input.value !== '') {
                return;
            }

            event.preventDefault();
            const submitter = event.submitter ?? undefined;
            const send = (token) => {
                input.value = token;
                form.requestSubmit ? form.requestSubmit(submitter) : form.submit();
            };

            fetchToken(win, input.dataset.recaptchaSiteKey ?? '', input.dataset.recaptchaAction ?? '')
                .then((token) => send(typeof token === 'string' && token !== '' ? token : input.dataset.recaptchaUnavailable ?? 'unavailable'))
                .catch(() => send(input.dataset.recaptchaUnavailable ?? 'unavailable'));
        });
    }

    return inputs.length;
};
