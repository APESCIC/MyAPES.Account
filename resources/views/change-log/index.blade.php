@extends('layouts.app')

@section('title', __('seo.change_log.title'))
@section('meta_description', __('seo.change_log.description'))
@section('meta_keywords', __('seo.change_log.keywords'))

@section('content')
<div class="change-log" data-change-log>
    <section class="change-log__hero" aria-labelledby="change-log-title">
        <p class="eyebrow">{{ __('public.change_log.release_records') }}</p>
        <h1 id="change-log-title">{{ __('public.change_log.change_log_hub') }}</h1>
        <p>{{ __('public.change_log.track_myapes_core_releases_fixes_compliance_work_access') }}</p>

        @php
            $changeLogUser = auth()->user();
            $showChangeLogFeedback = $changeLogUser !== null && (
                app(\App\Services\AuthorizationProfile::class)->isAdministrator($changeLogUser)
                || app(\App\Core\Accounts\AccountClassifier::class)->isStaffSide($changeLogUser)
            );
        @endphp
        @if ($showChangeLogFeedback)
            <section class="change-log__community" aria-labelledby="change-log-community-title">
                <h2 id="change-log-community-title">{{ __('public.change_log.feedback_source') }}</h2>
                <p>{{ __('public.change_log.report_bugs_suggest_improvements_or_follow_project_disc') }}</p>
                @include('partials._github-links', ['variant' => 'change-log'])
            </section>
        @endif

        <div class="change-log__current">
            <div>
                <span>{{ __('public.change_log.current_version') }}</span>
                <strong>v{{ $currentRelease['version'] }}</strong>
            </div>
            <div>
                <span>{{ ucfirst($currentRelease['type']) }} · {{ ucfirst($currentRelease['channel']) }}</span>
                <strong>{{ $currentRelease['title'] }}</strong>
            </div>
            <a href="#release-v{{ str_replace('.', '-', $currentRelease['version']) }}">{{ __('public.change_log.view_current_release') }}</a>
        </div>
    </section>

    <section class="change-log__controls" aria-label="{{ __('public.change_log.find_release_records') }}" data-change-log-controls hidden>
        <div class="change-log__search">
            <label for="change-log-search">{{ __('public.change_log.search_release_notes') }}</label>
            <input
                id="change-log-search"
                type="search"
                inputmode="search"
                autocomplete="off"
                placeholder="{{ __('public.change_log.search_versions_changes_affected_areas') }}"
                data-change-log-search
            >
        </div>

        <fieldset class="change-log__filters">
            <legend>{{ __('public.change_log.filter_releases') }}</legend>
            @foreach([
                'all' => 'All releases',
                'current' => 'Current release',
                'beta' => 'Beta',
                'added' => 'Added',
                'changed' => 'Changed',
                'fixed' => 'Fixed',
                'removed' => 'Removed',
                'security' => 'Security',
                'compliance' => 'Compliance',
                'accessibility' => 'Accessibility',
                'public-facing' => 'Public-facing',
                'internal-only' => 'Internal-only',
            ] as $filter => $label)
                @if($filter === 'internal-only' && ! $showInternalAudienceFilter)
                    @continue
                @endif
                <button
                    type="button"
                    data-change-log-filter="{{ $filter }}"
                    aria-pressed="{{ $filter === 'all' ? 'true' : 'false' }}"
                >{{ $label }}</button>
            @endforeach
        </fieldset>

        <div class="change-log__actions">
            <button type="button" data-change-log-expand>{{ __('public.change_log.expand_all_releases') }}</button>
            <button type="button" data-change-log-collapse>{{ __('public.change_log.collapse_all_releases') }}</button>
        </div>

        <p class="change-log__status" role="status" aria-live="polite" data-change-log-status>
            Showing {{ count($releases) }} releases
        </p>
    </section>

    <section class="change-log__records" aria-label="{{ __('public.change_log.release_history') }}">
        @foreach($releases as $release)
            @php($isCurrent = (bool) ($release['is_current'] ?? $loop->first))
            <article
                id="release-v{{ str_replace('.', '-', $release['version']) }}"
                class="change-log__release"
                data-release-record
                data-version="{{ $release['version'] }}"
                data-current="{{ $isCurrent ? 'true' : 'false' }}"
                data-channel="{{ $release['channel'] }}"
                data-categories="{{ implode(' ', $release['categories']) }}"
                data-audiences="{{ implode(' ', $release['audiences']) }}"
            >
                <details data-release-details @if($isCurrent) open @endif>
                    <summary>
                        <span class="change-log__release-version">v{{ $release['version'] }}</span>
                        <span>{{ $release['date'] }}</span>
                        <span>{{ ucfirst($release['channel']) }}</span>
                        <span>{{ $release['title'] }}</span>
                    </summary>

                    <div class="change-log__release-body">
                        <div class="change-log__badges" aria-label="{{ __('public.change_log.release_classifications') }}">
                            <span>{{ ucfirst($release['type']) }}</span>
                            @foreach($release['categories'] as $category)
                                <span>{{ ucfirst($category) }}</span>
                            @endforeach
                            @foreach($release['audiences'] as $audience)
                                <span>{{ ucfirst(str_replace('-', ' ', $audience)) }}</span>
                            @endforeach
                        </div>

                        <section>
                            <h2>{{ __('public.change_log.summary') }}</h2>
                            <p>{{ $release['summary'] }}</p>
                        </section>

                        <section>
                            <h2>{{ __('public.change_log.detailed_changes') }}</h2>
                            <ul>
                                @foreach($release['changes'] as $change)
                                    <li>{{ $change }}</li>
                                @endforeach
                            </ul>
                        </section>

                        <section>
                            <h2>{{ __('public.change_log.affected_areas') }}</h2>
                            <ul>
                                @foreach($release['affected_areas'] as $area)
                                    <li>{{ $area }}</li>
                                @endforeach
                            </ul>
                        </section>

                        <section>
                            <h2>{{ __('public.change_log.version_decision') }}</h2>
                            <p>{{ $release['version_rationale'] }}</p>
                        </section>

                        @if($showInternalNotes)
                            <section>
                                <h2>{{ __('public.change_log.validation') }}</h2>
                                <ul>
                                    @foreach($release['validation'] as $check)
                                        <li>{{ $check }}</li>
                                    @endforeach
                                </ul>
                            </section>

                            <section>
                                <h2>{{ __('public.change_log.known_limitations') }}</h2>
                                <ul>
                                    @foreach($release['known_limitations'] as $limitation)
                                        <li>{{ $limitation }}</li>
                                    @endforeach
                                </ul>
                            </section>

                            <section>
                                <h2>{{ __('public.change_log.rollback_notes') }}</h2>
                                <p>{{ $release['rollback'] }}</p>
                            </section>

                            <section>
                                <h2>{{ __('public.change_log.source') }}</h2>
                                <p>{{ $release['provenance'] }}</p>
                                <ul class="change-log__references">
                                    @foreach($release['references'] as $reference)
                                        <li><a href="{{ $reference['url'] }}">{{ $reference['label'] }}</a></li>
                                    @endforeach
                                </ul>
                            </section>
                        @endif
                    </div>
                </details>
            </article>
        @endforeach
    </section>

    <section class="change-log__empty" data-change-log-empty hidden>
        <h2>{{ __('public.change_log.no_releases_found') }}</h2>
        <p>{{ __('public.change_log.try_a_different_search_or_choose_all_releases') }}</p>
    </section>
</div>
@endsection
