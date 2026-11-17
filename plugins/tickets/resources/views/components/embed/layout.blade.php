<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ __('tickets::embed.title') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body class="sg-embed-ticket" data-myapes-embed-root @isset($parentOrigin) data-parent-origin="{{ $parentOrigin }}" @endisset>
    <main class="sg-embed-ticket__main">
        {{ $slot }}
    </main>
    @stack('scripts')
    <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
        (function () {
            var root = document.querySelector('[data-myapes-embed-root]');
            if (!root) return;
            var parentOrigin = root.getAttribute('data-parent-origin');
            function post(type, payload) {
                if (!parentOrigin || !window.parent || window.parent === window) return;
                window.parent.postMessage(Object.assign({ source: 'myapes-ticket-embed', type: type }, payload || {}), parentOrigin);
            }
            function height() {
                post('height', { height: Math.max(document.documentElement.scrollHeight, document.body.scrollHeight) });
            }
            post('ready', {});
            height();
            window.addEventListener('load', height);
            if (window.ResizeObserver) {
                new ResizeObserver(height).observe(document.body);
            }
            @stack('embed_events')
        })();
    </script>
</body>
</html>
