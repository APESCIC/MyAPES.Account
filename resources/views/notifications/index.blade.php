@extends('layouts.app')

@section('title', __('notifications.page_title').' | MyAPES Account')
@section('page_title', __('notifications.page_title'))
@section('page_subtitle', __('notifications.page_subtitle'))

@section('content')
    <div class="panel sg-notifications-page">
        @if($unreadCount > 0)
            <form method="post" action="{{ route('notifications.read-all') }}" class="sg-notifications-page__mark-all">
                @csrf
                <button type="submit" class="sg-btn sg-btn-secondary sg-btn-sm">
                    <i data-lucide="circle-check-big" aria-hidden="true"></i>
                    {{ __('notifications.mark_all_read') }}
                </button>
            </form>
        @endif

        @if($notifications->isEmpty())
            <p>{{ __('notifications.empty') }}</p>
        @else
            <ul class="sg-notification-list sg-notification-list--page">
                @foreach($notifications as $item)
                    <li @class(['sg-notification-list__item', 'is-unread' => ! $item['read']])>
                        <div class="sg-notification-list__body">
                            <a href="{{ $item['open_url'] }}" class="sg-notification-list__link">
                                <span class="sg-notification-list__title">{{ $item['title'] }}</span>
                                @if($item['summary'])
                                    <span class="sg-notification-list__summary">{{ $item['summary'] }}</span>
                                @endif
                                @if($item['created_at'])
                                    <time class="sg-notification-list__time" datetime="{{ $item['created_at']->toIso8601String() }}">
                                        {{ $item['created_at']->format('j M Y, H:i') }}
                                    </time>
                                @endif
                            </a>
                            @if(! $item['read'])
                                <form method="post" action="{{ route('notifications.read', $item['id']) }}" class="sg-notification-list__mark-read">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="sg-btn sg-btn-ghost sg-btn-sm">{{ __('notifications.mark_read') }}</button>
                                </form>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>

            {{ $notifications->links() }}
        @endif
    </div>
@endsection