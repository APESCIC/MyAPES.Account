<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('tickets::embed.verified_heading') }}</title>
    @vite(['resources/css/app.css'])
</head>
<body>
    <main>
        <h1>{{ __('tickets::embed.verified_heading') }}</h1>
        <p>{{ __('tickets::embed.verified_body', ['reference' => '#'.$ticket->id]) }}</p>
    </main>
</body>
</html>
