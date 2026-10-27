<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <x-seo />
    <meta name="theme-color" content="#f3e4c4">
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
<body @class(['has-mascot-dock' => $mascotTip])>
<a class="skip-link" href="#main-content">{{ __('public.chrome.app.blade.skip_to_main_content') }}</a>

<header class="mobile-header">
    <a href="{{ route('home') }}" class="mobile-brand" aria-label="{{ __('public.chrome.app.blade.myapes_core_home') }}">
        <img
            src="{{ asset('branding/logo-myapes-account.png') }}"
            srcset="{{ asset('logos/myapes-mark-256x256.png') }} 256w, {{ asset('branding/logo-myapes-account.png') }} 1024w"
            sizes="3.2rem"
            width="1024"
            height="1024"
            alt=""
        >
        <span><strong>{{ __('public.chrome.app.blade.myapes') }}</strong> Core</span>
    </a>
    <button
        type="button"
        class="sidebar-toggle"
        data-sidebar-toggle
        aria-controls="site-sidebar"
        aria-expanded="false"
        aria-label="{{ __('public.chrome.app.blade.open_navigation_menu') }}"
    >
        <i data-lucide="menu" aria-hidden="true"></i>
    </button>
</header>

<div class="app-shell">
    <aside id="site-sidebar" class="site-sidebar" data-sidebar aria-label="{{ __('public.chrome.app.blade.site_navigation') }}">
        <div class="site-sidebar__inner">
            <button type="button" class="sidebar-close" data-sidebar-close aria-label="{{ __('public.chrome.app.blade.close_navigation_menu') }}">
                <i data-lucide="x" aria-hidden="true"></i>
            </button>

            <a href="{{ route('home') }}" class="sidebar-brand" aria-label="{{ __('public.chrome.app.blade.myapes_core_home') }}">
                <img
                    src="{{ asset('branding/logo-myapes-account.png') }}"
                    srcset="{{ asset('logos/myapes-mark-256x256.png') }} 256w, {{ asset('branding/logo-myapes-account.png') }} 1024w"
                    sizes="(max-width: 64rem) 8.5rem, 10.75rem"
                    width="1024"
                    height="1024"
                    alt="{{ __('public.maintenance.blade.myapes_core') }}"
                >
            </a>

            <nav class="primary-nav" aria-label="{{ __('public.chrome.app.blade.primary_navigation') }}">
                @auth
                    <a href="{{ route('dashboard') }}" @class(['primary-nav__link', 'is-active' => request()->routeIs('dashboard')]) @if(request()->routeIs('dashboard')) aria-current="page" @endif>
                        <i data-lucide="layout-dashboard" aria-hidden="true"></i>
                        <span>{{ __('public.chrome.app.blade.dashboard') }}</span>
                    </a>
                    <a href="{{ route('profile.edit') }}" @class(['primary-nav__link', 'is-active' => request()->routeIs('profile.*')]) @if(request()->routeIs('profile.*')) aria-current="page" @endif>
                        <i data-lucide="user-round" aria-hidden="true"></i>
                        <span>{{ __('public.chrome.app.blade.profile') }}</span>
                    </a>
                    @foreach($moduleNavigation as $subCoreNavigation)
                        @php
                            $routePrefix = str($subCoreNavigation->subCore->routeName)
                                ->before('.')
                                ->toString();
                            $staffPluginPatterns = collect($staffPluginNavigation ?? [])
                                ->flatMap(static fn ($item): array => $item->routePatterns())
                                ->all();
                            $active = request()->routeIs($routePrefix.'.*');
                            foreach ($staffPluginPatterns as $pattern) {
                                if (request()->routeIs($pattern)) {
                                    $active = false;
                                    break;
                                }
                            }
                        @endphp
                        <a href="{{ route($subCoreNavigation->subCore->routeName) }}" @class(['primary-nav__link', 'is-active' => $active]) @if($active) aria-current="page" @endif>
                            <i data-lucide="{{ $subCoreNavigation->subCore->icon }}" aria-hidden="true"></i>
                            <span>{{ $subCoreNavigation->subCore->name }}</span>
                        </a>
                    @endforeach
                    @foreach($staffPluginNavigation ?? [] as $staffPluginNav)
                        @php
                            $staffPluginHome = $staffPluginNav->homeUrlFor(auth()->user());
                            $staffPluginNavActive = request()->routeIs(...$staffPluginNav->routePatterns());
                        @endphp
                        @continue($staffPluginHome === null)
                        <a href="{{ $staffPluginHome }}" @class(['primary-nav__link', 'is-active' => $staffPluginNavActive]) @if($staffPluginNavActive) aria-current="page" @endif>
                            <i data-lucide="{{ $staffPluginNav->icon }}" aria-hidden="true"></i>
                            <span>{{ $staffPluginNav->label }}</span>
                        </a>
                    @endforeach
                    @php
                        $adminPrimaryNav = app(\App\Support\AdminPrimaryNav::class)->forUser(auth()->user());
                    @endphp
                    @if($adminPrimaryNav !== null)
                        <a href="{{ $adminPrimaryNav['url'] }}" @class(['primary-nav__link', 'is-active' => $adminPrimaryNav['active']]) @if($adminPrimaryNav['active']) aria-current="page" @endif>
                            <i data-lucide="settings" aria-hidden="true"></i>
                            <span>{{ __('public.chrome.app.blade.admin') }}</span>
                        </a>
                    @endif
                @else
                    @foreach($publicPluginNavigation ?? [] as $publicPluginNav)
                        <a href="{{ route($publicPluginNav->routeName) }}" @class(['primary-nav__link', 'is-active' => request()->routeIs($publicPluginNav->routeIsPattern)]) @if(request()->routeIs($publicPluginNav->routeIsPattern)) aria-current="page" @endif>
                            <i data-lucide="{{ $publicPluginNav->icon }}" aria-hidden="true"></i>
                            <span>{{ $publicPluginNav->label }}</span>
                        </a>
                    @endforeach
                    <a href="{{ route('public.login') }}" @class(['primary-nav__link', 'is-active' => request()->routeIs('public.login')]) @if(request()->routeIs('public.login')) aria-current="page" @endif>
                        <i data-lucide="log-in" aria-hidden="true"></i>
                        <span>{{ __('public.chrome.app.blade.public_login') }}</span>
                    </a>
                    <a href="{{ route('public.register') }}" @class(['primary-nav__link', 'is-active' => request()->routeIs('public.register')]) @if(request()->routeIs('public.register')) aria-current="page" @endif>
                        <i data-lucide="user-plus" aria-hidden="true"></i>
                        <span>{{ __('public.chrome.app.blade.register') }}</span>
                    </a>
                    <a href="{{ route('staff.login') }}" @class(['primary-nav__link', 'is-active' => request()->routeIs('staff.*')]) @if(request()->routeIs('staff.*')) aria-current="page" @endif>
                        <i data-lucide="badge-check" aria-hidden="true"></i>
                        <span>{{ __('public.chrome.app.blade.staff_login') }}</span>
                    </a>
                @endauth
                @auth
                    @foreach($publicPluginNavigation ?? [] as $publicPluginNav)
                        <a href="{{ route($publicPluginNav->routeName) }}" @class(['primary-nav__link', 'is-active' => request()->routeIs($publicPluginNav->routeIsPattern)]) @if(request()->routeIs($publicPluginNav->routeIsPattern)) aria-current="page" @endif>
                            <i data-lucide="{{ $publicPluginNav->icon }}" aria-hidden="true"></i>
                            <span>{{ $publicPluginNav->label }}</span>
                        </a>
                    @endforeach
                @endauth
            </nav>

            <div class="sidebar-tools">
                <button type="button" class="sidebar-tool theme-toggle" data-theme-toggle aria-pressed="false" aria-label="{{ __('public.chrome.app.blade.switch_to_dark_theme') }}">
                    <span class="sidebar-tool__icon">
                        <i data-lucide="sun" class="theme-toggle__icon theme-toggle__icon--light" aria-hidden="true"></i>
                        <i data-lucide="moon" class="theme-toggle__icon theme-toggle__icon--dark" aria-hidden="true"></i>
                    </span>
                    <span data-theme-label>{{ __('public.chrome.app.blade.light_mode') }}</span>
                    <i data-lucide="chevron-right" class="sidebar-tool__chevron" aria-hidden="true"></i>
                </button>
                @auth
                    <a href="{{ route('profile.edit') }}" class="sidebar-tool" title="{{ auth()->user()->name }}">
                        <span class="sidebar-tool__icon"><i data-lucide="circle-user-round" aria-hidden="true"></i></span>
                        <span class="sidebar-tool__label">{{ auth()->user()->name }}</span>
                    </a>
                    <form method="post" action="{{ route('auth.logout') }}">
                        @csrf
                        <button type="submit" class="sidebar-tool">
                            <span class="sidebar-tool__icon"><i data-lucide="log-out" aria-hidden="true"></i></span>
                            <span>{{ __('public.chrome.app.blade.log_out') }}</span>
                        </button>
                    </form>
                @endauth

                <section class="sidebar-support" aria-labelledby="sidebar-support-title">
                    <h2 id="sidebar-support-title" class="sidebar-support__heading">{{ __('public.chrome.app.blade.app_support') }}</h2>
                    <ul class="sidebar-support__links">
                        <li>
                            <a
                                class="sidebar-support__pill"
                                href="{{ route('help') }}"
                                @if (request()->routeIs('help')) aria-current="page" @endif
                            >
                                <span class="sidebar-support__pill-icon">
                                    <i data-lucide="circle-help" aria-hidden="true"></i>
                                </span>
                                <span class="sidebar-support__pill-label">{{ __('public.chrome.app.blade.help') }}</span>
                            </a>
                        </li>
                    </ul>
                    @include('partials._github-links', ['variant' => 'sidebar'])
                </section>
            </div>
        </div>
    </aside>

    <button type="button" class="sidebar-backdrop" data-sidebar-backdrop aria-label="{{ __('public.chrome.app.blade.close_navigation_menu') }}" tabindex="-1"></button>

    <div class="app-frame">
        <header class="content-brand" aria-label="MyAPES Core">
            <strong>{{ __('public.chrome.app.blade.my') }}<span>{{ __('public.chrome.app.blade.apes') }}</span></strong> Core
            <small>{{ __('public.chrome.app.blade.association_of_protecting_exotic_species_cic') }}</small>
        </header>

