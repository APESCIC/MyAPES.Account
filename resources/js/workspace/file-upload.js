// Multi-file upload fields for tickets and cases (#547).
//
// Each [data-file-upload] wraps a real <input type="file" multiple>, so the
// form still works without JavaScript. With JavaScript, files can be dropped
// or chosen in several goes, removed one by one, and are checked against the
// per-type and per-submission limits before upload. Forms with files are sent
// with XMLHttpRequest so each file shows its own progress; the server answers
// with the redirect target (X-Upload-Progress header) and the page follows it.

export const UPLOAD_PROGRESS_HEADER = 'X-Upload-Progress';

export const formatBytes = (bytes) => {
    const value = Math.max(Number(bytes) || 0, 0);

    if (value >= 1024 * 1024) {
        return `${(value / (1024 * 1024)).toFixed(1).replace(/\.0$/, '')} MB`;
    }
    if (value < 1024) {
        return `${value} bytes`;
    }

    return `${(value / 1024).toFixed(1).replace(/\.0$/, '')} KB`;
};

export const matchesAccept = (file, accept) => {
    const rules = String(accept ?? '')
        .split(',')
        .map((rule) => rule.trim().toLowerCase())
        .filter((rule) => rule !== '');

    if (rules.length === 0) {
        return true;
    }

    const name = String(file?.name ?? '').toLowerCase();
    const type = String(file?.type ?? '').toLowerCase();

    return rules.some((rule) => {
        if (rule.startsWith('.')) {
            return name.endsWith(rule);
        }
        if (rule.endsWith('/*')) {
            return type.startsWith(rule.slice(0, -1));
        }

        return type === rule;
    });
};

// Splits candidate files into accepted and rejected ones, given what is
// already selected in this field and in the whole group.
export const checkFiles = (candidates, limits, current = [], groupBytes = 0) => {
    const accepted = [];
    const rejected = [];
    let count = current.length;
    let total = groupBytes;

    for (const file of candidates) {
        let reason = null;

        if (!matchesAccept(file, limits.accept)) {
            reason = 'type';
        } else if (file.size > limits.maxBytes) {
            reason = 'size';
        } else if (count >= limits.maxFiles) {
            reason = 'count';
        } else if (limits.maxTotalBytes && total + file.size > limits.maxTotalBytes) {
            reason = 'total';
        }

        if (reason === null) {
            accepted.push(file);
            count += 1;
            total += file.size;
        } else {
            rejected.push({ file, reason });
        }
    }

    return { accepted, rejected };
};

// Turns the bytes sent so far into a percentage per file, in form order.
export const perFileProgress = (sizes, loaded) => {
    let remaining = Math.max(Number(loaded) || 0, 0);

    return sizes.map((size) => {
        if (size <= 0) {
            return 100;
        }
        const sent = Math.min(remaining, size);
        remaining -= sent;

        return Math.round((sent / size) * 100);
    });
};

const sameFile = (left, right) => left.name === right.name
    && left.size === right.size
    && left.lastModified === right.lastModified;

export const withoutDuplicates = (candidates, current) => candidates.filter(
    (candidate, index) => !current.some((file) => sameFile(file, candidate))
        && candidates.findIndex((other) => sameFile(other, candidate)) === index,
);

export const messageFor = (reason, file, limits, labels) => {
    const template = labels[reason] ?? labels.type ?? '';

    return template
        .replace(':name', file.name)
        .replace(':size', formatBytes(limits.maxBytes))
        .replace(':max', String(limits.maxFiles))
        .replace(':total', formatBytes(limits.maxTotalBytes ?? 0));
};

const readLimits = (field, group) => ({
    maxFiles: Number(field.dataset.maxFiles) || Infinity,
    maxBytes: Number(field.dataset.maxBytes) || Infinity,
    accept: field.dataset.accept ?? '',
    maxTotalBytes: Number(group?.dataset.maxTotalBytes) || 0,
});

const readLabels = (field) => ({
    type: field.dataset.labelType ?? '',
    size: field.dataset.labelSize ?? '',
    count: field.dataset.labelCount ?? '',
    total: field.dataset.labelTotal ?? '',
    remove: field.dataset.labelRemove ?? 'Remove :name',
    selected: field.dataset.labelSelected ?? ':count selected',
});

const syncInput = (input, files) => {
    if (typeof DataTransfer === 'undefined') {
        return;
    }
    const transfer = new DataTransfer();
    files.forEach((file) => transfer.items.add(file));
    input.files = transfer.files;
};

