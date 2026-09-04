# GPP Conformance Programme — the authority on order, numbers and ownership

Ten plan documents were authored largely in parallel, so each was written as though it were the only
one touching the repo. They are individually sound and they collide in five specific, mechanical
ways. **This file is the tiebreak.** Where a plan disagrees with this file, this file wins, and the
plan should be corrected rather than worked around.

Read this before executing any plan.

---

## 1. The plans

| # | Plan | File | Tasks | Depends on |
|---|---|---|---|---|
| 1 | Foundation — eval set, scorer, resolver harness, CI | `2026-09-03-gpp-conformance-foundation.md` | 7 | — | 
| 2 | SSN removal | `…-ssn-removal.md` | 9 | 1 |
| 3a | SCD-2 versioning — schema + per-row paths | `…-scd2-versioning.md` | 10 | 1 |
| 3b | SCD-2 set-based parity | `…-scd2-set-based-parity.md` | 8 | 3a |
| 4 | Individual vs entity | `…-individual-vs-entity.md` | 10 | 1, 3a, **5** |
| 5 | Match keys & data quality | `…-match-keys-and-data-quality.md` | 10 | 1 |
| 5b | Pass B blocking | `…-pass-b-blocking.md` | 6 | 5 |
| 6 | Steward writer layer | `…-steward-writer-layer.md` | 9 | 1, 3a |
| 7 | Exclusion lifecycle | `…-exclusion-lifecycle.md` | 7 | 1, 3a |
| 8 | Incremental profiling | `…-incremental-profiling.md` | 8 | 1, 5 |

**Plan 1 is DONE** and on branch `feat/eval-harness` (18 commits + the harness fix `cf38efd`).
Suite: 95 tests, 270 assertions, `pint --test` clean.

Plan 4 added a dependency on plan 5 that its brief did not anticipate: the entity ladder needs plan
5's multi-valued identifier tier, and building a parallel mechanism was explicitly ruled out.

`gp_board_action` is **deferred out of plan 6** pending a design decision — see §6.

---

## 2. Execution order

Dependencies permit several orders. This one is canonical:

```
1 (done) → 5 → 3a → 3b → 2 → 5b → 4 → 6 → 7 → 8
```

**Revised 2026-09-04: 3a and 3b now precede plan 2.** Plan 3b argued this and it is right on all three
counts. Plan 2's `SetBackfillParityTest` drives `indexStaging()` and `resolveDeterministic()` under
`HubTestCase`, both of which issue DDL — so without 3b's Task 1 harness it silently commits its
fixture and pollutes the process (the same defect class as `cf38efd`). Plan 2's Task 7 column drop is
gated on a production measurement nobody here can obtain, and 3b must not queue behind that. And
after 3b, plan 2's `SetFinalizer`/`SqlBackfill` edits still apply as written. Plan 3b carries an
edit-by-edit table for the reverse order if it is ever forced.

**Why 5 before 2, when plan 2 is the smaller change.** Plan 2 deletes `ssn_hash`, the strongest
deterministic key. Plan 5 supplies the compensating ones (MMIS and DEA promoted to resolve-time
tiers). Plan 2's own blast-radius table says that if more than 5% of identities are bound *only* by
`ssn_hash`, plan 5 becomes a prerequisite. That number is unmeasured (§7), so ordering 5 first is the
choice that is safe under either outcome. It costs nothing if the number turns out to be small.

**If you deliberately reverse 2 and 5**, the eval-gate numbers in both plans change — see §4. Do not
reverse them without editing both plans' expected values.

3b must directly follow 3a: 3a ships a `SetBasedPathGuard` that makes `SetFinalizer::run()` and
`SqlBackfill::transform()` refuse to run, so the hub has no working bulk path until 3b lands.

---

## 3. Migration timestamps — assigned, because four plans claimed the same one

