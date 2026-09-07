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
        // ssn_hash used to sit at the top of this list at 0.99. The GPP
        // conformance programme removed it — Delivery Checklist §1 requires the
        // hub never store an SSN, so there is nothing left to match on. Adding a
        // key back here does NOT create a tier: DeterministicResolver reads this
        // map for confidences only, and DeterministicKeyConfigTest asserts
        // 'ssn_hash' is absent so it cannot creep back as a no-op either.
        'npi' => 0.99,
        // Vestigial for this source: stg_person.dea_number is always null from
        // StreamlineLocalConnector (DEA is multi-valued in this source, staged
        // into stg_person_identifier instead — see dea_multi below). Left
        // configured rather than removed since gp_identity.dea_number and this
        // tier are harmless dead code, not a bug to fix in this plan.
        'dea_number' => 0.99,
        'upin' => 0.99,
        // Multi-valued identifiers from gp_identity_identifier (Task 9). DEA is
        // federal (never state-scoped); MMIS is inherently state-scoped — see
        // the identifier tier in DeterministicResolver::matchDeterministic().
        'dea_multi' => 0.99,
        'mmis+state' => 0.99,
        'license_number+certification_state' => 0.99,
        'name+dob' => 0.95,
    ],

    /*
    | Junk / placeholder value screening — Delivery Checklist §2/§3 and
    | "Recommendations & Open Risks" P1 ("kill mega-blocks at the source").
    | A few high-frequency junk values are what make matching blow up at
    | scale; this generalizes SsnHashGuard's cardinality idea (a value shared
    | by implausibly many distinct people cannot be one person's identifier)
    | to any column, config-driven. Wired for 'npi' only in this plan (Task 5)
    | — 'placeholders' starts empty because, unlike SSN's filler list, nobody
    | has run gp:npi-audit-style measurement against a real hub yet to know
    | which specific NPI values are reused as filler here. Populate it the
    | same way SSN's was populated: from measurement, not a guess. license and
    | address values are good next columns for the same treatment (see this
    | plan's Self-review) but are not wired in yet.
    */
    'junk' => [
        'placeholders' => [
            'npi' => [],
        ],
        'max_identities_per_value' => [
            'npi' => 3,
        ],
        /*
        | Name-field placeholders. Stateless — the string itself is the signal,
        | so no cardinality query is needed and none is run. Applied only to
        | name-shaped fields (see StreamlineLocalConnector::cleanName), never to
        | address or city: "UNKNOWN" is unambiguous junk in a surname and can be
        | literal data elsewhere.
        |
        | AREALNULL is not hypothetical. It is a null sentinel the source data
        | actually emits — verified in 22 of the 84 rows of
        | streamline_local.exclusion_records, as the literal value of the
        | date_deleted key inside the unstructured per-registry `match` JSON.
        | It has NOT been observed in a name field, so listing it here is
        | defensive: the sources demonstrably write this string where a NULL
        | belongs, and a name column is where that would silently become a
        | match key. 00-PROGRAMME.md §5 assigns it to this list.
        */
        'name_placeholders' => [
            'INFORMATION NOT AVAILABLE', 'NOT AVAILABLE', 'UNKNOWN', 'N/A', 'NONE', 'AREALNULL',
        ],
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
    | There is no SSN block. gp-cami stored a CAMI-encrypted SSN, an ssn_hash and
    | an ssn_last_four, matched on the hash at 0.99 in Pass A, and screened that
    | tier for filler values (placeholder_plaintexts + a cardinality cap). All of
    | it was removed by the GPP conformance programme: Confluence Delivery
    | Checklist §1 requires that internal verified data stream via CDC and that the
    | SSN never be stored, and the GPP Data Model has no SSN column in the golden
    | layer. See docs/RUNNING.md for the removal record — including the measured
    | filler-SSN figures that justified the guard, which are worth keeping even
    | though the guard is gone.
    */

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
