@extends('layouts.app')

@section('title', __('seo.recruitment.show.title', ['role' => $role->title]))
@section('meta_description', \App\Support\SeoMeta::truncateDescription($role->summary ?: $role->description))
@section('meta_keywords', __('seo.recruitment.show.keywords'))

@section('content')
    @include('recruitment::_navigation')

    <div class="panel" data-recruitment-role-detail>
        <span class="service-label service-apes-cic">{{ $categoryLabels[$role->category] }}</span>
        <h1>{{ $role->title }}</h1>
        @if($role->summary)
            <p class="muted">{{ $role->summary }}</p>
        @endif
        @if($role->location || $role->commitment)
            <p class="muted">
                @if($role->location){{ __('recruitment::public.show.blade.location') }} {{ $role->location }}@endif
                @if($role->location && $role->commitment) · @endif
                @if($role->commitment){{ __('recruitment::public.show.blade.commitment') }} {{ $role->commitment }}@endif
            </p>
        @endif
        <div class="stack-spaced">
            {!! nl2br(e($role->description)) !!}
        </div>
        <div class="actions">
            <a href="{{ route('recruitment.index') }}">{{ __('recruitment::public.show.blade.back_to_open_roles') }}</a>
            @auth
                <a href="{{ route('recruitment.applications.index') }}">{{ __('recruitment::public._navigation.blade.my_applications') }}</a>
            @endauth
        </div>
    </div>

    <div class="panel" data-recruitment-apply>
        <h2>{{ __('recruitment::public.show.blade.apply_for_this_role') }}</h2>
        @if(! ($publicApplyEnabled ?? true))
            <p class="muted">{{ __('recruitment::public.show.blade.applications_are_not_open_for_public_roles_right_now_yo') }}</p>
        @elseif(auth()->guest())
            <p class="muted">{{ __('recruitment::public.show.blade.sign_in_or_create_a_public_account_to_apply_you_can_sti') }}</p>
            <div class="actions">
                <a href="{{ route('public.login') }}">{{ __('recruitment::public.show.blade.public_login') }}</a>
                <a href="{{ route('public.register') }}">{{ __('recruitment::public.show.blade.register') }}</a>
            </div>
        @else
            @if($existingApplication)
                <p>
                    {{ __('recruitment::public.show.blade.already_applied_status') }}
                    <span class="status">{{ $statusLabels[$existingApplication->status] ?? $existingApplication->status }}</span>
                </p>
                <div class="actions">
                    <a href="{{ route('recruitment.applications.show', $existingApplication) }}">{{ __('recruitment::public.show.blade.view_your_application') }}</a>
                </div>
            @elseif($canApply)
                <p class="muted">{{ __('recruitment::public.show.blade.each_person_may_apply_once_per_role_tell_us_briefly_why') }}</p>
                <form method="post" action="{{ route('recruitment.apply', $role) }}">
                    @csrf
                    <label for="application_statement">{{ __('recruitment::public.show.blade.why_are_you_interested') }}</label>
                    <textarea id="application_statement" name="statement" required maxlength="5000">{{ old('statement') }}</textarea>
                    <div class="actions">
                        <button type="submit">{{ __('recruitment::public.show.blade.submit_application') }}</button>
                    </div>
                </form>
            @else
                <p class="muted">{{ __('recruitment::public.show.blade.you_cannot_apply_for_this_role_with_your_current_accoun') }}</p>
            @endif
        @endif
    </div>
@endsection
