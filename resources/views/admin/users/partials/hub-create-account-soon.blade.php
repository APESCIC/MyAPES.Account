@php
    $accountUser = $accountUser ?? ($managedUser ?? $client);
    $isAdminAccountHub = $isAdminHub ?? false;
    $canManageAccess = $hubCanManageAccess ?? false;
    $createRoute = $isAdminAccountHub ? 'admin.users.create-account' : 'clients.create-account';
    $resendRoute = $isAdminAccountHub ? 'admin.users.resend-sign-in' : 'clients.resend-sign-in';
    $hasEmail = is_string($accountUser->email) && trim($accountUser->email) !== '';
    $isInvited = $accountUser->accountState() === \App\Core\Accounts\User::ACCOUNT_STATE_INVITED;
@endphp
@if($accountUser->isProfileOnly())
    <section class="panel" aria-labelledby="hub-create-account-title" data-hub-create-account>
        <h2 id="hub-create-account-title">{{ __('admin.users.hub.create_account.heading') }}</h2>
        <p class="muted">{{ __('admin.users.hub.create_account.intro') }}</p>
        @if($canManageAccess && $hasEmail)
            <form method="post" action="{{ route($createRoute, $accountUser) }}">
                @csrf
                <label class="inline-check">
                    <input type="checkbox" name="confirm_create_account" value="1" required>
                    <span>{{ __('admin.users.hub.create_account.confirm') }}</span>
                </label>
                <div class="actions">
                    <button type="submit">{{ __('admin.users.hub.create_account.submit') }}</button>
                </div>
            </form>
        @elseif($canManageAccess)
            <p class="muted" role="status">{{ __('admin.users.hub.create_account.email_required') }}</p>
        @endif
    </section>
@elseif($isInvited && $canManageAccess && $hasEmail)
    <section class="panel" aria-labelledby="hub-resend-sign-in-title" data-hub-resend-sign-in>
        <h2 id="hub-resend-sign-in-title">{{ __('admin.users.hub.resend_sign_in.heading') }}</h2>
        <p class="muted">{{ __('admin.users.hub.resend_sign_in.intro') }}</p>
        <form method="post" action="{{ route($resendRoute, $accountUser) }}">
            @csrf
            <label class="inline-check">
                <input type="checkbox" name="confirm_resend" value="1" required>
                <span>{{ __('admin.users.hub.resend_sign_in.confirm') }}</span>
            </label>
            <div class="actions">
                <button type="submit">{{ __('admin.users.hub.resend_sign_in.submit') }}</button>
            </div>
        </form>
    </section>
@endif
