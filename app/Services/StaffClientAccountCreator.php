<?php

namespace App\Services;

use App\Core\Accounts\PhoneVerificationPinService;
use App\Core\Accounts\User;
use App\Core\Accounts\UserProfile;
use App\Notifications\Auth\WelcomeSignInDetailsNotification;
use App\Rules\NotStaffEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Staff, volunteers and students create local public client accounts (#669–#673, #937–#939).
 */
final class StaffClientAccountCreator
{
    public function __construct(
        private readonly AuthorizationAccountSynchronizer $accounts,
        private readonly PhoneVerificationPinService $pins,
        private readonly AuditLogger $audit,
        private readonly TemporaryPasswordService $temporaryPasswords,
    ) {}

    /**
     * @param  array{
     *     name: string,
     *     first_name: string,
     *     last_name: string,
     *     date_of_birth: string,
     *     email?: ?string,
     *     mobile_number: string,
     *     landline_number?: ?string,
     *     address_line_1: string,
     *     address_line_2?: ?string,
     *     town_city: string,
     *     county?: ?string,
     *     postcode: string,
     *     pin: string,
     *     create_myapes_account?: bool
     * }  $input
     * @return array{user: User, plain_pin: string, account_created: bool}
     */
    public function create(User $actor, array $input): array
    {
        $email = isset($input['email']) && is_string($input['email']) && trim($input['email']) !== ''
            ? strtolower(trim($input['email']))
            : null;
        $mobile = $input['mobile_number'];
        $createAccount = (bool) ($input['create_myapes_account'] ?? false);

        if ($createAccount && $email === null) {
            throw ValidationException::withMessages([
                'email' => __('clients.validation.create_account_email_required'),
            ]);
        }

        if ($email !== null) {
            if (NotStaffEmail::isStaffDomain($email)) {
                throw ValidationException::withMessages([
                    'email' => __('clients.validation.staff_email_domain'),
                ]);
            }

            if (User::query()->where('email', $email)->exists()) {
                throw ValidationException::withMessages([
                    'email' => __('clients.validation.email_taken'),
                ]);
            }
        }

        if (UserProfile::query()
            ->where(static fn ($query) => $query
                ->where('mobile_number', $mobile)
                ->orWhere('phone', $mobile))
            ->exists()) {
            throw ValidationException::withMessages([
                'mobile_number' => __('clients.validation.phone_taken'),
            ]);
        }

        $plainPin = $input['pin'];
        $username = $this->temporaryPasswords->generateUsername(
            $input['first_name'],
            $input['last_name'],
        );

        $user = DB::transaction(function () use ($actor, $input, $email, $mobile, $username, $plainPin, $createAccount): User {
            $user = new User([
                'name' => $input['name'],
                'username' => $username,
                'email' => $email,
                'password' => Str::password(32),
                'identity_type' => User::IDENTITY_LOCAL,
                'account_state' => $createAccount
                    ? User::ACCOUNT_STATE_INVITED
                    : User::ACCOUNT_STATE_PROFILE_ONLY,
                'email_verified_at' => $email !== null ? now() : null,
            ]);
            $user->registration_consented_at = now();
            $user->registration_consent_staff_id = $actor->id;
            $user->save();

            $this->accounts->grantPublicBaseline($user);
            $user->contactPreference()->create([]);
            $user->profile()->create([
                'first_name' => $input['first_name'],
                'last_name' => $input['last_name'],
                'date_of_birth' => $input['date_of_birth'],
                'address_line_1' => $input['address_line_1'],
                'address_line_2' => $input['address_line_2'] ?? null,
                'town_city' => $input['town_city'],
                'county' => $input['county'] ?? null,
                'postcode' => $input['postcode'],
                'mobile_number' => $mobile,
                'landline_number' => $input['landline_number'] ?? null,
                'phone' => $mobile,
            ]);

            $this->pins->set($user, $plainPin);

            $this->audit->record('client.created', $actor, $user, [
                'client_user_id' => $user->id,
                'pin_set' => true,
                'create_myapes_account' => $createAccount,
                'staff_assisted_consent' => true,
                'consent_staff_id' => $actor->id,
            ]);

            return $user;
        });

        if ($createAccount) {
            $this->issueWelcomeCredentials($user, $actor, 'temporary_password.issued');
        }

        return [
            'user' => $user->fresh(['profile']),
            'plain_pin' => $plainPin,
            'account_created' => $createAccount,
        ];
    }

    /**
     * Promote a profile-only client to an invited MyAPES account (#938).
     */
    public function createAccountFromProfile(User $actor, User $client): User
    {
        if (! $client->isProfileOnly()) {
            throw ValidationException::withMessages([
                'create_myapes_account' => __('clients.validation.already_has_account'),
            ]);
        }

        $email = is_string($client->email) ? trim($client->email) : '';
        if ($email === '') {
            throw ValidationException::withMessages([
                'email' => __('clients.validation.create_account_email_required'),
            ]);
        }

        if (NotStaffEmail::isStaffDomain($email)) {
            throw ValidationException::withMessages([
                'email' => __('clients.validation.staff_email_domain'),
            ]);
        }

        $profile = $client->profile;
        $firstName = is_string($profile?->first_name) ? $profile->first_name : $client->name;
        $lastName = is_string($profile?->last_name) ? $profile->last_name : 'client';

        if ($client->username === null || $client->username === ''
            || ! str_contains((string) $client->username, '.')) {
            $client->forceFill([
                'username' => $this->temporaryPasswords->generateUsername($firstName, $lastName),
            ])->save();
        }

        $this->audit->record('Account.created_from_profile', $actor, $client, [
            'target_user_id' => $client->id,
        ]);

        $this->issueWelcomeCredentials($client, $actor, 'temporary_password.issued');

        return $client->fresh(['profile']);
    }

    /**
     * Resend sign-in details with a new temporary password (#942).
     */
    public function resendSignInDetails(User $actor, User $client): User
    {
        if ($client->isProfileOnly() || $client->suspended_at !== null) {
            throw ValidationException::withMessages([
                'resend' => __('clients.validation.resend_not_allowed'),
            ]);
        }

        $email = is_string($client->email) ? trim($client->email) : '';
        if ($email === '') {
            throw ValidationException::withMessages([
                'email' => __('clients.validation.create_account_email_required'),
            ]);
        }

        $this->issueWelcomeCredentials($client, $actor, 'temporary_password.resent');

        return $client->fresh(['profile']);
    }

    private function issueWelcomeCredentials(User $user, User $actor, string $event): void
    {
        $plain = $this->temporaryPasswords->issue($user, $actor, $event);
        $user->notify(new WelcomeSignInDetailsNotification($plain));
    }
}
