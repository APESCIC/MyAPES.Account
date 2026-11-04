// Replaces inline onsubmit/onchange handlers so pages work under a strict
// Content Security Policy (#485): data-confirm asks before a form submits,
// data-auto-submit submits the field's form when it changes.

export const confirmMessageFor = (form) => {
    const message = form?.dataset?.confirm;

    return typeof message === 'string' && message.trim() !== '' ? message : null;
};

export const initFormActions = (root = document, confirmFn = (message) => window.confirm(message)) => {
    root.addEventListener('submit', (event) => {
        const form = event.target;
        const message = confirmMessageFor(form);

        if (message !== null && !confirmFn(message)) {
            event.preventDefault();
        }
    }, true);

    root.addEventListener('change', (event) => {
        const field = event.target;

        if (field?.dataset && 'autoSubmit' in field.dataset && field.form) {
            field.form.requestSubmit ? field.form.requestSubmit() : field.form.submit();
        }
    });
};
