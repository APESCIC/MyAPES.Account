<div class="panel" id="account-directory">
    <h2>{{ __('public.profile.staff-edit.blade.staff_profile') }}</h2>
    <p class="muted">{{ __('public.profile.staff-edit.blade.directory_name_email_and_groups_stay_read_only_add_the_') }}</p>
    <dl class="admin-definition-list">
        <div>
            <dt>{{ __('public.profile.staff-edit.blade.name') }}</dt>
            <dd>{{ auth()->user()->name }}</dd>
        </div>
        <div>
            <dt>{{ __('public.profile.edit.blade.email') }}</dt>
            <dd>{{ auth()->user()->email }}</dd>
        </div>
        <div class="admin-definition-list__groups">
            <dt>{{ __('public.chrome.directory-group-list.blade.directory_groups') }}</dt>
            <dd>
                <x-directory-group-list :groups="auth()->user()->ldap_groups ?? []" />
            </dd>
        </div>
    </dl>
</div>