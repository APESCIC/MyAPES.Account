@extends('layouts.app')

@section('title', 'Admin user | MyAPES Core')

@section('content')
    <div class="panel-stack">
        <section class="panel" aria-labelledby="managed-user-title">
            <p><a href="{{ route('admin.users.index') }}">{{ __('admin.users.back_to_users') }}</a></p>
            <h1 id="managed-user-title">{{ $managedUser->name }}</h1>
            <p class="muted">{{ $managedUser->email }}</p>
            <dl class="admin-definition-list">
                <div><dt>{{ __('admin.users.account_id') }}</dt><dd>{{ $managedUser->id }}</dd></div>
                <div>
                    <dt>{{ __('admin.users.identity_source') }}</dt>
                    <dd>
                        {{ $identityLabel }}
                        @if($managedUser->isPendingFirstLogin())
                            <span class="status">{{ __('admin.users.pending_first_login') }}</span>
                        @endif
                    </dd>
                </div>
                <div><dt>{{ __('admin.users.suspension_state') }}</dt><dd>
                    {{ $managedUser->suspended_at === null ? __('admin.users.active') : __('admin.overview.suspended') }}
                    @can('admin.users.manage')
                        @if($canManageTarget && $managedUser->suspended_at === null)
                            · <a href="#suspend-user">{{ __('admin.users.suspend') }}</a>
                        @endif
                    @endcan
                </dd></div>
                <div><dt>{{ __('admin.users.authorization_epoch') }}</dt><dd>{{ $managedUser->authorization_epoch }}</dd></div>
                <div class="admin-definition-list__groups">
                    <dt>{{ __('admin.users.normalized_directory_groups') }}</dt>
                    <dd>
                        <x-directory-group-list :groups="$managedUser->ldap_groups ?? []" />
                    </dd>
                </div>
            </dl>
        </section>

        @if(session('temporary_password'))
            <section class="panel" aria-labelledby="temporary-password-title">
                <h2 id="temporary-password-title">{{ __('admin.users.one_time_temporary_password') }}</h2>
                <p>{{ __('admin.users.copy_this_password_now_and_share_it_with_the_account_holder_') }}</p>
                <label for="temporary-password">{{ __('admin.users.temporary_password') }}</label>
                <input
                    id="temporary-password"
                    class="temporary-password"
                    type="text"
                    readonly
                    value="{{ session('temporary_password') }}"
                    autocomplete="off"
                    spellcheck="false"
                >
            </section>
        @endif

        @if($isStaffAccount)
            <section class="panel" aria-labelledby="staff-profile-title">
                <h2 id="staff-profile-title">{{ __('admin.users.staff_profile') }}</h2>
                <p class="muted">{{ __('admin.users.directory_name_email_and_groups_stay_read_only') }}</p>
                @can('admin.users.manage')
                    @if($canManageTarget)
                        <form method="post" action="{{ route('admin.users.staff-profile.update', $managedUser) }}" enctype="multipart/form-data">
                            @csrf
                            @method('put')
                            <label for="job_title">{{ __('admin.access.job_title') }}</label>
                            <input id="job_title" name="job_title" value="{{ old('job_title', $staffProfile?->job_title) }}">
                            <label for="team">{{ __('admin.users.team') }}</label>
                            <select id="team" name="team">
                                <option value="">{{ __('admin.users.select_a_team') }}</option>
                                @foreach($teams as $value => $label)
                                    <option value="{{ $value }}" @selected(old('team', $staffProfile?->team) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <label for="work_phone">{{ __('admin.users.work_phone') }}</label>
                            <input id="work_phone" name="work_phone" value="{{ old('work_phone', $staffProfile?->work_phone) }}">
                            @if($staffProfile?->photo_path)
                                <p>
                                    <img src="{{ route('admin.users.staff-photo', $managedUser) }}" alt="{{ __('admin.users.current_staff_photo') }}" width="96" height="96">
                                </p>
                            @endif
                            <label for="photo">{{ __('admin.users.staff_photo') }}</label>
                            <input id="photo" type="file" name="photo" accept="image/*">
                            <div class="actions"><button type="submit">{{ __('admin.users.save_staff_profile') }}</button></div>
                        </form>
                    @endif
                @endcan
            </section>
        @else
            <section class="panel" aria-labelledby="public-profile-title">
                <h2 id="public-profile-title">{{ __('admin.users.public_profile') }}</h2>
                @can('admin.users.manage')
                    @if($canManageTarget)
                        <form method="post" action="{{ route('admin.users.profile.update', $managedUser) }}" enctype="multipart/form-data">
                            @csrf
                            @method('put')
                            <div class="row">
                                <div>
                                    <label for="preferred_name">{{ __('admin.users.preferred_name') }}</label>
                                    <input id="preferred_name" name="preferred_name" value="{{ old('preferred_name', $profile?->preferred_name) }}">
                                </div>
                                <div>
                                    <label for="phone">{{ __('admin.users.phone') }}</label>
                                    <input id="phone" name="phone" value="{{ old('phone', $profile?->phone) }}">
                                </div>
                                <div>
                                    <label for="organization">{{ __('admin.users.organisation') }}</label>
                                    <input id="organization" name="organization" value="{{ old('organization', $profile?->organization) }}">
                                </div>
                            </div>
                            <label for="support_needs">{{ __('admin.users.support_needs_or_access_notes') }}</label>
                            <textarea id="support_needs" name="support_needs">{{ old('support_needs', $profile?->support_needs) }}</textarea>
                            @include('profile._account-fields')
                            <label for="avatar">{{ __('admin.users.avatar_photo') }}</label>
                            <input id="avatar" type="file" name="avatar" accept="image/*">
                            <div class="actions"><button type="submit">{{ __('admin.users.save_public_profile') }}</button></div>
                        </form>
                    @endif
                @endcan
            </section>
        @endif

        @can('admin.users.manage')
            @if($canResendVerification)
                <section class="panel" id="resend-verification" aria-labelledby="resend-verification-title">
                    <h2 id="resend-verification-title">{{ __('admin.emails.resend_heading') }}</h2>
                    <p class="muted">{{ __('admin.emails.resend_help') }}</p>
                    <form method="post" action="{{ route('admin.users.resend-verification', $managedUser) }}">
                        @csrf
                        <div class="actions">
                            <button type="submit">{{ __('admin.emails.resend_button') }}</button>
                        </div>
                    </form>
                </section>
            @endif
            @if($canResetLocalPassword)
                <section class="panel" id="local-password" aria-labelledby="local-password-title">
                    <h2 id="local-password-title">{{ __('admin.users.local_password') }}</h2>
                    <p class="muted">{{ __('admin.users.temporary_password_help') }}</p>
                    <form method="post" action="{{ route('admin.users.password-reset', $managedUser) }}">
                        @csrf
                        <label class="inline-check">
                            <input type="checkbox" name="confirm" value="1" required>
                            <span>{{ __('admin.users.replace_the_current_password_with_a_one_time_temporary_passw') }}</span>
                        </label>
                        <div class="actions">
                            <button type="submit">{{ __('admin.users.reset_local_password') }}</button>
                        </div>
                    </form>
                </section>
            @elseif($managedUser->isPendingFirstLogin())
                <section class="panel" id="pending-first-login" aria-labelledby="pending-first-login-title">
                    <h2 id="pending-first-login-title">{{ __('admin.users.pending_first_login') }}</h2>
                    <p class="muted">{{ __('admin.users.this_directory_account_has_not_completed_staff_login_yet_pas') }}</p>
                    @if($canChasePendingFirstLogin)
                        <form method="post" action="{{ route('admin.users.pending-first-login-chase', $managedUser) }}">
                            @csrf
                            <label class="inline-check">
                                <input type="checkbox" name="confirm_chase" value="1" required>
                                <span>{{ __('admin.users.send_a_staff_login_reminder_that_opens_staff_login_cloudron') }}</span>
                            </label>
                            <div class="actions">
                                <button type="submit">{{ __('admin.users.send_staff_login_reminder') }}</button>
                            </div>
                        </form>
                        <p class="muted"><a href="{{ route('staff.login') }}">{{ __('admin.users.staff_login') }}</a> {{ __('admin.users.only_sign_in_path') }}</p>
                    @endif
                </section>
            @elseif(! $isStaffAccount && ! $managedUser->isLocalPasswordIdentity())
                <section class="panel" id="local-password" aria-labelledby="local-password-title">
                    <h2 id="local-password-title">{{ __('admin.users.local_password') }}</h2>
                    <p class="muted">{{ __('admin.users.this_account_uses_cloudron_directory_sign_in_reset_the_passw') }}</p>
                </section>
            @endif
        @endcan

        <section class="panel" aria-labelledby="role-provenance-title">
            <h2 id="role-provenance-title">{{ __('admin.users.provenanced_roles') }}</h2>
            <table>
                <caption>{{ __('admin.users.effective_role_assignments_and_their_recorded_sources') }}</caption>
                <thead><tr><th scope="col">{{ __('admin.access.role') }}</th><th scope="col">{{ __('admin.access.source') }}</th><th scope="col">{{ __('admin.users.directory_group') }}</th></tr></thead>
                <tbody>
                @forelse($managedUser->roleSources as $source)
                    <tr>
                        <td>{{ $source->getRelation('role')->name }}</td>
                        <td>{{ $source->source }}</td>
                        <td>{{ $source->directoryGroup?->name ?? 'Not applicable' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3">{{ __('admin.users.no_role_provenance_is_recorded') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </section>

        <section class="panel" aria-labelledby="effective-permissions-title">
            <h2 id="effective-permissions-title">{{ __('admin.users.effective_access') }}</h2>
            <p class="muted">{{ __('admin.users.summary_of_provenanced_roles_and_capability_packs_expand_adv') }}</p>

            <h3>{{ __('admin.access.job_roles') }}</h3>
            @if($managedUser->roles->isEmpty())
                <p>{{ __('admin.users.no_job_roles_are_assigned') }}</p>
            @else
                <ul class="permission-readable-list">
                    @foreach($managedUser->roles as $role)
                        <li>
                            <strong>
                                @if(\App\Support\DefaultJobRoles::isDefault($role->name))
                                    {{ \App\Support\DefaultJobRoles::title($role->name) }}
                                @else
                                    {{ $role->name }}
                                @endif
                            </strong>
                            <p class="muted">{{ $role->permissions->count() }} permissions on this role</p>
                        </li>
                    @endforeach
                </ul>
            @endif

            <h3>{{ __('admin.access.capability_packs') }}</h3>
            @php
                $activePacks = collect($packDefinitions)
                    ->filter(fn (array $pack, string $key): bool => ($packStates[$key] ?? 'off') !== 'off');
            @endphp
            @if($activePacks->isEmpty())
                <p>{{ __('admin.users.no_capability_packs_are_fully_or_partially_covered_by_this_a') }}</p>
            @else
                <ul class="permission-readable-list">
                    @foreach($activePacks as $packKey => $pack)
                        @php
                            $state = $packStates[$packKey] ?? 'off';
                        @endphp
                        <li>
                            <strong>{{ $pack['title'] }}</strong>
                            @if($state === 'indeterminate')
                                <p class="muted">{{ __('admin.users.partially_covered_expand_advanced_for_details') }}</p>
                            @else
                                <p class="muted">{{ count($pack['permissions']) }} permissions</p>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif

            <details class="permission-advanced" data-effective-permissions-advanced>
                <summary>{{ __('admin.access.advanced_permissions') }}</summary>
                @php
                    $assignedGroups = $permissions
                        ->groupBy(fn ($permission) => \App\Support\PermissionDescriptions::group($permission->name))
                        ->sortKeys();
                @endphp
                @forelse($assignedGroups as $groupName => $groupPermissions)
                    <div class="permission-readable-group">
                        <h3>{{ $groupName }}</h3>
                        <ul class="permission-readable-list">
                            @foreach($groupPermissions as $permission)
                                <li>
                                    <strong>{{ \App\Support\PermissionDescriptions::title($permission->name) }}</strong>
                                    <p class="muted">{{ \App\Support\PermissionDescriptions::description($permission->name) }}</p>
                                    <code>{{ $permission->name }}</code>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @empty
                    <p>{{ __('admin.users.no_effective_permissions') }}</p>
                @endforelse
            </details>
        </section>

        <section class="panel" aria-labelledby="direct-permission-provenance-title">
            <h2 id="direct-permission-provenance-title">{{ __('admin.users.direct_permission_provenance') }}</h2>
            <table>
                <caption>{{ __('admin.users.direct_permissions_and_their_recorded_assignment_sources') }}</caption>
                <thead><tr><th scope="col">{{ __('admin.users.permission') }}</th><th scope="col">{{ __('admin.access.source') }}</th><th scope="col">{{ __('admin.users.granting_account') }}</th></tr></thead>
                <tbody>
                @forelse($managedUser->permissionSources as $source)
                    <tr>
                        <td><code>{{ $source->getRelation('permission')->name }}</code></td>
                        <td>{{ $source->source }}</td>
                        <td>
                            @if($source->source === \App\Core\Accounts\PermissionSource::SOURCE_SYSTEM && $source->actor === null)
                                {{ __('admin.users.system') }}
                            @elseif($source->actor !== null)
                                {{ __('admin.users.account') }} {{ $source->actor->id }}
                            @else
                                {{ __('admin.access.unavailable') }}
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3">{{ __('admin.users.no_direct_permission_provenance_is_recorded') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </section>

        @can('admin.users.manage')
            @if($canManageTarget)
                <section class="panel" aria-labelledby="manage-user-title">
                    <h2 id="manage-user-title">{{ __('admin.users.manage_user') }}</h2>
                    <p class="muted">{{ __('admin.users.only_custom_local_roles_can_be_changed_protected_and_directo') }}</p>

                    <form method="post" action="{{ route('admin.users.roles.update', $managedUser) }}">
                        @csrf
                        @method('put')
                        <fieldset class="admin-checkbox-grid">
                            <legend>{{ __('admin.users.custom_local_roles') }}</legend>
                            @forelse($customRoles as $role)
                                <label class="inline-check">
                                    <input type="checkbox" name="roles[]" value="{{ $role->id }}" @checked(in_array($role->id, $localRoleIds, true))>
                                    <span>{{ $role->name }}</span>
                                </label>
                            @empty
                                <p>{{ __('admin.users.no_custom_roles_are_available') }}</p>
                            @endforelse
                        </fieldset>
                        <div class="actions"><button type="submit">{{ __('admin.users.update_local_roles') }}</button></div>
                    </form>

                    <hr class="section-divider">

                    @if($managedUser->suspended_at === null)
                        <form
                            id="suspend-user"
                            method="post"
                            action="{{ route('admin.users.suspension.store', $managedUser) }}"
                        >
                            @csrf
                            <label for="suspension-reason">{{ __('admin.users.suspension_reason') }}</label>
                            <textarea id="suspension-reason" name="reason" required maxlength="500">{{ old('reason') }}</textarea>
                            <label class="inline-check">
                                <input type="checkbox" name="confirm_suspend" value="1" required>
                                <span>{{ __('admin.users.i_confirm_i_want_to_suspend_this_account') }}</span>
                            </label>
                            <div class="actions"><button class="danger-btn" type="submit">{{ __('admin.users.suspend_user') }}</button></div>
                        </form>
                    @else
                        <form method="post" action="{{ route('admin.users.suspension.destroy', $managedUser) }}">
                            @csrf
                            @method('delete')
                            <button type="submit">{{ __('admin.users.reactivate_user') }}</button>
                        </form>
                    @endif
                </section>
            @endif
        @endcan

        <section class="panel" aria-labelledby="audit-history-title">
            <h2 id="audit-history-title">{{ __('admin.users.sanitized_audit_history') }}</h2>
            <table>
                <caption>{{ __('admin.users.recent_authorization_events_sensitive_identity_and_request_p') }}</caption>
                <thead><tr><th scope="col">{{ __('admin.overview.time') }}</th><th scope="col">{{ __('admin.users.event') }}</th><th scope="col">{{ __('admin.users.actor_id') }}</th><th scope="col">{{ __('admin.users.safe_context') }}</th></tr></thead>
                <tbody>
                @forelse($auditHistory as $audit)
                    <tr>
                        <td>{{ $audit['created_at'] }}</td>
                        <td><code>{{ $audit['event'] }}</code></td>
                        <td>{{ $audit['actor_id'] ?? __('admin.users.system') }}</td>
                        <td>
                            @forelse($audit['context'] as $key => $value)
                                <div><code>{{ $key }}</code>: {{ is_array($value) ? implode(', ', $value) : $value }}</div>
                            @empty
                                {{ __('admin.users.no_displayable_context') }}
                            @endforelse
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4">{{ __('admin.users.no_audit_history_is_recorded_for_this_user') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </section>
    </div>
@endsection
