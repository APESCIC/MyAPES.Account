<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('account.settings.redirect_title') }} | MyAPES Account</title>
    <meta http-equiv="refresh" content="0;url={{ route('account.show') }}">
    <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
        (() => {
            const map = {
                passkeys: @json(route('account.security').'#passkeys'),
                'two-factor': @json(route('account.security').'#two-factor'),
                password: @json(route('account.security').'#password'),
                'phone-pin': @json(route('account.security').'#phone-pin'),
                'signed-in-devices': @json(route('account.security').'#signed-in-devices'),
                'security-activity': @json(route('account.security').'#security-activity'),
                'account-email': @json(route('account.show').'#account-email'),
                username: @json(route('account.show').'#username'),
                appearance: @json(route('account.show').'#appearance'),
                notifications: @json(route('account.privacy').'#notifications'),
            };
            const hash = (window.location.hash || '').replace(/^#/, '');
            const target = map[hash] || @json(route('account.show'));
            window.location.replace(target);
        })();
    </script>
</head>
<body>
    <p><a href="{{ route('account.show') }}">{{ __('account.settings.redirect_continue') }}</a></p>
</body>
</html>
