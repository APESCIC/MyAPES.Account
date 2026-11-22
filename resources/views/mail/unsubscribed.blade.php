@extends('layouts.app')

@section('title', __('staff_messages.unsubscribe.title').' | '.__('terms.app_name'))

@section('content')
    <div class="panel">
        <h1>{{ __('staff_messages.unsubscribe.heading') }}</h1>
        <p class="muted">{{ __('staff_messages.unsubscribe.body') }}</p>
        <p><a href="{{ route('public.login') }}">{{ __('mail.brand.custom_body_action') }}</a></p>
    </div>
@endsection