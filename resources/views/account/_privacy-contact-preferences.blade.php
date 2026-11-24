@php
    $channels = [
        'calls' => __('account.settings.privacy.channels.calls'),
        'sms' => __('account.settings.privacy.channels.sms'),
        'whatsapp' => __('account.settings.privacy.channels.whatsapp'),
        'telegram' => __('account.settings.privacy.channels.telegram'),
    ];
@endphp
<section class="panel" id="contact-preferences" aria-labelledby="privacy-contact-title">
    <h2 id="privacy-contact-title">{{ __('account.settings.privacy.contact.heading') }}</h2>
    <p class="muted">{{ __('account.settings.privacy.contact.intro') }}</p>
    <p class="muted">{{ __('account.settings.privacy.policy_version', ['version' => $policyVersion]) }}</p>

    <div class="privacy-toggle-stack">
        @foreach ($channels as $channel => $label)
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
                        @checked($channelGranted[$channel] ?? false)
                        data-auto-submit
                    >
                    {{ $label }}
                </label>
            </form>
        @endforeach
    </div>
</section>
