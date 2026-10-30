@extends('layouts.app')

@section('title', 'Dashboard | MyAPES Account')

@section('content')
    @php
        $authorizationProfile = app(\App\Services\AuthorizationProfile::class);
        $roleKey = $authorizationProfile->displayKey(auth()->user());
        $roleLabel = $authorizationProfile->displayLabel(auth()->user());
    @endphp

    <div class="dashboard-layout">
        <aside class="identity-card" aria-labelledby="welcome-title">
            <div class="identity-card__intro">
                <p class="identity-card__eyebrow">{{ __('public.dashboard.welcome_back') }}</p>
                <h1 id="welcome-title">{{ auth()->user()->name }}</h1>
                <p class="identity-card__role">
                    <span>Role: <code>{{ $roleKey }}</code></span>
                    <span aria-hidden="true">•</span>
                    <strong>{{ $roleLabel }}</strong>
                </p>
            </div>
            <img
                src="{{ asset('mascot/spike-welcome.png') }}"
                alt="{{ __('public.dashboard.spike_the_cartoon_myapes_bearded_dragon_mascot') }}"
                class="identity-card__mascot"
                width="1024"
                height="1024"
            >
            <div class="identity-card__mission">
                <span class="identity-card__mission-icon" aria-hidden="true">
                    <i data-lucide="sprout"></i>
                </span>
                <p><strong>{{ __('public.dashboard.our_mission') }}</strong> Protect exotic species through rescue, rehabilitation, education and conservation.</p>
            </div>
        </aside>

        <section class="attention-panel" aria-labelledby="attention-title">
            <header class="attention-panel__header">
                <span class="attention-panel__compass" aria-hidden="true">
                    <i data-lucide="compass"></i>
                </span>
                <div>
                    <h2 id="attention-title">{{ __('public.dashboard.what_needs_your_attention_next') }}</h2>
                    <p>{{ __('public.dashboard.here_are_the_most_recently_updated_open_items_across_my') }}</p>
                </div>
            </header>

            <div class="attention-list">
                @forelse($attentionItems as $item)
                    <x-attention-item-link
                        :href="route($item->routeName, $item->recordId)"
                        :type="$item->type"
                        :icon="$item->icon"
                        :kicker="$item->service.' · '.$item->label"
                        :title="$item->title"
                        :status="$item->status"
                        :priority="$item->priority"
                        :context="$item->context"
                        :owner="$item->owner"
                        :updated-at="$item->updatedAt"
                    />
                @empty
                    <div class="attention-empty">
                        <x-mascot-tip
                            variant="empty"
                            title="{{ __('public.dashboard.you_are_all_caught_up') }}"
                            body="No open tickets, shelter cases or consultations need attention."
                        />
                    </div>
                @endforelse
            </div>
        </section>
    </div>

    <section class="service-summary" id="service-summary" aria-label="{{ __('public.dashboard.service_totals') }}">
        @foreach($moduleSummaries as $group)
            <section
                class="service-summary__group service-summary__group--{{ $group->key }}"
                data-sub-core="{{ $group->key }}"
                aria-labelledby="service-summary-{{ $group->key }}"
            >
                <header class="service-summary__header">
                    <h2 class="service-summary__heading" id="service-summary-{{ $group->key }}">
                        {{ $group->name }}
                    </h2>
                    <a
                        href="{{ route($group->routeName) }}"
                        class="service-summary__service-link"
                    >
                        Open service <i data-lucide="arrow-right" aria-hidden="true"></i>
                    </a>
                </header>
                <div
                    class="service-summary__items"
                    style="--module-count: {{ count($group->summaries) }}"
                >
                    @foreach($group->summaries as $summary)
                        <a
                            href="{{ route($summary->routeName) }}"
                            class="service-summary__item service-summary__item--{{ $summary->style }}"
                            data-module-instance="{{ $summary->instanceKey }}"
                        >
                            <i data-lucide="{{ $summary->icon }}" aria-hidden="true"></i>
                            <span class="service-summary__number">{{ $summary->total }}</span>
                            <span class="service-summary__copy">
                                <strong>{{ $summary->label }}</strong>
                                <small>{{ $summary->detail }}</small>
                            </span>
                        </a>
                    @endforeach
                </div>
            </section>
        @endforeach
    </section>
@endsection
