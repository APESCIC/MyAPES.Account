<section class="panel" id="notifications" aria-labelledby="privacy-notifications-title">
    <h2 id="privacy-notifications-title" class="visually-hidden">{{ __('account.settings.privacy.notifications.heading') }}</h2>
    <x-push.profile-notifications
        :types="$notificationTypes"
        :divisions="$notificationDivisions"
        :devices="$notificationDevices"
        :settings="$notificationSettings"
    />
</section>