As authored, `2026_09_05_000000` was claimed by plans 4, 5b, 3b and 8, and `2026_09_04_000000` by
plans 5 and 3a. Laravel does not hard-fail on distinct filenames sharing a timestamp — it orders them
alphabetically within it — so the collision produces a silent, accidental ordering rather than an
error. Two plans additionally proposed the identical filename `2026_09_05_000000_create_stg_person_block_key`.

Use exactly these. They are strictly increasing in dependency order, so a fresh `migrate` produces
the same schema regardless of the order the plans were merged in.

| Plan | Migration | Was |
|---|---|---|
| 2 | `2026_09_03_000000_drop_ssn_columns` | unchanged |
| 3a | `2026_09_04_000000_rename_mirrored_current_on_gp_identity_credential` | unchanged |
| 3a | `2026_09_04_000100_add_scd2_versioning` | unchanged |
| 3b | `2026_09_04_000200_add_stg_seed_id_to_gp_identity` | was `2026_09_05_000000` |
| 5 | `2026_09_05_000000_add_state_to_identifiers` | was `2026_09_04_000000` |
| 5 | `2026_09_05_000100_create_gp_quarantine` | was `2026_09_04_000001` |
| 5b | `2026_09_06_000000_create_stg_person_block_key` | was `2026_09_05_000000` |
| 4 | `2026_09_07_000000_add_entity_type_and_org_name` | was `2026_09_05_000000` |
| 7 | `2026_09_08_000000_add_exclusion_lifecycle_fields` | was `2026_09_05_090000` |
| 8 | `2026_09_09_000000_create_gp_identity_block_key` | was `2026_09_05_000000` |
| 8 | `2026_09_09_000100_create_gp_identity_signature` | was `2026_09_05_000001` |
| 6 | none | — |

Ordering constraints these satisfy, and which any renumbering must preserve: 3a's rename must precede
3a's versioning; 3b's scratch column requires 3a's versioning (`merged_into` becomes a golden
attribute); plan 4's `entity_type` and plan 7's lifecycle fields are added to tables 3a has already
versioned.

---

## 4. The eval fixture and the gate ratchet — one ladder, not five

`tests/eval/identity-pairs.json` and `EvalGateTest`'s ratchet are edited by plans 2, 4, 5, 5b and 8.
Each plan states its expected numbers as if the fixture were still at plan 1's baseline. Under the
canonical order the ladder is:

| After | `true_pairs` | `false_splits` | `recall` | Why it moves |
|---|---|---|---|---|
| plan 1 (now) | 9 | 0 | 1.0 | baseline |
| plan 5 | **11** | 0 | 1.0 | adds an MMIS pair and a DEA pair, both newly bindable |
| plan 2 | 11 | **1** | **0.9091** (10/11) | `ssn-a`/`ssn-b` lose their only key and become a false split |
| plan 5b | 11 | 1 | 0.9091 | adds a false-merge foil only; deliberately does not raise `true_pairs` |
| plan 4 | **13** | 1 | **0.9231** (12/13) | adds six organization records forming two entity pairs |
| plan 6 | 13 | 1 | 0.9231 | ingested decisions must not alter clustering; a move is a bug |
| plan 7, 3b, 8 | 13 | 1 | 0.9231 | asserted unchanged |

**`precision` stays 1.0000 and `false_merges` stays 0 at every step. Those two are absolute.**

Corrections each plan needs (the reconciliation pass applies these):

- **Plan 2** states `recall → 0.8889 (8/9)`. Under the canonical order it is **0.9091 (10/11)**. The
  0.8889 figure is correct only if plan 2 runs before plan 5.
- **Plan 5** states "unchanged — recall 1.0000, true_pairs 9" in four gate steps. Correct as written,
  because plan 5 runs first. Leave it, but say so explicitly so a reader does not "fix" it.
- **Plan 8** states "unchanged from plan 5's baseline … true_pairs 9". Self-contradictory: plan 5's
  baseline is 11. Under the canonical order plan 8 sees **13**.
- **Plan 4** ratchets `true_pairs` 11 → 13. Correct.
- **Plan 5b** assumes 11. Correct.

