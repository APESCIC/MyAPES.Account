@props(['menu'])
@php
    $qaProfile = $menu->user?->qaProfile;
    $qaReturnTo = \App\Core\Qa\QaLogin::safeReturnPath(request()->getRequestUri());
@endphp
<div class="sg-dock" data-workspace-dock @if($qaProfile) data-qa-dock @endif>
    @if($menu->user !== null)
        <div class="sg-dock__card">
            <span class="sg-avatar" aria-hidden="true">{{ $menu->user->initials }}</span>
            <span class="sg-dock__identity">
                <span class="sg-dock__name">{{ $menu->user->name }}</span>
                <span class="sg-dock__role">{{ $menu->user->roleLabel }}</span>
            </span>
        </div>
        @if($qaProfile !== null)
            <button type="button" class="sg-qa-pill" popovertarget="qa-switcher" aria-expanded="false" data-qa-pill>
                <i data-lucide="flask-conical" aria-hidden="true"></i>
                <span class="sg-qa-pill__label">{{ __('qa.pill.label', ['profile' => \App\Core\Qa\QaLogin::label($qaProfile)]) }}</span>
            </button>
            <div
                id="qa-switcher"
                class="sg-qa-flyout"
                popover
                role="dialog"
                aria-labelledby="qa-switcher-heading"
                data-qa-flyout
                @if($menu->user->qaConfirm) data-qa-autoopen @endif
            >
                <div class="sg-qa-panel__header">
                    <h2 id="qa-switcher-heading" class="sg-qa-panel__title">{{ __('qa.pill.switch_heading') }}</h2>
                    <span class="sg-qa-tag">{{ __('qa.panel.local_only') }}</span>
                </div>
                <x-qa.profile-picker
                    :profiles="$menu->user->qaPicker"
                    :current="$qaProfile"
                    :return-to="$qaReturnTo"
                    id-prefix="qa-switcher"
                />
                <form method="post" action="{{ route('qa.logout') }}" class="sg-qa-flyout__signout">
                    @csrf
                    <button type="submit" class="sg-btn sg-btn-secondary">
                        <i data-lucide="log-out" aria-hidden="true"></i>
                        {{ __('qa.sign_out') }}
                    </button>
                </form>
            </div>
        @else
            <div class="sg-dock__actions">
                <a href="{{ route('profile.edit') }}" class="sg-btn sg-btn-ghost sg-btn-sm">
                    <i data-lucide="circle-user-round" aria-hidden="true"></i>
                    {{ __('workspace.dock.account') }}
                </a>
                <form method="post" action="{{ route('auth.logout') }}">
                    @csrf
                    <button type="submit" class="sg-btn sg-btn-ghost sg-btn-sm">
                        <i data-lucide="log-out" aria-hidden="true"></i>
                        {{ __('workspace.dock.sign_out') }}
                    </button>
                </form>
            </div>
        @endif
    @else
        <div class="sg-dock__actions">
            <a href="{{ route('public.login') }}" class="sg-btn sg-btn-secondary sg-btn-sm">
                <i data-lucide="log-in" aria-hidden="true"></i>
                {{ __('workspace.dock.log_in') }}
            </a>
            @registrationOpen
                <a href="{{ route('public.register') }}" class="sg-btn sg-btn-primary sg-btn-sm">
                    <i data-lucide="user-plus" aria-hidden="true"></i>
                    {{ __('workspace.dock.register') }}
                </a>
            @endregistrationOpen
        </div>
    @endif

    <div class="sg-dock__tools" role="group" aria-label="{{ __('workspace.dock.tools') }}">
        <button
            type="button"
            class="sg-icon-btn sg-icon-btn--sm theme-toggle"
            data-theme-toggle
            aria-pressed="false"
            aria-label="{{ __('workspace.dock.theme_dark') }}"
            data-label-dark="{{ __('workspace.dock.theme_dark') }}"
            data-label-light="{{ __('workspace.dock.theme_light') }}"
        >
            <i data-lucide="moon" class="theme-toggle__icon theme-toggle__icon--light" aria-hidden="true"></i>
            <i data-lucide="sun" class="theme-toggle__icon theme-toggle__icon--dark" aria-hidden="true"></i>
        </button>
        <a
            href="{{ route('help') }}"
            class="sg-icon-btn sg-icon-btn--sm"
            aria-label="{{ __('public.chrome.app.blade.help') }}"
            title="{{ __('public.chrome.app.blade.help') }}"
        >
            <i data-lucide="circle-help" aria-hidden="true"></i>
        </a>
        @include('partials._github-links', ['variant' => 'dock'])
        <x-locale-switcher />
    </div>
</div>
