<?php

namespace App\Http\Controllers\Staff;

use App\Core\Accounts\AccountClassifier;
use App\Core\Accounts\PhoneVerificationPinService;
use App\Core\Accounts\User;
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
        $clients = collect();
        $filterHeading = null;

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
        } elseif (strlen($q) >= 2) {
            $results = $search->search($request->user(), $q, perType: 25);
            $hits = collect($results['groups'])->firstWhere('type', 'users')['hits'] ?? [];

            $clients = collect($hits)
                ->map(fn (array $hit): ?User => User::query()->with('roles')->find($hit['id']))
                ->filter(fn (?User $user): bool => $user instanceof User && ! $accounts->isStaffSide($user))
                ->values();
        }

        return view('clients.index', [
            'q' => $q,
            'nameReview' => $nameReview,
            'missingDetails' => $missingDetails,
            'filterHeading' => $filterHeading,
            'clients' => $clients,
            'pinMin' => PhoneVerificationPinService::MIN_LENGTH,
            'pinMax' => PhoneVerificationPinService::MAX_LENGTH,
        ]);
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
