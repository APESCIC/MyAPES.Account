@extends('layouts.app')

@section('title', 'Admin users | MyAPES Account')

@section('content')
    <section class="panel" aria-labelledby="admin-users-title">
        <h1 id="admin-users-title">
            @if(($filters['account_type'] ?? '') === 'staff')
                Staff
            @elseif(($filters['account_type'] ?? '') === 'public')
                Public users
            @else
                Users
            @endif
        </h1>
        <p class="muted">{{ __('admin.users.search_accounts_and_review_their_identity_status_and_effecti') }}</p>
        <p>
            <a href="{{ route('admin.users.index', ['account_type' => 'public']) }}">{{ __('admin.nav.public_users') }}</a>
            ·
            <a href="{{ route('admin.users.index', ['account_type' => 'staff']) }}">{{ __('admin.nav.staff') }}</a>
            ·
            <a href="{{ route('admin.users.index') }}">{{ __('admin.users.all_accounts') }}</a>
        </p>

        <form method="get" action="{{ route('admin.users.index') }}">
            @if(! empty($filters['account_type']))
                <input type="hidden" name="account_type" value="{{ $filters['account_type'] }}">
            @endif
            <div class="row">
                <div>
                    <label for="user-search">{{ __('admin.users.search_name_or_email') }}</label>
                    <input id="user-search" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="100">
                </div>
                <div>
                    <label for="identity-type">{{ __('admin.users.identity_source') }}</label>
                    <select id="identity-type" name="identity_type">
                        <option value="">{{ __('admin.users.all_identity_sources') }}</option>
                        @foreach($identityTypes as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['identity_type'] ?? '') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="user-status">{{ __('admin.access.status') }}</label>
                    <select id="user-status" name="status">
                        <option value="">{{ __('admin.access.all_statuses') }}</option>
                        <option value="active" @selected(($filters['status'] ?? '') === 'active')>{{ __('admin.users.active') }}</option>
                        <option value="suspended" @selected(($filters['status'] ?? '') === 'suspended')>{{ __('admin.overview.suspended') }}</option>
                    </select>
                </div>
                <div>
                    <label for="protected-role">{{ __('admin.users.protected_role') }}</label>
                    <select id="protected-role" name="protected_role">
                        <option value="">{{ __('admin.users.all_protected_roles') }}</option>
                        @foreach($protectedRoles as $role)
                            <option value="{{ $role }}" @selected(($filters['protected_role'] ?? '') === $role)>{{ $role }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="actions">
                <button type="submit">{{ __('admin.access.apply_filters') }}</button>
                <a href="{{ route('admin.users.index') }}">{{ __('admin.access.clear_filters') }}</a>
            </div>
        </form>
    </section>

    <section class="panel" aria-labelledby="user-results-title">
        <h2 id="user-results-title">{{ __('admin.users.user_results') }}</h2>
        <table>
            <caption>{{ $users->total() }} users, ordered by name and account ID</caption>
            <thead>
            <tr>
                <th scope="col">{{ __('admin.users.account') }}</th>
                <th scope="col">{{ __('admin.users.identity') }}</th>
                <th scope="col">{{ __('admin.access.status') }}</th>
                <th scope="col">{{ __('admin.users.protected_role') }}</th>
                <th scope="col">{{ __('admin.access.action') }}</th>
            </tr>
            </thead>
            <tbody>
            @forelse($users as $user)
                <tr>
                    <td><strong>{{ $user->name }}</strong><br><span class="muted">{{ $user->email }}</span></td>
                    <td>
                        {{ $identityTypes[$user->identity_type] ?? 'Unknown' }}
                        @if($user->identity_type === \App\Core\Accounts\User::IDENTITY_CLOUDRON_OIDC && blank($user->oidc_sub))
                            <br><span class="status">{{ __('admin.users.pending_first_login') }}</span>
                        @endif
                    </td>
                    <td>{{ $user->suspended_at === null ? __('admin.users.active') : __('admin.overview.suspended') }}</td>
                    <td>{{ $authorizationProfile->effectiveProtectedRole($user) ?? __('admin.access.none') }}</td>
                    <td>
                        <a href="{{ route('admin.users.show', $user) }}">{{ __('admin.users.view_user') }}</a>
                        @if(in_array($user->id, $resettableUserIds, true))
                            · <a href="{{ route('admin.users.show', $user) }}#local-password">{{ __('admin.users.reset_password') }}</a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5">{{ __('admin.users.no_users_match_these_filters') }}</td></tr>
            @endforelse
            </tbody>
        </table>
        {{ $users->links() }}
    </section>
@endsection
