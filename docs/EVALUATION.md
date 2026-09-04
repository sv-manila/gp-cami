# Evaluating match quality

## Baseline — before the GPP conformance programme

**Not yet measured.** Run `scripts/baseline-key-mix.sql` against the `golden_profile` hub and fill the table below.

| Metric | Value |
|---|---|
| Links by `ssn_hash` | PENDING |
| Links by `npi` | PENDING |
| Links by `name_dob` | PENDING |
| Links by `license_registry` | PENDING |
| Links by `probabilistic` | PENDING |
| Links by `new` | PENDING |
| Active identities | PENDING |
| Identities carrying an `ssn_hash` | PENDING |
| **Identities bound only by `ssn_hash`** | PENDING |
| **At-risk identities** (multi-row, no other key) | PENDING |
| Filler hashes on the blocklist | PENDING |

**Reading this:** the two bold rows size what Plan 2 (SSN removal) will fragment.
Every identity in "bound only by `ssn_hash`" loses its binding evidence and splits
into one identity per source row unless another key covers it.