const initField = (field, registry) => {
    const input = field.querySelector('input[type="file"]');
    const list = field.querySelector('[data-file-upload-list]');
    const status = field.querySelector('[data-file-upload-status]');
    const drop = field.querySelector('[data-file-upload-drop]');
    const group = field.closest('[data-file-upload-group]');

    if (!(input instanceof HTMLInputElement) || !list || !status) {
        return;
    }

    const labels = readLabels(field);
    const state = { field, input, files: [], items: new Map() };
    registry.push(state);

    const groupBytes = () => registry
        .filter((entry) => entry.field.closest('[data-file-upload-group]') === group)
        .reduce((sum, entry) => sum + entry.files.reduce((total, file) => total + file.size, 0), 0);

    const render = (errors = []) => {
        list.replaceChildren();
        state.items.clear();

        state.files.forEach((file, index) => {
            const item = document.createElement('li');
            item.className = 'file-upload__item';
            const name = document.createElement('span');
            name.className = 'file-upload__name';
            name.textContent = `${file.name} (${formatBytes(file.size)})`;
            const progress = document.createElement('progress');
            progress.max = 100;
            progress.value = 0;
            progress.hidden = true;
            progress.setAttribute('aria-label', file.name);
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'button-secondary file-upload__remove';
            remove.textContent = '×';
            remove.setAttribute('aria-label', labels.remove.replace(':name', file.name));
            remove.addEventListener('click', () => {
                state.files.splice(index, 1);
                syncInput(input, state.files);
                render();
                input.focus();
            });
            item.append(name, progress, remove);
            list.append(item);
            state.items.set(file, progress);
        });

        errors.forEach((message) => {
            const item = document.createElement('li');
            item.className = 'file-upload__item file-upload__item--error';
            item.textContent = message;
            list.append(item);
        });

        const summary = state.files.length > 0 || errors.length > 0
            ? labels.selected.replace(':count', String(state.files.length))
            : '';
        status.textContent = [summary, ...errors].filter((part) => part !== '').join(' ');
    };

    const add = (candidates) => {
        const limits = readLimits(field, group);
        const fresh = withoutDuplicates([...candidates], state.files);
        const { accepted, rejected } = checkFiles(fresh, limits, state.files, groupBytes());
        state.files.push(...accepted);
        syncInput(input, state.files);
        render(rejected.map(({ file, reason }) => messageFor(reason, file, limits, labels)));
    };

    input.addEventListener('change', () => {
        const chosen = [...(input.files ?? [])];
        if (typeof DataTransfer === 'undefined') {
            state.files = chosen;
            render();

            return;
        }
        add(chosen);
    });

    if (drop) {
        ['dragenter', 'dragover'].forEach((type) => drop.addEventListener(type, (event) => {
            event.preventDefault();
            drop.classList.add('is-dragging');
        }));
        ['dragleave', 'drop'].forEach((type) => drop.addEventListener(type, () => drop.classList.remove('is-dragging')));
        drop.addEventListener('drop', (event) => {
            event.preventDefault();
            add(event.dataTransfer?.files ?? []);
        });
    }

    render();
};

const uploadWithProgress = (form, registry, submitter) => {
    const entries = registry.filter((entry) => entry.input.form === form);
    const files = entries.flatMap((entry) => entry.files.map((file) => ({ file, progress: entry.items.get(file) })));

    if (files.length === 0 || typeof XMLHttpRequest === 'undefined' || typeof FormData === 'undefined') {
        return false;
    }

    window.tinymce?.triggerSave?.();
    const data = new FormData(form, submitter ?? undefined);
    const xhr = new XMLHttpRequest();
    const sizes = files.map(({ file }) => file.size);
    const buttons = [...form.querySelectorAll('button[type="submit"], button:not([type])')];

    files.forEach(({ progress }) => {
        if (progress) {
            progress.hidden = false;
        }
    });
    buttons.forEach((button) => {
        button.disabled = true;
    });

    xhr.upload.addEventListener('progress', (event) => {
        perFileProgress(sizes, event.loaded).forEach((percent, index) => {
            const progress = files[index].progress;
            if (progress) {
                progress.value = percent;
            }
        });
    });

    const fallback = () => {
        buttons.forEach((button) => {
            button.disabled = false;
        });
        form.dataset.fileUploadNative = '1';
        form.requestSubmit ? form.requestSubmit(submitter ?? undefined) : form.submit();
    };

    const fail = () => {
        buttons.forEach((button) => {
            button.disabled = false;
        });
        files.forEach(({ progress }) => {
            if (progress) {
                progress.hidden = true;
            }
        });
        const status = form.querySelector('[data-file-upload-group-status]');
        if (status) {
            status.textContent = status.dataset.labelFailed ?? '';
        }
    };

    xhr.addEventListener('load', () => {
        try {
            const body = JSON.parse(xhr.responseText);
            if (body && typeof body.redirect === 'string') {
                window.location.assign(body.redirect);

                return;
            }
        } catch {
            // Not the progress response.
        }
        if (xhr.status >= 400) {
            fail();

            return;
        }
        fallback();
    });
    xhr.addEventListener('error', fail);

    xhr.open((form.getAttribute('method') ?? 'post').toUpperCase(), form.action);
    xhr.setRequestHeader(UPLOAD_PROGRESS_HEADER, '1');
    xhr.setRequestHeader('Accept', 'text/html');
    xhr.send(data);

    return true;
};

export const initFileUploads = (root = document) => {
    const registry = [];

    for (const field of root.querySelectorAll('[data-file-upload]')) {
        initField(field, registry);
    }

    if (registry.length === 0) {
        return registry;
    }

    root.addEventListener('submit', (event) => {
        const form = event.target;
        if (event.defaultPrevented || !(form instanceof HTMLFormElement) || form.dataset.fileUploadNative === '1') {
            return;
        }

        if (uploadWithProgress(form, registry, event.submitter)) {
            event.preventDefault();
        }
    });

    return registry;
};
