@php($canManage = auth()->user()?->can('admin.settings.manage'))

<section class="panel" aria-labelledby="general-heading" data-settings-tab="general">
    <h2 id="general-heading">{{ __('admin.settings.general.heading') }}</h2>
    <p>{{ __('admin.settings.general.intro') }}</p>

    <form method="post" action="{{ route('admin.settings.general.update') }}" class="stacked-form" data-general-settings-form autocomplete="off">
        @csrf
        @method('PUT')
        <fieldset @disabled(! $canManage)>
            <legend>{{ __('admin.settings.general.contact_heading') }}</legend>
            <p class="muted">{{ __('admin.settings.general.contact_help') }}</p>

            <label for="general-support-email">{{ __('admin.settings.general.fields.support_email') }}</label>
            <input id="general-support-email" name="support_email" type="email" maxlength="255" value="{{ old('support_email', $values['support_email']) }}">
            @error('support_email')<p class="error-list" role="alert">{{ $message }}</p>@enderror

            <label for="general-support-phone">{{ __('admin.settings.general.fields.support_phone') }}</label>
            <input id="general-support-phone" name="support_phone" type="tel" maxlength="50" value="{{ old('support_phone', $values['support_phone']) }}">
            @error('support_phone')<p class="error-list" role="alert">{{ $message }}</p>@enderror
        </fieldset>

        <fieldset @disabled(! $canManage)>
            <legend>{{ __('admin.settings.general.registration_heading') }}</legend>
            <label class="inline-check">
                <input type="hidden" name="registration_enabled" value="0">
                <input type="checkbox" id="general-registration-enabled" name="registration_enabled" value="1" @checked(old('registration_enabled', $values['registration_enabled']))>
                <span>{{ __('admin.settings.general.fields.registration_enabled') }}</span>
            </label>
            <p class="muted">{{ __('admin.settings.general.registration_help') }}</p>
        </fieldset>

        <fieldset @disabled(! $canManage)>
            <legend>{{ __('admin.settings.general.banner_heading') }}</legend>
            <label class="inline-check">
                <input type="hidden" name="banner_enabled" value="0">
                <input type="checkbox" id="general-banner-enabled" name="banner_enabled" value="1" @checked(old('banner_enabled', $values['banner_enabled']))>
                <span>{{ __('admin.settings.general.fields.banner_enabled') }}</span>
            </label>

            <label for="general-banner-text">{{ __('admin.settings.general.fields.banner_text') }}</label>
            <textarea id="general-banner-text" name="banner_text" rows="3" maxlength="{{ $bannerMaxLength }}">{{ old('banner_text', $values['banner_text']) }}</textarea>
            <p class="muted">{{ __('admin.settings.general.banner_help', ['max' => $bannerMaxLength]) }}</p>
            @error('banner_text')<p class="error-list" role="alert">{{ $message }}</p>@enderror
        </fieldset>

        @if($canManage)
            <div class="actions">
                <button type="submit">{{ __('admin.settings.general.save') }}</button>
            </div>
        @endif
    </form>
</section>

<section class="panel" aria-labelledby="general-retention-heading" data-general-retention>
    <h2 id="general-retention-heading">{{ __('admin.settings.general.retention_heading') }}</h2>
    <p>{{ __('admin.settings.general.retention_intro') }}</p>
    <dl class="detail-list">
        @foreach($retention as $key => $value)
            <div>
                <dt>{{ __("admin.settings.general.retention.{$key}") }}</dt>
                <dd>{{ $value }}</dd>
            </div>
        @endforeach
    </dl>
</section>

<section class="panel" aria-labelledby="general-uploads-heading" data-general-uploads>
    <h2 id="general-uploads-heading">{{ __('admin.settings.general.uploads_heading') }}</h2>
    <p>{{ __('admin.settings.general.uploads_intro') }}</p>
    <dl class="detail-list">
        @foreach($uploads as $key => $value)
            <div>
                <dt>{{ __("admin.settings.general.uploads.{$key}") }}</dt>
                <dd>{{ $value }}</dd>
            </div>
        @endforeach
    </dl>
</section>

<section class="panel" aria-labelledby="general-links-heading" data-general-links>
    <h2 id="general-links-heading">{{ __('admin.settings.general.links_heading') }}</h2>
    <ul>
        @foreach($links as $key => $url)
            <li><a href="{{ $url }}" data-general-link="{{ $key }}">{{ __("admin.settings.general.links.{$key}") }}</a> {{ __("admin.settings.general.links.{$key}_help") }}</li>
        @endforeach
    </ul>
</section>
