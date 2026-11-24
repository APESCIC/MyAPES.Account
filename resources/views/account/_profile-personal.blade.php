<section class="panel" id="profile-personal" aria-labelledby="profile-personal-title">
    <h2 id="profile-personal-title">{{ __('public.profile.edit.blade.your_profile') }}</h2>
    <p class="muted">{{ __('public.profile.edit.blade.core_account_details_used_across_all_apes_services') }}</p>
    <form method="post" action="{{ route('profile.update') }}" enctype="multipart/form-data">
        @csrf
        @method('put')
        <div class="row">
            <div>
                <label for="preferred_name">{{ __('public.profile.edit.blade.preferred_name') }}</label>
                <input id="preferred_name" name="preferred_name" value="{{ old('preferred_name', $profile?->preferred_name) }}">
            </div>
            <div>
                <label for="phone">{{ __('public.profile.edit.blade.phone') }}</label>
                <input id="phone" name="phone" value="{{ old('phone', $profile?->phone) }}">
            </div>
            <div>
                <label for="organization">{{ __('public.profile.edit.blade.organisation') }}</label>
                <input id="organization" name="organization" value="{{ old('organization', $profile?->organization) }}">
            </div>
        </div>
        <label for="support_needs">{{ __('public.profile.edit.blade.support_needs_or_access_notes') }}</label>
        <textarea id="support_needs" name="support_needs">{{ old('support_needs', $profile?->support_needs) }}</textarea>
        @include('profile._account-fields')
        <label for="avatar">{{ __('public.profile.edit.blade.avatar_photo') }}</label>
        <input id="avatar" type="file" name="avatar" accept="image/*">
        <div class="actions">
            <button type="submit">{{ __('public.profile.edit.blade.save_profile') }}</button>
        </div>
    </form>
</section>