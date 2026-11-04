@php
    /** @var array<int, array<string, mixed>> $groups */
    $cascadePayload = collect($groups)->map(function (array $group): array {
        return [
            'key' => $group['key'],
            'subcategories' => collect($group['subcategories'] ?? [])->map(fn (array $sub): array => [
                'key' => $sub['key'],
                'label' => $sub['label'],
                'requires_website' => (bool) ($sub['requires_website'] ?? false),
                'allows_attachments' => (bool) ($sub['allows_attachments'] ?? false),
            ])->values()->all(),
        ];
    })->values()->all();
@endphp
<script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
(() => {
    const groups = @json($cascadePayload);
    const parent = document.querySelector('[data-category-parent]');
    const child = document.querySelector('[data-category-child]');
    const websiteField = document.querySelector('[data-website-field]');
    const attachmentFields = document.querySelector('[data-attachment-fields]');
    const websiteSelect = document.getElementById('affected_website_key');
    const oldParent = @json($oldParent ?? null);
    const oldChild = @json($oldChild ?? null);

    if (!parent || !child) {
        return;
    }

    function currentSubs() {
        const group = groups.find((entry) => entry.key === parent.value);
        return group ? group.subcategories : [];
    }

    function selectedSub() {
        return currentSubs().find((entry) => entry.key === child.value) || null;
    }

    function refreshChild(preferred) {
        const subs = currentSubs();
        child.innerHTML = '';
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = 'Select subcategory';
        child.appendChild(placeholder);
        subs.forEach((sub) => {
            const option = document.createElement('option');
            option.value = sub.key;
            option.textContent = sub.label;
            child.appendChild(option);
        });
        if (preferred && subs.some((sub) => sub.key === preferred)) {
            child.value = preferred;
        } else if (subs.length === 1) {
            child.value = subs[0].key;
        }
        refreshFlags();
    }

    function refreshFlags() {
        const sub = selectedSub();
        const requiresWebsite = !!(sub && sub.requires_website);
        const allowsAttachments = !!(sub && sub.allows_attachments);
        if (websiteField) {
            websiteField.hidden = !requiresWebsite;
            if (websiteSelect) {
                websiteSelect.required = requiresWebsite;
                websiteSelect.setAttribute('aria-required', requiresWebsite ? 'true' : 'false');
                if (!requiresWebsite) {
                    websiteSelect.value = '';
                }
            }
        }
        if (attachmentFields) {
            attachmentFields.hidden = !allowsAttachments;
        }
    }

    parent.addEventListener('change', () => refreshChild(null));
    child.addEventListener('change', refreshFlags);

    if (oldParent) {
        parent.value = oldParent;
    }
    refreshChild(oldChild);
})();
</script>
