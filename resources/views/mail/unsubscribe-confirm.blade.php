@extends('layouts.app')

@section('title', __('staff_messages.unsubscribe.confirm_title').' | '.__('terms.app_name'))

@section('content')
    <div class="panel">
        <h1>{{ __('staff_messages.unsubscribe.confirm_heading') }}</h1>
        <p class="muted">{{ __('staff_messages.unsubscribe.confirm_body') }}</p>
        <form method="post" action="{{ request()->fullUrl() }}">
            @csrf
            <button type="submit">{{ __('staff_messages.unsubscribe.confirm_action') }}</button>
        </form>
        <p><a href="{{ route('public.login') }}">{{ __('mail.brand.custom_body_action') }}</a></p>
    </div>
@endsection
