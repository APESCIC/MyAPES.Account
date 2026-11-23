@extends('layouts.app')

@section('title', __('workspace.dock.report_problem'))

@section('content')
<div class="panel">
    <h1>{{ __('workspace.dock.report_problem') }}</h1>
    <p class="muted">{{ __('workspace.dock.report_problem_intro') }}</p>

    <form method="post" action="{{ route('staff.report-problem.store') }}" class="stack">
        @csrf

        <label>
            <span>{{ __('workspace.dock.report_problem_subject') }}</span>
            <input type="text" name="subject" value="{{ old('subject') }}" required maxlength="255">
            @error('subject')
                <span class="error">{{ $message }}</span>
            @enderror
        </label>

        <label>
            <span>{{ __('workspace.dock.report_problem_description') }}</span>
            <textarea name="description" rows="8" required>{{ old('description') }}</textarea>
            @error('description')
                <span class="error">{{ $message }}</span>
            @enderror
        </label>

        <button type="submit">{{ __('workspace.dock.report_problem_submit') }}</button>
    </form>
</div>
@endsection
