@props(['menu'])
@php
    $user = $menu->user;
@endphp
@if($user !== null)
    <div class="sg-user-menu" data-workspace-user-menu>
        <button
            type="button"
            class="sg-user-menu__trigger"
            data-workspace-user-menu-trigger
            aria-expanded="false"
            aria-haspopup="menu"
            aria-controls="workspace-user-menu-panel"
            aria-label="{{ __('workspace.nav.user_menu') }}"
        >
            <span class="sg-avatar" aria-hidden="true">{{ $user->initials }}</span>
            <span class="sg-visually-hidden">{{ $user->name }}</span>
        </button>
        <div
            id="workspace-user-menu-panel"
            class="sg-user-menu__panel"
            role="menu"
            data-workspace-user-menu-panel
            hidden
        >
            <div class="sg-user-menu__identity">
                <span class="sg-avatar" aria-hidden="true">{{ $user->initials }}</span>
                <span class="sg-user-menu__meta">
                    <span class="sg-user-menu__name">{{ $user->name }}</span>
                    <span class="sg-user-menu__role">{{ $user->roleLabel }}</span>
                </span>
            </div>

            @if($user->qaProfile !== null)
                <button type="button" class="sg-qa-pill" popovertarget="qa-switcher" aria-expanded="false" data-qa-pill>
                    <i data-lucide="flask-conical" aria-hidden="true"></i>
                    <span class="sg-qa-pill__label">{{ __('qa.pill.label', ['profile' => \App\Core\Qa\QaLogin::label($user->qaProfile)]) }}</span>
                </button>
                <div
                    id="qa-switcher"
                    class="sg-qa-flyout"
                    popover
                    role="dialog"
                    aria-labelledby="qa-switcher-heading"
                    data-qa-flyout
                    @if($user->qaConfirm) data-qa-autoopen @endif
                >
                    <div class="sg-qa-panel__header">
                        <h2 id="qa-switcher-heading" class="sg-qa-panel__title">{{ __('qa.pill.switch_heading') }}</h2>
                        <span class="sg-qa-tag">{{ __('qa.panel.local_only') }}</span>
                    </div>
                    <x-qa.profile-picker
                        :profiles="$user->qaPicker"
                        :current="$user->qaProfile"
                        :return-to="$user->qaReturnTo"
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
            @endif

            <ul class="sg-user-menu__list" role="none">
                @foreach($menu->userMenuItems as $item)
                    <li role="none">
                        <a
                            href="{{ $item->url }}"
                            class="sg-user-menu__item"
                            role="menuitem"
                            @if(str_starts_with($item->url, 'http')) target="_blank" rel="noopener noreferrer" @endif
                        >
                            <i data-lucide="{{ $item->icon }}" aria-hidden="true"></i>
                            <span>{{ $item->label }}</span>
                            @if(str_starts_with($item->url, 'http'))
                                <span class="sg-visually-hidden">{{ __('workspace.dock.opens_in_new_tab') }}</span>
                            @endif
                        </a>
                    </li>
                @endforeach
            </ul>

            <div class="sg-user-menu__theme" role="none">
                <x-theme-switcher
                    variant="menu"
                    id-prefix="user-menu-theme"
                    :appearance="$appearance ?? ['skin' => 'glassy', 'mode' => 'light', 'calm' => false]"
                />
            </div>

            @if($user->qaProfile === null)
                <form method="post" action="{{ route('auth.logout') }}" class="sg-user-menu__signout">
                    @csrf
                    <button type="submit" class="sg-btn sg-btn-ghost sg-btn-sm sg-user-menu__signout-btn">
                        <i data-lucide="log-out" aria-hidden="true"></i>
                        {{ __('workspace.dock.sign_out') }}
                    </button>
                </form>
            @endif

            <div class="sg-user-menu__footer">
                <x-locale-switcher />
            </div>
        </div>
    </div>
@endif