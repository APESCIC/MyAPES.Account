<section class="panel" aria-labelledby="integrations-heading" data-settings-tab="integrations">
    <h2 id="integrations-heading">{{ __('admin.settings.integrations.heading') }}</h2>
    <p>{{ __('admin.settings.integrations.intro') }}</p>
    @if($integrations === [])
        <p class="muted">{{ __('admin.settings.integrations.empty') }}</p>
    @endif
</section>

@php($canManage = auth()->user()?->can('admin.settings.manage'))
@php($envBadge = '<span class="status" data-env-badge>'.e(__('admin.settings.integrations.env_badge')).'</span>')

@foreach($integrations as $key => $integration)
    @php($prefix = 'admin.settings.integrations.'.$key)
    @php($statusKey = 'admin.settings.integrations.status.'.$integration['status'])
    <section class="panel" aria-labelledby="integration-{{ $key }}-heading" data-integration="{{ $key }}">
        <h2 id="integration-{{ $key }}-heading">{{ __($prefix.'.name') }}</h2>
        <p>{{ __($prefix.'.description') }}</p>
        <p data-integration-status="{{ $integration['status'] }}">
            <span class="status">{{ __($statusKey) }}</span>
        </p>
        @if($integration['warning'] !== null)
            <p class="error-list" role="status" data-integration-warning>{{ $integration['warning'] }}</p>
        @endif

        <form method="post" action="{{ route('admin.settings.integrations.update', $key) }}" class="stacked-form" data-integration-form="{{ $key }}" autocomplete="off">
            @csrf
            @method('PUT')
            <fieldset @disabled(! $canManage)>
                <legend class="visually-hidden">{{ __($prefix.'.name') }}</legend>

                @foreach($integration['fields'] as $fieldKey => $field)
                    @php($id = 'integration-'.$key.'-'.str_replace('_', '-', $fieldKey))
                    @php($helpKey = $prefix.'.help.'.$fieldKey)
                    @if($field['input'] === 'checkbox')
                        <label class="inline-check">
                            @unless($field['env'])<input type="hidden" name="{{ $fieldKey }}" value="0">@endunless
                            <input type="checkbox" id="{{ $id }}" name="{{ $fieldKey }}" value="1" @checked(old($fieldKey, $field['value'])) @disabled($field['env'])>
                            <span>{{ __($prefix.'.fields.'.$fieldKey) }}</span>
                            @if($field['env']) {!! $envBadge !!} @endif
                        </label>
                    @elseif($field['input'] === 'password')
                        <label for="{{ $id }}">{{ __($prefix.'.fields.'.$fieldKey) }} @if($field['env']) {!! $envBadge !!} @endif</label>
                        <input id="{{ $id }}" name="{{ $fieldKey }}" type="password" maxlength="500" autocomplete="new-password" @disabled($field['env'])>
                        <p class="muted" data-secret-state="{{ $fieldKey }}">
                            @if($field['env'])
                                {{ __('admin.settings.integrations.secret_env') }}
                            @elseif($field['saved'] && $field['hint'] !== null)
                                {{ __('admin.settings.integrations.secret_saved_hint', ['hint' => $field['hint']]) }}
                            @elseif($field['saved'])
                                {{ __('admin.settings.integrations.secret_saved') }}
                            @else
                                {{ __('admin.settings.integrations.secret_not_saved') }}
                            @endif
                            {{ __('admin.settings.integrations.secret_help') }}
                        </p>
                    @else
                        <label for="{{ $id }}">{{ __($prefix.'.fields.'.$fieldKey) }} @if($field['env']) {!! $envBadge !!} @endif</label>
                        <input id="{{ $id }}" name="{{ $fieldKey }}" type="{{ $field['input'] }}" maxlength="255" value="{{ old($fieldKey, $field['value']) }}" @disabled($field['env'])>
                    @endif
                    @if(\Illuminate\Support\Facades\Lang::has($helpKey))
                        <p class="muted">{{ __($helpKey) }}</p>
                    @endif
                @endforeach

                @if($canManage)
                    <div class="actions">
                        <button type="submit">{{ __('admin.settings.integrations.save', ['name' => __($prefix.'.name')]) }}</button>
                    </div>
                @endif
            </fieldset>
        </form>

        @if($integration['testable'])
            <dl class="detail-list">
                <div>
                    <dt>{{ __('admin.settings.integrations.last_test') }}</dt>
                    <dd data-integration-last-test>
                        @if($integration['lastTest'] === null)
                            {{ __('admin.settings.integrations.never') }}
                        @elseif($integration['lastTest']['ok'])
                            {{ __('admin.settings.integrations.last_test_ok', ['at' => $integration['lastTest']['at']->format('Y-m-d H:i')]) }}
                        @else
                            {{ __('admin.settings.integrations.last_test_failed', ['at' => $integration['lastTest']['at']->format('Y-m-d H:i'), 'reason' => $integration['lastTest']['error']]) }}
                        @endif
                    </dd>
                </div>
            </dl>
            @if($canManage)
                <form method="post" action="{{ route('admin.settings.integrations.test', $key) }}" class="actions" data-integration-test-form="{{ $key }}">
                    @csrf
                    <button type="submit" class="button button-secondary">{{ __('admin.settings.integrations.test') }}</button>
                </form>
            @endif
        @endif
    </section>
@endforeach
