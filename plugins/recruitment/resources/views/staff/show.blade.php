@extends('layouts.app')

@section('title', 'Recruitment: '.$role->title)

@section('content')
    @inject('ukDateTime', \App\Support\UkDateTime::class)
    @include('recruitment::staff._navigation')
    <div class="panel">
        <span class="service-label service-apes-cic">{{ __('recruitment::staff.staff.index.blade.apes_cic') }}</span>
        <h1>{{ $role->title }}</h1>
        <p class="muted">{{ $role->category }} | {{ $role->status }}</p>
        <dl class="ticket-meta">
            @if($revealCreator)
                <div>
                    <dt>{{ __('recruitment::staff.staff.show.blade.created_by') }}</dt>
                    <dd>
                        {{ $role->creator?->name ?? '—' }}
                        @if($role->creator)
                            <br><small class="muted">{{ $role->creator->email }}</small>
                        @endif
                    </dd>
                </div>
            @endif
            <div>
                <dt>{{ __('recruitment::staff.staff.show.blade.created') }}</dt>
                <dd>{{ $ukDateTime->formatDate($role->created_at) ?? '—' }}</dd>
            </div>
            <div>
                <dt>{{ __('recruitment::staff.staff.show.blade.id') }}</dt>
                <dd>#{{ $role->id }}</dd>
            </div>
            @if($role->published_at)
                <div>
                    <dt>{{ __('recruitment::staff.staff.show.blade.published') }}</dt>
                    <dd>{{ $ukDateTime->formatDate($role->published_at) }}</dd>
                </div>
            @endif
            @if($role->closed_at)
                <div>
                    <dt>{{ __('recruitment::staff.staff.show.blade.closed') }}</dt>
                    <dd>{{ $ukDateTime->formatDate($role->closed_at) }}</dd>
                </div>
            @endif
        </dl>
        @if($role->summary)
            <p>{{ $role->summary }}</p>
        @endif
        @if($role->location || $role->commitment)
            <p class="muted">
                @if($role->location){{ __('recruitment::staff.staff.show.blade.location') }} {{ $role->location }}@endif
                @if($role->location && $role->commitment) | @endif
                @if($role->commitment){{ __('recruitment::staff.staff.show.blade.commitment') }} {{ $role->commitment }}@endif
            </p>
        @endif
        <div class="stack-spaced">
            {!! nl2br(e($role->description)) !!}
        </div>
        @if($canPublish || $canClose)
            <div class="actions">
                @if($canPublish)
                    <form method="post" action="{{ route('apes-cic.recruitment.publish', $role) }}">
                        @csrf
                        <button type="submit">{{ __('recruitment::staff.staff.show.blade.publish_open') }}</button>
                    </form>
                @endif
                @if($canClose)
                    <form method="post" action="{{ route('apes-cic.recruitment.close', $role) }}">
                        @csrf
                        <button type="submit">{{ __('recruitment::staff.staff.show.blade.close_role') }}</button>
                    </form>
                @endif
            </div>
        @endif
        @if($canUpdate)
            <form method="post" action="{{ route('apes-cic.recruitment.update', $role) }}" class="stack-spaced">
                @csrf
                @method('put')
                <div class="row">
                    <div>
                        <label for="role_title">{{ __('recruitment::staff.staff.index.blade.title') }}</label>
                        <input id="role_title" name="title" value="{{ old('title', $role->title) }}" required>
                    </div>
                    <div>
                        <label for="role_category">{{ __('recruitment::staff.staff.index.blade.category') }}</label>
                        <select id="role_category" name="category" required>
                            @foreach($categories as $category)
                                <option value="{{ $category }}" @selected(old('category', $role->category) === $category)>
                                    {{ $category }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="row">
                    <div>
                        <label for="role_location">{{ __('recruitment::staff.staff.index.blade.location') }}</label>
                        <input id="role_location" name="location" value="{{ old('location', $role->location) }}">
                    </div>
                    <div>
                        <label for="role_commitment">{{ __('recruitment::staff.staff.index.blade.commitment') }}</label>
                        <input id="role_commitment" name="commitment" value="{{ old('commitment', $role->commitment) }}">
                    </div>
                </div>
                <label for="role_summary">{{ __('recruitment::staff.staff.index.blade.summary') }}</label>
                <input id="role_summary" name="summary" value="{{ old('summary', $role->summary) }}">
                <label for="role_description">{{ __('recruitment::staff.staff.index.blade.description') }}</label>
                <textarea id="role_description" name="description" required>{{ old('description', $role->description) }}</textarea>
                <div class="actions">
                    <button type="submit">{{ __('recruitment::staff.staff.show.blade.update_recruitment_role') }}</button>
                    <a href="{{ route('apes-cic.recruitment.index') }}">{{ __('recruitment::staff.staff.show.blade.back') }}</a>
                </div>
            </form>
        @else
            <a href="{{ route('apes-cic.recruitment.index') }}">{{ __('recruitment::staff.staff.show.blade.back') }}</a>
        @endif
    </div>
@endsection
