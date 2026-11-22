@extends('layouts.app')

@section('title', __('staff_messages.create').' | '.__('workspace.app_name'))

@section('content')
    <div class="panel">
        <h1>{{ __('staff_messages.create') }}</h1>
        <form method="post" action="{{ route('messages.store') }}">
            @csrf
            @include('staff.messages._form', ['message' => new \App\Core\Mail\StaffMessage(), 'canSendAll' => $canSendAll, 'groups' => $groups, 'moduleOptions' => $moduleOptions])
            <button type="submit">{{ __('staff_messages.actions.save_draft') }}</button>
        </form>
    </div>
@endsection