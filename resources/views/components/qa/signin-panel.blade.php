@props(['profiles'])
<section id="developer-signin" {{ $attributes->class('sg-qa-panel') }} aria-labelledby="developer-signin-heading" data-qa-signin-panel>
    <div class="sg-qa-panel__header">
        <h2 id="developer-signin-heading" class="sg-qa-panel__title">
            <i data-lucide="flask-conical" aria-hidden="true"></i>
            {{ __('qa.panel.title') }}
        </h2>
        <span class="sg-qa-tag">{{ __('qa.panel.local_only') }}</span>
    </div>
    <p class="sg-qa-panel__intro">
        {{ __('qa.panel.intro') }}
    </p>
    <x-qa.profile-picker :profiles="$profiles" id-prefix="developer-signin" />
</section>
