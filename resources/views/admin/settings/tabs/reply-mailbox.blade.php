<section class="panel" aria-labelledby="reply-mailbox-heading" data-settings-tab="reply-mailbox">
    <h2 id="reply-mailbox-heading">{{ __('admin.settings.reply_mailbox.heading') }}</h2>
    <p>{{ __('admin.settings.reply_mailbox.intro', ['address' => $address]) }}</p>
    <p class="muted" data-reply-mailbox-state>
        @if(! $repliesEnabled)
            {{ __('admin.settings.reply_mailbox.status_off') }}
        @elseif(! $pollingConfigured)
            {{ __('admin.settings.reply_mailbox.status_no_mailbox') }}
        @else
            {{ __('admin.settings.reply_mailbox.status_on') }}
        @endif
    </p>
</section>
