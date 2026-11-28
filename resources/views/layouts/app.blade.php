<!doctype html>
@php($appearance ??= ['skin' => 'glassy', 'mode' => 'light', 'calm' => false])
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-skin="{{ $appearance['skin'] }}" data-theme="{{ $appearance['mode'] }}" @auth data-appearance-source="server" @endauth @if($appearance['calm']) data-calm="true" @endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <x-seo />
    <meta name="theme-color" content="#d2f4fa">
    <meta name="msapplication-config" content="{{ asset('browserconfig.xml') }}">
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicons/favicon-16x16.png') }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicons/favicon-32x32.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('icons/apple-touch-icon.png') }}">
    <link rel="manifest" href="{{ asset('site.webmanifest') }}">
    <link
        rel="preload"
        as="image"
        type="image/png"
        href="{{ asset('logos/myapes-mark-40x40.png') }}"
        imagesrcset="{{ asset('logos/myapes-mark-40x40.png') }} 1x, {{ asset('logos/myapes-mark-80x80.png') }} 2x, {{ asset('logos/myapes-mark-120x120.png') }} 3x"
    >
    <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
        (() => {
            const root = document.documentElement;
            try {
                if (root.dataset.appearanceSource === 'server') {
                    root.style.colorScheme = root.dataset.theme === 'dark' ? 'dark' : 'light';

                    return;
                }

                const appearanceKey = 'myapes-appearance';
                const themeKey = 'myapes-theme';
                let appearance = { skin: 'glassy', mode: 'light', calm: false };
                try {
                    const savedAppearance = localStorage.getItem(appearanceKey);
                    if (savedAppearance) {
                        const parsed = JSON.parse(savedAppearance);
                        if (parsed && typeof parsed === 'object') {
                            appearance.skin = parsed.skin === 'jungle' ? 'jungle' : 'glassy';
                            appearance.mode = parsed.mode === 'dark' ? 'dark' : 'light';
                            appearance.calm = Boolean(parsed.calm);
                        }
                    } else {
                        const savedTheme = localStorage.getItem(themeKey);
                        appearance.mode = savedTheme === 'dark' ? 'dark' : 'light';
                    }
                } catch {
                    appearance.mode = 'light';
                }
                root.dataset.skin = appearance.skin;
                root.dataset.theme = appearance.mode;
                root.style.colorScheme = appearance.mode;
                if (appearance.calm) {
                    root.setAttribute('data-calm', 'true');
                } else {
                    root.removeAttribute('data-calm');
                }
            } catch {
                if (root.dataset.appearanceSource !== 'server') {
                    root.dataset.theme = 'light';
                }
                root.style.colorScheme = 'light';
            }
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
@php($isAuthPage = request()->routeIs('public.login', 'public.register', 'staff.login', 'password.request', 'password.reset', 'password.confirm', 'password.forced-change.*', 'two-factor.login', 'verification.notice', 'qa.index'))
@php($showPushSoftPrompt = auth()->check() && (request()->routeIs('dashboard') || request()->routeIs('*.tickets.show')))
<body @class(['workspace-shell', 'is-auth-page' => $isAuthPage, 'has-mascot-dock' => $mascotTip]) @auth data-push-register data-push-sw-url="{{ route('push.service-worker') }}" data-push-destroy-url="{{ route('push.subscriptions.destroy') }}" @endauth>
<a class="skip-link" href="#main-content">{{ __('public.chrome.app.blade.skip_to_main_content') }}</a>

<div class="sg-page" data-workspace-page>
    <div class="sg-main-column">
        <x-workspace.app-bar :menu="$workspaceMenu" />
        <div class="sg-content">
            <x-workspace.page-header
                :menu="$workspaceMenu"
                :title="trim($__env->yieldContent('page_title')) ?: null"
                :subtitle="trim($__env->yieldContent('page_subtitle')) ?: null"
            />

            @if($isAuthPage)
                <div class="sg-auth-logo">
                    <img
                        src="{{ asset('logos/myapes-header-light-600x128.png') }}"
                        srcset="{{ asset('logos/myapes-header-light-600x128.png') }} 1x, {{ asset('logos/myapes-header-light-1200x256.png') }} 2x"
                        width="300"
                        height="64"
                        alt="{{ __('workspace.app_name') }}"
                        class="sg-auth-logo__light"
                    >
                    <img
                        src="{{ asset('logos/myapes-header-dark-600x128.png') }}"
                        srcset="{{ asset('logos/myapes-header-dark-600x128.png') }} 1x, {{ asset('logos/myapes-header-dark-1200x256.png') }} 2x"
                        width="300"
                        height="64"
                        alt="{{ __('workspace.app_name') }}"
                        class="sg-auth-logo__dark"
                    >
                </div>
            @endif

            <main id="main-content" class="app-main" tabindex="-1">
                @if($showPushSoftPrompt ?? false)
                    <x-push.device-notifications soft />
                @endif
                @include('partials.site-banner')
                <x-flash-toasts />
                @include('partials.client-records-notice')
                @include('partials.import-alert')

                @yield('content')
            </main>

            @if($mascotTip)
                <aside
                    class="mascot-dock mascot-dock--collapsed"
                    data-mascot-dock
                    data-mascot-route="{{ $mascotTip['route'] }}"
                    aria-label="{{ __('public.chrome.app.blade.tip_from_spike_the_myapes_bearded_dragon') }}"
                >
                    <button
                        type="button"
                        class="mascot-dock__toggle"
                        data-mascot-toggle
                        aria-expanded="false"
                        aria-controls="mascot-dock-bubble"
                        aria-label="{{ __('public.chrome.app.blade.show_tip_from_spike') }}"
                    >
                        <img
                            src="{{ asset('mascot/spike-dock.png') }}"
                            alt=""
                            class="mascot-dock__avatar"
                            width="512"
                            height="512"
                            loading="eager"
                            decoding="async"
                        >
                    </button>
                    <div
                        id="mascot-dock-bubble"
                        class="mascot-dock__bubble"
                        data-mascot-bubble
                        hidden
                    >
                        <button
                            type="button"
                            class="mascot-dock__dismiss"
                            data-mascot-dismiss
                            aria-label="{{ __('public.chrome.app.blade.hide_tip') }}"
                        >
                            <span aria-hidden="true">&times;</span>
                        </button>
                        <p><strong>{{ $mascotTip['title'] }}</strong> {{ $mascotTip['body'] }}</p>
                    </div>
                </aside>
            @endif

            <footer class="site-footer">
                <div class="site-footer__brand">
                    <span><strong>{{ __('public.chrome.app.blade.myapes') }}</strong> {{ __('public.chrome.app.blade.account') }}</span>
                    <span>© {{ now()->year }} Association of Protecting Exotic Species CIC · CIC No: 16253848</span>
                </div>
                <nav class="site-footer__links" aria-label="{{ __('public.chrome.app.blade.legal_and_help') }}">
                    @foreach($publicPluginNavigation ?? [] as $publicPluginNav)
                        <a href="{{ route($publicPluginNav->routeName) }}" @if (request()->routeIs($publicPluginNav->routeIsPattern)) aria-current="page" @endif>{{ $publicPluginNav->label }}</a>
                    @endforeach
                    <a href="{{ route('privacy') }}" @if (request()->routeIs('privacy')) aria-current="page" @endif>{{ __('public.chrome.app.blade.privacy') }}</a>
                    <a href="{{ route('cookies') }}" @if (request()->routeIs('cookies')) aria-current="page" @endif>{{ __('public.chrome.app.blade.cookies') }}</a>
                    <a href="{{ route('help') }}" data-tour-target="footer-help" @if (request()->routeIs('help')) aria-current="page" @endif>{{ __('public.chrome.app.blade.help') }}</a>
                    <a href="{{ route('terms') }}" @if (request()->routeIs('terms')) aria-current="page" @endif>{{ __('public.chrome.app.blade.terms') }}</a>
                    @guest
                        <a href="{{ route('staff.login') }}" @if (request()->routeIs('staff.login')) aria-current="page" @endif>{{ __('public.chrome.app.blade.staff_login') }}</a>
                    @endguest
                </nav>
                <a
                    class="site-footer__version"
                    href="{{ route('change-log.index') }}"
                    aria-label="View the MyAPES Account change log for version v{{ $appVersion }}"
                >v{{ $appVersion }}</a>
                @guest
                    <div class="critter-chrome site-footer__critter" aria-hidden="true">
                        <x-critter name="spike" pose="welcome" decorative class="critter--chrome" />
                    </div>
                    <x-theme-switcher
                        variant="footer"
                        id-prefix="footer-theme"
                        class="site-footer__theme"
                        :appearance="$appearance ?? ['skin' => 'glassy', 'mode' => 'light', 'calm' => false]"
                    />
                @endguest
            </footer>
        </div>
    </div>
</div>

<x-workspace.drawer />
<x-workspace.command-palette :menu="$workspaceMenu" />
@auth
    @if(request()->routeIs('dashboard'))
        <x-workspace.guided-tour />
    @endif
@endauth
@stack('scripts')
</body>
</html>