**The rule that survives all of this:** never lower a floor, never delete an assertion, never remove
a fixture record to keep a number up. The `true_pairs >= N` assertion exists precisely because
`MatchScorer::score([], [])` returns a perfect score. When a plan legitimately moves a number, it
re-baselines the ratchet **in the same commit** and states the new value and the reason.

---

## 5. Shared components — one owner each

Two plans independently invented overlapping components.

| Component | Owner | Everyone else |
|---|---|---|
| `Versioner` (the SCD-2 write primitive) | **3a** | Call it. Do not add a second write path. Plans 4, 6, 7 all write versioned tables through it. |
| `SetBasedPathGuard` | **3a** creates it, **3b** deletes it | Plan 4's Task 1 must distinguish "guarded" from "live": if 3b has landed, its six set-based obligations are live and must be folded in, not deferred. |
| Block-key construction | **5b** (`BlockKeyBuilder`, staging legs) | Plan 4 proposed its own `BlockKey` for the entity leg — **delegate to 5b's builder instead**. Plan 8's `block_key → {identity_id}` inverted index reads what 5b writes. |
| Junk / placeholder dictionary | **5** (`JunkKeyGuard`) | Written from scratch, deliberately not by extending `SsnHashGuard`, which plan 2 deletes. **Add `AREALNULL` to its placeholder list** — verified present in 22 of 84 local `exclusion_records` rows (§7). |
| `gp_identity_resolution` writes | **6** | `target_key` must be byte-identical to `CredentialSearchController.php:322-325`'s existing `registry:license_number[:license_type]`. |
| `link_state` transitions | **6** | Plan 7 depends on this and correctly does not specify it. |

---

## 6. Open decisions — for the design review, not for a plan to pick

**`gp_board_action` may be the wrong shape.** Migration `2026_07_20_150000` added it "to align
gp-cami with the canonical GPP spec", which specifies a separate append-only board-action table. But
the source disagrees: `exclusion_lists.type` includes `board_action` (verified — `albmba`, `armbba`,
`gabnba`, `kymbba` and dozens more), so board actions already arrive as typed exclusion lists and
already flow into `gp_identity_exclusion`. The spec-shaped columns (`action_type`, `action_date`,
`resolution_date`) live in unstructured per-registry JSON that CAMI normalizes through roughly ten
field-name variants per attribute. Options: build the normalizer and keep the table; drop the table
and treat board actions as typed exclusions; or keep it as a projection of the exclusion rows.
Guessing the mapping means writing confidently wrong compliance data, which is why plan 6 split it
out. **Spans plans 6 and 7.**

**Pass B is narrower than its config admits, and two plans' value depends on it.** Verified
arithmetic: a record with no DOB caps at **0.72** against a `review_band_floor` of 0.75, so it can
never reach even the review band however perfect every other signal is. Same for a record missing
both address and zip. And `blockKey()` returns null on a blank surname, so organizations never enter
Pass B at all. The config comment documents the `auto_merge_at` problem thoroughly and not this one.
Consequence: plan 5b (wider blocking) and plan 6 (consuming the review band) both deliver less than
their scope suggests until the weights are recalibrated — Phase-3 work nobody has scheduled. Plan 5b
was explicit about this rather than faking a fixture case to hide it.

**The type inference in plan 4 has no source column behind it.** All 70 columns of
`streamline_local.employees` were checked: there is no `employee_type`, `entity_type`,
`is_individual` or `record_type`, and no organization table anywhere. Plan 4 infers `entity` from
`TRIM(business) <> '' AND TRIM(first_name) = '' AND TRIM(last_name) = ''`. A false positive gives a
real person an entity block key and costs them the SSN/DEA/licence/name+dob tiers — a permanent,
silent false split. Accept the inference or get a source column added.

---

## 7. Measurements nobody here could take

No one in this environment has production hub credentials. These are human tasks, and two of them
gate decisions:

