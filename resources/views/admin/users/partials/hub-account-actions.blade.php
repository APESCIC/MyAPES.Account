@php
    $accountUser = $accountUser ?? ($managedUser ?? $client);
    $canResetPassword = $canResetLocalPassword ?? false;
    $canManageAccess = $hubCanManageAccess ?? false;
    $isAdminAccountHub = $isAdminHub ?? false;
    $passwordResetRoute = $isAdminAccountHub ? 'admin.users.password-reset' : 'clients.password-reset';
    $twoFactorResetRoute = $isAdminAccountHub ? 'admin.users.two-factor.reset' : 'clients.two-factor.reset';
    $suspendRoute = $isAdminAccountHub ? 'admin.users.suspension.store' : 'clients.suspension.store';
    $reactivateRoute = $isAdminAccountHub ? 'admin.users.suspension.destroy' : 'clients.suspension.destroy';
@endphp
@if($canManageAccess)
    @if($canResetPassword)
        <section class="panel" id="local-password" aria-labelledby="local-password-title" data-hub-password-reset>
            <h2 id="local-password-title">{{ __('admin.users.local_password') }}</h2>
            <p class="muted">{{ __('admin.users.temporary_password_help') }}</p>
            <form method="post" action="{{ route($passwordResetRoute, $accountUser) }}">
                @csrf
                <label for="password-reset-reason">{{ __('admin.users.hub.account_management.reason') }}</label>
                <textarea id="password-reset-reason" name="reason" required maxlength="500">{{ old('reason') }}</textarea>
                <p class="muted">{{ __('admin.users.hub.account_management.reason_help') }}</p>
                <label class="inline-check">
                    <input type="checkbox" name="confirm" value="1" required>
                    <span>{{ __('admin.users.replace_the_current_password_with_a_one_time_temporary_passw') }}</span>
                </label>
                <div class="actions">
                    <button type="submit">{{ __('admin.users.reset_local_password') }}</button>
                </div>
            </form>
        </section>
    @endif

    <section class="panel" aria-labelledby="hub-two-factor-title" data-hub-two-factor>
        <h2 id="hub-two-factor-title">{{ __('admin.users.hub.admin_tools.two_factor') }}</h2>
        @if($twoFactorEnabled)
            <p class="muted">{{ __('admin.users.hub.admin_tools.two_factor_help') }}</p>
            <form method="post" action="{{ route($twoFactorResetRoute, $accountUser) }}">
                @csrf
                <label for="two-factor-reset-reason">{{ __('admin.users.hub.account_management.reason') }}</label>
                <textarea id="two-factor-reset-reason" name="reason" required maxlength="500">{{ old('reason') }}</textarea>
                <p class="muted">{{ __('admin.users.hub.account_management.reason_help') }}</p>
                <label class="inline-check">
                    <input type="checkbox" name="confirm_two_factor_reset" value="1" required>
                    <span>{{ __('admin.users.hub.admin_tools.two_factor_confirm') }}</span>
                </label>
                <div class="actions">
                    <button class="danger-btn" type="submit">{{ __('admin.users.hub.admin_tools.two_factor_reset') }}</button>
                </div>
            </form>
        @else
            <p class="muted">{{ __('admin.users.hub.admin_tools.two_factor_not_enabled') }}</p>
        @endif
    </section>

    @if($accountUser->suspended_at === null)
        <section class="panel" aria-labelledby="hub-suspend-title">
            <h2 id="hub-suspend-title">{{ __('admin.users.suspend') }}</h2>
            <form
                id="suspend-user"
                method="post"
                action="{{ route($suspendRoute, $accountUser) }}"
            >
                @csrf
                <label for="suspension-reason">{{ __('admin.users.suspension_reason') }}</label>
                <textarea id="suspension-reason" name="reason" required maxlength="500">{{ old('reason') }}</textarea>
                <label class="inline-check">
                    <input type="checkbox" name="confirm_suspend" value="1" required>
                    <span>{{ __('admin.users.i_confirm_i_want_to_suspend_this_account') }}</span>
                </label>
                <div class="actions">
                    <button class="danger-btn" type="submit">{{ __('admin.users.suspend_user') }}</button>
                </div>
            </form>
        </section>
    @else
        <section class="panel" aria-labelledby="hub-reactivate-title">
            <h2 id="hub-reactivate-title">{{ __('admin.users.reactivate_user') }}</h2>
            <form method="post" action="{{ route($reactivateRoute, $accountUser) }}">
                @csrf
                @method('delete')
                <label for="reactivate-reason">{{ __('admin.users.hub.account_management.reason') }}</label>
                <textarea id="reactivate-reason" name="reason" required maxlength="500">{{ old('reason') }}</textarea>
                <div class="actions">
                    <button type="submit">{{ __('admin.users.reactivate_user') }}</button>
                </div>
            </form>
        </section>
    @endif
@endif
