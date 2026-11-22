@php
    $guide = __('push.home_screen');
    $steps = $guide['steps'] ?? [];
@endphp
<section class="push-home-screen-guide" aria-labelledby="push-home-screen-guide-heading">
    <h3 id="push-home-screen-guide-heading" class="push-home-screen-guide__heading">
        {{ $guide['heading'] }}
    </h3>
    <p class="muted push-home-screen-guide__lead">{{ $guide['lead'] }}</p>
    <p class="push-home-screen-guide__version">
        <strong>{{ $guide['version_label'] }}</strong> {{ $guide['version_requirement'] }}
    </p>
    <ol class="push-home-screen-guide__steps">
        @foreach ($steps as $step)
            <li class="push-home-screen-guide__step">
                <div class="push-home-screen-guide__step-text">
                    <p class="push-home-screen-guide__step-title">{{ $step['title'] }}</p>
                    <p class="muted">{{ $step['body'] }}</p>
                </div>
                <img
                    class="push-home-screen-guide__figure"
                    src="{{ asset($step['image']) }}"
                    width="120"
                    height="120"
                    alt="{{ $step['image_alt'] }}"
                    loading="lazy"
                    decoding="async"
                >
            </li>
        @endforeach
    </ol>
    <p class="muted push-home-screen-guide__footnote">{{ $guide['footnote'] }}</p>
</section>