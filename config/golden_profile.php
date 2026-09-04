<?php

/*
|--------------------------------------------------------------------------
| Golden Profile (gp-cami) engine configuration
|--------------------------------------------------------------------------
|
| Tunable without code change. See gp-cami/PROJECT_PLAN.md and the master
| GOLDEN_PROFILE_PLAN.md. All values are safe placeholders for Phase 0;
| thresholds get calibrated from labeled data in Phase 3.
|
*/

return [

    // Default hub + source connection names (see config/database.php).
    'connections' => [
        'hub' => 'golden_profile',
        'source' => 'streamline_local',
    ],

    /*
    | Pass A — deterministic keys, in priority order, with bind confidence.
    | name+dob sits lower: common name + shared birthday can collide.
    */
    'deterministic_keys' => [
        'ssn_hash' => 0.99,
        'npi' => 0.99,
        'dea_number' => 0.99,
        'upin' => 0.99,
        'license_number+certification_state' => 0.99,
        'name+dob' => 0.95,
    ],

    /*
    | Pass B — probabilistic. No single global cutoff in production: thresholds
    | are set per source-pair from labeled data (Phase 3). These are the
    | fallback bands used until a pair is calibrated. Bands per GPP spec:
    | >=0.92 auto_match, 0.75-0.92 review, <0.75 no_match (new identity).
    */
    'probabilistic' => [
        'default_mode' => 'recall_first_review',
        'auto_merge_at' => 0.92,
        'review_band_floor' => 0.75,
        'block_size_cap' => 2000,   // oversized blocks flagged for steward, never truncated
        'calibrated_pairs' => [],
        // weighted signal contributions (sum of fired weights, capped at 1.0)
        //
        // CALIBRATION WARNING — provider_type is declared but NOT implemented:
        // stg_person carries no provider/entity-type column, so ProbabilisticResolver
        // ::score() never fires this weight. The implemented weights sum to exactly
        // 0.92, which equals auto_merge_at, so 'auto_match' is only reachable on a
        // perfect score across every other signal (name JW == 1.0 AND exact DOB AND
        // address AND zip AND shared exclusion). In practice Pass B lands in the
        // review band or below. Rebalancing these weights (or lowering auto_merge_at)
        // changes merge behaviour across the whole hub, so it is deliberately left
        // alone here: it is a Phase 3 calibration decision against labeled data, not
        // a code fix. ProbabilisticResolver::warnIfAutoMergeUnreachable() logs a
        // warning once per process while the implemented weights only reach
        // auto_merge_at (or less), and ProbabilisticScoringTest keeps the gap from
        // being reintroduced silently.
        'weights' => [
            'name' => 0.45,   // Jaro-Winkler over all aliases
            'dob' => 0.20,
            'address' => 0.15,   // any-vs-any across mailing/practice/alt
            'provider_type' => 0.08,   // UNIMPLEMENTED — no source column
            'exclusion_share' => 0.07,   // shared exclusion registry/flags
            'zip' => 0.05,
        ],
        // Signals score() actually implements. Kept explicit so the reachability
        // check can tell "not configured" apart from "configured but never fires".
        'implemented_weights' => ['name', 'dob', 'address', 'exclusion_share', 'zip'],
        // Hard-no rules: block a merge outright regardless of score (GPP "Get it wrong" safeguards).
        'hard_no' => [
            'conflicting_dob' => true,  // both non-null and different
            'two_valid_npis' => true,  // both non-null and different
        ],
    ],

    /*
    | Survivorship — per-field winner order by source system_code, then recency.
    | "verified" beats all; compliance facts trust the issuer; status conflicts
    | take the most-restrictive value. Unlisted systems fall back to reliability_rank.
    */
    'survivorship' => [
        'internal_verified_decay_days' => 365,
        'field_authority' => [
            'identity' => ['verified', 'nppes', 'streamline_local', 'state_license', 'scraped_license'],
            'license' => ['state_license', 'nppes', 'scraped_license', 'streamline_local'],
            'address' => ['nppes', 'state_license', 'streamline_local', 'scraped_license'],
            'exclusion' => ['leie', 'sam', 'state_exclusion', 'streamline_local'],
        ],
        // status conflict resolution: most-restrictive wins (GPP conflict-resolution research)
        'status_severity' => ['revoked' => 5, 'suspended' => 4, 'excluded' => 5, 'lapsed' => 3, 'expired' => 2, 'active' => 1],
    ],

    /*
    | Two-track error priority (adopted review principle §8.1).
    | Identity = precision-first; compliance links = recall-first.
    | Exclusion links are stored as reviewable candidates, never merged.
    */
    'tracks' => [
        'identity' => 'precision_first',
        'compliance' => 'recall_first',
        'exclusion_link_default_state' => 'candidate', // candidate|confirmed|rejected
    ],

    /*
    | SSN handling. CAMI resolves its plaintext key via streamlineverify/security's
    | KeyManager; gp-cami no longer depends on that package (it was pulled in for
    | this one constant) and instead replicates its two manager branches inline.
    | Encryption is non-deterministic (AES-256-CBC, random IV) so matching is on
    | the sha512 hash, never ciphertext. gp-cami must use the SAME plaintext key
    | as CAMI or hashes/ciphertext won't align.
    */
    'ssn' => [
        'encryption_key_id' => env('GP_SSN_ENCRYPTION_KEY_ID', 1),
        'store_encrypted' => true,   // parity with streamline_local.social_security_num
        'match_on' => 'ssn_hash', // sha512(ssn + plaintext_key)
        // Not read anywhere — the API's withholding of the SSN is enforced
        // structurally by IdentityProfileResource, which simply never emits
        // ssn_hash or the ciphertext. Named for the FULL SSN: ssn_last_four is
        // returned regardless, so reading this as "no SSN data is returned" is
        // wrong. Kept only as documentation of intent; delete it or wire it, but
        // do not trust it as a control.
        'return_full_ssn_in_api' => false,

        // The shared CAMI plaintext key. SsnHasher has always read this path, but
        // the key was never declared here, so the "explicitly configured key"
        // branch was dead in every environment and hash() silently returned null.
        // Local dev leaves this unset and resolves via the streamline_local
        // encryption_keys registry (LocalStrategy) instead; prod, where the key
        // lives behind KMS and is not derivable from the source DB, must set
        // GP_SSN_PLAINTEXT_KEY or SSN matching is unavailable (and now says so).
        'plaintext_key' => env('GP_SSN_PLAINTEXT_KEY'),

        // The plaintext key for encryption_keys rows with manager = 'local'.
        // Mirrors LocalStrategy::getKey() from the streamlineverify/security
        // package, which ignores its argument and returns a hardcoded
        // constant — CAMI only registers that strategy when app.env is 'local'
        // or 'integration' (see AppServiceProvider::register()). This is a
        // local/integration development key ONLY; production keys are held
        // behind KMS under manager = 'aws' and are not derivable here (see
        // 'plaintext_key' above / GP_SSN_PLAINTEXT_KEY). Deliberately left
        // unset in this file and in .env.example — sv-manila/gp-cami is a
        // PUBLIC repository, so the value is set only in each developer's own
        // gitignored .env.
        'local_manager_key' => env('GP_SSN_LOCAL_MANAGER_KEY'),

        /*
        | Placeholder-SSN safeguard.
        |
        | ssn_hash is a 0.99-confidence deterministic key, but CAMI's source data
        | contains filler SSNs (all-zero, sequential, repeated digits). Every
        | person sharing a filler value hashes identically, so an unguarded
        | ssn_hash tier collapses all of them into ONE identity — a false merge
        | that no later pass undoes. Two independent guards:
        |
        |   placeholder_plaintexts - hashed with the live key at run time and
        |       excluded from the ssn_hash tier. Exact, but needs the key.
        |   max_identities_per_hash - any ssn_hash carried by more than this many
        |       distinct PEOPLE upstream — distinct (last_name, first_name,
        |       date_of_birth) triples in stg_person — is treated as filler and
        |       skipped. Works with no key at all, and catches fillers not listed
        |       above. Counting distinct rows in gp_identity instead would never
        |       fire: the tiers mint one identity per hash, so a filler ends up on
        |       exactly one identity. See SsnHashGuard.
        |
        | Measured on the current hub: 17 filler hashes covering 9,164 distinct
        | people, the worst single hash carried by 9,072 of them.
        */
        'placeholder_plaintexts' => [
            '000000000', '111111111', '222222222', '333333333', '444444444',
            '555555555', '666666666', '777777777', '888888888', '999999999',
            '123456789', '987654321', '012345678',
        ],
        'max_identities_per_hash' => 3,
    ],

    /*
    | Run modes (console commands + queued jobs).
    */
    'engine' => [
        'queue' => env('GP_ENGINE_QUEUE', 'gp-engine'),
        'chunk_size' => 1000,   // chunkById keyset paging, never offset
        'workers' => 4,      // parallel backfill workers (disjoint id/account ranges)
    ],

    /*
    | REST API — response shaping.
    */
    'api' => [
        // JSON rollup columns on gp_identity_profile. A row here is unbounded:
        // identity 3 carries a 69MB credentials blob and a 38MB exclusions blob,
        // enough to exhaust PHP's memory_limit while hydrating a single result.
        'json_columns' => [
            'identifiers', 'addresses', 'licenses', 'credentials', 'exclusions',
            'accounts', 'aliases', 'source_records', 'resolutions',
        ],
        // Any of those columns larger than this is omitted from the response and
        // named in meta.omitted_fields, so a caller can tell a genuinely empty
        // list apart from one that was withheld.
        'max_json_bytes' => 2097152,   // 2MB
    ],

    /*
    | REST API — credential-search qualifying status filter.
    | Codes mirror MatchSummaryStatus (streamlineverify/sv). Confirm the live
    | set before Phase 9; sv package is added at Phase 9, not Phase 0.
    */
    'credential_search' => [
        // status codes returned as a qualifying match
        'qualifying_status_codes' => [20, 30, 40, 45, 65, 70, 80, 85, 90],
        // explicitly excluded: 0 (*Valid), 10 (Pending), 50/60 (Expired), 100 (Error)
        'excluded_status_codes' => [0, 10, 50, 60, 100],
        'respect_expiry_date' => true, // expiry_date IS NULL OR expiry_date >= CURDATE()
        // Links per batch when folding an identity's credential links down to the
        // single qualifying one. Bounds both PHP memory and the source-side
        // whereIn placeholder count: an over-merged identity can hold >360k links
        // for one registry, which as a single statement exceeds MySQL's 65,535
        // placeholder limit.
        //
        // DO NOT RAISE THIS. It looks like a batching knob that trades round trips
        // for throughput, and it is not — each chunk becomes a whereIn against the
        // CAMI source, which is a remote server, and a large placeholder list there
        // is pathologically slow. Measured on identity 59 (9,358 links) with
        // idx_identity_registry_match in place:
        //
        //     chunk=1000    8.9s
        //     chunk=2000    6.8s
        //     chunk=5000  360.7s     <-- 50x worse
        //     chunk=10000 178.5s
        //
        // All sizes return the same credential, so the only thing tuning this can
        // change is how slow the endpoint is. 1000-2000 is the usable band.
        'link_chunk_size' => 1000,
        // Refuse to resolve an identity holding more than this many qualifying
        // links for one registry: the per-link source-side date lookups make it
        // unbounded work (identity 3 needs ~390s), and a partial scan would return
        // a wrong credential. Hub-wide average is 5.54 links per identity+registry
        // pair, so only over-merged records trip this. 0 disables the guard.
        'max_links' => 10000,
        // never roll these into the hub at all: 10 (Pending), 100 (Error),
        // 85 (Invalid - Incorrect License # Format), 80 (Invalid - No NPI Match)
        'rollup_exclude_status_codes' => [10, 100, 85, 80],
    ],

];
