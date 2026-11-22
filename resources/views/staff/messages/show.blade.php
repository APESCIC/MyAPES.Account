@extends('layouts.app')

@section('title', $message->subject.' | '.__('workspace.app_name'))

@section('content')
    <div class="panel">
        <h1>{{ $message->subject }}</h1>
        <p class="muted">
            {{ $message->statusLabel() }}
            · {{ __('staff_messages.show.sent_by', ['name' => $message->sender->name]) }}
            @if($message->queued_at)
                · {{ $message->queued_at->format('j M Y H:i') }}
            @endif
        </p>
        <p class="muted">
            {{ __('staff_messages.show.stats', [
                'sent' => $message->sent_count,
                'failed' => $message->failed_count,
                'opted' => $message->opted_out_count,
            ]) }}
        </p>

        <x-rich-text :value="$message->body_html" />

        <p><a href="{{ route('messages.index') }}">{{ __('staff_messages.history') }}</a></p>
    </div>
@endsection