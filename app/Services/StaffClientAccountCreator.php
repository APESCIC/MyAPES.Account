<?php

namespace App\Services;

use App\Core\Accounts\PhoneVerificationPinService;
use App\Core\Accounts\User;
use App\Core\Accounts\UserProfile;
use App\Notifications\Auth\StaffAssistedSetPasswordNotification;
use App\Rules\NotStaffEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Staff, volunteers and students create local public client accounts (#669–#673).
 */
final class StaffClientAccountCreator
{
    public function __construct(
        private readonly AuthorizationAccountSynchronizer $accounts,
        private readonly PhoneVerificationPinService $pins,
        private readonly AuditLogger $audit,
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
     *     send_set_password_email?: bool
     * }  $input
     * @return array{user: User, plain_pin: string, set_password_email_sent: bool}
     */
    public function create(User $actor, array $input): array
    {
        $email = isset($input['email']) && is_string($input['email']) && trim($input['email']) !== ''
            ? strtolower(trim($input['email']))
            : null;
        $mobile = $input['mobile_number'];
        $sendEmail = (bool) ($input['send_set_password_email'] ?? false) && $email !== null;

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
        $username = $this->uniqueUsername($input['name'], $email, $mobile);

        $user = DB::transaction(function () use ($actor, $input, $email, $mobile, $username, $plainPin, $sendEmail): User {
            $user = new User([
                'name' => $input['name'],
                'username' => $username,
                'email' => $email,
                'password' => Str::password(32),
                'identity_type' => User::IDENTITY_LOCAL,
                'account_state' => $sendEmail
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
                'set_password_email_sent' => $sendEmail,
                'staff_assisted_consent' => true,
                'consent_staff_id' => $actor->id,
            ]);

            return $user;
        });

        if ($sendEmail) {
            $this->sendSetPasswordLink($user);
        }

        return [
            'user' => $user->fresh(['profile']),
            'plain_pin' => $plainPin,
            'set_password_email_sent' => $sendEmail,
        ];
    }

    private function sendSetPasswordLink(User $user): void
    {
        $status = Password::broker('staff_assisted')->sendResetLink(
            ['email' => $user->email],
            function (User $notifiable, string $token): void {
                $notifiable->notify(new StaffAssistedSetPasswordNotification($token));
            },
        );

        if ($status !== Password::RESET_LINK_SENT) {
            throw ValidationException::withMessages([
                'send_set_password_email' => __('clients.validation.set_password_email_failed'),
            ]);
        }
    }

    private function uniqueUsername(string $name, ?string $email, string $mobile): string
    {
        $base = Str::lower(Str::slug(Str::before($email ?? $name, '@'), ''));
        $base = preg_replace('/[^a-z0-9]/', '', (string) $base) ?: 'client';
        $base = substr($base, 0, 20);
        if (strlen($base) < 3) {
            $base = 'client'.substr(preg_replace('/\D/', '', $mobile) ?: '000', -3);
        }

        for ($i = 0; $i < 20; $i++) {
            $candidate = $base.($i === 0 ? '' : (string) random_int(10, 99));
            $candidate = substr($candidate, 0, 30);
            if (! User::query()->where('username', $candidate)->exists()) {
                return $candidate;
            }
        }

        return 'client'.Str::lower(Str::random(8));
    }
}
