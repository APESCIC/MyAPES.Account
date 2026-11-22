@props(['soft' => false])
@php
    $pushConfig = [
        'vapidUrl' => route('push.vapid-public-key'),
        'storeUrl' => route('push.subscriptions.store'),
        'destroyUrl' => route('push.subscriptions.destroy'),
        'messages' => [
            'unsupported' => __('push.device.status_unsupported'),
            'home_screen' => __('push.device.status_home_screen'),
            'default' => __('push.device.status_default'),
            'granted' => __('push.device.status_granted'),
            'blocked' => __('push.device.status_blocked'),
            'subscribed' => __('push.device.status_subscribed'),
            'turned_off' => __('push.device.status_turned_off'),
            'dismissed' => __('push.device.status_dismissed'),
            'error' => __('push.device.status_error'),
        ],
    ];
@endphp
<div
    @class([
        'push-device',
        'push-device--soft' => $soft,
    ])
    data-push-device
    @if($soft) data-push-soft-prompt hidden @endif
    data-push-config='@json($pushConfig, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE)'
>
    @if($soft)
        <x-alert tone="info" class="push-device__alert">
            <p><strong>{{ __('push.soft.title') }}</strong></p>
            <p class="muted">{{ __('push.soft.lead') }}</p>
        </x-alert>
    @else
        <h2 class="push-device__heading">{{ __('push.device.heading') }}</h2>
        <p class="muted push-device__intro">{{ __('push.device.intro') }}</p>
    @endif

    <p class="visually-hidden" role="status" aria-live="polite" data-push-status></p>

    <div data-push-state="unsupported" hidden>
        <p>{{ __('push.device.unsupported') }}</p>
    </div>

    <div data-push-state="home_screen" hidden>
        <p>{{ __('push.device.home_screen') }}</p>
    </div>

    <div data-push-state="default">
        <p>{{ __('push.device.default') }}</p>
    </div>

    <div data-push-state="granted" hidden>
        <p>{{ __('push.device.granted') }}</p>
    </div>

    <div data-push-state="blocked" hidden>
        <p>{{ __('push.device.blocked_intro') }}</p>
        <ul class="push-device__unblock-list">
            <li><strong>{{ __('push.device.unblock_chrome_label') }}</strong> {{ __('push.device.unblock_chrome') }}</li>
            <li><strong>{{ __('push.device.unblock_edge_label') }}</strong> {{ __('push.device.unblock_edge') }}</li>
            <li><strong>{{ __('push.device.unblock_firefox_label') }}</strong> {{ __('push.device.unblock_firefox') }}</li>
            <li><strong>{{ __('push.device.unblock_safari_label') }}</strong> {{ __('push.device.unblock_safari') }}</li>
        </ul>
    </div>

    <div data-push-state="subscribed" hidden>
        <p>{{ __('push.device.subscribed') }}</p>
    </div>

    <div class="push-device__actions">
        <x-button type="button" variant="primary" size="sm" data-push-turn-on icon="bell">
            {{ __('push.device.turn_on') }}
        </x-button>
        <x-button type="button" variant="secondary" size="sm" data-push-turn-off hidden icon="bell">
            {{ __('push.device.turn_off') }}
        </x-button>
        @if($soft)
            <x-button type="button" variant="ghost" size="sm" data-push-not-now>
                {{ __('push.soft.not_now') }}
            </x-button>
        @endif
    </div>
</div>