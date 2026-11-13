{{-- Email import incident banner for administrators (#779); clears on the next successful poll. --}}
@can('admin.settings.view')
    @inject('importAlerts', 'App\Core\Mail\Inbound\ImportAlerts')
    @php($importIncident = $importAlerts->incident())
    @if($importIncident !== null)
        <x-alert tone="warning" :title="__('admin.emails.import.alert.title')" data-import-alert>
            <p>{{ __('admin.emails.import.alert.body', ['at' => $importIncident['opened_at']->format('Y-m-d H:i'), 'reason' => $importIncident['failure']->message()]) }}</p>
            <div class="actions">
                <a href="{{ route('admin.emails.import.index') }}">{{ __('admin.emails.import.alert.action') }}</a>
            </div>
        </x-alert>
    @endif
@endcan
