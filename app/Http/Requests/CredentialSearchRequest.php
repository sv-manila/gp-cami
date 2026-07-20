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
            'dob' => ['nullable', 'date'],
            'ssn' => ['nullable', 'string', 'max:32'],
        ];
    }
}