<main id="main-content" class="app-main" tabindex="-1">
    @if(app()->environment(['local', 'testing']))
        @php
            $authorizationProfile = app(\App\Services\AuthorizationProfile::class);
            $activeRole = auth()->user() === null
                ? null
                : $authorizationProfile->qaSelectorFor(auth()->user());
            $activeRoleLabel = auth()->user() === null
                ? 'Guest'
                : $authorizationProfile->displayLabel(auth()->user());
        @endphp
        <section class="qa-switcher" aria-label="{{ __('public.chrome.app.blade.local_qa_role_switcher') }}">
            <div class="qa-switcher__identity">
                <strong>{{ __('public.chrome.app.blade.local_qa') }}</strong>
                <span class="qa-switcher__badge">{{ __('public.chrome.app.blade.dev_only') }}</span>
            </div>
            <div class="qa-switcher__current">
                <span>{{ __('public.chrome.app.blade.current_role') }}</span>
                <strong><i data-lucide="user-round" aria-hidden="true"></i>{{ $activeRoleLabel }}</strong>
            </div>
            <div class="qa-switcher__forms" aria-label="{{ __('public.chrome.app.blade.switch_active_role') }}">
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
                        <button type="submit" @class(['qa-role-button', 'is-active' => $activeRole === $role]) aria-pressed="{{ $activeRole === $role ? 'true' : 'false' }}">
                            {{ $label }}
                        </button>
                    </form>
                @endforeach
            </div>
            <span class="qa-switcher__environment">
                <i data-lucide="leaf" aria-hidden="true"></i>
                Development mode · QA environment
            </span>
        </section>
    @endif

    @if(session('status'))
        <div class="alert" role="status">{{ session('status') }}</div>
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
        <span><strong>{{ __('public.chrome.app.blade.myapes') }}</strong> Core</span>
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
        <x-locale-switcher />
    </nav>
    <a
        class="site-footer__version"
        href="{{ route('change-log.index') }}"
        aria-label="View the MyAPES Core change log for version v{{ $appVersion }}"
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
</body>
</html>
