@extends('layouts.app')

@section('title', __('staff_messages.title').' | '.__('workspace.app_name'))

@section('content')
    <div class="panel">
        <h1>{{ __('staff_messages.history') }}</h1>
        <p class="muted">{{ __('staff_messages.intro') }}</p>
        @if($canSend)
            <p><a href="{{ route('messages.create') }}">{{ __('staff_messages.create') }}</a></p>
        @endif
    </div>

    <div class="panel">
        @forelse($messages as $message)
            <p>
                <a href="{{ route('messages.show', $message) }}">{{ $message->subject }}</a>
                <span class="muted">
                    · {{ __('staff_messages.status.'.$message->status) }}
                    · {{ __('staff_messages.show.sent_by', ['name' => $message->sender?->name ?? '—']) }}
                    · {{ $message->created_at->format('j M Y H:i') }}
                </span>
            </p>
        @empty
            <p class="muted">{{ __('staff_messages.history') }}: —</p>
        @endforelse

        {{ $messages->links() }}
    </div>
@endsection