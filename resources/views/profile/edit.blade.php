@extends('layouts.app')

@section('title', 'Profile | MyAPES Account')

@section('content')
    <div class="panel-stack">
        <div class="panel" id="notifications">
            <x-push.profile-notifications
                :types="$notificationTypes"
                :divisions="$notificationDivisions"
                :devices="$notificationDevices"
                :settings="$notificationSettings"
            />
        </div>
    </div>
@endsection