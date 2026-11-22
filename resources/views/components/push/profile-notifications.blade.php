@props([
    'types' => [],
    'divisions' => [],
    'devices' => [],
    'settings' => null,
])
@php
    $quietEnabled = (bool) ($settings?->quiet_hours_enabled ?? false);
    $quietStart = $settings?->quiet_hours_start
        ? substr((string) $settings->quiet_hours_start, 0, 5)
        : config('push-profile.quiet_hours_default_start');
    $quietEnd = $settings?->quiet_hours_end
        ? substr((string) $settings->quiet_hours_end, 0, 5)
        : config('push-profile.quiet_hours_default_end');
@endphp
<div class="push-profile-notifications">
    <x-push.device-notifications />

    <h2 class="push-profile-notifications__heading">{{ __('push.profile.types_heading') }}</h2>
    <p class="muted">{{ __('push.profile.types_intro') }}</p>

    <form method="post" action="{{ route('profile.notifications.update') }}" class="push-profile-notifications__form">
        @csrf
        @method('put')

        <div class="push-profile-notifications__table-wrap">
            <table class="push-profile-notifications__table">
                <caption class="visually-hidden">{{ __('push.profile.types_caption') }}</caption>
                <thead>
                    <tr>
                        <th scope="col">{{ __('push.profile.column_type') }}</th>
                        <th scope="col">{{ __('push.profile.column_push') }}</th>
                        <th scope="col">{{ __('push.profile.column_email') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($types as $type)
                        <tr>
                            <th scope="row">{{ $type['label'] }}</th>
                            <td class="push-profile-notifications__switch">
                                <label class="push-pref-switch">
                                    <span class="visually-hidden">{{ __('push.profile.push_for', ['type' => $type['label']]) }}</span>
                                    <input
                                        type="checkbox"
                                        name="push[{{ $type['key'] }}]"
                                        value="1"
                                        class="push-pref-switch__input"
                                        @checked(old('push.'.$type['key'], $type['push_enabled']))
                                        @disabled($type['push_locked'])
                                    >
                                </label>
                            </td>
                            <td class="push-profile-notifications__switch">
                                @if($type['email_locked'])
                                    <input type="hidden" name="email[{{ $type['key'] }}]" value="1">
                                @endif
                                <label class="push-pref-switch">
                                    <span class="visually-hidden">{{ __('push.profile.email_for', ['type' => $type['label']]) }}</span>
                                    <input
                                        type="checkbox"
                                        name="email[{{ $type['key'] }}]"
                                        value="1"
                                        class="push-pref-switch__input"
                                        @checked($type['email_locked'] ? true : old('email.'.$type['key'], $type['email_enabled']))
                                        @disabled($type['email_locked'])
                                    >
                                </label>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if($divisions !== [])
            <h3 class="push-profile-notifications__subheading">{{ __('push.profile.divisions_heading') }}</h3>
            <p class="muted">{{ __('push.profile.divisions_intro') }}</p>
            <ul class="push-profile-notifications__division-list">
                @foreach($divisions as $division)
                    <li>
                        <label class="push-pref-switch push-pref-switch--labelled">
                            <input
                                type="checkbox"
                                name="divisions[{{ $division['key'] }}]"
                                value="1"
                                class="push-pref-switch__input"
                                @checked(old('divisions.'.$division['key'], $division['enabled']))
                            >
                            <span>{{ $division['name'] }}</span>
                        </label>
                    </li>
                @endforeach
            </ul>
        @endif

        <fieldset class="push-profile-notifications__quiet">
            <legend>{{ __('push.profile.quiet_heading') }}</legend>
            <p class="muted">{{ __('push.profile.quiet_intro') }}</p>
            <label class="push-pref-switch push-pref-switch--labelled">
                <input
                    type="checkbox"
                    name="quiet_hours_enabled"
                    value="1"
                    class="push-pref-switch__input"
                    @checked(old('quiet_hours_enabled', $quietEnabled))
                >
                <span>{{ __('push.profile.quiet_enable') }}</span>
            </label>
            <div class="push-profile-notifications__quiet-times">
                <label for="quiet_hours_start">{{ __('push.profile.quiet_start') }}</label>
                <input
                    type="time"
                    id="quiet_hours_start"
                    name="quiet_hours_start"
                    value="{{ old('quiet_hours_start', $quietStart) }}"
                >
                <label for="quiet_hours_end">{{ __('push.profile.quiet_end') }}</label>
                <input
                    type="time"
                    id="quiet_hours_end"
                    name="quiet_hours_end"
                    value="{{ old('quiet_hours_end', $quietEnd) }}"
                >
                <p class="muted">{{ __('push.profile.quiet_timezone') }}</p>
            </div>
        </fieldset>

        <div class="actions">
            <x-button type="submit" variant="primary">{{ __('push.profile.save') }}</x-button>
        </div>
    </form>

    <h2 class="push-profile-notifications__heading">{{ __('push.profile.devices_heading') }}</h2>
    <p class="muted">{{ __('push.profile.devices_intro') }}</p>

    @if($devices === [])
        <p class="muted">{{ __('push.profile.devices_empty') }}</p>
    @else
        <div class="push-profile-notifications__table-wrap">
            <table class="push-profile-notifications__table">
                <caption class="visually-hidden">{{ __('push.profile.devices_caption') }}</caption>
                <thead>
                    <tr>
                        <th scope="col">{{ __('push.profile.column_browser') }}</th>
                        <th scope="col">{{ __('push.profile.column_system') }}</th>
                        <th scope="col">{{ __('push.profile.column_added') }}</th>
                        <th scope="col">{{ __('push.profile.column_last_used') }}</th>
                        <th scope="col">{{ __('push.profile.column_actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($devices as $device)
                        <tr>
                            <td>{{ $device['browser'] }}</td>
                            <td>{{ $device['system'] }}</td>
                            <td>{{ $device['added_at'] ?? '—' }}</td>
                            <td>{{ $device['last_used_at'] ?? '—' }}</td>
                            <td class="push-profile-notifications__device-actions">
                                <form method="post" action="{{ route('profile.notifications.devices.test', $device['id']) }}">
                                    @csrf
                                    <x-button type="submit" variant="secondary" size="sm">{{ __('push.profile.send_test') }}</x-button>
                                </form>
                                <form method="post" action="{{ route('profile.notifications.devices.destroy', $device['id']) }}">
                                    @csrf
                                    @method('delete')
                                    <x-button type="submit" variant="ghost" size="sm">{{ __('push.profile.remove_device') }}</x-button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>