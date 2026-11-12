@extends('layouts.app')

@section('title', __('admin.settings.page_title'))

@section('content')
    <header class="page-heading">
        <div>
            <p class="eyebrow">{{ __('admin.settings.eyebrow') }}</p>
            <h1>{{ __('admin.settings.heading') }}</h1>
            <p>{{ __('admin.settings.intro') }}</p>
        </div>
    </header>

    <nav class="admin-subnav" aria-label="{{ __('admin.settings.tabs.label') }}" data-settings-tabs>
        @foreach($tabs as $key => $label)
            <a href="{{ route('admin.settings.show', $key) }}" @if($activeTab === $key) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>

    <div class="panel-stack">
        @include($tabView, $tabData)
    </div>
@endsection
