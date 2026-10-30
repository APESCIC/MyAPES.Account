<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="light">
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
    <script>
        (() => {
            try {
                const savedTheme = localStorage.getItem('myapes-theme');
                const theme = savedTheme === 'dark' ? 'dark' : 'light';
                document.documentElement.dataset.theme = theme;
                document.documentElement.style.colorScheme = theme;
            } catch {
                document.documentElement.dataset.theme = 'light';
                document.documentElement.style.colorScheme = 'light';
            }
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
@php($isAuthPage = request()->routeIs('public.login', 'public.register', 'staff.login', 'password.request', 'password.reset', 'password.confirm', 'two-factor.login', 'verification.notice'))
<body @class(['workspace-shell', 'is-auth-page' => $isAuthPage, 'has-mascot-dock' => $mascotTip])>
<a class="skip-link" href="#main-content">{{ __('public.chrome.app.blade.skip_to_main_content') }}</a>

<div class="sg-page" data-workspace-page>
    <aside class="sg-rail-dock" aria-label="{{ __('workspace.nav.label') }}" data-workspace-rail-dock>
        <x-workspace.rail :menu="$workspaceMenu" />
    </aside>

    <div class="sg-main-column">
        <div class="sg-content">
            <x-workspace.topbar :menu="$workspaceMenu" />
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
                @if(session('status'))
                    <x-alert tone="ok">{{ session('status') }}</x-alert>
                @endif
                @if($errors->any())
                    <div class="error-list" role="alert">
                        @foreach($errors->all() as $error)
                            <div>{{ $error }}</div>
                        @endforeach
                    </div>
                @endif

                @yield('content')
            </main>

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
                    <a href="{{ route('help') }}" @if (request()->routeIs('help')) aria-current="page" @endif>{{ __('public.chrome.app.blade.help') }}</a>
                    <a href="{{ route('terms') }}" @if (request()->routeIs('terms')) aria-current="page" @endif>{{ __('public.chrome.app.blade.terms') }}</a>
                </nav>
                <a
                    class="site-footer__version"
                    href="{{ route('change-log.index') }}"
                    aria-label="View the MyAPES Account change log for version v{{ $appVersion }}"
                >v{{ $appVersion }}</a>
            </footer>
        </div>
    </div>

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
</div>

<x-workspace.drawer />
<x-workspace.command-palette :menu="$workspaceMenu" />
</body>
</html>
