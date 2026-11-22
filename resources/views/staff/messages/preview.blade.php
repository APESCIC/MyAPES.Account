@extends('layouts.app')

@section('title', __('staff_messages.preview').' | '.__('workspace.app_name'))

@section('content')
    <div class="panel">
        <h1>{{ __('staff_messages.preview') }}</h1>
        <p class="muted">{{ __('staff_messages.counts.will_receive', ['count' => $message->recipient_count]) }}</p>
        @if($message->opted_out_count > 0)
            <p class="muted">{{ __('staff_messages.counts.opted_out', ['count' => $message->opted_out_count]) }}</p>
        @endif

        <h2>{{ $message->subject }}</h2>
        <x-rich-text :value="$message->body_html" />

        <p>
            <a href="{{ route('messages.edit', $message) }}">{{ __('staff_messages.actions.back_edit') }}</a>
        </p>

        <form method="post" action="{{ route('messages.confirm', $message) }}">
            @csrf
            @if($requiresConfirmCount)
                <p class="muted">{{ __('staff_messages.confirm.large_send') }}</p>
                <label for="confirm_count">{{ __('staff_messages.fields.confirm_count') }}</label>
                <input id="confirm_count" name="confirm_count" type="number" required min="0" inputmode="numeric">
            @endif
            <button type="submit">{{ __('staff_messages.confirm.send') }}</button>
        </form>
    </div>
@endsection