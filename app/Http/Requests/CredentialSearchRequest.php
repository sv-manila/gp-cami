<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Contract confirmed with CAMI 2026-07-20:
 * required = registry, first_name, last_name; optional narrowers = license_number,
 * license_type, dob, ssn.
 */
class CredentialSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'registry' => ['required', 'string', 'max:255'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'license_number' => ['nullable', 'string', 'max:100'],
            'license_type' => ['nullable', 'string', 'max:100'],
            // Both narrow identity resolution AND gate credential matches whose
            // scrape recorded an SSN or DOB — see CredentialSelector.
            'dob' => ['nullable', 'date_format:Y-m-d'],
            // 9 digits, dashes or spaces optional. Previously max:32 with no shape
            // check, so a typo silently became a non-matching hash instead of an
            // error the caller could see.
            'ssn' => ['nullable', 'string', 'max:32', 'regex:/^\d{3}[- ]?\d{2}[- ]?\d{4}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'dob.date_format' => 'dob must be a calendar date as YYYY-MM-DD.',
            'ssn.regex' => 'ssn must be 9 digits, optionally separated as 123-45-6789.',
        ];
    }
}
