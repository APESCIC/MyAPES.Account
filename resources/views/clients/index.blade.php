@extends('layouts.app')

@section('title', __('clients.title').' | '.__('workspace.app_name'))

@section('content')
    <div class="panel">
        <div class="clients-directory-header">
            <div>
                <h1>{{ __('clients.title') }}</h1>
                <p class="muted">{{ __('clients.intro') }}</p>
            </div>
            <p>
                <a class="button" href="#create">{{ __('clients.create_heading') }}</a>
            </p>
        </div>

        <form method="get" action="{{ route('clients.index') }}" class="clients-directory-filters">
            @if(($directoryTab ?? 'all') !== 'all')
                <input type="hidden" name="directory_tab" value="{{ $directoryTab }}">
            @endif
            @if(($accountState ?? null) !== null)
                <input type="hidden" name="account_state" value="{{ $accountState }}">
            @endif
            <label for="q">{{ __('clients.search') }}</label>
            <input id="q" name="q" value="{{ $q ?? '' }}" placeholder="{{ __('clients.search_placeholder') }}" autocomplete="off" autofocus>
            <label for="account_state_filter">{{ __('clients.account_state') }}</label>
            <select id="account_state_filter" name="account_state" data-clients-state-filter>
                <option value="">{{ __('clients.directory_tabs.all') }}</option>
                <option value="{{ \App\Core\Accounts\User::ACCOUNT_STATE_ACTIVE }}" @selected(($accountState ?? null) === \App\Core\Accounts\User::ACCOUNT_STATE_ACTIVE)>{{ __('clients.account_states.active') }}</option>
                <option value="{{ \App\Core\Accounts\User::ACCOUNT_STATE_PROFILE_ONLY }}" @selected(($accountState ?? null) === \App\Core\Accounts\User::ACCOUNT_STATE_PROFILE_ONLY)>{{ __('clients.account_states.profile_only') }}</option>
                <option value="{{ \App\Core\Accounts\User::ACCOUNT_STATE_INVITED }}" @selected(($accountState ?? null) === \App\Core\Accounts\User::ACCOUNT_STATE_INVITED)>{{ __('clients.account_states.invited') }}</option>
            </select>
            <button type="submit">{{ __('clients.search_submit') }}</button>
            @if(($q ?? '') !== '' || ($nameReview ?? false) || ($missingDetails ?? false) || ($directoryTab ?? 'all') !== 'all')
                <a href="{{ route('clients.index') }}">{{ __('clients.clear') }}</a>
            @endif
        </form>
        <p>
            <a href="{{ route('clients.index', ['missing_details' => 1]) }}">{{ __('clients.missing_details_link') }}</a>
            ·
            <a href="{{ route('clients.index', ['name_review' => 1]) }}">{{ __('clients.name_review_link') }}</a>
        </p>
    </div>

    @if($showDirectory ?? false)
        <div class="panel clients-directory" data-clients-directory>
            <h2>{{ $filterHeading ?? __('clients.directory_heading') }}</h2>

            @php
                $tabQuery = array_filter([
                    'q' => ($q ?? '') !== '' ? $q : null,
                ]);
                $tabs = [
                    'all' => __('clients.directory_tabs.all'),
                    'with_account' => __('clients.directory_tabs.with_account'),
                    'profile_only' => __('clients.directory_tabs.profile_only'),
                    'invited' => __('clients.directory_tabs.invited'),
                ];
            @endphp

            <div class="clients-directory-tabs" role="tablist" aria-label="{{ __('clients.directory_tabs_label') }}" data-clients-tablist>
                @foreach($tabs as $tabKey => $tabLabel)
                    @php
                        $href = route('clients.index', array_filter([
                            ...$tabQuery,
                            'directory_tab' => $tabKey === 'all' ? null : $tabKey,
                            'account_state' => match ($tabKey) {
                                'with_account' => \App\Core\Accounts\User::ACCOUNT_STATE_ACTIVE,
                                'profile_only' => \App\Core\Accounts\User::ACCOUNT_STATE_PROFILE_ONLY,
                                'invited' => \App\Core\Accounts\User::ACCOUNT_STATE_INVITED,
                                default => null,
                            },
                        ]));
                        $selected = ($directoryTab ?? 'all') === $tabKey;
                    @endphp
                    <a
                        id="clients-tab-{{ $tabKey }}"
                        role="tab"
                        href="{{ $href }}"
                        class="clients-directory-tab"
                        aria-selected="{{ $selected ? 'true' : 'false' }}"
                        tabindex="{{ $selected ? '0' : '-1' }}"
                        data-clients-tab="{{ $tabKey }}"
                    >
                        {{ $tabLabel }}
                        <span class="clients-directory-tab-count">({{ $tabCounts[$tabKey] ?? 0 }})</span>
                    </a>
                @endforeach
            </div>

            @if(($directoryTab ?? 'all') === 'profile_only')
                <p class="muted">{{ __('clients.profile_only_helper') }}</p>
            @endif

            @if($clients->isNotEmpty())
                <div class="clients-directory-table-wrap" role="region" aria-label="{{ __('clients.directory_heading') }}">
                    <table class="clients-directory-table">
                        <thead>
                            <tr>
                                <th scope="col">{{ __('clients.fields.name') }}</th>
                                <th scope="col">{{ __('clients.fields.date_of_birth') }}</th>
                                <th scope="col">{{ __('clients.fields.email') }}</th>
                                <th scope="col">{{ __('clients.fields.mobile') }}</th>
                                <th scope="col">{{ __('clients.fields.postcode') }}</th>
                                <th scope="col">{{ __('clients.account_state') }}</th>
                                <th scope="col">{{ __('clients.directory_actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($clients as $client)
                                @php
                                    $state = $client->accountState();
                                    $badge = match ($state) {
                                        \App\Core\Accounts\User::ACCOUNT_STATE_INVITED => 'invited',
                                        \App\Core\Accounts\User::ACCOUNT_STATE_PROFILE_ONLY => 'profile_only',
                                        default => 'active',
                                    };
                                @endphp
                                <tr class="clients-directory-row" data-client-id="{{ $client->id }}" data-account-state="{{ $state }}">
                                    <td data-label="{{ __('clients.fields.name') }}">
                                        <a href="{{ route('clients.show', $client) }}">{{ $client->name }}</a>
                                    </td>
                                    <td data-label="{{ __('clients.fields.date_of_birth') }}">
                                        {{ $client->profile?->date_of_birth?->format('d/m/Y') ?: '—' }}
                                    </td>
                                    <td data-label="{{ __('clients.fields.email') }}">
                                        {{ $client->email ?: __('clients.no_email') }}
                                    </td>
                                    <td data-label="{{ __('clients.fields.mobile') }}">
                                        {{ $client->profile?->mobile_number ?: '—' }}
                                    </td>
                                    <td data-label="{{ __('clients.fields.postcode') }}">
                                        {{ $client->profile?->postcode ?: '—' }}
                                    </td>
                                    <td data-label="{{ __('clients.account_state') }}">
                                        <span class="clients-state-badge clients-state-badge--{{ $badge }}">
                                            {{ __("clients.account_states.{$badge}") }}
                                        </span>
                                    </td>
                                    <td data-label="{{ __('clients.directory_actions') }}">
                                        <a href="{{ route('clients.show', $client) }}">{{ __('clients.directory_view') }}</a>
                                        @if($state === \App\Core\Accounts\User::ACCOUNT_STATE_INVITED)
                                            <span class="muted">· {{ __('clients.directory_resend_invite_soon') }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @elseif(($q ?? '') !== '')
                <p class="muted">{{ __('clients.no_results', ['query' => $q]) }}</p>
            @else
                <p class="muted">{{ __('clients.directory_empty') }}</p>
            @endif
        </div>
    @elseif(($missingDetails ?? false) || ($nameReview ?? false))
        <div class="panel">
            <h2>{{ $filterHeading }}</h2>
            @if($missingDetails ?? false)
                <p class="muted">{{ __('clients.missing_details_intro') }}</p>
            @elseif($nameReview ?? false)
                <p class="muted">{{ __('clients.name_review_intro') }}</p>
            @endif
            @if($clients->isNotEmpty())
                @foreach($clients as $client)
                    <p>
                        <a href="{{ route('clients.show', $client) }}">{{ $client->name }}</a>
                        <span class="muted">· {{ $client->email ?: $client->username }}</span>
                    </p>
                @endforeach
            @elseif($missingDetails ?? false)
                <p class="muted">{{ __('clients.missing_details_empty') }}</p>
            @else
                <p class="muted">{{ __('clients.name_review_empty') }}</p>
            @endif
        </div>
    @endif

    <div class="panel" id="create" data-client-create>
        <h2>{{ __('clients.create_heading') }}</h2>
        <p class="muted">{{ __('clients.create_intro') }}</p>
        <form method="post" action="{{ route('clients.store') }}" data-client-create-form>
            @csrf
            <label for="first_name">{{ __('clients.fields.first_name') }}</label>
            <input id="first_name" name="first_name" value="{{ old('first_name') }}" required autocomplete="given-name">
            @error('first_name')<p class="error">{{ $message }}</p>@enderror

            <label for="last_name">{{ __('clients.fields.last_name') }}</label>
            <input id="last_name" name="last_name" value="{{ old('last_name') }}" required autocomplete="family-name">
            @error('last_name')<p class="error">{{ $message }}</p>@enderror

            <label for="date_of_birth">{{ __('clients.fields.date_of_birth') }}</label>
            <input id="date_of_birth" name="date_of_birth" value="{{ old('date_of_birth') }}" required placeholder="{{ __('clients.fields.date_of_birth_placeholder') }}" inputmode="numeric" autocomplete="bday">
            <p class="muted">{{ __('clients.fields.date_of_birth_hint') }}</p>
            @error('date_of_birth')<p class="error">{{ $message }}</p>@enderror

            <label for="email">{{ __('clients.fields.email') }}</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" data-client-email>
            <p class="muted">{{ __('clients.fields.email_hint') }}</p>

            <label for="mobile_number">{{ __('clients.fields.mobile') }}</label>
            <input id="mobile_number" name="mobile_number" value="{{ old('mobile_number') }}" required autocomplete="tel">

            <label for="landline_number">{{ __('clients.fields.landline') }}</label>
            <input id="landline_number" name="landline_number" value="{{ old('landline_number') }}" autocomplete="tel">

            <label for="address_line_1">{{ __('clients.fields.address_line_1') }}</label>
            <input id="address_line_1" name="address_line_1" value="{{ old('address_line_1') }}" required autocomplete="address-line1">

            <label for="address_line_2">{{ __('clients.fields.address_line_2') }}</label>
            <input id="address_line_2" name="address_line_2" value="{{ old('address_line_2') }}" autocomplete="address-line2">

            <label for="town_city">{{ __('clients.fields.town_city') }}</label>
            <input id="town_city" name="town_city" value="{{ old('town_city') }}" required autocomplete="address-level2">

            <label for="county">{{ __('clients.fields.county') }}</label>
            <input id="county" name="county" value="{{ old('county') }}" autocomplete="address-level1">

            <label for="postcode">{{ __('clients.fields.postcode') }}</label>
            <input id="postcode" name="postcode" value="{{ old('postcode') }}" required autocomplete="postal-code">

            <label for="pin">{{ __('clients.fields.pin') }}</label>
            <input id="pin" name="pin" type="password" inputmode="numeric" autocomplete="new-password" required minlength="{{ $pinMin }}" maxlength="{{ $pinMax }}">
            <p class="muted">{{ __('clients.fields.pin_hint', ['min' => $pinMin, 'max' => $pinMax]) }}</p>

            <label for="pin_confirmation">{{ __('clients.fields.pin_confirmation') }}</label>
            <input id="pin_confirmation" name="pin_confirmation" type="password" inputmode="numeric" autocomplete="new-password" required minlength="{{ $pinMin }}" maxlength="{{ $pinMax }}">

            <label class="inline-check" for="create_myapes_account">
                <input
                    id="create_myapes_account"
                    type="checkbox"
                    name="create_myapes_account"
                    value="1"
                    @checked((bool) old('create_myapes_account'))
                    data-client-create-account
                >
                {{ __('clients.fields.create_myapes_account') }}
            </label>
            <p class="muted">{{ __('clients.fields.create_myapes_account_hint') }}</p>

            <fieldset>
                <legend>{{ __('clients.fields.consent_legend') }}</legend>
                <label class="inline-check" for="staff_assisted_consent">
                    <input
                        id="staff_assisted_consent"
                        type="checkbox"
                        name="staff_assisted_consent"
                        value="1"
                        required
                        @checked((bool) old('staff_assisted_consent'))
                    >
                    {{ __('clients.fields.consent') }}
                </label>
            </fieldset>

            <button type="submit">{{ __('clients.create_submit') }}</button>
        </form>
    </div>
@endsection
