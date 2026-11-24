@php
    $profileUser = $profileUser ?? ($managedUser ?? $client);
    $profileRecord = $profile ?? $profileUser->profile;
    $contactPreference = $preference ?? $profileUser->contactPreference;
    $canSeeDob = $hubCanViewDateOfBirth ?? false;
    $dobValue = $profileRecord?->date_of_birth;
    $dobDisplay = $dobValue
        ? ($canSeeDob ? $dobValue->format('d/m/Y') : __('admin.users.hub.profile.date_of_birth_hidden'))
        : '—';
@endphp
<section class="panel" aria-labelledby="hub-account-profile-title" data-hub-account-profile>
    <h2 id="hub-account-profile-title">{{ __('admin.users.public_profile') }}</h2>
    <dl class="admin-definition-list">
        <div>
            <dt>{{ __('clients.fields.first_name') }}</dt>
            <dd>{{ $profileRecord?->first_name ?: '—' }}</dd>
        </div>
        <div>
            <dt>{{ __('clients.fields.last_name') }}</dt>
            <dd>{{ $profileRecord?->last_name ?: '—' }}</dd>
        </div>
        <div>
            <dt>{{ __('clients.fields.date_of_birth') }}</dt>
            <dd data-hub-date-of-birth @unless($canSeeDob) data-hub-date-of-birth-masked @endunless>{{ $dobDisplay }}</dd>
        </div>
        <div>
            <dt>{{ __('admin.users.preferred_name') }}</dt>
            <dd>{{ $profileRecord?->preferred_name ?: '—' }}</dd>
        </div>
        <div>
            <dt>{{ __('clients.fields.mobile') }}</dt>
            <dd>{{ $profileRecord?->mobile_number ?: '—' }}</dd>
        </div>
        <div>
            <dt>{{ __('clients.fields.address_line_1') }}</dt>
            <dd>{{ $profileRecord?->address_line_1 ?: '—' }}</dd>
        </div>
        <div>
            <dt>{{ __('clients.fields.address_line_2') }}</dt>
            <dd>{{ $profileRecord?->address_line_2 ?: '—' }}</dd>
        </div>
        <div>
            <dt>{{ __('clients.fields.town_city') }}</dt>
            <dd>{{ $profileRecord?->town_city ?: '—' }}</dd>
        </div>
        <div>
            <dt>{{ __('clients.fields.county') }}</dt>
            <dd>{{ $profileRecord?->county ?: '—' }}</dd>
        </div>
        <div>
            <dt>{{ __('clients.fields.postcode') }}</dt>
            <dd>{{ $profileRecord?->postcode ?: '—' }}</dd>
        </div>
        <div>
            <dt>{{ __('admin.users.organisation') }}</dt>
            <dd>{{ $profileRecord?->organization ?: '—' }}</dd>
        </div>
    </dl>
</section>

<section class="panel" aria-labelledby="hub-contact-preferences-title" data-hub-contact-preferences>
    <h2 id="hub-contact-preferences-title">{{ __('admin.users.hub.admin_tools.contact_preferences') }}</h2>
    <dl class="admin-definition-list">
        @foreach (['calls' => 'Calls', 'sms' => 'SMS', 'whatsapp' => 'WhatsApp', 'telegram' => 'Telegram', 'email' => 'Email'] as $channel => $label)
            <div>
                <dt>{{ $label }}</dt>
                <dd>{{ $contactPreference?->{$channel} ? __('admin.users.hub.profile.yes') : __('admin.users.hub.profile.no') }}</dd>
            </div>
        @endforeach
    </dl>
</section>
