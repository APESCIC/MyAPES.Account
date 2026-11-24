@php
    $emailChannels = [
        'email' => __('account.settings.privacy.email_opt_in.staff_email'),
        'marketing_email' => __('account.settings.privacy.email_opt_in.marketing_email'),
    ];
@endphp
<section class="panel" id="email-opt-in" aria-labelledby="privacy-email-opt-in-title">
    <h2 id="privacy-email-opt-in-title">{{ __('account.settings.privacy.email_opt_in.heading') }}</h2>
    <p class="muted">{{ __('account.settings.privacy.email_opt_in.intro') }}</p>

    @foreach ($emailChannels as $channel => $label)
        <form method="post" action="{{ route('account.privacy.contact-preferences.update') }}" class="privacy-toggle-row">
            @csrf
            @method('put')
            <input type="hidden" name="channel" value="{{ $channel }}">
            <input type="hidden" name="granted" value="0">
            <label class="inline-check privacy-toggle-label">
                <input
                    type="checkbox"
                    name="granted"
                    value="1"
                    @checked($emailGranted[$channel] ?? false)
                    data-auto-submit
                >
                {{ $label }}
            </label>
        </form>
        @if ($channel === 'email')
            <p class="muted">{{ __('account.settings.privacy.email_opt_in.staff_email_help') }}</p>
        @else
            <p class="muted">{{ __('account.settings.privacy.email_opt_in.marketing_email_help') }}</p>
        @endif
    @endforeach
</section>
