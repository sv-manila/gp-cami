<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Contract confirmed with CAMI 2026-07-20:
 * required = registry, first_name, last_name; optional narrowers = license_number,
 * license_type, dob, ssn.
 *
 * NARROWED by the GPP conformance programme (SSN removal). `ssn` used to do two
 * unrelated jobs; only one of them survives:
 *
 *   1. narrow identity resolution, by matching gp_identity_profile.ssn_hash —
 *      REMOVED. Delivery Checklist §1 requires that the hub never store an SSN, so
 *      there is no hash to match against and no key to compute one with.
 *   2. gate credential matches whose own scrape recorded an SSN — KEPT. That
 *      comparison is against credential_matches.match->request_params.ssn, read
 *      live from the source per request; it stores nothing, hashes nothing and
 *      needs no key. Dropping it would return one person's credential for a
 *      request about another, which is a precision regression with no compliance
 *      benefit whatsoever.
 *
 * The value is never persisted and never logged — resolveIdentity() logs only
 * WHICH narrowers were supplied, never their values.
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
            // scrape recorded a DOB — see CredentialSelector.
            'dob' => ['nullable', 'date_format:Y-m-d'],
            // 9 digits (dashes or spaces optional) OR a bare last-four.
            //
            // The last-four form is accepted so a caller can stop putting a whole
            // SSN on the wire: CredentialSelector::ssnParts() already reports a
            // partial value as known-by-last4 and identityAgrees() already
            // compares at the strongest precision the two sides share, so no new
            // logic is needed. The trade the caller makes is that a four-digit
            // request against a full-SSN payload degrades to a last-four
            // comparison — weaker, but their choice, per request.
            //
            // The shape check itself stays for the reason it was added: without it
            // a typo used to become a non-matching hash rather than an error the
            // caller could see. It still matters, because a typo now silently
            // withholds every SSN-bearing credential match instead.
            'ssn' => ['nullable', 'string', 'max:32', 'regex:/^(\d{3}[- ]?\d{2}[- ]?\d{4}|\d{4})$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'dob.date_format' => 'dob must be a calendar date as YYYY-MM-DD.',
            'ssn.regex' => 'ssn must be 9 digits (optionally separated as 123-45-6789) or the last 4 digits.',
        ];
    }
}
