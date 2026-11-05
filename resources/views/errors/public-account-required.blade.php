@extends('layouts.app')

@section('title', __('public.account_separation.page_title'))

@section('content')
    <div class="panel">
        <h1>{{ __('public.account_separation.title') }}</h1>
        <x-public-account-required :with-title="false" />
        <div class="actions">
            <a href="{{ route('dashboard') }}">{{ __('public.403.blade.go_to_dashboard') }}</a>
        </div>
    </div>
@endsection
