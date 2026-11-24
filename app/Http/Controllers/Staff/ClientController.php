<?php

namespace App\Http\Controllers\Staff;

use App\Core\Accounts\AccountClassifier;
use App\Core\Accounts\PhoneVerificationPinService;
use App\Core\Accounts\User;
use App\Core\Search\Providers\UsersRecordSearchProvider;
use App\Core\Search\RecordSearch;
use App\Services\UserActivityTimeline;
use App\Services\UserProfileHubActions;
use App\Services\UserRecordsSummary;
use App\Http\Controllers\Concerns\BuildsUserProfileHub;
use App\Http\Controllers\Controller;
use App\Services\AuthorizationProfile;
use App\Services\StaffClientAccountCreator;
use App\Services\TotpTwoFactorService;
use App\Services\UkPhoneNumber;
use App\Support\ClientIdentityRules;
use App\Support\ClientMandatoryFields;
use App\Support\PersonName;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Staff › Clients: create public client accounts (#669–#673) and profile hub (#521).
 */
class ClientController extends Controller
{
    use BuildsUserProfileHub;

    public function __construct(
        private readonly AuthorizationProfile $profile,
    ) {}

    public function index(Request $request, RecordSearch $search, AccountClassifier $accounts): View
    {
        Gate::authorize(AuthorizationProfile::PERMISSION_CLIENT_SEARCH);

        $q = trim((string) $request->query('q', ''));
        $nameReview = $request->boolean('name_review');
        $missingDetails = $request->boolean('missing_details');
        $directoryTab = $this->resolveDirectoryTab($request);
        $accountState = $this->accountStateForTab($directoryTab);
        $clients = collect();
        $filterHeading = null;
        $tabCounts = [
            'all' => 0,
            'with_account' => 0,
            'profile_only' => 0,
            'invited' => 0,
        ];

        if ($missingDetails) {
            $clients = User::query()
                ->tap(fn ($query) => $accounts->scopePublic($query))
                ->with(['profile', 'roles'])
                ->tap(fn ($query) => ClientMandatoryFields::scopeMissingDetails($query))
                ->orderBy('name')
                ->limit(100)
                ->get();
            $filterHeading = __('clients.missing_details_heading');
        } elseif ($nameReview) {
            $clients = User::query()
                ->tap(fn ($query) => $accounts->scopePublic($query))
                ->with(['profile', 'roles'])
                ->where(function ($query): void {
                    $query->whereDoesntHave('profile')
                        ->orWhereHas('profile', function ($profile): void {
                            $profile->where(function ($names): void {
                                $names->whereNull('first_name')
                                    ->orWhere('first_name', '')
                                    ->orWhereNull('last_name')
                                    ->orWhere('last_name', '');
                            });
                        });
                })
                ->orderBy('name')
                ->limit(100)
                ->get();
            $filterHeading = __('clients.name_review_heading');
        } else {
            if (strlen($q) >= 2) {
                $results = $search->search($request->user(), $q, perType: 25);
                $hits = collect($results['groups'])->firstWhere('type', 'users')['hits'] ?? [];
                $clients = UsersRecordSearchProvider::usersForHits($hits)
                    ->reject(fn (User $user): bool => $accounts->isStaffSide($user))
                    ->values();
            } else {
                $clients = User::query()
                    ->tap(fn ($query) => $accounts->scopePublic($query))
                    ->with(['profile', 'roles'])
                    ->orderByDesc('updated_at')
                    ->limit(100)
                    ->get();
            }

            $tabCounts = $this->directoryTabCounts($clients);
            if ($accountState !== null) {
                $clients = $clients
                    ->filter(static fn (User $user): bool => $user->accountState() === $accountState)
                    ->values();
            }
            $filterHeading = __('clients.directory_heading');
        }

        return view('clients.index', [
            'q' => $q,
            'nameReview' => $nameReview,
            'missingDetails' => $missingDetails,
            'filterHeading' => $filterHeading,
            'clients' => $clients,
            'directoryTab' => $directoryTab,
            'accountState' => $accountState,
            'tabCounts' => $tabCounts,
            'showDirectory' => ! $missingDetails && ! $nameReview,
            'pinMin' => PhoneVerificationPinService::MIN_LENGTH,
            'pinMax' => PhoneVerificationPinService::MAX_LENGTH,
        ]);
    }

    /**
     * @return 'all'|'with_account'|'profile_only'|'invited'
     */
    private function resolveDirectoryTab(Request $request): string
    {
        $tab = (string) $request->query('directory_tab', '');
        $state = (string) $request->query('account_state', '');

        $fromState = match ($state) {
            User::ACCOUNT_STATE_ACTIVE => 'with_account',
            User::ACCOUNT_STATE_PROFILE_ONLY => 'profile_only',
            User::ACCOUNT_STATE_INVITED => 'invited',
            default => null,
        };

        if (in_array($tab, ['all', 'with_account', 'profile_only', 'invited'], true)) {
            return $tab;
        }

        return $fromState ?? 'all';
    }

    private function accountStateForTab(string $tab): ?string
    {
        return match ($tab) {
            'with_account' => User::ACCOUNT_STATE_ACTIVE,
            'profile_only' => User::ACCOUNT_STATE_PROFILE_ONLY,
            'invited' => User::ACCOUNT_STATE_INVITED,
            default => null,
        };
    }

    /**
     * @param  \Illuminate\Support\Collection<int, User>  $clients
     * @return array{all: int, with_account: int, profile_only: int, invited: int}
     */
    private function directoryTabCounts($clients): array
    {
        $counts = [
            'all' => $clients->count(),
            'with_account' => 0,
            'profile_only' => 0,
            'invited' => 0,
        ];

        foreach ($clients as $client) {
            match ($client->accountState()) {
                User::ACCOUNT_STATE_ACTIVE => $counts['with_account']++,
                User::ACCOUNT_STATE_PROFILE_ONLY => $counts['profile_only']++,
                User::ACCOUNT_STATE_INVITED => $counts['invited']++,
                default => null,
            };
        }

        return $counts;
    }

    public function store(
        Request $request,
        StaffClientAccountCreator $creator,
        UkPhoneNumber $phones,
    ): RedirectResponse {
        Gate::authorize(AuthorizationProfile::PERMISSION_CLIENT_CREATE);

        $request->merge([
            'email' => filled($request->input('email'))
                ? strtolower(trim((string) $request->input('email')))
                : null,
            'mobile_number' => $phones->normalize($request->input('mobile_number'), 'mobile_number'),
            'landline_number' => $phones->normalize($request->input('landline_number'), 'landline_number', false),
        ]);

        $length = PhoneVerificationPinService::MIN_LENGTH.','.PhoneVerificationPinService::MAX_LENGTH;
        $validated = $request->validate(array_merge(
            ClientIdentityRules::nameAndDobRules(),
            ClientIdentityRules::addressAndMobileRules(),
            [
                'email' => ['nullable', 'string', 'email', 'max:255'],
                'landline_number' => ['nullable', 'string', 'max:32', 'regex:/^\+44\d{9,10}$/'],
                'pin' => ['required', 'string', 'regex:/^\d{'.$length.'}$/', 'confirmed'],
                'send_set_password_email' => ['sometimes', 'boolean'],
                'staff_assisted_consent' => ['accepted'],
            ],
        ), array_merge(ClientIdentityRules::messages(), [
            'pin.regex' => __('clients.validation.pin_invalid', [
                'min' => PhoneVerificationPinService::MIN_LENGTH,
                'max' => PhoneVerificationPinService::MAX_LENGTH,
            ]),
            'staff_assisted_consent.accepted' => __('clients.validation.consent_required'),
        ]));

        $validated['postcode'] = strtoupper((string) preg_replace('/\s+/', ' ', trim($validated['postcode'])));
        $validated['date_of_birth'] = ClientIdentityRules::normaliseDateOfBirth($validated['date_of_birth']);
        $validated['name'] = PersonName::display($validated['first_name'], $validated['last_name']);
        $validated['send_set_password_email'] = $request->boolean('send_set_password_email');

        if ($validated['email'] === null) {
            $validated['send_set_password_email'] = false;
        }

        $result = $creator->create($request->user(), $validated);

        $request->session()->flash('client_created_pin', $result['plain_pin']);
        $request->session()->flash(
            'status',
            $result['set_password_email_sent']
                ? __('clients.flash.created_with_email')
                : __('clients.flash.created'),
        );

        return redirect()->route('clients.show', $result['user']);
    }

    public function show(
        Request $request,
        User $client,
        UserRecordsSummary $records,
        UserProfileHubActions $actions,
        UserActivityTimeline $timeline,
        TotpTwoFactorService $twoFactor,
    ): View {
        Gate::authorize(AuthorizationProfile::PERMISSION_CLIENT_VIEW);
        abort_if($this->profile->hasDirectoryProtectedEligibility($client), 404);

        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $client->load('profile', 'contactPreference');
        $plainPin = $request->session()->pull('client_created_pin');
        $missingMandatory = ClientMandatoryFields::missingFor($client->profile);

        return view('clients.show', [
            'client' => $client,
            'managedUser' => $client,
            'missingMandatory' => $missingMandatory,
            'plainPin' => is_string($plainPin) ? $plainPin : null,
            'preference' => $client->contactPreference,
            'profile' => $client->profile,
            'isAdminHub' => false,
            'canEditHub' => false,
            'canManageTarget' => false,
            'canResendVerification' => false,
            'isStaffAccount' => false,
            'tabRoute' => 'clients.show',
            'tabParam' => 'client',
            'tabSubject' => $client,
            'noteStoreRoute' => 'clients.notes.store',
            ...$this->profileHubData(
                $request,
                $client,
                $actor,
                $records,
                $actions,
                $timeline,
                $twoFactor,
            ),
        ]);
    }
}
