@extends('layouts.app')

@section('title', __('admin.security.page_title'))

@section('content')
    <header class="page-heading">
        <div>
            <p class="eyebrow">{{ __('admin.security.eyebrow') }}</p>
            <h1>{{ __('admin.security.heading') }}</h1>
            <p>{{ __('admin.security.intro') }}</p>
        </div>
    </header>

    <ul class="panel-stack" data-security-hub>
        @foreach($links as $link)
            <li class="panel">
                <h2><a href="{{ $link['url'] }}">{{ $link['label'] }}</a></h2>
                <p>{{ $link['description'] }}</p>
            </li>
        @endforeach
    </ul>
@endsection
