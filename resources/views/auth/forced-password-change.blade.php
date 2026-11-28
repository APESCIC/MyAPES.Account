@extends('layouts.app')

@section('title', __('auth.forced_password.heading').' | MyAPES Account')

@section('content')
    <div class="panel" data-forced-password-change>
        <h1>{{ __('auth.forced_password.heading') }}</h1>
        <p class="muted">{{ __('auth.forced_password.intro') }}</p>
        <x-mascot-tip />

        <form method="post" action="{{ route('password.forced-change.update') }}" data-password-strength>
            @csrf

            <label for="password">{{ __('auth.forced_password.new_password') }}</label>
            <input
                id="password"
                type="password"
                name="password"
                autocomplete="new-password"
                required
                minlength="12"
                aria-describedby="password-strength-status password-checklist"
                data-password-input
            >
            @error('password')
                <p class="form-error" role="alert">{{ $message }}</p>
            @enderror

            <div
                id="password-strength-status"
                class="password-strength"
                role="status"
                aria-live="polite"
                data-strength-status
            >
                {{ __('auth.forced_password.strength_idle') }}
            </div>

            <ul id="password-checklist" class="password-checklist" data-password-checklist>
                <li data-rule="length">{{ __('auth.forced_password.rule_length') }}</li>
                <li data-rule="personal">{{ __('auth.forced_password.rule_personal') }}</li>
                <li data-rule="match">{{ __('auth.forced_password.rule_match') }}</li>
            </ul>

            <label for="password_confirmation">{{ __('auth.forced_password.confirm') }}</label>
            <input
                id="password_confirmation"
                type="password"
                name="password_confirmation"
                autocomplete="new-password"
                required
                minlength="12"
                data-password-confirm
            >

            <div class="actions">
                <button type="submit">{{ __('auth.forced_password.submit') }}</button>
            </div>
        </form>
    </div>
@endsection

@php
    $personalParts = array_values(array_filter([
        $user->username,
        is_string($user->email) ? \Illuminate\Support\Str::before($user->email, '@') : null,
        $user->name,
        $user->profile?->first_name,
        $user->profile?->last_name,
    ], static function (?string $part): bool {
        return is_string($part) && strlen($part) >= 3;
    }));
@endphp
@push('scripts')
<script>
(() => {
    const form = document.querySelector('[data-password-strength]');
    if (!form) return;
    const input = form.querySelector('[data-password-input]');
    const confirm = form.querySelector('[data-password-confirm]');
    const status = form.querySelector('[data-strength-status]');
    const checklist = form.querySelector('[data-password-checklist]');
    const personal = @json($personalParts);

    const labels = {
        weak: @json(__('auth.forced_password.strength_weak')),
        fair: @json(__('auth.forced_password.strength_fair')),
        strong: @json(__('auth.forced_password.strength_strong')),
        idle: @json(__('auth.forced_password.strength_idle')),
    };

    function score(value) {
        let s = 0;
        if (value.length >= 12) s += 1;
        if (value.length >= 16) s += 1;
        if (/[A-Z]/.test(value) && /[a-z]/.test(value)) s += 1;
        if (/\d/.test(value) && /[^A-Za-z0-9]/.test(value)) s += 1;
        return s;
    }

    function containsPersonal(value) {
        const lower = value.toLowerCase();
        return personal.some((part) => lower.includes(String(part).toLowerCase()));
    }

    function update() {
        const value = input.value || '';
        const conf = confirm.value || '';
        const lengthOk = value.length >= 12;
        const personalOk = value.length === 0 || !containsPersonal(value);
        const matchOk = value.length > 0 && value === conf;

        checklist.querySelector('[data-rule="length"]')?.classList.toggle('is-met', lengthOk);
        checklist.querySelector('[data-rule="personal"]')?.classList.toggle('is-met', personalOk);
        checklist.querySelector('[data-rule="match"]')?.classList.toggle('is-met', matchOk);

        if (!value) {
            status.textContent = labels.idle;
            return;
        }
        const s = score(value);
        status.textContent = s >= 3 ? labels.strong : (s >= 2 ? labels.fair : labels.weak);
    }

    input.addEventListener('input', update);
    confirm.addEventListener('input', update);
    update();
})();
</script>
@endpush
