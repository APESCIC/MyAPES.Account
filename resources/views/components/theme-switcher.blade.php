@props([
    'variant' => 'default',
    'idPrefix' => 'theme-switcher',
    'appearance' => null,
])

@php
    $appearance ??= ['skin' => 'glassy', 'mode' => 'light', 'calm' => false];
    $skins = config('themes.skins', []);
    $modes = config('themes.modes', ['light', 'dark']);
    $currentSkin = $appearance['skin'] ?? 'glassy';
    $currentMode = $appearance['mode'] ?? 'light';
    $currentCalm = (bool) ($appearance['calm'] ?? false);
@endphp

<div
    {{ $attributes->class([
        'theme-switcher',
        'theme-switcher--'.$variant,
    ]) }}
    data-theme-switcher
    data-variant="{{ $variant }}"
    @auth
        data-theme-preference-url="{{ route('account.theme-preference.store') }}"
        data-csrf-token="{{ csrf_token() }}"
    @endauth
>
    @if($variant === 'menu')
        <p id="{{ $idPrefix }}-heading" class="theme-switcher__heading">{{ __('workspace.theme.section') }}</p>
    @elseif($variant === 'footer')
        <p id="{{ $idPrefix }}-heading" class="theme-switcher__heading theme-switcher__heading--footer">{{ __('workspace.theme.footer_label') }}</p>
    @elseif($variant === 'profile')
        <span class="sg-visually-hidden" id="{{ $idPrefix }}-heading">{{ __('workspace.theme.appearance_heading') }}</span>
    @endif

    <div
        class="theme-switcher__group"
        role="radiogroup"
        aria-labelledby="{{ $idPrefix }}-skin-label"
        data-appearance-key="skin"
    >
        <span id="{{ $idPrefix }}-skin-label" class="theme-switcher__label">{{ __('workspace.theme.skin') }}</span>
        <div class="theme-switcher__options">
            @foreach($skins as $skinKey => $skinMeta)
                <button
                    type="button"
                    class="theme-switcher__option"
                    role="radio"
                    data-appearance-value="{{ $skinKey }}"
                    aria-checked="{{ $currentSkin === $skinKey ? 'true' : 'false' }}"
                    tabindex="{{ $currentSkin === $skinKey ? '0' : '-1' }}"
                >
                    {{ $skinKey === 'jungle' ? __('workspace.theme.skin_jungle') : __('workspace.theme.skin_glassy') }}
                </button>
            @endforeach
        </div>
    </div>

    <div
        class="theme-switcher__group"
        role="radiogroup"
        aria-labelledby="{{ $idPrefix }}-mode-label"
        data-appearance-key="mode"
    >
        <span id="{{ $idPrefix }}-mode-label" class="theme-switcher__label">{{ __('workspace.theme.mode') }}</span>
        <div class="theme-switcher__options">
            @foreach($modes as $modeKey)
                <button
                    type="button"
                    class="theme-switcher__option"
                    role="radio"
                    data-appearance-value="{{ $modeKey }}"
                    aria-checked="{{ $currentMode === $modeKey ? 'true' : 'false' }}"
                    tabindex="{{ $currentMode === $modeKey ? '0' : '-1' }}"
                >
                    {{ $modeKey === 'dark' ? __('workspace.theme.mode_dark') : __('workspace.theme.mode_light') }}
                </button>
            @endforeach
        </div>
    </div>

    <div class="theme-switcher__group theme-switcher__group--calm">
        <button
            type="button"
            id="{{ $idPrefix }}-calm"
            class="theme-switcher__calm"
            data-appearance-key="calm"
            role="switch"
            aria-checked="{{ $currentCalm ? 'true' : 'false' }}"
        >
            <span class="theme-switcher__label">{{ __('workspace.theme.calm') }}</span>
        </button>
    </div>
</div>