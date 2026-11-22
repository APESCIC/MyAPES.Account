@php
    use App\Core\Accounts\AccountClassifier;

    $filter = old('filter_mode', $message->recipient_filter['mode'] ?? 'group');
@endphp

<fieldset>
    <legend>{{ __('staff_messages.fields.category') }}</legend>
    <label class="inline-check">
        <input type="radio" name="category" value="service" @checked(old('category', $message->category ?? 'service') === 'service') required>
        {{ __('staff_messages.fields.category_service') }}
    </label>
    <p class="muted">{{ __('staff_messages.fields.category_service_help') }}</p>
    <label class="inline-check">
        <input type="radio" name="category" value="optional" @checked(old('category', $message->category ?? '') === 'optional')>
        {{ __('staff_messages.fields.category_optional') }}
    </label>
    <p class="muted">{{ __('staff_messages.fields.category_optional_help') }}</p>
</fieldset>

<label for="subject">{{ __('staff_messages.fields.subject') }}</label>
<input id="subject" name="subject" value="{{ old('subject', $message->subject ?? '') }}" required maxlength="255">

<label for="body_html">{{ __('staff_messages.fields.body') }}</label>
<x-rich-text-editor name="body_html" :value="old('body_html', app(\App\Core\Content\RichText::class)->forEditor($message->body_html ?? null))" required />

<fieldset>
    <legend>{{ __('staff_messages.fields.recipients') }}</legend>
    <label for="filter_mode">{{ __('staff_messages.fields.filter_mode') }}</label>
    <select id="filter_mode" name="filter_mode" required data-staff-message-filter>
        @if($canSendAll)
            <option value="all" @selected($filter === 'all')>{{ __('staff_messages.fields.mode_all') }}</option>
        @endif
        <option value="group" @selected($filter === 'group')>{{ __('staff_messages.fields.mode_group') }}</option>
        <option value="module" @selected($filter === 'module')>{{ __('staff_messages.fields.mode_module') }}</option>
        <option value="users" @selected($filter === 'users')>{{ __('staff_messages.fields.mode_users') }}</option>
    </select>

    <div data-filter-panel="group" @class(['hidden' => $filter !== 'group'])>
        <label for="filter_group">{{ __('staff_messages.fields.mode_group') }}</label>
        <select id="filter_group" name="filter_group">
            @foreach($groups as $key => $label)
                <option value="{{ $key }}" @selected(old('filter_group', $message->recipient_filter['group'] ?? AccountClassifier::PUBLIC) === $key)>{{ $label }}</option>
            @endforeach
        </select>
    </div>

    <div data-filter-panel="module" @class(['hidden' => $filter !== 'module'])>
        <label for="filter_module">{{ __('staff_messages.fields.mode_module') }}</label>
        <select id="filter_module" name="filter_module">
            @foreach($moduleOptions as $option)
                <option value="{{ $option['key'] }}" @selected(old('filter_module', $message->recipient_filter['module'] ?? '') === $option['key'])>{{ $option['label'] }}</option>
            @endforeach
        </select>
    </div>

    <div data-filter-panel="users" @class(['hidden' => $filter !== 'users'])>
        <label for="filter_user_ids">{{ __('staff_messages.fields.mode_users') }}</label>
        <select id="filter_user_ids" name="filter_user_ids[]" multiple data-staff-message-user-picker data-endpoint="{{ route('messages.user-candidates') }}">
            @foreach(old('filter_user_ids', $message->recipient_filter['user_ids'] ?? []) as $userId)
                <option value="{{ $userId }}" selected>{{ $userId }}</option>
            @endforeach
        </select>
        <p class="muted">{{ __('clients.search_placeholder') }}</p>
    </div>
</fieldset>