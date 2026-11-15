@php
    $servicesPayload = collect($services)->map(fn (array $service): array => [
        'key' => $service['key'],
        'areas' => $service['areas'],
    ])->values()->all();
    $cascadePayload = collect($cicGroups)->map(function (array $group): array {
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
    const services = @json($servicesPayload);
    const cicGroups = @json($cascadePayload);
    const serviceSelect = document.querySelector('[data-open-ticket-service]');
    const areaSelect = document.querySelector('[data-open-ticket-area]');
    const cicFields = document.querySelector('[data-cic-fields]');
    const child = document.querySelector('[data-category-child]');
    const websiteField = document.querySelector('[data-website-field]');
    const attachmentFields = document.querySelector('[data-attachment-fields]');
    const websiteSelect = document.getElementById('affected_website_key');
    const prefillService = @json($prefillService);
    const prefillArea = @json($prefillArea);
    const oldChild = @json($oldSubCategory);

    if (!serviceSelect || !areaSelect) {
        return;
    }

    function currentAreas() {
        const entry = services.find((item) => item.key === serviceSelect.value);
        return entry ? entry.areas : [];
    }

    function currentSubs() {
        const group = cicGroups.find((entry) => entry.key === areaSelect.value);
        return group ? group.subcategories : [];
    }

    function selectedSub() {
        return currentSubs().find((entry) => entry.key === child?.value) || null;
    }

    function refreshAreas(preferred) {
        const areas = currentAreas();
        areaSelect.innerHTML = '';
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = @json(__('tickets::public.select_area'));
        areaSelect.appendChild(placeholder);
        areas.forEach((area) => {
            const option = document.createElement('option');
            option.value = area.key;
            option.textContent = area.label;
            areaSelect.appendChild(option);
        });
        if (preferred && areas.some((area) => area.key === preferred)) {
            areaSelect.value = preferred;
        } else if (areas.length === 1) {
            areaSelect.value = areas[0].key;
        }
        refreshCic();
    }

    function refreshCic(preferredChild) {
        const isCic = serviceSelect.value === 'apes-cic';
        if (cicFields) {
            cicFields.hidden = !isCic;
        }
        if (child) {
            child.required = isCic;
            child.innerHTML = '';
            const placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = @json(__('tickets::ui.index.blade.select_subcategory'));
            child.appendChild(placeholder);
            if (isCic) {
                currentSubs().forEach((sub) => {
                    const option = document.createElement('option');
                    option.value = sub.key;
                    option.textContent = sub.label;
                    child.appendChild(option);
                });
                if (preferredChild && currentSubs().some((sub) => sub.key === preferredChild)) {
                    child.value = preferredChild;
                } else if (currentSubs().length === 1) {
                    child.value = currentSubs()[0].key;
                }
            }
        }
        refreshFlags();
    }

    function refreshFlags() {
        const isCic = serviceSelect.value === 'apes-cic';
        const sub = selectedSub();
        const requiresWebsite = !!(isCic && sub && sub.requires_website);
        const allowsAttachments = !isCic || !!(sub && sub.allows_attachments);
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

    serviceSelect.addEventListener('change', () => refreshAreas(null));
    areaSelect.addEventListener('change', () => refreshCic(null));
    child?.addEventListener('change', refreshFlags);

    if (prefillService) {
        serviceSelect.value = prefillService;
    }
    refreshAreas(prefillArea);
    if (prefillService === 'apes-cic') {
        refreshCic(oldChild);
    }
})();
</script>
