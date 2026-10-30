@props(['menu'])
<div class="sg-dock" data-workspace-dock>
    @if($menu->user !== null)
        <div class="sg-dock__card">
            <span class="sg-avatar" aria-hidden="true">{{ $menu->user->initials }}</span>
            <span class="sg-dock__identity">
                <span class="sg-dock__name">{{ $menu->user->name }}</span>
                <span class="sg-dock__role">{{ $menu->user->roleLabel }}</span>
            </span>
        </div>
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
    @else
        <div class="sg-dock__actions">
            <a href="{{ route('public.login') }}" class="sg-btn sg-btn-secondary sg-btn-sm">
                <i data-lucide="log-in" aria-hidden="true"></i>
                {{ __('workspace.dock.log_in') }}
            </a>
            <a href="{{ route('public.register') }}" class="sg-btn sg-btn-primary sg-btn-sm">
                <i data-lucide="user-plus" aria-hidden="true"></i>
                {{ __('workspace.dock.register') }}
            </a>
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

    @if(app()->environment(['local', 'testing']))
        <details class="sg-qa" data-qa-switcher>
            <summary>
                <i data-lucide="leaf" aria-hidden="true"></i>
                <span>{{ __('workspace.dock.qa_current', ['role' => $menu->user?->roleLabel ?? 'Guest']) }}</span>
            </summary>
            <div class="sg-qa__roles" role="group" aria-label="{{ __('public.chrome.app.blade.switch_active_role') }}">
                @foreach([
                    'service_user' => 'Public',
                    'student' => 'Student',
                    'volunteer' => 'Volunteer',
                    'staff' => 'Staff',
                    'admin' => 'Admin',
                    'superadmin' => 'Super Admin',
                ] as $role => $label)
                    <form method="post" action="{{ route('qa.switch-role') }}">
                        @csrf
                        <input type="hidden" name="role" value="{{ $role }}">
                        <button
                            type="submit"
                            @class(['sg-btn', 'sg-btn-sm', 'qa-role-button', 'sg-btn-primary' => $menu->user?->qaRole === $role, 'sg-btn-secondary' => $menu->user?->qaRole !== $role])
                            aria-pressed="{{ $menu->user?->qaRole === $role ? 'true' : 'false' }}"
                        >{{ $label }}</button>
                    </form>
                @endforeach
            </div>
        </details>
    @endif
</div>
