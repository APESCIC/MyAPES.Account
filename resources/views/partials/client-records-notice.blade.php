@if(auth()->user()?->client_records_archived_at !== null && auth()->user()?->client_records_notice_dismissed_at === null)
    <x-alert tone="warning" :title="__('public.client_records_notice.title')" data-client-records-notice>
        <p>{{ __('public.client_records_notice.body') }}</p>
        <div class="actions">
            <a href="{{ route('help') }}#staff-and-personal-accounts">{{ __('public.account_separation.learn_more') }}</a>
            @registrationOpen
                <a href="{{ route('public.register') }}">{{ __('public.account_separation.register') }}</a>
            @endregistrationOpen
            <form method="post" action="{{ route('client-records-notice.dismiss') }}">
                @csrf
                <button type="submit" class="button-secondary">{{ __('public.client_records_notice.dismiss') }}</button>
            </form>
        </div>
    </x-alert>
@endif
