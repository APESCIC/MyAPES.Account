@php
    use App\Support\Notifications\AccountNotificationPresenter;

    $user = auth()->user();
    $unreadCount = $user->unreadNotifications()->count();
    $recent = $user->notifications()->latest()->limit(8)->get()->map(
        fn ($notification) => AccountNotificationPresenter::present($notification),
    );
@endphp
<button
    type="button"
    class="sg-icon-btn sg-icon-btn--sm sg-notification-bell"
    popovertarget="notification-flyout"
    aria-expanded="false"
    data-notification-bell
    aria-label="{{ $unreadCount > 0 ? __('notifications.bell_label_unread', ['count' => $unreadCount]) : __('notifications.bell_label') }}"
>
    <i data-lucide="bell" aria-hidden="true"></i>
    @if($unreadCount > 0)
        <span class="sg-notification-bell__badge" data-testid="notification-unread-count">{{ $unreadCount }}</span>
    @endif
</button>
<div
    id="notification-flyout"
    class="sg-notification-flyout"
    popover
    role="dialog"
    aria-labelledby="notification-flyout-heading"
    data-notification-flyout
>
    <div class="sg-notification-flyout__header">
        <h2 id="notification-flyout-heading" class="sg-notification-flyout__title">{{ __('notifications.flyout_title') }}</h2>
        @if($unreadCount > 0)
            <form method="post" action="{{ route('notifications.read-all') }}" class="sg-notification-flyout__mark-all">
                @csrf
                <button type="submit" class="sg-btn sg-btn-ghost sg-btn-sm">
                    {{ __('notifications.mark_all_read') }}
                </button>
            </form>
        @endif
    </div>
    @if($recent->isEmpty())
        <p class="sg-notification-flyout__empty">{{ __('notifications.empty') }}</p>
    @else
        <ul class="sg-notification-list">
            @foreach($recent as $item)
                <li @class(['sg-notification-list__item', 'is-unread' => ! $item['read']])>
                    <a href="{{ $item['open_url'] }}" class="sg-notification-list__link">
                        <span class="sg-notification-list__title">{{ $item['title'] }}</span>
                        @if($item['summary'])
                            <span class="sg-notification-list__summary">{{ $item['summary'] }}</span>
                        @endif
                        @if($item['created_at'])
                            <time class="sg-notification-list__time" datetime="{{ $item['created_at']->toIso8601String() }}">
                                {{ $item['created_at']->diffForHumans() }}
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
                </li>
            @endforeach
        </ul>
    @endif
    <a href="{{ route('notifications.index') }}" class="sg-notification-flyout__view-all">{{ __('notifications.view_all') }}</a>
</div>