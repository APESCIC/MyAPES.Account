<section class="panel" id="signed-in-devices">
    <h2>{{ __('account.settings.security_devices.heading') }}</h2>
    <p class="muted">{{ __('account.settings.security_devices.intro') }}</p>

    @if(! $canListMultipleSignedInDevices)
        <p class="muted">{{ __('account.settings.security_devices.single_device_note') }}</p>
    @endif

    <ul class="signed-in-device-list">
        @foreach($signedInDevices as $device)
            <li @class(['signed-in-device-item', 'signed-in-device-item--current' => $device['current']])>
                <div>
                    <strong>{{ $device['label'] }}</strong>
                    @if($device['current'])
                        <span class="pill">{{ __('account.settings.security_devices.current_device') }}</span>
                    @endif
                    <p class="muted">
                        @if($device['last_activity'])
                            {{ __('account.settings.security_devices.last_active', ['datetime' => $device['last_activity']->format('d/m/Y H:i')]) }}
                        @endif
                        @if($device['ip_address'])
                            · {{ __('account.settings.security_devices.ip', ['ip' => $device['ip_address']]) }}
                        @endif
                    </p>
                </div>
            </li>
        @endforeach
    </ul>

    @if($canListMultipleSignedInDevices && count($signedInDevices) > 1)
        <form method="post" action="{{ route('account.security.sessions.destroy-others') }}" class="mt-2">
            @csrf
            @method('delete')
            <p class="muted">{{ __('account.settings.security_devices.sign_out_others_note') }}</p>
            <div class="actions">
                <button type="submit">{{ __('account.settings.security_devices.sign_out_others') }}</button>
            </div>
        </form>
    @endif
</section>