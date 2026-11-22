@extends('layouts.app')

@section('title', __('staff_messages.edit').' | '.__('workspace.app_name'))

@section('content')
    <div class="panel">
        <h1>{{ __('staff_messages.edit') }}</h1>
        <form method="post" action="{{ route('messages.update', $message) }}">
            @csrf
            @method('PUT')
            @include('staff.messages._form', ['message' => $message, 'canSendAll' => $canSendAll, 'groups' => $groups, 'moduleOptions' => $moduleOptions])
            <button type="submit">{{ __('staff_messages.actions.continue_preview') }}</button>
        </form>
    </div>
@endsection