| Needed | Where from | Gates |
|---|---|---|
| Identities bound **only** by `ssn_hash` | `scripts/baseline-key-mix.sql` query 3 | Whether the canonical 5-before-2 order is necessary or merely safe. Plan 2's blast-radius table keys off it. |
| Full `docs/EVALUATION.md` baseline table | same script | Plan 2 Task 1; the table currently reads `PENDING` in every row |
| The 107,882 / 107,888 alias counts | real hub | Plan 4's reclassification blast radius. `gp_cami_test` is empty, so plan 4 took these from a migration docblock and flagged them |
| `exclusion_records.match` registry catalogue | real hub | Plan 7's deferred typed lifecycle dates. The 84-row dev sample proves heterogeneity, not the full shape set |
| Row growth under SCD-2 | real hub | 3a's index sizing; currently reasoned, not measured |

Before running query 3, add a `match_key IS NOT NULL` guard: `SUM(match_key <> 'ssn_hash') = 0`
silently ignores NULL rows, so an identity with an `ssn_hash` link plus a NULL-key link is
miscounted as ssn-only.

---

## 8. Verified corrections to claims made in the plans or their briefs

Each of these was checked against the code or the live database, and several correct something I
asserted in the shared authoring brief.

| Claim | Verdict |
|---|---|
| The eval fixture's NPIs are all check-digit valid | **False.** `1987654327` and `1112223339` fail the NPPES Luhn-over-`80840` check. Correct values `1987654328` / `1112223338`. Harmless today (neither is shared between records) but silent no-ops once plan 5's validation lands. Plan 5 Task 2 owns the fix. |
| Organization names get merged with people via a business-name match path | **False.** Zero alias reads in `Resolution/` or `Engine.php`, and `mergeByNameDob` requires a non-null DOB. The real cross-type merge paths are licence+state, `upin` and `npi`. |
| `alias_type = 'business'` requires shape inference to identify | **False.** It is already a stored label. |
| MySQL `SOUNDEX()` matches PHP's | **False.** MySQL does not truncate to 4 characters: `McDonald` → PHP `M235`, MySQL `M23543`. Latent today because both paths compute `block_key` in PHP; live the moment 5b or 8 moves it into SQL. Plan 8 specifies `LEFT(SOUNDEX(x),4)`. |
| `HubTestCase`'s transaction isolates each test | **False, and fixed** in `cf38efd`. Any DDL implicitly commits in MySQL, and Laravel's `causedByConcurrencyError()` matches "There is no active transaction" so `rollBack()` returns success having done nothing. `tearDown()` now sweeps unconditionally (24 ms; `TRUNCATE` measured 5,031 ms and is itself DDL). Plans 5 and 8 need no workaround. |
| `stg_ssn` is a column | **No** — it is a runtime-created index name (`SqlBackfill::indexStaging()`), covering `stg_person.ssn_hash` alongside the migration's `idx_ssn`. Both must be dropped before the column. Plan 2 gets this right in its text. |
| `has_active_exclusion` is lying | **No** — its formula is consistent across both materializers. It is *inert*, because `link_state` never leaves `candidate`. Fails safe; starts working when plan 6 lands. |
| "Exclusions are never deleted" is unimplemented | **Already holds, vacuously.** Neither `Engine::rollupExclusions()` nor `SqlBackfill::rollup()` ever deletes an exclusion; only credentials have a retire path. |
| A long org name breaks `AliasIndexer` | **Directionally right, mislocated.** Both alias columns are `varchar(100)`; the mismatch is `employee_additional_info.value` at `varchar(255)`, so it breaks at staging (`stg_person_alias.first_name`) first. Untriggered in dev, live in production. |
| `streamlineverify/sv` is a dependency | **False.** Declared as a repository, never required, never imported. `security` was the only private package and `e64f73d` removed it. |

---

## 9. Live bugs found while planning — actionable independently of any plan

Each verified against the running code or the live database. None was introduced by this programme;
all were found by looking closely enough to write a plan.

