<?php

namespace App\Support;

use Carbon\Carbon;
use Closure;
use Illuminate\Validation\Validator;

/**
 * Shared mandatory identity field rules for staff client create and public register (#912, #913).
 */
final class ClientIdentityRules
{
    public const MAX_AGE_YEARS = 120;

    /**
     * @return array<string, list<string|Closure>>
     */
    public static function nameAndDobRules(bool $dateOfBirthRequired = true): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'date_of_birth' => array_values(array_filter([
                $dateOfBirthRequired ? 'required' : 'nullable',
                'date_format:d/m/Y',
                self::dateOfBirthSanityRule(),
            ])),
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public static function addressAndMobileRules(): array
    {
        return [
            'mobile_number' => ['required', 'string', 'max:32', 'regex:/^\+44\d{9,10}$/'],
            'address_line_1' => ['required', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'town_city' => ['required', 'string', 'max:255'],
            'county' => ['nullable', 'string', 'max:255'],
            'postcode' => ['required', 'string', 'max:16', 'regex:/^[A-Za-z]{1,2}\d[A-Za-z\d]?\s*\d[A-Za-z]{2}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'first_name.required' => __('clients.validation.first_name_required'),
            'last_name.required' => __('clients.validation.last_name_required'),
            'date_of_birth.required' => __('clients.validation.date_of_birth_required'),
            'date_of_birth.date_format' => __('clients.validation.date_of_birth_format'),
            'mobile_number.required' => __('clients.validation.mobile_required'),
            'address_line_1.required' => __('clients.validation.address_line_1_required'),
            'town_city.required' => __('clients.validation.town_city_required'),
            'postcode.required' => __('clients.validation.postcode_required'),
            'postcode.regex' => __('clients.validation.postcode_invalid'),
        ];
    }

    public static function normaliseDateOfBirth(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('d/m/Y', trim($value))->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private static function dateOfBirthSanityRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || trim($value) === '') {
                return;
            }

            try {
                $dob = Carbon::createFromFormat('d/m/Y', trim($value))->startOfDay();
            } catch (\Throwable) {
                return;
            }

            $today = now()->startOfDay();

            if ($dob->greaterThan($today)) {
                $fail(__('clients.validation.date_of_birth_future'));

                return;
            }

            if ($dob->lt($today->copy()->subYears(self::MAX_AGE_YEARS))) {
                $fail(__('clients.validation.date_of_birth_too_old'));
            }
        };
    }

    public static function afterNormalisingDob(Validator $validator): void
    {
        // Intentionally empty — callers normalise after validate().
    }
}
