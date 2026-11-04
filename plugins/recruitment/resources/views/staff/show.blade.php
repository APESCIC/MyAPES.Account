@extends('layouts.app')

@section('title', 'Recruitment: '.$role->title)

@section('content')
    @inject('ukDateTime', \App\Support\UkDateTime::class)
    @include('recruitment::_navigation')
    <div class="panel">
        <span class="service-label service-apes-cic">{{ __('recruitment::staff.staff.index.blade.apes_cic') }}</span>
        <h1>{{ $role->title }}</h1>
        <p class="muted">{{ \App\Support\WorkflowLabels::label('recruitment_category', $role->category) }} | {{ \App\Support\WorkflowLabels::status($role->status) }}</p>
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
            <x-rich-text :value="$role->description" />
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
                                    {{ \App\Support\WorkflowLabels::label('recruitment_category', $category) }}
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
                <x-rich-text-editor id="role_description" name="description" :value="old('description', $role->description)" required />
                <div class="actions">
                    <button type="submit">{{ __('recruitment::staff.staff.show.blade.update_recruitment_role') }}</button>
                    <a href="{{ route('apes-cic.recruitment.index') }}">{{ __('recruitment::staff.staff.show.blade.back') }}</a>
                </div>
            </form>
        @else
            <a href="{{ route('apes-cic.recruitment.index') }}">{{ __('recruitment::staff.staff.show.blade.back') }}</a>
        @endif
    </div>

    <div class="panel" id="questions" data-recruitment-questions>
        <h2>{{ __('recruitment::staff.questions.heading') }}</h2>
        <p class="muted">{{ __('recruitment::staff.questions.intro') }}</p>
        @if($role->questions->isEmpty())
            <p class="muted">{{ __('recruitment::staff.questions.none') }}</p>
        @else
            <ol class="stack-spaced">
                @foreach($role->questions as $question)
                    <li>
                        <strong>{{ $question->label }}</strong>
                        <span class="muted">· {{ $answerTypes[$question->answer_type] ?? $question->answer_type }} · {{ $question->required ? __('recruitment::staff.questions.required') : __('recruitment::staff.questions.optional') }}</span>
                        @if($question->help_text)
                            <br><small class="muted">{{ $question->help_text }}</small>
                        @endif
                        @if($canUpdate)
                            <div class="actions">
                                @unless($loop->first)
                                    <form method="post" action="{{ route('apes-cic.recruitment.questions.move', [$role, $question]) }}">
                                        @csrf
                                        <input type="hidden" name="direction" value="up">
                                        <button type="submit" class="sg-btn-secondary">{{ __('recruitment::staff.questions.move_up') }}</button>
                                    </form>
                                @endunless
                                @unless($loop->last)
                                    <form method="post" action="{{ route('apes-cic.recruitment.questions.move', [$role, $question]) }}">
                                        @csrf
                                        <input type="hidden" name="direction" value="down">
                                        <button type="submit" class="sg-btn-secondary">{{ __('recruitment::staff.questions.move_down') }}</button>
                                    </form>
                                @endunless
                                <form method="post" action="{{ route('apes-cic.recruitment.questions.destroy', [$role, $question]) }}">
                                    @csrf
                                    @method('delete')
                                    <button type="submit" class="sg-btn-secondary">{{ __('recruitment::staff.questions.remove') }}</button>
                                </form>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ol>
        @endif

        @if($canUpdate)
            <form method="post" action="{{ route('apes-cic.recruitment.questions.store', $role) }}" class="stack-spaced">
                @csrf
                <h3>{{ __('recruitment::staff.questions.add_heading') }}</h3>
                <label for="question_label">{{ __('recruitment::staff.questions.label') }}</label>
                <input id="question_label" name="label" value="{{ old('label') }}" maxlength="255" required>
                <label for="question_help">{{ __('recruitment::staff.questions.help_text') }}</label>
                <input id="question_help" name="help_text" value="{{ old('help_text') }}" maxlength="500">
                <label for="question_type">{{ __('recruitment::staff.questions.answer_type') }}</label>
                <select id="question_type" name="answer_type" required>
                    @foreach($answerTypes as $type => $typeLabel)
                        <option value="{{ $type }}" @selected(old('answer_type') === $type)>{{ $typeLabel }}</option>
                    @endforeach
                </select>
                <label>
                    <input type="checkbox" name="required" value="1" @checked(old('required'))>
                    {{ __('recruitment::staff.questions.make_required') }}
                </label>
                <div class="actions">
                    <button type="submit">{{ __('recruitment::staff.questions.add') }}</button>
                </div>
            </form>
        @endif
    </div>
@endsection