**`ProbabilisticResolver::sharesExclusionRegistry()` ignores its input record.**
```php
private function sharesExclusionRegistry(object $p, int $identityId): bool
{
    $regs = $this->hub()->table('gp_identity_exclusion')->where('identity_id', $identityId)
        ->whereNotNull('registry')->pluck('registry');
    return $regs->isNotEmpty();          // $p is never used
}
```
It is named "shares" and tests nothing of the kind: the `exclusion_share` weight (0.07) fires whenever
the *candidate* identity carries any exclusion at all. For individuals this inflates Pass B scores.
For entities it is worse, because plan 4 makes `auto_match` reachable — a pair could auto-merge on
name + address + zip plus *any* exclusion row, and an `auto_match` bind skips `logReview()`, so it
never surfaces for review. Owner: plan 5b or 6. Plan 4's claims about entity Pass B behaviour rest on
this leg working as named and must be restated or the leg fixed.

**Set-based `enrich()` inserts a duplicate row on every run when a unique-key part is NULL.**
MySQL treats NULLs as distinct inside a unique index, so `ON DUPLICATE KEY UPDATE` never fires.
Measured on `gp_cami_test`: three identical inserts into `gp_license` with a NULL
`certification_state` produced **3 rows**; the same with non-null values produced **1**. `gp_address`
behaves identically, and `uq_addr` has **four** nullable parts (`address1`, `city`, `state`, `zip`),
so any address missing a state or zip duplicates on every bulk run. Consequence: `license_count` and
`address_count` on `gp_identity_profile` are inflated in any hub that has been backfilled more than
once. Plan 3b fixes it in code with `<=>` in every key join and notes that `license_count` will
legitimately *fall*; a schema-level fix needs a `COALESCE` sentinel in the `current_key` and is
deferred to plan 5.

**`Engine::mergeIdentity()`'s version probe counts a refusal as a merge.** `return $after === $before
&& $before !== 0 ? 0 : 1;` — a null current-version lookup casts to `0`, so a refused merge returns 1
and `dedup()`'s `do { … } while ($round > 0)` can spin. Unreachable today because the `! $s || ! $l`
guard sits upstream, but the guard is upstream of `applyMerge`, not of the probe. Found by plan 4's
review agent.

**`HubTestCase`'s per-test transaction was not isolation — fixed in `cf38efd`.** Retained here
because the failure mode recurs: any DDL implicitly commits in MySQL, and Laravel's
`causedByConcurrencyError()` matches "There is no active transaction", so `rollBack()` returns
success having rolled back nothing.

---

## 10. Corrections plan 3b makes to plan 3a

3b read 3a closely and found three things wrong in it. Apply these when executing 3a.

- **3a's Task 2 expectation that `$ssn4` and `$term` need `AND current = 1` is wrong.** Both read
  `stg_person`, which 3a deliberately does not version, so the filter would be a fatal
  `Unknown column`. Only `$prim` needs it.
- **Byte-identical profile rows are not achievable for the JSON columns.** MySQL 8 has no `ORDER BY`
  inside `JSON_ARRAYAGG`, and the per-row path's order is equally unpinned, so the documented
  invariant narrows to a multiset comparison. Separately, four order-dependent *scalar* picks
  (primary address, the `dea_number` fallback, `terminated`, `ssn_last_four`) had a tiebreak on the
  set-based side and none on the per-row side; 3b fixes those so the invariant is true rather than
  lucky.
- **`uq_*_current` cannot enforce single-current when a key part is NULL**, because 3a's
  `current_key` uses NULL-propagating `CONCAT` on purpose. 3b closes it in code; the schema-level fix
  is deferred to plan 5.

And one verified fact that makes 3b's central design necessary: the hub's text columns are
`utf8mb4_unicode_ci`, so the **server** considers `'SMITH' = 'Smith'` true while PHP `===` considers
it false. Without `CAST(… AS BINARY)` on both sides of every comparison, the per-row path would
version a case change and the set-based path would not — and the canonical spelling would then be
frozen forever. Measured: `SELECT 'SMITH' = 'Smith'` returns 1; with `CAST(… AS BINARY)`, 0.
