@extends('layouts.app')

@section('title', __('seo.recruitment.index.title'))
@section('meta_description', __('seo.recruitment.index.description'))
@section('meta_keywords', __('seo.recruitment.index.keywords'))

@section('content')
    @include('recruitment::_navigation')

    <div class="panel" data-recruitment-board>
        <span class="service-label service-apes-cic">{{ __('recruitment::public.index.blade.apes_cic') }}</span>
        <h1>{{ __('recruitment::public._navigation.blade.open_roles') }}</h1>
        <p class="muted">{{ __('recruitment::public.index.blade.staff_volunteering_and_student_opportunities_with_apes_') }}</p>
        <x-mascot-tip />
    </div>

    <div class="panel" aria-label="{{ __('recruitment::public.index.blade.filter_roles_by_category') }}">
        <div class="actions" role="tablist" aria-label="{{ __('recruitment::public.index.blade.role_categories') }}">
            <a
                href="{{ route('recruitment.index') }}"
                @class(['is-active' => $selectedCategory === null])
                @if($selectedCategory === null) aria-current="page" @endif
            >{{ __('recruitment::public.index.blade.all') }}</a>
            @foreach($categories as $category)
                <a
                    href="{{ route('recruitment.index', ['category' => $category]) }}"
                    @class(['is-active' => $selectedCategory === $category])
                    @if($selectedCategory === $category) aria-current="page" @endif
                >{{ $categoryLabels[$category] }}</a>
            @endforeach
        </div>
    </div>

    <div class="panel" id="list">
        <h2>
            @if($selectedCategory)
                {{ $categoryLabels[$selectedCategory] }} roles
            @else
                All open roles
            @endif
        </h2>

        @if($roles->isEmpty())
            <x-mascot-tip
                variant="empty"
                title="{{ __('recruitment::public.index.blade.no_open_roles_right_now') }}"
                body="{{ $selectedCategory ? 'Try another category, or check back soon.' : 'Check back soon for staff, volunteer, and student openings.' }}"
            />
        @else
            <ul class="stack stack-spaced" data-recruitment-role-list>
                @foreach($roles as $role)
                    <li class="panel panel-flat">
                        <span class="service-label service-apes-cic">{{ $categoryLabels[$role->category] }}</span>
                        <h3>
                            <a href="{{ route('recruitment.show', $role) }}">{{ $role->title }}</a>
                        </h3>
                        @if($role->summary)
                            <p>{{ $role->summary }}</p>
                        @endif
                        @if($role->location || $role->commitment)
                            <p class="muted">
                                @if($role->location){{ $role->location }}@endif
                                @if($role->location && $role->commitment) · @endif
                                @if($role->commitment){{ $role->commitment }}@endif
                            </p>
                        @endif
                        <div class="actions">
                            <a href="{{ route('recruitment.show', $role) }}">{{ __('recruitment::public.show.blade.view_role') }}</a>
                        </div>
                    </li>
                @endforeach
            </ul>
            {{ $roles->links() }}
        @endif
    </div>
@endsection
