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
        'hub'    => 'golden_profile',
        'source' => 'streamline_local',
    ],

    /*
    | Pass A — deterministic keys, in priority order, with bind confidence.
    | name+dob sits lower: common name + shared birthday can collide.
    */
    'deterministic_keys' => [
        'ssn_hash'                          => 0.99,
        'npi'                               => 0.99,
        'dea_number'                        => 0.99,
        'upin'                              => 0.99,
        'license_number+certification_state' => 0.99,
        'name+dob'                          => 0.95,
    ],

    /*
    | Pass B — probabilistic. No single global cutoff in production: thresholds
    | are set per source-pair from labeled data (Phase 3). These are the
    | fallback bands used until a pair is calibrated.
    */
    'probabilistic' => [
        // A pair without enough labels to calibrate defaults to recall-first
        // + steward review, never a guessed auto-merge.
        'default_mode'        => 'recall_first_review',
        'auto_merge_at'       => 0.92,   // >= : auto-merge
        'review_band_floor'   => 0.75,   // [floor, auto_merge_at) : review queue
        // < review_band_floor : treated as a new identity
        'block_size_cap'      => 2000,   // oversized blocks are split + logged, never truncated
        'calibrated_pairs'    => [],     // filled in Phase 3: ['systemA:systemB' => ['auto'=>..,'review'=>..]]
    ],

    /*
    | Two-track error priority (adopted review principle §8.1).
    | Identity = precision-first; compliance links = recall-first.
    | Exclusion links are stored as reviewable candidates, never merged.
    */
    'tracks' => [
        'identity'   => 'precision_first',
        'compliance' => 'recall_first',
        'exclusion_link_default_state' => 'candidate', // candidate|confirmed|rejected
    ],

    /*
    | Survivorship — authority beats recency across trust tiers.
    | Recency only tiebreaks within the same reliability_rank tier.
    */
    'survivorship' => [
        'internal_verified_decay_days' => 365, // human-checked value decays past this window
    ],

    /*
    | SSN handling (streamlineverify/security). Encryption is non-deterministic
    | (AES-256-CBC, random IV) so matching is on the sha512 hash, never ciphertext.
    | gp-cami must use the SAME plaintext key as CAMI or hashes/ciphertext won't align.
    */
    'ssn' => [
        'encryption_key_id' => env('GP_SSN_ENCRYPTION_KEY_ID', 1),
        'store_encrypted'   => true,   // parity with streamline_local.social_security_num
        'match_on'          => 'ssn_hash', // sha512(ssn + plaintext_key)
        'return_in_api'     => false,  // responses expose ssn_last_four only
    ],

    /*
    | Run modes (console commands + queued jobs).
    */
    'engine' => [
        'queue'        => env('GP_ENGINE_QUEUE', 'gp-engine'),
        'chunk_size'   => 1000,   // chunkById keyset paging, never offset
        'workers'      => 4,      // parallel backfill workers (disjoint id/account ranges)
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
        'excluded_status_codes'   => [0, 10, 50, 60, 100],
        'respect_expiry_date'     => true, // expiry_date IS NULL OR expiry_date >= CURDATE()
    ],

];
