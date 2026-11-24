@php
    $accountUser = $accountUser ?? ($managedUser ?? $client);
@endphp
<section class="panel" aria-labelledby="hub-account-summary-title" data-hub-account-summary>
    <h2 id="hub-account-summary-title">{{ __('admin.users.hub.account_management.heading') }}</h2>
    <dl class="admin-definition-list">
        <div>
            <dt>{{ __('admin.users.hub.account_management.username') }}</dt>
            <dd>{{ $accountUser->username }}</dd>
        </div>
        <div>
            <dt>{{ __('admin.users.hub.account_management.email') }}</dt>
            <dd>{{ $accountUser->email ?: __('admin.users.hub.account_management.no_email') }}</dd>
        </div>
        <div>
            <dt>{{ __('admin.users.hub.account_management.state') }}</dt>
            <dd>{{ __("admin.users.account_states.{$accountUser->accountState()}") }}</dd>
        </div>
        <div>
            <dt>{{ __('admin.users.hub.account_management.last_sign_in') }}</dt>
            <dd>
                @if($hubLastSignInAt)
                    {{ $hubLastSignInAt->timezone(config('app.timezone'))->format('d/m/Y H:i') }}
                @else
                    {{ __('admin.users.hub.account_management.never_signed_in') }}
                @endif
            </dd>
        </div>
        <div>
            <dt>{{ __('admin.users.hub.account_management.roles') }}</dt>
            <dd>
                @if($hubRoleLabels === [])
                    {{ __('admin.users.hub.account_management.no_roles') }}
                @else
                    {{ implode(', ', $hubRoleLabels) }}
                @endif
            </dd>
        </div>
    </dl>
</section>
