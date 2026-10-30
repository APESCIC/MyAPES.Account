@extends('layouts.app')

@section('title', 'Staff profile | MyAPES Account')

@section('content')
    <div class="panel">
        <h1>{{ __('public.profile.staff-edit.blade.staff_profile') }}</h1>
        <p class="muted">{{ __('public.profile.staff-edit.blade.directory_name_email_and_groups_stay_read_only_add_the_') }}</p>
        <dl class="admin-definition-list">
            <div><dt>{{ __('public.profile.staff-edit.blade.name') }}</dt><dd>{{ auth()->user()->name }}</dd></div>
            <div><dt>{{ __('public.profile.edit.blade.email') }}</dt><dd>{{ auth()->user()->email }}</dd></div>
            <div class="admin-definition-list__groups">
                <dt>{{ __('public.chrome.directory-group-list.blade.directory_groups') }}</dt>
                <dd>
                    <x-directory-group-list :groups="auth()->user()->ldap_groups ?? []" />
                </dd>
            </div>
        </dl>
        <form method="post" action="{{ route('profile.update') }}" enctype="multipart/form-data">
            @csrf
            @method('put')
            <label for="job_title">{{ __('public.profile.staff-edit.blade.job_title') }}</label>
            <input id="job_title" name="job_title" value="{{ old('job_title', $staffProfile?->job_title) }}">
            <label for="team">{{ __('public.profile.staff-edit.blade.team') }}</label>
            <select id="team" name="team">
                <option value="">{{ __('public.profile.staff-edit.blade.select_a_team') }}</option>
                @foreach($teams as $value => $label)
                    <option value="{{ $value }}" @selected(old('team', $staffProfile?->team) === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <label for="work_phone">{{ __('public.profile.staff-edit.blade.work_phone') }}</label>
            <input id="work_phone" name="work_phone" value="{{ old('work_phone', $staffProfile?->work_phone) }}" placeholder="+447700900123">
            @if($staffProfile?->photo_path)
                <p>
                    <img src="{{ route('profile.staff-photo') }}" alt="{{ __('public.profile.staff-edit.blade.current_staff_photo') }}" width="96" height="96">
                </p>
            @endif
            <label for="photo">{{ __('public.profile.staff-edit.blade.staff_photo') }}</label>
            <input id="photo" type="file" name="photo" accept="image/*">
            <div class="actions">
                <button type="submit">{{ __('public.profile.staff-edit.blade.save_staff_profile') }}</button>
            </div>
        </form>
        <x-locale-switcher />
    </div>
@endsection
