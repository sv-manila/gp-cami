# GPP Conformance — SSN Removal Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development
> (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove every stored, matched-on and returned SSN-derived value from the gp-cami hub — the `ssn_hash` deterministic tier, the `ssn_hash`/`ssn_last_four` columns on `stg_person`/`gp_identity`/`gp_identity_profile`, `SsnHasher`, `SsnHashGuard` and the runtime blocklist table — so the Confluence Delivery Checklist §1 ("stream internal verified data via CDC, never store SSN") and the GPP Data Model's SSN-free golden layer become provable from the schema instead of asserted by policy.

**Architecture:** The removal runs in dependency order — resolution tiers first (per-row `DeterministicResolver`, then set-based `SqlBackfill` + `Engine::dedup`, which must stay in agreement), then the survivorship/materialize layer, then the API surface, then the support classes and their config, and only then the migration that drops the columns. Existing `gp_source_link` rows bound by `match_key = 'ssn_hash'` are **retained, not un-merged**: `DeterministicResolver::resolve()` short-circuits on an existing link for a source row before it ever consults a tier, so the live clustering is stable under `gp:sync` with no action at all, and un-merging would deliberately manufacture false splits — the error mode `docs/EVALUATION.md` names as the costlier of the two. What the removal does forfeit is *rebuild reproducibility*, and that is recorded explicitly with a measured count rather than left to be discovered.

**Tech Stack:** PHP ^8.3 (8.4.12 local), Laravel 13.20, PHPUnit 12.5, Laravel Pint, MySQL 8.

## Global Constraints

- Branch off the latest `feat/eval-harness` (or `master` once that has merged) as
  `feat/<slug>`. Do not push to `master`.
- `composer install` needs no credentials — every dependency is public packagist. `vendor/` already
  exists in the working checkout.
- **The test harness is MySQL, never SQLite.** The schema reuses index names across tables
  (`idx_identity` is on eight of them); MySQL scopes index names per table, SQLite per database, so
  `migrate` dies at the second `gp_*` table. Extend `Tests\Support\HubTestCase`.
- Tests must never touch the real hub. `phpunit.xml` points `GP_DB_*`/`SRC_DB_*` at `127.0.0.1:1`
  deliberately — do not remove or repoint those lines. `HubTestCase` reads a separate
  `GP_TEST_DB_*` set and skips when unset.
- `HubTestCase::setUp()` runs `migrate:fresh`, which DROPS EVERY TABLE in the target schema. Its
  guard requires a database name starting with `gp_` and containing `test`. Do not weaken it. The
  same MySQL server hosts `streamline_local`, `streamline_test`, `streamline_integration` and
  `admin_dash_sb`.
- Never edit a migration that has already run against the shared hub. New behaviour = new migration,
  prefixed `2026_09_*`.
- Style: `vendor/bin/pint --dirty` before every commit — never bare `vendor/bin/pint`, which
  reformats the whole repo. 4-space indent, plain PHP (no `declare(strict_types=1)`), explanatory
  docblocks that state the reason for a decision, not just the rule.
- Tests use `public function test_snake_case(): void`. There are NO PHPUnit attributes anywhere in
  `tests/`; do not introduce `#[Test]`.
- CI runs `vendor/bin/pint --test` and `vendor/bin/phpunit --fail-on-skipped`. A skipped test fails
  the build by design — the database-backed tests must actually run.
- Commit messages: `type(scope): imperative summary`.
- `sv-manila/gp-cami` is a PUBLIC repository. Never commit a key, token, credential or real
  person's data. The eval fixture is synthetic and must stay synthetic.

### Additional constraints specific to this plan

- **The scope decision is closed.** The user was shown the trade-off — the hub's strongest
  deterministic key against the Delivery Checklist — and chose *change the code to match the docs*.
  Do not propose keeping SSN, do not propose amending the Confluence page, and do not add a feature
  flag that keeps the tier switchable. A flag would leave the stored columns in place, so the
  compliance claim would still be false.
- **Never delete records from `tests/eval/identity-pairs.json` to keep a metric up.** The
  `ssn-a`/`ssn-b` pair becomes a permanent, accepted false split and stays in the fixture. Task 2
  enforces this with an assertion so it cannot be quietly undone later.
- **The two resolution paths must stay in agreement.** `DeterministicResolver` (per-row) and
  `SqlBackfill` (set-based) implement the same ladder twice. Task 2 changes the first, Task 3 the
  second, and Task 3 proves the agreement with a database test rather than by inspection.
- The migration that drops the columns is **Task 7, deliberately last**. Every earlier task leaves
  the suite green with the columns present but unused; dropping them earlier would break
  `HubTestCase::stagePerson()` and `EvalRunner` in the middle of the plan.
- `is_ssn_match` on `gp_identity_exclusion` / `src_match` is **not in scope and must not be touched**.
  It is a boolean recording *why CAMI's own exclusion matcher fired*, mirrored from
  `streamline_local.matches`. It holds no SSN and no SSN-derived value. Deleting it would destroy
  exclusion provenance for no compliance gain.

---

## Programme context — this is plan 2 of 8

| # | Plan | Depends on | Status |
|---|---|---|---|
| 1 | Foundation — eval set, scorer, resolver harness, CI | — | **DONE, merged into this branch** |
| **2** | **SSN removal** *(this document)* | **1** | **this plan** |
| 3 | SCD-2 versioning | 1 | to write |
| 4 | Individual vs entity | 1, 3 | to write |
| 5 | Match keys & data quality | 1 | to write |
| 6 | Steward writer layer | 1, 3 | to write |
| 7 | Exclusion lifecycle | 1, 3 | to write |
| 8 | Incremental profiling | 1, 5 | to write |

This plan sits immediately after the foundation because it is the only plan in the programme that
*removes* matching capability. Plan 1 exists precisely so that removal can be measured instead of
guessed: the eval gate currently scores 1.0/1.0, and this plan's entire risk profile is legible only
because that number exists to move. It sits before plans 3–8 because everything they touch —
versioned identity writes, entity keys, compensating match keys, steward decisions — would otherwise
have to be built twice, once with the SSN tier and once without.

The dependency that runs the other way is real, and it is handled as a sequencing rule rather than a
blocker: plan 5's compensating keys (MMIS as a resolve-time tier, `(state, provider#)`, name+state
blocking) are what limit fragmentation once the SSN tier is gone. Because this plan **retains
existing links** (Task 8), removing the tier fragments nothing at the moment it lands — fragmentation
is deferred to the next from-scratch rebuild. Task 1 measures the size of that deferred cost and sets
the threshold at which plan 5 must precede that rebuild.

---

## The three design decisions this plan commits to

Stated once, here, so the tasks can be read against them.

### 1. `ssn_last_four` goes, along with the full SSN

`ssn_last_four` is not the SSN, and the Delivery Checklist's words forbid only the SSN. It goes
anyway, for three reasons that compound:

- **The consumer already owns it.** CAMI is the system of record for
  `streamline_local.employees.social_security_num`. The only caller of these endpoints is CAMI. So
  the hub reads an SSN-derived value out of CAMI's database, stores it, and hands it back to CAMI —
  duplicating PII across a trust boundary for exactly zero information the caller did not already
  have.
- **Last-four is treated as an authenticator everywhere else.** Name + DOB + last-four is a
  standard knowledge-based-authentication triple, and `gp_identity_profile` already returns name and
  DOB. Keeping the third element makes the profile endpoint an identity-verification oracle, which is
  not what it is for.
- **It removes a live per-row/set-based divergence.** `ProfileMaterializer::ssnLastFour()` does
  `whereIn(...)->whereNotNull('ssn_last_four')->value(...)` with **no ORDER BY**, while
  `SetFinalizer`'s `$ssn4` subquery takes `ROW_NUMBER() OVER (… ORDER BY sp.stg_person_id ASC)`. For
  any identity whose linked staged rows carry two different last-fours, the two finalize paths can
  disagree — a real, present breach of the "rebuild produces a byte-identical profile" invariant the
  `link_id ASC` tiebreak in `Survivorship` was added to protect. Deleting the field deletes the
  divergence rather than fixing it.

Cost, stated: `IdentityProfileResource` and `CredentialSearchController`'s identity echo both lose the
field, so this is a **breaking response change** for the client-side consumers named in Task 9.

### 2. The `ssn` request parameter stays; only its identity-narrowing job goes

A supplied `ssn` does two unrelated jobs in `CredentialSearchController`, and only one of them
touches stored data:

| Job | Mechanism | Needs stored SSN? |
|---|---|---|
| Narrow which identity resolves | `whereIn('ssn_hash', $hasher->candidateHashes($ssn))` against `gp_identity_profile` | **Yes** — dies with this plan |
| Gate credential matches to the right person | `CredentialSelector::identityAgrees()` compares against `credential_matches.match->request_params.ssn`, read live from `streamline_local` per request | **No** — stores nothing, hashes nothing, needs no key |

Job 2 is the reason the parameter survives. Removing it would return one person's credential for
another person's request — a precision regression with no compliance benefit whatsoever, since the
value is never written to any hub table and never logged (`resolveIdentity()` logs only
`array_keys(array_filter([...]))`, i.e. *which* narrowers were supplied, never their values).

That still changes the published contract: the field's meaning narrows from "narrows resolution and
gates matches" to "gates matches only". Task 5 makes the change, Task 9 records and communicates it.
Task 5 also **widens the accepted format to a bare last-four**, because
`CredentialSelector::ssnParts()` already compares at whatever precision the two sides share — so
CAMI can stop sending a full SSN over the wire without losing the gate. A four-digit request against
a full-SSN payload degrades the comparison to last-four, which is the caller's explicit trade and is
documented in the request rules.

`CredentialIdentityGateTest` therefore needs **no surgery**: it exercises `CredentialSelector` alone,
never `SsnHasher` and never the hub. Its 16 tests all still describe live behaviour. (The plan brief
listed it as needing surgery on the assumption the gate was going; it is not.)

### 3. Existing merges are retained; the plan does not re-resolve

This is the hardest question in the plan. Dropping `gp_identity.ssn_hash` does not un-merge the
identities the tier built — those `gp_source_link` rows already exist with `match_key = 'ssn_hash'`.
Three options were considered:

**(a) Re-resolve the affected identities from scratch.** Rejected. The tier ran at 0.99 confidence
behind `SsnHashGuard`, which already excluded filler hashes by placeholder list *and* by cardinality
(any hash carried by more than 3 distinct upstream people). So a surviving `ssn_hash` merge is, by
construction, a **high-precision merge of two records that genuinely share an SSN** — it is the right
answer. Un-merging deliberately converts correct answers into false splits, and `docs/EVALUATION.md`
is explicit that a false split is how an excluded provider gets missed and costs more than a false
merge. Removing the *authority* to make a merge is a compliance requirement; retroactively undoing
merges already made is not, and nothing in the Delivery Checklist asks for it. It would also be
expensive: re-resolving means truncating and rebuilding `gp_source_link` for the affected identities,
which cascades through `dedup`, survivorship and profile materialization.

**(b) Leave them and say nothing.** Rejected — it hides the consequence in (c).

**(c) Retain them, and record the loss of reproducibility. Chosen.**

Why retention needs no code at all: `DeterministicResolver::resolve()` looks up
`gp_source_link` by `(system_id, source_table, source_id)` and returns `$existing->identity_id`
**before** `matchDeterministic()` is ever called. So every legacy link — SSN-bound or not — is
preserved by the incremental path for free, with no pinning and therefore without freezing
`enrich()` on those rows (`is_pinned` suppresses re-enrichment, so pinning would have been an
actively harmful way to achieve what idempotency already gives).

What is genuinely forfeited, and the honest cost of the whole plan:

> **The hub's current clustering is no longer reproducible from a from-scratch rebuild.** A future
> `gp:backfill` into an empty hub will produce *N* more identities than the hub holds today, where
> *N* = (source rows on `ssn_hash`-only identities) − (count of those identities). Task 1's new
> query 6 measures *N* before anything is removed.

On the "byte-identical profile" invariant: it is not violated. That invariant is about
`Survivorship` (per-row) and `SetFinalizer` (set-based) producing the same profile **from the same
set of links** — which is why the fix was a tiebreak-ordering agreement, not a resolution change.
Task 4 keeps both paths in step and Task 4's parity test pins it. Retaining historical links leaves
that invariant untouched; decision 1 above actually strengthens it by deleting the one field where
the two paths could already disagree.

The provenance of the retained bindings is written down rather than left implicit: Task 8's script
inserts one `gp_resolution_log` row per affected identity with `action = 'override'` (the enum's
member for "a binding the current matcher would not produce"), `match_key = 'ssn_hash'` and an actor
that marks it as a machine record, not a steward decision. A 13M-row rebuild is not free — roughly
the cost of the original backfill — and Task 8 states when it should be paid.

---

## File Structure

| File | Responsibility |
|---|---|
| `scripts/baseline-key-mix.sql` *(modify)* | Adds the query predicting how many extra identities a post-removal rebuild produces |
| `docs/EVALUATION.md` *(modify)* | Filled baseline table, rollout decision thresholds, re-baselined "Achieved" numbers, rebuild-reproducibility note |
| `app/GoldenProfile/Resolution/DeterministicResolver.php` *(modify)* | Per-row Pass A ladder — loses the `ssn_hash` tier, the guard, and the `ssn_hash` writes |
| `config/golden_profile.php` *(modify)* | Loses `deterministic_keys['ssn_hash']` (Task 2) and the whole `ssn` block (Task 6) |
| `tests/Feature/ResolverLadderTest.php` *(modify)* | The two SSN-tier tests become removal assertions |
| `tests/Unit/DeterministicKeyConfigTest.php` *(modify)* | Five tiers not six; new assertion that `ssn_hash` cannot come back |
| `tests/Feature/EvalGateTest.php` *(modify)* | Re-baselined ratchet, plus an assertion naming *which* pair splits |
| `tests/eval/identity-pairs.json` *(modify)* | `notes` relabel the `ssn-*` pair as an accepted false split; the records stay |
| `tests/Unit/EvalSetShapeTest.php` *(modify)* | Asserts the `ssn-*` records are still present and still one truth cluster |
| `app/GoldenProfile/SqlBackfill.php` *(modify)* | Set-based ladder — loses the tier, staging index, blocklist build, key rollup and INSERT columns |
| `app/GoldenProfile/Engine.php` *(modify)* | `dedup()` stops merging on `ssn_hash`; `applyMerge` stops inheriting it |
| `tests/Feature/SetBackfillParityTest.php` *(create)* | Proves the set-based path agrees with the per-row path on a shared hash |
| `app/GoldenProfile/Resolution/Survivorship.php` *(modify)* | `IDENTITY_FIELDS` loses `ssn_hash` |
| `app/GoldenProfile/Materialize/SetFinalizer.php` *(modify)* | `IDENTITY_FIELDS`, `IDENTITY_KEY_INDEXES`, the `ssn4` subquery and the profile INSERT lose SSN |
| `app/GoldenProfile/Materialize/ProfileMaterializer.php` *(modify)* | Stops writing `ssn_hash`/`ssn_last_four`; `ssnLastFour()` deleted |
| `app/GoldenProfile/Connectors/StreamlineLocalConnector.php` *(modify)* | Stops reading SSN from `streamline_local` at all |
| `tests/Feature/ProfileHasNoSsnTest.php` *(create)* | A rebuilt profile carries no SSN, and the two finalizers' field maps are identical |
| `app/Http/Controllers/Api/V1/CredentialSearchController.php` *(modify)* | Drops the `ssn_hash` narrower, the `SsnHasher` dependency and the `ssn_last_four` echo |
| `app/Http/Requests/CredentialSearchRequest.php` *(modify)* | `ssn` survives as a credential-gate input only, and now accepts a last-four |
| `app/Http/Resources/IdentityProfileResource.php` *(modify)* | Drops `ssn_last_four` from the response |
| `tests/Unit/CredentialSearchRequestRulesTest.php` *(create)* | Pins the narrowed `ssn` contract |
| `tests/Feature/CredentialLinkCapTest.php` *(modify)* | Stale "503 (SSN matching unavailable)" outcome comment corrected |
| `app/GoldenProfile/Support/SsnHasher.php` *(delete)* | — |
| `app/GoldenProfile/Support/SsnHashGuard.php` *(delete)* | — |
| `tests/Unit/SsnHasherTest.php` *(delete)* | — |
| `tests/Unit/SsnHashGuardTest.php` *(delete)* | — |
| `tests/Unit/NoSsnSupportRemainsTest.php` *(create)* | Source-level guard that the deleted classes and config stay deleted |
| `.env.example` *(modify)* | `GP_SSN_*` variables removed |
| `database/migrations/2026_09_03_000000_drop_ssn_columns.php` *(create)* | Drops the columns, their indexes, the runtime blocklist table, and the dead `gp_edge` enum member |
| `tests/Support/HubTestCase.php` *(modify)* | `stagePerson()` stops staging SSN columns |
| `app/GoldenProfile/Eval/EvalRunner.php` *(modify)* | Stops staging SSN columns |
| `tests/Feature/SsnColumnsDroppedTest.php` *(create)* | Asserts the schema has no SSN columns left |
| `scripts/retire-ssn-tier-provenance.sql` *(create)* | One `gp_resolution_log` row per identity whose binding is retained but no longer reproducible |
| `tests/Feature/RetireSsnProvenanceScriptTest.php` *(create)* | Runs that script against the scratch hub so it cannot be committed broken |
| `docs/RUNNING.md` *(modify)* | SSN note replaced with the removal record |
| `docs/PROVISIONING.md` *(modify)* | Shared-SSN-key provisioning step retired |
| `PROJECT_PLAN.md` *(modify)* | §2, §7 and §11 corrected; the contract change and its notification recorded |

---

## Task 1: Measure the blast radius and set the rollout threshold

`scripts/baseline-key-mix.sql` was written by plan 1 Task 1 and **has never been run** — nobody in
this environment has production hub credentials, so every row of `docs/EVALUATION.md`'s baseline
table still says `PENDING`. This task fills it and turns the number into a decision.

Two things must be kept apart, because `docs/EVALUATION.md` currently conflates them and its wording
gets corrected here:

- **The code changes are not blocked** by this measurement. Tasks 2–7 and 9 are local, tested, and
  land regardless — the Delivery Checklist requirement does not become conditional on a row count.
- **The hub rollout is blocked** by it: running the migration and Task 8's provenance script against
  the shared hub without knowing *N* means not knowing what the next rebuild will produce.

The existing script has five queries. It is missing the one number that actually predicts the future:
how many *extra identities* a from-scratch rebuild will mint once the tier is gone. Query 3 counts
the identities that lose their only binding evidence; the fragmentation is the number of source rows
those identities hold, minus the identities themselves.

**Files:**
- Modify: `scripts/baseline-key-mix.sql:36-39` (append query 6 after the blocklist query)
- Modify: `docs/EVALUATION.md:1-30` (the baseline table and its "Status: outstanding" block)

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: a committed markdown table in `docs/EVALUATION.md` containing `identities_bound_only_by_ssn`
  and `projected_extra_identities`, and a `## Plan 2 rollout decision` section whose threshold rule
  Task 7 Step 1 and Task 8 Step 1 both read before touching the shared hub.

- [ ] **Step 1: Add the projection query to the baseline script**

Append to `scripts/baseline-key-mix.sql`:

```sql

-- 6. The number that predicts the future: how many EXTRA identities a
--    from-scratch rebuild produces once the ssn_hash tier is gone.
--
--    Plan 2 retains existing links (see the plan's design decision 3), so
--    removing the tier fragments nothing at the moment it lands. The cost is
--    deferred to the next full rebuild, which will mint one identity per source
--    row for every identity that had no other binding evidence. That delta is
--    (rows on ssn-only identities) - (count of those identities).
--
--    Read alongside query 3: query 3 says how many identities change shape,
--    this says by how much. An identity bound only by ssn_hash but holding a
--    single source row contributes 0 — it was already effectively a singleton.
SELECT COUNT(*)               AS ssn_only_identities,
       COALESCE(SUM(links),0) AS ssn_only_links,
       COALESCE(SUM(links),0) - COUNT(*) AS projected_extra_identities
FROM (
    SELECT identity_id, COUNT(*) AS links
    FROM gp_source_link
    GROUP BY identity_id
    HAVING SUM(match_key <> 'ssn_hash') = 0
       AND SUM(match_key =  'ssn_hash') > 0
) t;
```

- [ ] **Step 2: Verify the SQL parses and runs against the scratch hub**

The scratch schema is empty, so every query returns zeros or an empty set — that is the point. This
catches a typo before the script is handed to someone with production credentials, which is the only
verification available here. `mysql` is not on PATH; use PDO.

Run:

```bash
php -r '
$db = getenv("GP_TEST_DB_DATABASE") ?: "gp_cami_test";
$h  = getenv("GP_TEST_DB_HOST") ?: "192.168.56.22";
$p  = new PDO("mysql:host=$h;dbname=$db", getenv("GP_TEST_DB_USERNAME") ?: "root", getenv("GP_TEST_DB_PASSWORD") ?: "root");
$sql = file_get_contents("scripts/baseline-key-mix.sql");
foreach (array_filter(array_map("trim", explode(";", $sql))) as $i => $q) {
    if (preg_match("/^(--|\s*$)/", $q) && ! preg_match("/select/i", $q)) { continue; }
    try { $p->query($q); echo "query ".($i+1).": OK\n"; }
    catch (Throwable $e) { echo "query ".($i+1).": ".$e->getMessage()."\n"; }
}'
```

Expected: `OK` for queries 1, 2, 3, 4 and 6. Query 5 reports
`Base table or view not found: ... gp_ssn_hash_blocklist` — that table is created ad-hoc by
`SsnHashGuard::buildBlocklistTable()` and no migration makes it, so a fresh schema has never had
it. That is a valid result, not a failure. Any other error is a typo to fix.

- [ ] **Step 3: Hand the script to someone with hub credentials**

This is a **human step** and the only one in the plan that cannot be executed here. The command:

```bash
mysql -h <gp-host> -u <gp-user> -p golden_profile < scripts/baseline-key-mix.sql
```

Record the six result sets. If the operator can only return screenshots, the six queries are already
shaped to fit one screen each. Do not stop the rest of the plan waiting for this — Tasks 2 to 7 and 9
do not read it. Only Task 7 Step 1 and Task 8 Step 1 do.

- [ ] **Step 4: Fill the baseline table and write the decision rule**

Replace `docs/EVALUATION.md` lines 1–30 (from `# Evaluating match quality` down to and including the
`**Status: outstanding.**` paragraph) with:

```markdown
# Evaluating match quality

## Baseline — before the GPP conformance programme

Measured `<DATE>` against the `golden_profile` hub with `scripts/baseline-key-mix.sql`.

| Metric | Value |
|---|---|
| Links by `ssn_hash` | `<FILL>` |
| Links by `npi` | `<FILL>` |
| Links by `name_dob` | `<FILL>` |
| Links by `license_registry` | `<FILL>` |
| Links by `probabilistic` | `<FILL>` |
| Links by `new` | `<FILL>` |
| Active identities | `<FILL>` |
| Identities carrying an `ssn_hash` | `<FILL>` |
| **Identities bound only by `ssn_hash`** | `<FILL>` |
| **At-risk identities** (multi-row, no other key) | `<FILL>` |
| **Projected extra identities after a rebuild** | `<FILL>` |
| Filler hashes on the blocklist | `<FILL>` |

**Reading this:** the three bold rows size what plan 2 costs. Plan 2 **retains** every existing
`gp_source_link` row, so nothing fragments when the tier is removed —
`DeterministicResolver::resolve()` returns an existing link's identity before it consults any tier,
so `gp:sync` keeps producing today's clustering indefinitely. The cost is deferred to the next
from-scratch rebuild, which will produce "projected extra identities" more identities than the hub
holds now. That number, not the link count, is the one to act on.

## Plan 2 rollout decision

`P` = projected extra identities ÷ active identities.

| `P` | Decision |
|---|---|
| < 0.5% | Proceed. Fragmentation on the next rebuild is noise; no sequencing change. |
| 0.5% – 5% | Proceed. Land plan 5's compensating keys (MMIS resolve-time tier, `(state, provider#)`, name+state blocking) **before the next full rebuild**, and schedule that rebuild after plan 5. |
| > 5% | Proceed, and treat the rebuild as gated: plan 5 becomes a prerequisite for it, and the programme order after plan 2 is re-cut to put plan 5 next. Say so at the design review. |

**Every row of that table says "proceed."** This is a compliance decision, not a performance one —
the Delivery Checklist §1 requirement does not become conditional on a row count, and the removal is
not up for renegotiation (the trade-off was put to the user and the answer was to change the code).
What `P` decides is *when the next full rebuild happens* and *what must land first*, because a
rebuild is the only event at which the deferred fragmentation is actually paid.

**If the measurement is still outstanding when the code is ready to merge:** merge the code and the
migration, and hold **Task 8's provenance script** — it is the only step whose output depends on the
number. Record the date the script was handed over and to whom.
```

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add scripts/baseline-key-mix.sql docs/EVALUATION.md
git commit -m "docs(eval): project post-removal fragmentation and set the plan 2 rollout threshold"
```

---

## Task 2: Retire the `ssn_hash` tier from the per-row resolver, and re-baseline the eval gate

**This is one commit, deliberately.** The moment the tier stops firing, `EvalGateTest`'s two ratchet
assertions fail — by design, that is what plan 1 built them for. Splitting the removal from the
re-baseline would leave CI red between two tasks, so both happen here and the task's exit criterion
is a fully green suite.

**Re-baselined onto plan 5's numbers, not plan 1's (`00-PROGRAMME.md` §2, §4).** `00-PROGRAMME.md`
fixes the canonical execution order as `1 → 5 → 3a → 3b → 2 → …`, so by the time this task runs, plan
5 has already raised the fixture to 11 true pairs (an MMIS pair and a DEA pair, both newly bindable)
with recall still 1.0. **The `0.8889` (8/9) figure below applies only if plan 2 runs before plan 5**;
under the canonical order the correct re-baseline is `0.9091` (10/11), on a fixture of 11 true pairs,
not 9.

The measured consequence, worked out in advance so the implementer knows what to expect rather than
what to accept: the fixture's predicted clusters become smith{a,b,c}, garcia{a,b}, kowalski{a,b},
chain{a,b,c}, the plan-5 MMIS and DEA pairs, and singletons — 10 predicted pairs, all correct.
`true_pairs` stays 11 (the `ssn-a`/`ssn-b` truth cluster is untouched), `true_positives` drops to 10.

| Metric | Before | After |
|---|---|---|
| `true_pairs` | 11 | 11 |
| `predicted_pairs` | 11 | 10 |
| `true_positives` | 11 | 10 |
| `false_merges` | 0 | 0 |
| `false_splits` | 0 | **1** |
| precision | 1.0 | 1.0 (10/10) |
| recall | 1.0 | **0.9091** (10/11) |
| f1 | 1.0 | **0.9524** (20/21) |

Both programme floors still hold — precision 1.0 ≥ 0.99, recall 0.9091 ≥ 0.80 — so only the two
ratchets move. They are re-baselined onto the **integer counts**, not the float, because 10/11 has no
exact decimal form and `assertSame(0.909…, …)` invites someone to loosen it later;
`assertSame(1, false_splits)` and `assertSame(10, true_positives)` are exact and, with the existing
`true_pairs >= 11`, pin numerator and denominator both.

One assertion is **added**, not just relaxed: `false_splits === 1` on its own would happily accept a
regression that split `garcia` while accidentally merging the `ssn` pair. The new assertion names
which two records are allowed to be apart.

**Files:**
- Modify: `app/GoldenProfile/Resolution/DeterministicResolver.php:5`, `:16-28`, `:126-139`, `:214-234`, `:236-261`
- Modify: `config/golden_profile.php:26-33`
- Modify: `tests/Feature/ResolverLadderTest.php:107-137`
- Modify: `tests/Unit/DeterministicKeyConfigTest.php:15-35`, `:37-49`
- Modify: `tests/Feature/EvalGateTest.php:11-50`
- Modify: `tests/eval/identity-pairs.json:3`, `:35-46`
- Modify: `tests/Unit/EvalSetShapeTest.php` (append two tests)
- Modify: `docs/EVALUATION.md` (the `## Achieved` section)

**Interfaces:**
- Consumes: `Tests\Support\HubTestCase` — `protected int $systemId`, `protected function hub()`,
  `protected function stagePerson(array $overrides = []): int`,
  `protected function stageLicense(int $stgPersonId, string $number, ?string $state = null): void`.
  `App\GoldenProfile\Eval\EvalRunner::__construct(int $systemId)` and
  `->run(EvalSet $set): array{clusters: list<list<string>>, report: array<string,mixed>}`.
- Produces: `DeterministicResolver::__construct(int $systemId)` and `resolve(int $stgPersonId): int`
  unchanged in signature; `matchDeterministic()` now has **five** tiers (npi, dea_number, upin,
  license_registry, name_dob) and no `SsnHashGuard` member. Task 3 mirrors this in `SqlBackfill`.

- [ ] **Step 1: Turn the two SSN-tier tests into removal assertions**

In `tests/Feature/ResolverLadderTest.php`, replace both SSN tests (lines 107–137, i.e.
`test_a_filler_ssn_hash_does_not_weld_unrelated_people_together` and
`test_two_rows_sharing_an_ssn_hash_bind_to_one_identity`) with:

```php
    public function test_a_shared_ssn_hash_no_longer_binds_two_rows(): void
    {
        // The inverse of the test this replaces. ssn_hash was the strongest key in
        // Pass A (0.99, exact, no name or DOB cross-check); the Delivery Checklist
        // §1 forbids the hub storing SSN at all, so the tier is gone and these two
        // records — same real person, different first names, only one DOB — are a
        // KNOWN, ACCEPTED false split. The eval gate carries the same pair and the
        // same expectation; see docs/EVALUATION.md.
        //
        // The column is still present at this point in the plan (the migration is
        // the last task), so staging a hash is still legal here. It simply has no
        // effect, which is exactly what this asserts.
        $hash = hash('sha512', 'resolver-ladder-test-distinct-ssn');

        $a = $this->stagePerson(['first_name' => 'Grace', 'last_name' => 'Adeyemi', 'date_of_birth' => '1979-05-14', 'ssn_hash' => $hash]);
        $b = $this->stagePerson(['first_name' => 'Gracie', 'last_name' => 'Adeyemi', 'date_of_birth' => null, 'ssn_hash' => $hash]);

        $this->assertNotSame(
            $this->resolve($a), $this->resolve($b),
            'the ssn_hash tier was removed by the GPP conformance programme — a shared hash must not bind',
        );
    }

    public function test_no_link_is_ever_recorded_with_an_ssn_hash_match_key(): void
    {
        // Guards the provenance side of the removal. Task 8 retains historical
        // links whose match_key is 'ssn_hash' as a record of what the hub used to
        // do; no NEW link may claim that key, or the retained rows stop being
        // distinguishable from fresh ones and the audit trail is worthless.
        $hash = hash('sha512', 'resolver-ladder-test-no-new-ssn-links');

        foreach ([['Ana', 'Reyes', '1980-01-01'], ['Ben', 'Cruz', '1975-02-02']] as [$f, $l, $d]) {
            $this->resolve($this->stagePerson([
                'first_name' => $f, 'last_name' => $l, 'date_of_birth' => $d, 'ssn_hash' => $hash,
            ]));
        }

        $this->assertSame(
            0,
            $this->hub()->table('gp_source_link')->where('match_key', 'ssn_hash')->count(),
            'resolution must never mint a new ssn_hash-keyed link',
        );
    }
```

- [ ] **Step 2: Run them and watch them fail**

Run: `vendor/bin/phpunit tests/Feature/ResolverLadderTest.php --filter=ssn_hash`

Expected: 2 failures.
- `test_a_shared_ssn_hash_no_longer_binds_two_rows` — `Failed asserting that two variables are not
  identical.` (the tier still binds them)
- `test_no_link_is_ever_recorded_with_an_ssn_hash_match_key` — `Failed asserting that 1 is identical
  to 0.` (the second row links on `ssn_hash`)

- [ ] **Step 3: Remove the tier from `DeterministicResolver`**

Four edits to `app/GoldenProfile/Resolution/DeterministicResolver.php`.

(a) Delete line 5, the import:

```php
use App\GoldenProfile\Support\SsnHashGuard;
```

(b) Replace lines 16–28 (the members and the constructor) with:

```php
    private ProbabilisticResolver $probabilistic;

    /** Bind confidence per key, from config instead of literals. */
    private array $keyConfidence;

    public function __construct(private int $systemId)
    {
        $this->probabilistic = new ProbabilisticResolver($systemId);
        $this->keyConfidence = config('golden_profile.deterministic_keys', []);
    }
```

(c) Replace lines 126–139 (the tier-ordering comment's SSN paragraph and the `ssn_hash` tier itself)
so that the comment keeps its still-true half and the tier is gone. The block from
`// Every key tier below adds orderBy('identity_id')` through the closing brace of the `ssn_hash`
`if` becomes:

```php
        // Every tier below adds orderBy('identity_id') before ->value(). Without it
        // the winner among several rows sharing a key is whatever storage order
        // returns, so the same source row could bind to different identities across
        // runs — and dedup()'s own docs acknowledge multiple active identities can
        // share a key before dedup runs. The set-based backfill already pins
        // MIN(identity_id); this makes the per-row path agree with it.
        //
        // There is no ssn_hash tier. It used to lead this ladder at 0.99 — an exact
        // match with no name or DOB cross-check, the strongest key the hub had — and
        // it was removed by the GPP conformance programme because the Delivery
        // Checklist §1 requires that the hub never store an SSN. npi now leads.
        // Historical links still carry match_key = 'ssn_hash'; they are retained
        // provenance, not something this method can reproduce.
        if ($p->npi) {
```

(d) In `createIdentity()` (was line 224) delete the line:

```php
            'ssn_hash' => $p->ssn_hash,
```

(e) Replace `backfillKeys()` (lines 236–261) with:

```php
    /** Backfill identity keys that were null when a later row supplies them. */
    private function backfillKeys(int $identityId, object $p): void
    {
        $id = $this->hub()->table('gp_identity')->where('identity_id', $identityId)->first();
        $upd = [];
        // ssn_hash was in this list, behind a SsnHashGuard screen that refused to
        // promote a filler hash onto an identity that lacked one. Both are gone:
        // there is no ssn_hash tier to hand a bogus 0.99 key to, and after the
        // Task 7 migration there is no column to write.
        foreach (['npi', 'upin', 'dea_number', 'canonical_dob'] as $col) {
            $srcCol = $col === 'canonical_dob' ? 'date_of_birth' : $col;
            if (empty($id->$col) && ! empty($p->$srcCol)) {
                $upd[$col] = $p->$srcCol;
            }
        }
        foreach (['canonical_first' => 'first_name', 'canonical_last' => 'last_name', 'canonical_middle' => 'middle_name'] as $col => $src) {
            if (empty($id->$col) && ! empty($p->$src)) {
                $upd[$col] = $p->$src;
            }
        }
        if ($upd) {
            $this->hub()->table('gp_identity')->where('identity_id', $identityId)->update($upd);
        }
    }
```

- [ ] **Step 4: Drop the tier's confidence from config**

In `config/golden_profile.php`, replace lines 26–33 with:

```php
    'deterministic_keys' => [
        // ssn_hash used to sit at the top of this list at 0.99. The GPP
        // conformance programme removed it — Delivery Checklist §1 requires the
        // hub never store an SSN, so there is nothing left to match on. Adding a
        // key back here does NOT create a tier: DeterministicResolver reads this
        // map for confidences only, and DeterministicKeyConfigTest asserts
        // 'ssn_hash' is absent so it cannot creep back as a no-op either.
        'npi' => 0.99,
        'dea_number' => 0.99,
        'upin' => 0.99,
        'license_number+certification_state' => 0.99,
        'name+dob' => 0.95,
    ],
```

- [ ] **Step 5: Run the ladder tests and watch them pass**

Run: `vendor/bin/phpunit tests/Feature/ResolverLadderTest.php`

Expected: PASS, 12 tests. (Was 13 — one filler-guard test was replaced by one no-new-links test and
one bind test was inverted, so the count drops by one because the filler test's premise no longer
exists.)

- [ ] **Step 6: Repair `DeterministicKeyConfigTest`**

Two of its five tests count six tiers. In `tests/Unit/DeterministicKeyConfigTest.php` replace lines
15–35 (`test_every_key_tier_has_a_configured_confidence` and
`test_name_dob_ranks_below_the_hard_identifier_tiers`) with:

```php
    public function test_every_key_tier_has_a_configured_confidence(): void
    {
        $keys = config('golden_profile.deterministic_keys');

        foreach (['npi', 'dea_number', 'upin', 'license_number+certification_state', 'name+dob'] as $tier) {
            $this->assertArrayHasKey($tier, $keys, "tier $tier has no configured confidence");
            $this->assertGreaterThan(0.0, $keys[$tier]);
            $this->assertLessThanOrEqual(1.0, $keys[$tier]);
        }
    }

    public function test_ssn_hash_is_not_a_configured_tier(): void
    {
        // The GPP conformance programme removed the ssn_hash tier because the
        // Delivery Checklist §1 forbids the hub storing an SSN. A confidence left
        // in this map would be inert — the resolver has no such tier to score —
        // but it would read as though the capability still existed, which is
        // exactly the kind of drift this file was written to stop.
        $this->assertArrayNotHasKey('ssn_hash', config('golden_profile.deterministic_keys'));
    }

    public function test_name_dob_ranks_below_the_hard_identifier_tiers(): void
    {
        $keys = config('golden_profile.deterministic_keys');

        // A shared common name plus a shared birthday is weaker evidence than a
        // shared NPI, DEA or UPIN; if that ordering inverts, the tier order is wrong.
        foreach (['npi', 'dea_number', 'upin'] as $strong) {
            $this->assertLessThan($keys[$strong], $keys['name+dob']);
        }
    }
```

Then in `test_resolver_reads_config_rather_than_literals` change the expected `confidence()` call
count from 6 to 5:

```php
        $this->assertSame(
            5,
            preg_match_all('/\$this->confidence\(/', $source),
            'each of the 5 deterministic tiers should take its confidence from config',
        );
```

Leave `test_every_deterministic_tier_orders_before_taking_a_value` alone — it counts `->value()`
call sites against ordered ones and both drop by one, so the equality still holds. Leave
`test_ssn_placeholder_guard_is_configured` alone for now; it reads
`golden_profile.ssn.placeholder_plaintexts`, which still exists until Task 6 deletes the block.

- [ ] **Step 7: Run the eval gate and record what it actually says**

Run: `vendor/bin/phpunit tests/Feature/EvalGateTest.php`

Expected: FAIL with
`regression against the measured baseline — precision 1.0000 recall 0.9091 f1 0.9524 — 0 false merge(s), 1 false split(s)`
(the `false_splits` assertion trips first; the recall assertion would follow).

**Write down the message verbatim.** If the numbers differ from the table at the head of this task,
stop and find out why before re-baselining — a different number means something other than the SSN
tier changed, and re-baselining onto it would launder a real regression into the ratchet.

- [ ] **Step 8: Re-baseline the gate onto the measured numbers**

Replace `tests/Feature/EvalGateTest.php` lines 11–50 (the whole test method) with:

```php
    public function test_the_resolver_clears_the_quality_gate_on_the_eval_set(): void
    {
        $set = EvalSet::load(base_path('tests/eval/identity-pairs.json'));
        $result = (new EvalRunner($this->systemId))->run($set);
        $report = $result['report'];

        $message = sprintf(
            'precision %.4f recall %.4f f1 %.4f — %d false merge(s), %d false split(s)',
            $report['precision'], $report['recall'], $report['f1'],
            $report['false_merges'], $report['false_splits']
        );

        // The eval set must not shrink. Deleting records raises every ratio for
        // free, so a floor on the metrics alone is not a regression net — this
        // pins the denominator. 11 true pairs = smith(3) + garcia(1) + kowalski(1)
        // + chain(3) + ssn(1) + mmis(1) + dea(1) — the last two added by plan 5,
        // which runs before this plan under 00-PROGRAMME.md §2. The ssn pair
        // stays in the set even though the matcher can no longer find it; see
        // the block below.
        $this->assertGreaterThanOrEqual(
            11, $report['true_pairs'],
            'the eval set shrank — pairs were removed, not the matcher improved'
        );

        // Precision-first per config golden_profile.tracks.identity. A false merge
        // welds two real providers together and nothing downstream undoes it, so
        // the gate on merges is absolute.
        $this->assertSame(0, $report['false_merges'], "false merges are never acceptable — $message");
        $this->assertGreaterThanOrEqual(0.99, $report['precision'], $message);

        // Recall floor sits below the PROJECT_PLAN 0.95 target: Pass B cannot
        // auto-merge today (its implemented weights sum to exactly auto_merge_at),
        // so the deterministic ladder alone sets the ceiling. Raise this as
        // calibration lands. Never lower it to make a build pass.
        $this->assertGreaterThanOrEqual(0.80, $report['recall'], $message);

        // RE-BASELINED by the GPP conformance programme's SSN removal (plan 2),
        // onto plan 5's fixture, not plan 1's — see 00-PROGRAMME.md §2 and §4.
        // Plan 5 runs first under the canonical order and raises true_pairs to
        // 11 (an mmis pair and a dea pair, both newly bindable) with recall
        // still 1.0. This task's own baseline is therefore 11, not 9.
        //
        // Was: assertSame(0, false_splits) and assertSame(1.0, recall), measured
        // against plan 5's 11-pair fixture. The ssn_hash tier was the only thing
        // binding ssn-a to ssn-b, so removing it turns that pair into a false
        // split: true_pairs 11 (unchanged), true_positives 11 -> 10, recall
        // 1.0 -> 10/11 (0.9091), f1 1.0 -> 20/21 (0.9524), false_splits 0 -> 1.
        // Precision stays 1.0 because the tier only ever produced correct
        // merges. (If this plan is ever run before plan 5 instead, the fixture
        // is still at 9 true pairs and the corresponding figures are
        // true_positives 8, recall 0.8889 (8/9), f1 0.9412 (16/17) — see the
        // note at the top of this task.)
        //
        // The ratchet is on the integer counts, not the float. 10/11 has no
        // exact decimal form, and a float ratchet is an invitation to widen the
        // tolerance later; these two are exact, and together with
        // true_pairs >= 11 above they pin the numerator and the denominator.
        $this->assertSame(1, $report['false_splits'], "regression against the measured baseline — $message");
        $this->assertSame(10, $report['true_positives'], "regression against the measured baseline — $message");

        // WHICH pair is allowed to be split. false_splits === 1 on its own would
        // accept a run that split garcia and simultaneously merged the ssn pair —
        // same count, two new defects. This names the accepted split.
        $clusterOf = function (string $ref) use ($result): array {
            foreach ($result['clusters'] as $cluster) {
                if (in_array($ref, $cluster, true)) {
                    return $cluster;
                }
            }

            return [];
        };

        $this->assertNotContains('ssn-b', $clusterOf('ssn-a'),
            'the ssn pair is the ONE accepted false split; it must be this pair and no other');
        foreach ([['smith-a', 'smith-b'], ['smith-a', 'smith-c'], ['garcia-a', 'garcia-b'],
            ['kowalski-a', 'kowalski-b'], ['chain-a', 'chain-b'], ['chain-a', 'chain-c']] as [$x, $y]) {
            $this->assertContains($y, $clusterOf($x), "$x and $y must still resolve together — $message");
        }
    }
```

- [ ] **Step 9: Relabel the fixture — the records stay**

The `ssn-a`/`ssn-b` records **remain in the fixture and remain one truth cluster**, permanently, as
the record of a capability the hub deliberately gave up. `docs/EVALUATION.md` forbids deleting them
and `true_pairs >= 11` exists to catch it; retiring them and cutting the denominator to 10 would make
the gate report a perfect 1.0 recall for a matcher that is measurably worse than yesterday's, which
is the exact failure mode plan 1 built the assertion to prevent.

In `tests/eval/identity-pairs.json`, replace the `notes` value on line 3 with (single line, as JSON
requires):

```
"notes": "Labeled identity pairs for gp-cami. `truth` lists ground-truth clusters: every ref in a cluster is the same real person. The ssn-* pair is a KNOWN, ACCEPTED FALSE SPLIT and must never be deleted. It was bound solely by the ssn_hash tier, which the GPP conformance programme removed (Delivery Checklist §1: the hub never stores an SSN), so the matcher can no longer find it — recall is 10/11 by design and EvalGateTest ratchets on exactly that. Deleting the pair would restore a perfect score for a matcher that is measurably worse, which is what the `true_pairs >= 11` assertion exists to catch; EvalSetShapeTest asserts both refs are still present in one cluster. The ssn_hash value is kept on the records as documentation of what the retired tier matched on — a synthetic sha512 of the literal string gp-cami-eval-fixture-synthetic-ssn-grace-adeyemi, deliberately NOT a filler value — but EvalRunner stops staging it once Task 7 drops the column. The nodob-a/nodob-b pair (same name, no DOB on either record) is kept apart in `truth` as a deliberate POLICY assertion — never merge on name alone without a DOB, no matter how suggestive the name match looks — not a conclusion drawn from the available evidence, so nobody should later 'correct' the answer key to merge them."
```

Leave `records` and `truth` byte-for-byte unchanged.

- [ ] **Step 10: Make the fixture guard executable**

Append to `tests/Unit/EvalSetShapeTest.php`:

```php
    public function test_the_retired_ssn_pair_is_still_in_the_set(): void
    {
        // The ssn_hash tier is gone and this pair is now an accepted false split.
        // Deleting the records would restore a perfect recall score for a strictly
        // worse matcher — the one thing docs/EVALUATION.md forbids outright. The
        // pair is the fixture's memory of a capability the hub gave up.
        $refs = array_column($this->set()->records(), 'ref');

        $this->assertContains('ssn-a', $refs);
        $this->assertContains('ssn-b', $refs);
    }

    public function test_the_retired_ssn_pair_is_still_one_truth_cluster(): void
    {
        // Splitting them in the ANSWER KEY would be the subtler way to make the
        // gate green: true_pairs drops to 10 and recall returns to 1.0 without a
        // single record being deleted. The answer key records who the same person
        // IS, which the matcher's ability to find them does not change.
        $clusters = array_filter(
            $this->set()->truthClusters(),
            fn ($c) => in_array('ssn-a', $c, true),
        );

        $this->assertCount(1, $clusters);
        $this->assertContains('ssn-b', reset($clusters));
    }
```

- [ ] **Step 11: Run the whole suite**

Run: `vendor/bin/phpunit`

Expected: PASS, 0 failures, 0 skipped. Test count moves from 92 to 93 (−1 ladder test, +1 ladder
test, +1 config test, +2 shape tests). Assertion count rises; the exact figure is whatever this run
reports — record it for the commit message.

- [ ] **Step 12: Update the "Achieved" section of `docs/EVALUATION.md`**

Replace the `## Achieved — measured on this branch` section (its table and the paragraph after it)
with:

```markdown
## Achieved — measured on this branch

Measured with `vendor/bin/phpunit tests/Feature/EvalGateTest.php` after the GPP conformance
programme's SSN removal (plan 2), executed in the canonical order (`00-PROGRAMME.md` §2), i.e. after
plan 5 has already raised the fixture from 9 to 11 true pairs.

| Metric | Before plan 2 (after plan 5) | After plan 2 |
|---|---|---|
| Precision | 1.0000 | 1.0000 |
| Recall | 1.0000 | **0.9091** (10/11) |
| F1 | 1.0000 | **0.9524** (20/21) |
| False merges | 0 | 0 |
| False splits | 0 | **1** |
| True pairs | 11 | 11 |
| True positives | 11 | **10** |
| Records | 17 + plan 5's mmis/dea records | same |
| Clusters | 10 + plan 5's new clusters | same |

**If plan 2 runs before plan 5 instead**, the fixture is still at plan 1's 9 true pairs and the
corresponding baseline is recall 0.8889 (8/9), f1 0.9412 (16/17), true_positives 8.
`00-PROGRAMME.md` §2 fixes the order as 5-before-2, so the table above is the one to build against.

**Why recall moved, and why that is correct.** The `ssn_hash` tier was the only evidence binding
`ssn-a` to `ssn-b`. Confluence Delivery Checklist §1 requires that the hub never store an SSN and the
GPP Data Model has no SSN column in the golden layer, so the tier and the columns behind it were
removed. That pair is now a **known, accepted false split** and stays in the fixture permanently.
Precision is unaffected: the tier only ever produced correct merges, so nothing it used to do was
wrong — the hub simply is not allowed to do it.

`EvalGateTest` ratchets on `false_splits === 1` and `true_positives === 10` — integer counts, because
10/11 has no exact decimal form and a float ratchet invites a widened tolerance. It additionally
asserts *which* pair may be split, so a future change cannot trade this split for a different one at
the same count. The floors (precision ≥ 0.99, recall ≥ 0.80) are untouched and were never at risk.

**The `ssn-*` records must not be deleted, and the answer key must not be re-cut to separate them.**
Either move would return the report to a perfect score for a strictly worse matcher. `true_pairs >= 11`
catches the first; `EvalSetShapeTest::test_the_retired_ssn_pair_is_still_one_truth_cluster` catches the
second.

**Plan 5 already supplied the compensating keys, before this task ran.** MMIS as a resolve-time tier
and DEA promoted alongside it (`00-PROGRAMME.md` §2's stated reason for ordering 5 before 2) are why
this task's baseline starts at 11 true pairs rather than 9 — not because either one recovers *this*
pair. Nothing but `ssn_hash` ever bound `ssn-a` to `ssn-b`, by fixture design, so no key plan 5 adds
recovers this specific split; the recall floor recovers overall, across the fixture, not for this
pair. There is no further "when plan 5 lands" step left for this task.
```

Also correct the closing paragraph of the file, which still speaks of plan 2 in the future tense.
Replace the final `**Plan 2 will move this floor.** …` paragraph with:

```markdown
**Plan 2 moved this baseline, not the floor.** The `ssn-a`/`ssn-b` pair was bound by the `ssn_hash`
tier; when the tier was removed the pair became a false split and recall fell to a known, measured
10/11 (against plan 5's already-raised 11-pair fixture; 8/9 only if plan 2 runs before plan 5 — see
`00-PROGRAMME.md` §2 and §4). The floors were never lowered — the two measured ratchets were
re-baselined onto exact integer counts and the records stayed in the fixture. That is the pattern for
any future capability removal: re-baseline the ratchet, keep the evidence, say so in the PR
description.
```

- [ ] **Step 13: Commit**

```bash
vendor/bin/pint --dirty
git add app/GoldenProfile/Resolution/DeterministicResolver.php config/golden_profile.php \
        tests/Feature/ResolverLadderTest.php tests/Unit/DeterministicKeyConfigTest.php \
        tests/Feature/EvalGateTest.php tests/eval/identity-pairs.json \
        tests/Unit/EvalSetShapeTest.php docs/EVALUATION.md
git commit -m "feat(resolution)!: retire the ssn_hash deterministic tier and re-baseline the eval gate

Delivery Checklist §1 requires the hub never store an SSN, and the GPP Data
Model has no SSN column in the golden layer. The ssn_hash tier was the hub's
strongest deterministic key (0.99, exact, no name or DOB cross-check); it is
removed, npi now leads the ladder.

Measured cost on tests/eval/identity-pairs.json: the ssn-a/ssn-b pair was
bound solely by this tier and is now a known, accepted false split.

  true_pairs      11 -> 11    (fixture unchanged; records NOT deleted; 11 is plan 5's
                               baseline, already raised from plan 1's 9 before this task ran)
  true_positives  11 -> 10
  false_splits    0 -> 1
  precision   1.0000 -> 1.0000
  recall      1.0000 -> 0.9091 (10/11)
  f1          1.0000 -> 0.9524 (20/21)

Both programme floors still hold (precision >= 0.99, recall >= 0.80). The two
measured ratchets are re-baselined onto exact integer counts rather than the
float, and a new assertion names WHICH pair may be split so the count cannot be
traded for a different defect. Recovering this recall is plan 5's job."
```

---

## Task 3: Retire the tier from the set-based path and from `dedup`

The ladder is implemented twice. Task 2 changed `DeterministicResolver` (per-row, used by `gp:sync`);
this changes `SqlBackfill::resolveDeterministic()` (set-based, used by `gp:backfill` on 13.4M rows)
and `Engine::dedup()`, which merges identities that share a key *after* resolution. Leaving either
behind would mean `gp:backfill` still built SSN-bound identities and `dedup` still folded together
any that survived — the divergence the authoring brief calls a live risk.

`Engine::dedup()` matters as much as the tier: `mergeByColumn('ssn_hash')` is a **second, independent**
route to an SSN merge. It groups active identities by a shared non-null `ssn_hash` and merges them
regardless of how they were resolved, so it would keep welding on SSN even with the tier gone.

This task is genuinely testable here, which is easy to miss: `new SqlBackfill` touches only the hub
(`ensureSystem()` on `gp_source_system`, then `new StreamlineLocalConnector(int $systemId)`, whose
constructor takes an int and opens no connection), and `resolveDeterministic()`, `indexStaging()` and
`Engine::dedup()` are hub-only as well. Only `stage()` reads `streamline_local`, and nothing here
calls it. So the set-based ladder can be exercised against `gp_cami_test` for the first time.

One wrinkle to plan for: `SqlBackfill::SYSTEM_CODE` is the literal `'streamline_local'` and
`ensureSystem()` inserts it, whereas `HubTestCase::seedSystem()` suffixes a `uniqid()`. The two
`system_id`s differ, so a parity test must stage its rows under **SqlBackfill's own** system id,
looked up from `gp_source_system` after construction.

**Files:**
- Modify: `app/GoldenProfile/SqlBackfill.php:6`, `:35`, `:38`, `:47`, `:97`, `:350-362`, `:425-440`, `:445-470`, `:478-515`, `:553-575`, `:594-600`
- Modify: `app/GoldenProfile/Engine.php:10`, `:35`, `:44`, `:249-256`, `:275`, `:296-315`, `:463-470`
- Create: `tests/Feature/SetBackfillParityTest.php`

**Interfaces:**
- Consumes: `Tests\Support\HubTestCase` (`$systemId`, `hub()`, `stagePerson()`);
  `DeterministicResolver::__construct(int $systemId)`, `->resolve(int $stgPersonId): int` — five
  tiers as of Task 2.
- Produces: `SqlBackfill::KEY_TIERS = ['npi', 'upin', 'dea_number']` (a `private const`, read by
  reflection in the test); `SqlBackfill::resolveDeterministic(?callable $log = null): void` and
  `indexStaging(?callable $log = null): void` unchanged in signature; `Engine::dedup(?callable
  $progress = null, int $shard = 0, int $shards = 1): int` unchanged in signature, no longer merging
  on `ssn_hash`; neither class has an `SsnHashGuard` member.

- [ ] **Step 1: Write the failing parity test**

Create `tests/Feature/SetBackfillParityTest.php`:

```php
<?php

namespace Tests\Feature;

use App\GoldenProfile\Engine;
use App\GoldenProfile\Resolution\DeterministicResolver;
use App\GoldenProfile\SqlBackfill;
use ReflectionClass;
use Tests\Support\HubTestCase;

/**
 * The deterministic ladder exists twice — DeterministicResolver (per row, used by
 * gp:sync) and SqlBackfill (set-based, used by gp:backfill over 13.4M rows). They
 * must agree, and the authoring brief records that a previous mismatch broke the
 * "rebuild produces a byte-identical profile" invariant. The SSN removal changes
 * matching semantics, so it has to change both.
 *
 * This is the first test to drive the set-based path. It can: `new SqlBackfill`
 * touches only the hub (ensureSystem, then a connector whose constructor takes an
 * int and opens nothing), and resolveDeterministic/indexStaging are hub-only too.
 * Only stage() reads streamline_local, and nothing here calls it.
 */
class SetBackfillParityTest extends HubTestCase
{
    /**
     * SqlBackfill::SYSTEM_CODE is the literal 'streamline_local' and ensureSystem()
     * inserts it; HubTestCase::seedSystem() suffixes a uniqid(). So the two system
     * ids differ and staged rows must use SqlBackfill's, not $this->systemId.
     */
    private function backfillSystemId(): int
    {
        return (int) $this->hub()->table('gp_source_system')
            ->where('system_code', SqlBackfill::SYSTEM_CODE)->value('system_id');
    }

    public function test_the_set_based_key_tiers_do_not_include_ssn_hash(): void
    {
        $tiers = (new ReflectionClass(SqlBackfill::class))
            ->getReflectionConstant('KEY_TIERS')->getValue();

        $this->assertNotContains('ssn_hash', $tiers,
            'the set-based ladder still has an ssn_hash tier — it must match DeterministicResolver');
        $this->assertSame(['npi', 'upin', 'dea_number'], $tiers);
    }

    public function test_the_set_based_path_does_not_bind_two_rows_sharing_an_ssn_hash(): void
    {
        $backfill = new SqlBackfill;
        $systemId = $this->backfillSystemId();
        $hash = hash('sha512', 'set-backfill-parity-distinct-ssn');

        // Same shape as ResolverLadderTest's per-row case: one real person, two
        // records, different first names, a DOB on only one of them. Nothing but a
        // shared ssn_hash could bind them.
        foreach ([['Grace', 'Adeyemi', '1979-05-14'], ['Gracie', 'Adeyemi', null]] as [$f, $l, $d]) {
            $this->stagePerson([
                'system_id' => $systemId, 'first_name' => $f, 'last_name' => $l,
                'date_of_birth' => $d, 'ssn_hash' => $hash,
            ]);
        }

        $backfill->resolveDeterministic();

        $identities = $this->hub()->table('gp_source_link')
            ->where('system_id', $systemId)->distinct()->pluck('identity_id');

        $this->assertCount(2, $identities,
            'the set-based tiers still bind on a shared ssn_hash');
        $this->assertSame(0, $this->hub()->table('gp_source_link')
            ->where('match_key', 'ssn_hash')->count(),
            'the set-based path must never mint a new ssn_hash-keyed link');
    }

    public function test_the_set_based_path_agrees_with_the_per_row_path_on_npi(): void
    {
        // The control. If the tiers were removed too enthusiastically, the case
        // above would pass for the wrong reason — this proves the ladder still
        // binds what it should, on both paths.
        $backfill = new SqlBackfill;
        $systemId = $this->backfillSystemId();

        foreach ([['Robert', 'Smith', '1970-04-02'], ['Bob', 'Smith', null]] as [$f, $l, $d]) {
            $this->stagePerson([
                'system_id' => $systemId, 'first_name' => $f, 'last_name' => $l,
                'date_of_birth' => $d, 'npi' => 1234567893,
            ]);
        }

        $backfill->resolveDeterministic();

        $setBased = $this->hub()->table('gp_source_link')
            ->where('system_id', $systemId)->distinct()->pluck('identity_id');

        $this->assertCount(1, $setBased, 'the set-based npi tier stopped binding');

        // And the per-row path, on its own system id so the two runs cannot
        // contaminate each other.
        $a = $this->stagePerson(['npi' => 1987654327, 'first_name' => 'Ada']);
        $b = $this->stagePerson(['npi' => 1987654327, 'first_name' => 'Adele', 'date_of_birth' => null]);
        $resolver = new DeterministicResolver($this->systemId);

        $this->assertSame($resolver->resolve($a), $resolver->resolve($b));
    }

    public function test_dedup_no_longer_merges_identities_that_share_an_ssn_hash(): void
    {
        // dedup is a SECOND route to an SSN merge, independent of the tier: it
        // groups active identities by a shared non-null column and folds them
        // together however they were resolved. Removing the tier alone would leave
        // this welding on SSN.
        $hash = hash('sha512', 'set-backfill-parity-dedup-ssn');
        $ids = [];

        foreach ([['Grace', 'Adeyemi', '1979-05-14'], ['Gracie', 'Adeyemi', '1981-11-02']] as [$f, $l, $d]) {
            $ids[] = (int) $this->hub()->table('gp_identity')->insertGetId([
                'identity_uuid' => (string) \Illuminate\Support\Str::uuid(),
                'canonical_first' => $f, 'canonical_last' => $l, 'canonical_dob' => $d,
                'ssn_hash' => $hash, 'confidence' => 1.0, 'record_count' => 0,
                'status' => 'active', 'first_seen' => now(), 'last_updated' => now(),
            ]);
        }

        (new Engine)->dedup();

        $stillActive = $this->hub()->table('gp_identity')
            ->whereIn('identity_id', $ids)->where('status', 'active')->count();

        $this->assertSame(2, $stillActive, 'dedup merged two identities on a shared ssn_hash');
    }

    public function test_staging_no_longer_indexes_the_ssn_hash_column(): void
    {
        // indexStaging adds indexes on the tier-key columns before transform so the
        // GROUP BY / NOT EXISTS become index lookups. An index on a column no tier
        // reads is maintenance cost on every one of 13.4M staging inserts, and the
        // Task 7 migration drops the column, which would then fail against a
        // leftover runtime index.
        (new SqlBackfill)->indexStaging();

        $this->assertNull($this->hub()->selectOne(
            'SELECT 1 FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1',
            ['stg_person', 'stg_ssn'],
        ), 'indexStaging still creates stg_ssn on stg_person.ssn_hash');
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `vendor/bin/phpunit tests/Feature/SetBackfillParityTest.php`

Expected: 5 failures.
- `test_the_set_based_key_tiers_do_not_include_ssn_hash` — `Failed asserting that an array does not
  contain 'ssn_hash'.`
- `test_the_set_based_path_does_not_bind_two_rows_sharing_an_ssn_hash` — `Failed asserting that
  actual size 1 matches expected size 2.`
- `test_the_set_based_path_agrees_with_the_per_row_path_on_npi` — PASSES already (it is the control;
  if it fails, Task 2 broke the npi tier and must be fixed first).
- `test_dedup_no_longer_merges_identities_that_share_an_ssn_hash` — `Failed asserting that 1 is
  identical to 2.`
- `test_staging_no_longer_indexes_the_ssn_hash_column` — `indexStaging still creates stg_ssn on
  stg_person.ssn_hash`.

So 4 failures and 1 pass. If the control fails, stop.

- [ ] **Step 3: Remove the tier from `SqlBackfill`**

Nine edits to `app/GoldenProfile/SqlBackfill.php`.

(a) Delete line 6: `use App\GoldenProfile\Support\SsnHashGuard;`

(b) Delete line 35: `    private SsnHashGuard $ssnGuard;` (and the blank line that followed it).

(c) Replace line 38 (`KEY_TIERS`) with:

```php
    /**
     * Single-column deterministic key tiers, in confidence order.
     *
     * ssn_hash used to lead this list. The GPP conformance programme removed it —
     * Delivery Checklist §1 requires the hub never store an SSN — so this list must
     * stay identical in content and order to the tiers in
     * DeterministicResolver::matchDeterministic(). SetBackfillParityTest pins it.
     */
    private const KEY_TIERS = ['npi', 'upin', 'dea_number'];
```

(d) Delete line 47 from the constructor: `        $this->ssnGuard = new SsnHashGuard;`

(e) In `indexStaging()`, delete line 97: `            'stg_ssn' => 'ssn_hash',`

(f) Replace the head of `resolveDeterministic()` (lines 350–362, from `$log ??=` through the
`foreach (self::KEY_TIERS ...)` line) with:

```php
        $log ??= fn ($p, $d) => null;

        // There is no ssn_hash tier and therefore no filler-SSN blocklist to build.
        // Both existed because ssn_hash was an exact 0.99 key with no name or DOB
        // cross-check, so every person carrying a placeholder SSN hashed to the same
        // value and the tier bound them all to one identity. The tier is gone
        // (Delivery Checklist §1), so the guard has nothing left to guard.

        // Single-column key tiers, highest confidence first. After each tier we
        // backfill identity keys from the just-linked rows so a later tier sees
        // an earlier identity's secondary keys (mirrors row-by-row backfillKeys)
        // — without this, set-based tiers mint duplicate identities.
        foreach (self::KEY_TIERS as $col) {
```

(g) In `backfillIdentityKeys()`, remove `ssn_hash` from both the derived-key subquery and the `SET`
clause. The statement becomes:

```php
        $this->hub()->statement(
            "UPDATE gp_identity i
             JOIN (
                 SELECT l.identity_id,
                        MAX(s.npi) npi, MAX(s.upin) upin, MAX(s.dea_number) dea_number,
                        MAX(s.date_of_birth) dob, MAX(s.first_name) fn, MAX(s.last_name) ln, MAX(s.middle_name) mn
                 FROM gp_source_link l
                 JOIN stg_person s ON s.system_id=l.system_id AND s.source_table=l.source_table AND s.source_id=l.source_id
                 GROUP BY l.identity_id
             ) k ON k.identity_id = i.identity_id
             SET i.npi=COALESCE(i.npi,k.npi),
                 i.upin=COALESCE(i.upin,k.upin), i.dea_number=COALESCE(i.dea_number,k.dea_number),
                 i.canonical_dob=COALESCE(i.canonical_dob,k.dob), i.canonical_first=COALESCE(i.canonical_first,k.fn),
                 i.canonical_last=COALESCE(i.canonical_last,k.ln), i.canonical_middle=COALESCE(i.canonical_middle,k.mn)
             WHERE i.status='active'",
            []
        );
```

(h) Replace `tierCreate()` in full — the `$guard` local and the `ssn_hash` column both go:

```php
    /** Create one identity per distinct new value of $col among unlinked rows. */
    private function tierCreate(string $col): void
    {
        $this->hub()->statement(
            "INSERT INTO gp_identity
                (identity_uuid, canonical_first, canonical_middle, canonical_last, canonical_dob,
                 npi, upin, dea_number, confidence, record_count, status, first_seen, last_updated)
             SELECT UUID(), r.first_name, r.middle_name, r.last_name, r.date_of_birth,
                 r.npi, r.upin, r.dea_number, 1.0, 0, 'active', NOW(), NOW()
             FROM stg_person r
             JOIN (
                 SELECT MIN(s.stg_person_id) mid
                 FROM stg_person s
                 LEFT JOIN gp_source_link l
                   ON l.system_id=s.system_id AND l.source_table=s.source_table AND l.source_id=s.source_id
                 LEFT JOIN (SELECT `$col` k FROM gp_identity WHERE status='active' AND `$col` IS NOT NULL GROUP BY `$col`) gi
                   ON gi.k = s.`$col`
                 WHERE s.system_id = ? AND s.`$col` IS NOT NULL
                   AND l.link_id IS NULL     -- not yet linked (anti-join)
                   AND gi.k IS NULL          -- no active identity has this key yet (anti-join)
                 GROUP BY s.`$col`
             ) f ON f.mid = r.stg_person_id",
            [$this->systemId]
        );
    }
```

(i) Replace `tierLink()` in full — only the `$guard` local and its interpolation go:

```php
    /** Link every unlinked row whose $col matches an active identity. */
    private function tierLink(string $col, string $keyName): void
    {
        $this->hub()->statement(
            "INSERT INTO gp_source_link
                (identity_id, system_id, source_table, source_id, account_id, employeelist_id,
                 match_method, match_key, match_score, match_state, is_pinned, linked_at)
             SELECT i.identity_id, s.system_id, s.source_table, s.source_id, s.account_id, s.employeelist_id,
                 'deterministic', ?, 0.99, 'auto_match', 0, NOW()
             FROM stg_person s
             JOIN (SELECT `$col` k, MIN(identity_id) identity_id FROM gp_identity
                   WHERE status='active' AND `$col` IS NOT NULL GROUP BY `$col`) i ON i.k = s.`$col`
             WHERE s.system_id = ? AND s.`$col` IS NOT NULL
               AND NOT EXISTS (SELECT 1 FROM gp_source_link l
                    WHERE l.system_id=s.system_id AND l.source_table=s.source_table AND l.source_id=s.source_id)",
            [$keyName, $this->systemId]
        );
    }
```

(j) In `nameDobCreateAndLink()`, drop `ssn_hash` from the `INSERT INTO gp_identity` column list and
`r.ssn_hash` from its `SELECT`. The two lines become:

```php
                (identity_uuid, canonical_first, canonical_middle, canonical_last, canonical_dob,
                 npi, upin, dea_number, confidence, record_count, status, first_seen, last_updated)
             SELECT UUID(), r.first_name, r.middle_name, r.last_name, r.date_of_birth,
                 r.npi, r.upin, r.dea_number, 1.0, 0, 'active', NOW(), NOW()
```

(k) In `residualCreateAndLink()`, the same two lines (note this one also carries `merged_into`):

```php
                (identity_uuid, canonical_first, canonical_middle, canonical_last, canonical_dob,
                 npi, upin, dea_number, confidence, record_count, status, merged_into, first_seen, last_updated)
             SELECT UUID(), s.first_name, s.middle_name, s.last_name, s.date_of_birth,
                 s.npi, s.upin, s.dea_number, 1.0, 0, 'active', s.stg_person_id, NOW(), NOW()
```

(l) Replace `IDENTITY_KEY_INDEXES` (line ~594) with:

```php
    /**
     * gp_identity key indexes, dropped during the residual bulk insert and rebuilt
     * after. Must stay identical to SetFinalizer::IDENTITY_KEY_INDEXES — both drop
     * and rebuild the same set, and a mismatch would leave one of them recreating
     * an index the other just dropped. idx_ssn is not here: the column goes in
     * the Task 7 migration.
     */
    private const IDENTITY_KEY_INDEXES = [
        'idx_npi' => 'npi',
        'idx_upin' => 'upin',
        'idx_dea' => 'dea_number',
        'idx_name_dob' => 'canonical_last, canonical_first, canonical_dob',
    ];
```

- [ ] **Step 4: Remove the SSN merge route from `Engine`**

Five edits to `app/GoldenProfile/Engine.php`.

(a) Delete line 10: `use App\GoldenProfile\Support\SsnHashGuard;`

(b) Delete line 35: `    private SsnHashGuard $ssnGuard;` and its blank line.

(c) Delete line 44 from the constructor: `        $this->ssnGuard = new SsnHashGuard;`

(d) In `dedup()`'s docblock, replace the opening sentence (line ~249) so it does not advertise a key
that no longer exists:

```php
    /**
     * Consolidate identities that share a deterministic key — npi, upin,
     * dea_number, license (number+state+board), identifier (DEA/MMIS), or
     * name+dob. ssn_hash was in this list and was a SECOND, independent route to
     * an SSN merge: it folded together any identities carrying the same hash
     * however they were resolved, so removing the resolution tier alone would have
     * left dedup still welding on SSN. Both are gone (Delivery Checklist §1).
     * Parallel
```

…keeping the rest of the existing docblock from `id-partitioned loading can mint…` unchanged.

(e) Replace line 275's column list and the guard screen inside `mergeByColumn()`. Line 275 becomes:

```php
            foreach (['npi', 'upin', 'dea_number'] as $col) {
```

and in `mergeByColumn()` delete the five-line screen:

```php
            // A filler ssn_hash is not evidence of shared identity. Resolution now
            // refuses to bind on one, but dedup would still fold together any
            // identities that already carry it — so screen here too.
            if ($col === 'ssn_hash' && $this->ssnGuard->isBlocked($val)) {
                continue;
            }
```

(f) In `applyMerge()` (line ~465), drop `ssn_hash` from the inherited-column list:

```php
        // ssn_hash was inherited here too. With the column gone (Task 7) a survivor
        // has nothing SSN-shaped left to inherit.
        foreach (['npi', 'upin', 'dea_number', 'canonical_dob',
            'canonical_first', 'canonical_last', 'canonical_middle'] as $c) {
```

- [ ] **Step 5: Run the parity test and watch it pass**

Run: `vendor/bin/phpunit tests/Feature/SetBackfillParityTest.php`

Expected: PASS, 5 tests.

- [ ] **Step 6: Run the whole suite**

Run: `vendor/bin/phpunit`

Expected: PASS, 0 failures, 0 skipped, 98 tests. The eval gate is unaffected — it drives the per-row
resolver, not `SqlBackfill`.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty
git add app/GoldenProfile/SqlBackfill.php app/GoldenProfile/Engine.php \
        tests/Feature/SetBackfillParityTest.php
git commit -m "feat(backfill)!: remove the ssn_hash tier from the set-based path and from dedup

Mirrors the per-row removal so the two ladders stay in agreement. SqlBackfill
loses the tier, the stg_ssn staging index, the filler-hash blocklist build, the
ssn_hash key rollup and the column from four INSERT...SELECT statements;
Engine::dedup stops merging on ssn_hash, which was a second and independent
route to an SSN merge that the tier removal alone would have left intact.

Adds tests/Feature/SetBackfillParityTest.php — the first test to drive the
set-based ladder. It can: new SqlBackfill touches only the hub, and only stage()
reads streamline_local."
```

---

## Task 4: Stop surviving, materializing and ingesting SSN

Resolution no longer matches on SSN, but four classes still carry it through the pipeline:
`Survivorship` and `SetFinalizer` both list `ssn_hash` in `IDENTITY_FIELDS` (so it is still chosen
per-field and written to `gp_attribute` and `gp_survivorship_audit`), both materializers still write
`ssn_hash` and `ssn_last_four` onto `gp_identity_profile`, and `StreamlineLocalConnector` still reads
both columns out of `streamline_local`. That last one is the actual compliance boundary: after this
task gp-cami never reads an SSN-derived value from CAMI's database at all, which is what "stream
internal verified data via CDC (never store SSN)" asks for.

This is where **decision 1** (drop `ssn_last_four`) is executed, and where it pays for itself. The
two finalize paths disagree on that field today:

| Path | How it picks `ssn_last_four` |
|---|---|
| `ProfileMaterializer::ssnLastFour()` | `whereIn(...)->whereNotNull('ssn_last_four')->value(...)` — **no ORDER BY** |
| `SetFinalizer`'s `$ssn4` subquery | `ROW_NUMBER() OVER (PARTITION BY identity_id ORDER BY sp.stg_person_id ASC)` |

For any identity whose linked staged rows carry two different last-fours, the per-row and set-based
finalizers can produce different profile rows — a present breach of the invariant the `link_id ASC`
tiebreak in `Survivorship` was added to protect. Deleting the field deletes the divergence outright;
there is no need to decide which ordering was correct.

The `IDENTITY_FIELDS` maps in the two classes are documented as "the same map" but nothing enforced
it. This task adds the enforcement, because both are edited here and a one-sided edit is exactly the
drift the brief warns about.

**Files:**
- Modify: `app/GoldenProfile/Resolution/Survivorship.php:26-37`
- Modify: `app/GoldenProfile/Materialize/SetFinalizer.php:28-39`, `:76-85`, `:137-146`, `:337-341`, `:346-390`
- Modify: `app/GoldenProfile/Materialize/ProfileMaterializer.php:119-131`, `:190-201`
- Modify: `app/GoldenProfile/Connectors/StreamlineLocalConnector.php:66-67`
- Create: `tests/Feature/ProfileHasNoSsnTest.php`

**Interfaces:**
- Consumes: `Tests\Support\HubTestCase` (`$systemId`, `hub()`, `stagePerson()`);
  `DeterministicResolver::resolve(int $stgPersonId): int`;
  `Survivorship::recompute(int $identityId): void`;
  `ProfileMaterializer::__construct(?AliasIndexer $aliasIndexer = null)` and
  `->rebuild(int $identityId): void`.
- Produces: `Survivorship::IDENTITY_FIELDS` and `SetFinalizer::IDENTITY_FIELDS` — both `private const
  array<string,string>`, now identical and SSN-free; `SetFinalizer::IDENTITY_KEY_INDEXES` matching
  `SqlBackfill::IDENTITY_KEY_INDEXES` from Task 3;
  `StreamlineLocalConnector::personRow(object $emp, ?array $accountMap = null): array` — same
  signature, two fewer keys. Task 7's migration depends on nothing here still writing the columns.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ProfileHasNoSsnTest.php`:

```php
<?php

namespace Tests\Feature;

use App\GoldenProfile\Materialize\ProfileMaterializer;
use App\GoldenProfile\Materialize\SetFinalizer;
use App\GoldenProfile\Resolution\DeterministicResolver;
use App\GoldenProfile\Resolution\Survivorship;
use ReflectionClass;
use Tests\Support\HubTestCase;

/**
 * The pipeline must not carry an SSN-derived value past staging, and the two
 * finalize paths must agree about which fields exist at all.
 *
 * On ssn_last_four specifically: it is not the SSN, and the Delivery Checklist
 * forbids only the SSN — but CAMI is the system of record for
 * employees.social_security_num AND the only caller of these endpoints, so the hub
 * read an SSN-derived value out of CAMI's own database, stored it, and handed it
 * back. That is PII duplicated across a trust boundary for no information the
 * caller lacked. It also removes a real divergence: ProfileMaterializer picked the
 * last four with no ORDER BY while SetFinalizer used ROW_NUMBER() ordered by
 * stg_person_id, so the two finalizers could disagree on the same identity.
 */
class ProfileHasNoSsnTest extends HubTestCase
{
    public function test_the_two_finalizers_declare_identical_identity_fields(): void
    {
        // Documented as "the same map as Survivorship" and never enforced. Both are
        // edited by this task; a one-sided edit is the drift that broke the
        // byte-identical-profile invariant once already.
        $perRow = (new ReflectionClass(Survivorship::class))
            ->getReflectionConstant('IDENTITY_FIELDS')->getValue();
        $setBased = (new ReflectionClass(SetFinalizer::class))
            ->getReflectionConstant('IDENTITY_FIELDS')->getValue();

        $this->assertSame($perRow, $setBased,
            'Survivorship and SetFinalizer must survive exactly the same fields, in the same order');
        $this->assertArrayNotHasKey('ssn_hash', $perRow);
    }

    public function test_the_finalizers_manage_the_same_identity_key_indexes(): void
    {
        // Both drop these before their bulk canonical writes and rebuild them
        // after. If the lists differ, one recreates an index the other dropped —
        // and idx_ssn must be in neither, since Task 7 drops the column.
        $setFinalizer = (new ReflectionClass(SetFinalizer::class))
            ->getReflectionConstant('IDENTITY_KEY_INDEXES')->getValue();
        $sqlBackfill = (new ReflectionClass(\App\GoldenProfile\SqlBackfill::class))
            ->getReflectionConstant('IDENTITY_KEY_INDEXES')->getValue();

        $this->assertSame($sqlBackfill, $setFinalizer);
        $this->assertArrayNotHasKey('idx_ssn', $setFinalizer);
    }

    public function test_a_rebuilt_profile_carries_no_ssn_value(): void
    {
        // The columns still exist at this point in the plan — the migration is
        // Task 7 — so this proves the WRITERS stopped, which is what has to be true
        // before the columns can safely go.
        $stg = $this->stagePerson([
            'first_name' => 'Grace', 'last_name' => 'Adeyemi', 'date_of_birth' => '1979-05-14',
            'ssn_hash' => hash('sha512', 'profile-has-no-ssn-test'), 'ssn_last_four' => '4321',
        ]);

        $identityId = (new DeterministicResolver($this->systemId))->resolve($stg);
        (new Survivorship)->recompute($identityId);
        (new ProfileMaterializer)->rebuild($identityId);

        $profile = $this->hub()->table('gp_identity_profile')
            ->where('identity_id', $identityId)->first();

        $this->assertNotNull($profile, 'the profile was not materialized at all');
        $this->assertSame('Adeyemi', $profile->last_name, 'the profile is materializing the wrong row');
        $this->assertNull($profile->ssn_hash, 'the materializer still writes ssn_hash');
        $this->assertNull($profile->ssn_last_four, 'the materializer still writes ssn_last_four');

        // gp_identity itself must not have been given the hash either.
        $this->assertNull(
            $this->hub()->table('gp_identity')->where('identity_id', $identityId)->value('ssn_hash'),
            'resolution or survivorship still writes gp_identity.ssn_hash',
        );
    }

    public function test_survivorship_records_no_ssn_provenance(): void
    {
        // Survivorship writes every candidate value to gp_attribute and the winner
        // to gp_survivorship_audit. An ssn_hash left in IDENTITY_FIELDS would keep
        // copying the hash into two more tables, neither of which the migration
        // touches — a quiet second store of the thing being removed.
        $stg = $this->stagePerson([
            'first_name' => 'Grace', 'last_name' => 'Adeyemi', 'date_of_birth' => '1979-05-14',
            'ssn_hash' => hash('sha512', 'profile-has-no-ssn-provenance'),
        ]);

        $identityId = (new DeterministicResolver($this->systemId))->resolve($stg);
        (new Survivorship)->recompute($identityId);

        $this->assertSame(0, $this->hub()->table('gp_attribute')
            ->where('identity_id', $identityId)->where('attr_name', 'ssn_hash')->count());
        $this->assertSame(0, $this->hub()->table('gp_survivorship_audit')
            ->where('identity_id', $identityId)->where('attribute_name', 'ssn_hash')->count());
    }

    public function test_the_connector_does_not_read_ssn_from_the_source(): void
    {
        // The compliance boundary. personRow() is a pure mapping (no DB write) and
        // takes the source row as an object, so it can be called with a stub. After
        // this task gp-cami never reads an SSN-derived column out of
        // streamline_local at all — which is what "never store SSN" requires, and
        // is stronger than merely not persisting it.
        $emp = (object) [
            'id' => 501, 'employeelist_id' => null, 'first_name' => 'Grace',
            'middle_name' => null, 'last_name' => 'Adeyemi', 'date_of_birth' => '1979-05-14',
            'ssn_hash' => hash('sha512', 'connector-must-not-read-this'),
            'ssn_last_four' => '4321', 'npi' => 0, 'upin' => null,
            'address1' => null, 'city' => null, 'state' => null, 'zip' => null,
            'terminated' => 0, 'date_modified' => '2026-08-01 00:00:00',
        ];

        $row = (new \App\GoldenProfile\Connectors\StreamlineLocalConnector($this->systemId))
            ->personRow($emp, []);

        $this->assertArrayNotHasKey('ssn_hash', $row);
        $this->assertArrayNotHasKey('ssn_last_four', $row);
        $this->assertSame('Adeyemi', $row['last_name'], 'the mapping itself broke');
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `vendor/bin/phpunit tests/Feature/ProfileHasNoSsnTest.php`

Expected: 5 failures.
- `..._identical_identity_fields` — the two maps match each other but both contain `ssn_hash`:
  `Failed asserting that an array does not have the key 'ssn_hash'.`
- `..._same_identity_key_indexes` — `Failed asserting that two arrays are identical.` (Task 3 already
  removed `idx_ssn` from `SqlBackfill`; `SetFinalizer` still has it.)
- `..._carries_no_ssn_value` — `the materializer still writes ssn_last_four` (`ssn_hash` on the
  profile is already null because Task 2 stopped `createIdentity()` writing it, but `Survivorship`
  will have put it back — so this may fail on `gp_identity.ssn_hash` instead; either message is the
  expected failure).
- `..._records_no_ssn_provenance` — `Failed asserting that 1 is identical to 0.`
- `..._does_not_read_ssn_from_the_source` — `Failed asserting that an array does not have the key
  'ssn_hash'.`

- [ ] **Step 3: Drop the field from `Survivorship`**

In `app/GoldenProfile/Resolution/Survivorship.php`, replace `IDENTITY_FIELDS` (lines 26–37) with:

```php
    /**
     * identity field <- staged column.
     *
     * Must stay identical, in content AND order, to
     * SetFinalizer::IDENTITY_FIELDS — the two classes are the per-row and
     * set-based halves of the same computation and ProfileHasNoSsnTest asserts
     * they agree. ssn_hash was the last entry; the GPP conformance programme
     * removed it (Delivery Checklist §1), which also stops the hash being copied
     * into gp_attribute and gp_survivorship_audit on every recompute.
     */
    private const IDENTITY_FIELDS = [
        'canonical_first' => 'first_name',
        'canonical_middle' => 'middle_name',
        'canonical_last' => 'last_name',
        'canonical_suffix' => 'name_suffix',
        'canonical_dob' => 'date_of_birth',
        'npi' => 'npi',
        'upin' => 'upin',
        'dea_number' => 'dea_number',
    ];
```

- [ ] **Step 4: Drop it from `SetFinalizer`**

Five edits to `app/GoldenProfile/Materialize/SetFinalizer.php`.

(a) Replace `IDENTITY_FIELDS` (lines 28–39) with:

```php
    /**
     * identity canonical column <= staged column.
     *
     * Must stay identical, in content AND order, to
     * Resolution\Survivorship::IDENTITY_FIELDS. ProfileHasNoSsnTest asserts it.
     */
    private const IDENTITY_FIELDS = [
        'canonical_first' => 'first_name',
        'canonical_middle' => 'middle_name',
        'canonical_last' => 'last_name',
        'canonical_suffix' => 'name_suffix',
        'canonical_dob' => 'date_of_birth',
        'npi' => 'npi',
        'upin' => 'upin',
        'dea_number' => 'dea_number',
    ];
```

(b) In `survivorship()`, the comment above `dropIdentityKeyIndexes()` names `ssn_hash` first.
Replace it with:

```php
        // The canonical UPDATEs below rewrite indexed columns (npi, upin,
        // dea_number, and canonical_last/first/dob via idx_name_dob) across
        // every identity, so each would maintain a secondary index row-by-row over
        // ~13M rows — the survivorship bottleneck. Drop the key indexes first and
        // rebuild once at the end (same trick resolveDeterministic uses for the
        // residual insert). dedup already ran (it needed them); nothing between
        // here and the rebuild needs them.
```

(c) Replace `IDENTITY_KEY_INDEXES` (lines 137–146) with:

```php
    /**
     * gp_identity key indexes — must stay identical to
     * SqlBackfill::IDENTITY_KEY_INDEXES. idx_ssn is gone with its column.
     */
    private const IDENTITY_KEY_INDEXES = [
        'idx_npi' => 'npi',
        'idx_upin' => 'upin',
        'idx_dea' => 'dea_number',
        'idx_name_dob' => 'canonical_last, canonical_first, canonical_dob',
    ];
```

(d) In `materialize()`, delete the whole `$ssn4` subquery definition (lines 337–341):

```php
        $ssn4 = "SELECT identity_id, ssn_last_four FROM (
                    SELECT l.identity_id, sp.ssn_last_four,
                        ROW_NUMBER() OVER (PARTITION BY l.identity_id ORDER BY sp.stg_person_id ASC) rn
                    FROM gp_source_link l JOIN stg_person sp ON $link
                    WHERE sp.ssn_last_four IS NOT NULL AND $rL ) t WHERE rn = 1";
```

(e) In the profile `INSERT`, remove the two columns from the column list, the two expressions from
the `SELECT`, and the join. The column-list line becomes:

```php
            (identity_id, identity_uuid, first_name, middle_name, last_name, suffix, date_of_birth,
             npi, upin, dea_number, identifier_count, identifiers,
```

the `SELECT` line becomes:

```php
            i.identity_id, i.identity_uuid, i.canonical_first, i.canonical_middle, i.canonical_last,
            i.canonical_suffix, i.canonical_dob, i.npi, i.upin,
```

and delete the join line entirely:

```php
        LEFT JOIN ($ssn4) ssn4   ON ssn4.identity_id = i.identity_id
```

- [ ] **Step 5: Drop it from `ProfileMaterializer`**

Two edits to `app/GoldenProfile/Materialize/ProfileMaterializer.php`.

(a) In the `updateOrInsert` payload, delete these two lines (were 127–128):

```php
                'ssn_hash' => $identity->ssn_hash,
                'ssn_last_four' => $this->ssnLastFour($stgIds),
```

(b) Delete the `ssnLastFour()` method entirely (lines 190–201):

```php
    private function ssnLastFour($stgIds): ?string
    {
        if ($stgIds->isEmpty()) {
            return null;
        }

        return $this->hub()->table('stg_person')->whereIn('stg_person_id', $stgIds)
            ->whereNotNull('ssn_last_four')->value('ssn_last_four');
    }
```

There is nothing to replace it with. Note for the record why it is not merely being fixed: it had no
`ORDER BY`, so on an identity with two different last-fours among its linked rows it returned an
arbitrary one while `SetFinalizer` returned the lowest-`stg_person_id` one. Deleting the field
resolves the disagreement without having to rule on which was right.

- [ ] **Step 6: Stop the connector reading SSN from the source**

In `app/GoldenProfile/Connectors/StreamlineLocalConnector.php::personRow()`, replace lines 66–67:

```php
            'ssn_hash' => $emp->ssn_hash ?: null,          // ingest as-is (global key)
            'ssn_last_four' => $emp->ssn_last_four ?: null,
```

with a comment in their place, so the omission reads as deliberate to the next person diffing this
mapping against `stg_person`'s columns:

```php
            // No ssn_hash / ssn_last_four. This mapping is the boundary at which
            // gp-cami stops reading SSN-derived data from streamline_local
            // entirely — Delivery Checklist §1, "stream internal verified data via
            // CDC (never store SSN)". The source columns still exist; the hub
            // simply never selects them. stage() does `select *`, so nothing else
            // needs changing to make that true.
```

- [ ] **Step 7: Run the test and watch it pass**

Run: `vendor/bin/phpunit tests/Feature/ProfileHasNoSsnTest.php`

Expected: PASS, 5 tests.

- [ ] **Step 8: Run the whole suite**

Run: `vendor/bin/phpunit`

Expected: PASS, 0 failures, 0 skipped, 103 tests.

- [ ] **Step 9: Commit**

```bash
vendor/bin/pint --dirty
git add app/GoldenProfile/Resolution/Survivorship.php \
        app/GoldenProfile/Materialize/SetFinalizer.php \
        app/GoldenProfile/Materialize/ProfileMaterializer.php \
        app/GoldenProfile/Connectors/StreamlineLocalConnector.php \
        tests/Feature/ProfileHasNoSsnTest.php
git commit -m "feat(profile)!: stop surviving, materializing and ingesting SSN

Removes ssn_hash from Survivorship::IDENTITY_FIELDS and
SetFinalizer::IDENTITY_FIELDS (which also stops the hash being copied into
gp_attribute and gp_survivorship_audit on every recompute), removes ssn_hash and
ssn_last_four from both profile writers, and stops StreamlineLocalConnector
reading either column out of streamline_local — the point at which the hub no
longer touches SSN-derived source data at all.

ssn_last_four goes too. It is not the SSN, but CAMI is both the system of record
for employees.social_security_num and the only caller, so the hub was
duplicating PII across a trust boundary for no information the caller lacked.
Dropping it also removes a live divergence between the two finalize paths:
ProfileMaterializer picked it with no ORDER BY, SetFinalizer with ROW_NUMBER()
ordered by stg_person_id, so the two could disagree on the same identity.

Adds a reflection test pinning the two IDENTITY_FIELDS maps (and the two
IDENTITY_KEY_INDEXES maps) as identical — documented as the same map and never
enforced until now."
```

---

## Task 5: Narrow the published API contract

This is the task with an audience outside the repo. `PROJECT_PLAN.md` §7 records the request contract
as *confirmed with CAMI on 2026-07-20*, and two of its properties change here. Task 9 writes the
change down and names who has to be told; this task makes it.

**What changes for a caller:**

| Surface | Before | After |
|---|---|---|
| `ssn` request param | Narrowed identity resolution (`whereIn('ssn_hash', …)`) **and** gated credential matches | Gates credential matches only |
| `ssn` accepted format | 9 digits, dashes/spaces optional | 9 digits **or a bare last-four** |
| `warnings[]` on the response | Could carry `ssn_not_used_for_identity_resolution` | Always `[]` — the key stays |
| `identity.ssn_last_four` (credential-search) | Returned | **Gone** |
| `ssn_last_four` (identity-search resource) | Returned | **Gone** |
| HTTP statuses | 200 / 404 / 409 | unchanged |

Three judgements inside that table, stated so a reviewer can disagree with the reasoning rather than
guess at it:

1. **The parameter survives** because its second job stores nothing. `CredentialSelector::identityAgrees()`
   compares the caller's value against `credential_matches.match->request_params.ssn`, extracted from
   the source payload at request time on a different server. No hub write, no hash, no key, and
   `resolveIdentity()` logs only which narrowers were *present*, never their values. Deleting the
   gate would hand one person's credential to a request about another — a precision regression with
   no compliance benefit.
2. **A bare last-four is now accepted** because `CredentialSelector::ssnParts()` already returns
   `['known'=>true,'full'=>null,'last4'=>…]` for a partial value and `identityAgrees()` already
   compares "at the strongest precision the pair has in common". So this needs no new logic — only a
   validation rule that stops rejecting the smaller input. It lets CAMI stop sending a full SSN over
   the wire; the cost is that a four-digit request against a full-SSN payload degrades to a
   last-four comparison, which is the caller's choice to make per request.
3. **The `warnings` key stays** even though nothing can populate it. It is part of the published
   response shape; removing it is a second breaking change for zero benefit, and a future warning
   then has somewhere to go.

`tests/Unit/CredentialIdentityGateTest.php` needs **no changes**. All 16 of its tests exercise
`CredentialSelector` alone — no `SsnHasher`, no hub, no controller. Every one still describes live
behaviour. Do not touch it.

**Files:**
- Modify: `app/Http/Controllers/Api/V1/CredentialSearchController.php:1-17`, `:25-81`, `:100-106`, `:145-153`
- Modify: `app/Http/Requests/CredentialSearchRequest.php:7-43`
- Modify: `app/Http/Resources/IdentityProfileResource.php:8-23`
- Modify: `tests/Feature/CredentialLinkCapTest.php:37-47`
- Create: `tests/Unit/CredentialSearchRequestRulesTest.php`

**Interfaces:**
- Consumes: `CredentialSelector::pick(Collection $links, bool $respectExpiry, string $today, ?string
  $ssn = null, ?string $dob = null): ?object` — unchanged, still called with the request's `ssn`.
- Produces: `CredentialSearchController::__construct()` — **no arguments** (was
  `__construct(private SsnHasher $ssnHasher)`); `__invoke(CredentialSearchRequest $request):
  JsonResponse` unchanged in signature; `CredentialSearchRequest::rules(): array` with an `ssn` rule
  accepting 9 digits or 4. Task 6 deletes `SsnHasher`, which is only possible once this task removes
  the injection.

- [ ] **Step 1: Write the failing contract test**

Create `tests/Unit/CredentialSearchRequestRulesTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Http\Requests\CredentialSearchRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * The credential-search request contract was confirmed with CAMI on 2026-07-20
 * (PROJECT_PLAN §7) and the GPP conformance programme narrows it. `ssn` survives,
 * because it does two unrelated jobs and only one of them touched stored data:
 *
 *   1. narrowing identity resolution via gp_identity_profile.ssn_hash — removed
 *      with the column;
 *   2. gating credential matches whose own scrape recorded an SSN, which compares
 *      against credential_matches.match->request_params.ssn read live from the
 *      source and needs no key, no hash and no hub write.
 *
 * Job 2 is why the field stays: dropping it would return one person's credential
 * for a request about another, with no compliance benefit at all since the value
 * is never persisted and never logged.
 *
 * A bare last-four is now accepted so CAMI can send less. CredentialSelector
 * already compares at whatever precision the two sides share, so this is a
 * validation change only.
 */
class CredentialSearchRequestRulesTest extends TestCase
{
    private function validate(array $payload): \Illuminate\Contracts\Validation\Validator
    {
        $request = new CredentialSearchRequest;

        return Validator::make($payload, $request->rules(), $request->messages());
    }

    private function base(array $extra = []): array
    {
        return array_merge([
            'registry' => 'CA-BRN', 'first_name' => 'Grace', 'last_name' => 'Adeyemi',
        ], $extra);
    }

    public function test_the_ssn_field_is_still_accepted(): void
    {
        // It gates credential matches. Removing it would be a precision regression
        // dressed up as a compliance win.
        foreach (['123456789', '123-45-6789', '123 45 6789'] as $shape) {
            $this->assertFalse($this->validate($this->base(['ssn' => $shape]))->fails(), $shape);
        }
    }

    public function test_a_bare_last_four_is_accepted(): void
    {
        // Lets CAMI stop putting a whole SSN on the wire. The gate still works:
        // CredentialSelector::ssnParts() reports a partial value as known-by-last4
        // and identityAgrees() compares at the shared precision.
        $this->assertFalse($this->validate($this->base(['ssn' => '6789']))->fails());
    }

    public function test_a_malformed_ssn_is_still_rejected(): void
    {
        // The shape check exists because a typo used to become a non-matching hash
        // instead of an error the caller could see. It still matters: a typo now
        // silently withholds every SSN-bearing credential match instead.
        foreach (['12345', '12345678', '1234567890', 'abcdefghi', '123-4-56789'] as $bad) {
            $v = $this->validate($this->base(['ssn' => $bad]));
            $this->assertTrue($v->fails(), $bad);
            $this->assertSame(
                'ssn must be 9 digits (optionally separated as 123-45-6789) or the last 4 digits.',
                $v->errors()->first('ssn'),
                $bad,
            );
        }
    }

    public function test_ssn_remains_optional(): void
    {
        $this->assertFalse($this->validate($this->base())->fails());
    }

    public function test_the_controller_takes_no_ssn_hasher(): void
    {
        // SsnHasher is deleted in the next task and this injection is the only
        // thing standing in the way. Asserted structurally rather than by
        // instantiating the controller, which would need the container.
        $ctor = (new \ReflectionClass(\App\Http\Controllers\Api\V1\CredentialSearchController::class))
            ->getConstructor();

        $this->assertTrue($ctor === null || $ctor->getNumberOfParameters() === 0,
            'the controller still depends on a hasher');
    }

    public function test_the_identity_profile_resource_emits_no_ssn_field(): void
    {
        // The resource is a flat array literal, so a source scan is exact here and
        // needs neither a database row nor a request. It never emitted ssn_hash;
        // ssn_last_four goes with this plan.
        $source = file_get_contents(app_path('Http/Resources/IdentityProfileResource.php'));

        $this->assertStringNotContainsString("'ssn_last_four'", $source);
        $this->assertStringNotContainsString("'ssn_hash'", $source);
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `vendor/bin/phpunit tests/Unit/CredentialSearchRequestRulesTest.php`

Expected: 4 failures, 2 passes.
- `test_a_bare_last_four_is_accepted` — fails; the current regex requires 9 digits.
- `test_a_malformed_ssn_is_still_rejected` — fails on the message text (currently `ssn must be 9
  digits, optionally separated as 123-45-6789.`).
- `test_the_controller_takes_no_ssn_hasher` — `the controller still depends on a hasher`.
- `test_the_identity_profile_resource_emits_no_ssn_field` — fails on `'ssn_last_four'`.
- `test_the_ssn_field_is_still_accepted` and `test_ssn_remains_optional` pass already.

- [ ] **Step 3: Narrow the request rules**

Replace `app/Http/Requests/CredentialSearchRequest.php` in full:

```php
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
```

- [ ] **Step 4: Remove the hub narrower from the controller**

Four edits to `app/Http/Controllers/Api/V1/CredentialSearchController.php`.

(a) Delete the import on line 7 (`use App\GoldenProfile\Support\SsnHasher;`) and the `Log` facade
import stays — it is still used for the multi-identity warning and the link-cap error.

(b) Replace lines 15–81 (the class opening through the end of `__invoke`) with:

```php
class CredentialSearchController extends Controller
{
    /**
     * POST /api/v1/credential-search
     * Resolve one person, return the latest qualifying credential match plus any
     * prior resolution. 404 if no identity resolves; 200 with match=null if the
     * person has no qualifying, unexpired credential.
     */
    public function __invoke(CredentialSearchRequest $request): JsonResponse
    {
        // A supplied SSN used to do two separate jobs. Only the second survives
        // the GPP conformance programme:
        //
        //   1. narrowing identity resolution, by matching
        //      gp_identity_profile.ssn_hash — REMOVED with the column, per
        //      Delivery Checklist §1 ("never store SSN");
        //   2. gating credential matches whose own scrape recorded an SSN, which
        //      compares against the value in the source payload and needs no key,
        //      no hash and no hub write. See latestQualifyingCredential() and
        //      CredentialSelector::identityAgrees().
        //
        // The controller used to warn when job 1 was unavailable because a
        // silently-dropped SSN filter would answer 200 with a possibly-different
        // person's data. There is nothing left to drop silently: job 1 no longer
        // exists for anyone, so its absence is the documented contract rather than
        // an environmental accident.
        //
        // `warnings` stays in the response shape. Nothing can populate it today,
        // but it is published, so removing it would be a second breaking change
        // for no benefit — and a future warning has somewhere to go.
        $warnings = [];

        $identity = $this->resolveIdentity($request);

        if (! $identity) {
            return response()->json([
                'match' => null,
                'prior_resolution' => null,
                'message' => 'No identity resolved for the given inputs.',
                'warnings' => $warnings,
            ], 404);
        }

        $match = $this->latestQualifyingCredential($identity, $request);
        $prior = $this->priorResolution($identity, $request);

        return response()->json([
            'identity' => [
                'identity_id' => (int) $identity->identity_id,
                'identity_uuid' => $identity->identity_uuid,
                'first_name' => $identity->first_name,
                'last_name' => $identity->last_name,
            ],
            'match' => $match,
            'prior_resolution' => $prior,
            'warnings' => $warnings,
        ], 200);
    }
```

Note what left the identity echo: `'ssn_last_four' => $identity->ssn_last_four`. That is the
breaking response change; Task 9 records it.

(c) In `resolveIdentity()`, delete the `ssn_hash` narrower (lines 100–106):

```php
        // Only when a key exists. candidateHashes() returns [] without one, and
        // whereIn('ssn_hash', []) matches NOTHING — so an unavailable key would
        // turn every SSN-bearing request into a 404 rather than simply not
        // narrowing. __invoke() has already recorded the warning for this case.
        if ($r->filled('ssn') && $this->ssnHasher->available()) {
            $q->whereIn('ssn_hash', $this->ssnHasher->candidateHashes($r->input('ssn')));
        }
```

Leave the `dob` and `license_number` narrowers, and leave the multi-identity `Log::warning` exactly
as it is — its `narrowers` list still reports whether an `ssn` was supplied, which is now the honest
statement that a narrower was *offered and not used for resolution*. Add one line of comment where
the block was removed so the omission is legible:

```php
        // No ssn narrower. gp_identity_profile has no ssn_hash column to match
        // against — see __invoke(). A supplied ssn still gates the credential
        // matches further down.
```

(d) At the end of `resolveIdentity()`, drop `ssn_last_four` from the hydrate `select` (line 152) and
update the comment above it, which lists the echoed columns:

```php
        // Only the columns this endpoint actually uses: the response echoes
        // identity_id/uuid/first_name/last_name, and the credential and
        // prior-resolution lookups key off identity_id. Selecting * here would
        // pull the JSON rollups too — identity 3 ("John Smith", 397,170 credential
        // links) carries a 69MB credentials blob and a 38MB exclusions blob, enough
        // to exhaust PHP's memory_limit on the hydrate alone.
        return GpIdentityProfile::query()
            ->select('identity_id', 'identity_uuid', 'first_name', 'last_name')
            ->find($matches[0]);
```

- [ ] **Step 5: Drop `ssn_last_four` from the identity-search resource**

In `app/Http/Resources/IdentityProfileResource.php`, replace the docblock and delete the field:

```php
/**
 * Shapes a gp_identity_profile row for the API.
 *
 * It never emitted an SSN or an ssn_hash — that was structural, not a filter, and
 * it stays structural. ssn_last_four was the one SSN-derived field it did return
 * and the GPP conformance programme removed it: CAMI is both the system of record
 * for employees.social_security_num and the only caller, so the hub was handing
 * back PII the caller already owned, for no information it lacked.
 */
class IdentityProfileResource extends JsonResource
{
```

and delete line 23:

```php
            'ssn_last_four' => $this->ssn_last_four,
```

- [ ] **Step 6: Correct the stale outcome list in `CredentialLinkCapTest`**

`test_409_is_distinguishable_from_every_other_outcome_of_this_endpoint` names a 503 the endpoint has
not returned since the warning mechanism replaced the refusal, and cannot return at all now. Replace
lines 37–47 with:

```php
    public function test_409_is_distinguishable_from_every_other_outcome_of_this_endpoint(): void
    {
        // credential-search has three distinct outcomes and a caller has to be able
        // to tell them apart: 200 (resolved, match possibly null), 404 (no identity
        // resolved), 409 (link cap). If any two collide, an integration cannot
        // react correctly.
        //
        // A 503 used to be listed here, from when a missing SSN hash key refused
        // the whole request. That became a 200-with-warning and then, with the SSN
        // removal, nothing at all — there is no key to be missing. 503 is kept in
        // the exclusion list anyway: it is the status a proxy or a downed source
        // connection produces, and 409 must not be confused with it either.
        $capStatus = (new TooManyCredentialLinksException(1, 1))->render()->getStatusCode();

        $this->assertNotContains($capStatus, [200, 404, 503]);
        $this->assertSame(409, $capStatus);
    }
```

- [ ] **Step 7: Run the test and watch it pass**

Run: `vendor/bin/phpunit tests/Unit/CredentialSearchRequestRulesTest.php tests/Unit/CredentialIdentityGateTest.php tests/Feature/CredentialLinkCapTest.php`

Expected: PASS. `CredentialIdentityGateTest` must pass **unmodified** — if it does not, the gate was
damaged and the controller change is wrong.

- [ ] **Step 8: Run the whole suite**

Run: `vendor/bin/phpunit`

Expected: PASS, 0 failures, 0 skipped, 109 tests.

- [ ] **Step 9: Commit**

```bash
vendor/bin/pint --dirty
git add app/Http/Controllers/Api/V1/CredentialSearchController.php \
        app/Http/Requests/CredentialSearchRequest.php \
        app/Http/Resources/IdentityProfileResource.php \
        tests/Feature/CredentialLinkCapTest.php \
        tests/Unit/CredentialSearchRequestRulesTest.php
git commit -m "feat(api)!: narrow the credential-search SSN contract and drop ssn_last_four

BREAKING for CAMI-side consumers. Three changes to a contract confirmed with
CAMI on 2026-07-20 (PROJECT_PLAN §7):

  * \`ssn\` no longer narrows identity resolution — gp_identity_profile has no
    ssn_hash column to match against. It still gates credential matches, which
    compares against the source payload live and stores nothing, so the field is
    KEPT rather than removed: dropping it would return one person's credential
    for a request about another, with no compliance benefit.
  * \`ssn\` now also accepts a bare last-four, so a caller can stop putting a
    whole SSN on the wire. CredentialSelector already compares at the shared
    precision; this is a validation change only.
  * \`identity.ssn_last_four\` and the identity-search resource's
    \`ssn_last_four\` are gone.

\`warnings\` stays in the response shape, always empty — it is published, and a
future warning needs somewhere to go. CredentialIdentityGateTest is unchanged
and still passes: it exercises CredentialSelector alone."
```

---

## Task 6: Delete `SsnHasher`, `SsnHashGuard`, their config and their env vars

Nothing references either class any more: Task 2 removed the guard from `DeterministicResolver`,
Task 3 from `SqlBackfill` and `Engine`, Task 5 removed the hasher from the controller. This task
deletes them, the `config/golden_profile.php` `ssn` block they read, and the `GP_SSN_*` variables in
`.env.example`.

`SsnHasher` was rewritten in `e64f73d` to replicate CAMI's `KeyManager`/`EncryptionKey::scopeForDataPoint()`
lookup inline, which dropped the private `streamlineverify/security` dependency. **That work is not
wasted and is worth saying so in the commit message**: it is precisely what makes this deletion a
purely local change. Had the dependency still been there, removing it would have meant a
`composer.json` change, a CI credentials story and a coordinated release. Instead the file just goes.

Two deletions here need care rather than a `rm`:

- **`gp_ssn_hash_blocklist`** is created at runtime by `SsnHashGuard::buildBlocklistTable()` with a
  `CREATE TABLE IF NOT EXISTS`. There is no migration for it, so it exists in any hub where
  `gp:backfill` has run since the guard was added and nowhere else. Deleting the class orphans the
  table; Task 7's migration drops it.
- **`config('golden_profile.ssn')`** carries the measured filler-SSN figures (17 hashes over 9,164
  distinct people, worst single hash on 9,072 of them). Those numbers are the evidence for why the
  guard existed and are worth keeping somewhere — Task 9 moves them into `docs/RUNNING.md`'s removal
  record rather than letting them vanish with the block.

**Files:**
- Delete: `app/GoldenProfile/Support/SsnHasher.php`
- Delete: `app/GoldenProfile/Support/SsnHashGuard.php`
- Delete: `tests/Unit/SsnHasherTest.php`
- Delete: `tests/Unit/SsnHashGuardTest.php`
- Modify: `config/golden_profile.php:108-179` (the comment banner and the whole `ssn` block)
- Modify: `tests/Unit/DeterministicKeyConfigTest.php` (delete `test_ssn_placeholder_guard_is_configured`)
- Modify: `.env.example:44-58`
- Create: `tests/Unit/NoSsnSupportRemainsTest.php`

**Interfaces:**
- Consumes: nothing — this task only removes.
- Produces: no `App\GoldenProfile\Support\SsnHasher` or `SsnHashGuard` class exists; no
  `golden_profile.ssn` config key exists. Task 7's migration and Task 9's docs both assume this.

- [ ] **Step 1: Write the failing regression guard**

Create `tests/Unit/NoSsnSupportRemainsTest.php`:

```php
<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * A removal is only done when it cannot quietly come back. These assertions are
 * cheap and they fail loudly the first time someone reintroduces SSN support by
 * copying an old file back in or restoring a config block from git history.
 *
 * Structural, not behavioural, on purpose: there is no behaviour left to test.
 * The Delivery Checklist §1 requirement ("stream internal verified data via CDC,
 * never store SSN") is a statement about what the codebase does NOT contain, so
 * that is what is asserted.
 */
class NoSsnSupportRemainsTest extends TestCase
{
    public function test_the_ssn_support_classes_are_gone(): void
    {
        $this->assertFalse(class_exists(\App\GoldenProfile\Support\SsnHasher::class));
        $this->assertFalse(class_exists(\App\GoldenProfile\Support\SsnHashGuard::class));
        $this->assertFileDoesNotExist(app_path('GoldenProfile/Support/SsnHasher.php'));
        $this->assertFileDoesNotExist(app_path('GoldenProfile/Support/SsnHashGuard.php'));
    }

    public function test_the_ssn_config_block_is_gone(): void
    {
        // The block held the plaintext key paths, the placeholder-SSN list and the
        // cardinality cap. All three only meant something to the guard.
        $this->assertNull(config('golden_profile.ssn'));
    }

    /**
     * The classes that read or write stg_person / gp_identity /
     * gp_identity_profile. A leftover column reference in any of them would break
     * at runtime the moment the migration drops the columns — and it would break
     * inside a 13M-row backfill rather than in CI.
     *
     * EvalRunner is deliberately absent and is added by the migration's own
     * commit: it legitimately still stages ssn_hash while the column exists, so
     * listing it here early would just make the build red for a task.
     *
     * @return list<string>
     */
    private function pipelineFiles(): array
    {
        return [
            'GoldenProfile/Resolution/DeterministicResolver.php',
            'GoldenProfile/Resolution/Survivorship.php',
            'GoldenProfile/Materialize/SetFinalizer.php',
            'GoldenProfile/Materialize/ProfileMaterializer.php',
            'GoldenProfile/Connectors/StreamlineLocalConnector.php',
            'GoldenProfile/SqlBackfill.php',
            'GoldenProfile/Engine.php',
            'Http/Controllers/Api/V1/CredentialSearchController.php',
            'Http/Resources/IdentityProfileResource.php',
        ];
    }

    public function test_no_class_in_the_pipeline_still_carries_an_ssn_column(): void
    {
        foreach ($this->pipelineFiles() as $file) {
            // Comments are stripped first: several of these files explain WHY the
            // columns are absent, and that prose is the point — it must not be
            // what trips the assertion.
            $code = implode("\n", array_filter(
                array_map('trim', file(app_path($file))),
                fn ($line) => ! str_starts_with($line, '//')
                    && ! str_starts_with($line, '*')
                    && ! str_starts_with($line, '/*'),
            ));

            $this->assertStringNotContainsString('ssn_hash', $code, "$file still references ssn_hash");
            $this->assertStringNotContainsString('ssn_last_four', $code, "$file still references ssn_last_four");
        }
    }

    public function test_no_gp_ssn_environment_variable_is_documented(): void
    {
        // .env.example is the contract for a new developer's environment. A
        // GP_SSN_* line there would have them chase a key nothing reads, and — in
        // a public repo — invite someone to paste a real one in.
        $this->assertStringNotContainsString('GP_SSN_', file_get_contents(base_path('.env.example')));
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `vendor/bin/phpunit tests/Unit/NoSsnSupportRemainsTest.php`

Expected: 3 failures, 1 pass.
- `test_the_ssn_support_classes_are_gone` — `Failed asserting that false is true` on `class_exists`.
- `test_the_ssn_config_block_is_gone` — `Failed asserting that Array … is null.`
- `test_no_gp_ssn_environment_variable_is_documented` — `Failed asserting that '…GP_SSN_…' does not
  contain "GP_SSN_".`
- `test_no_class_in_the_pipeline_still_carries_an_ssn_column` — **passes already**, because Tasks 2–5
  cleaned every file in `pipelineFiles()`. It is written here rather than earlier so the guard exists
  in one place; Task 7 extends its list with `EvalRunner` once the column is gone.

- [ ] **Step 3: Delete the classes and their tests**

```bash
git rm app/GoldenProfile/Support/SsnHasher.php \
       app/GoldenProfile/Support/SsnHashGuard.php \
       tests/Unit/SsnHasherTest.php \
       tests/Unit/SsnHashGuardTest.php
```

`SsnHashGuardTest` also takes with it the PHPUnit notice the authoring brief records ("mock without
expectations") — `createMock(SsnHasher::class)` with a `method()->willReturn()` and no expectation.
Test output gets marginally more pristine as a side effect.

- [ ] **Step 4: Remove the config block**

In `config/golden_profile.php`, delete the comment banner and the entire `'ssn' => [...]` array
(lines 108–179, from `    /*` above `| SSN handling.` through the closing `],`). Replace all of it
with a single tombstone comment, so the next reader diffing this file against `stg_person`'s columns
knows the omission is intentional and where the reasoning went:

```php
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
```

- [ ] **Step 5: Drop the config test that reads the block**

In `tests/Unit/DeterministicKeyConfigTest.php`, delete
`test_ssn_placeholder_guard_is_configured` in full (lines 74–82):

```php
    public function test_ssn_placeholder_guard_is_configured(): void
    {
        $placeholders = config('golden_profile.ssn.placeholder_plaintexts');

        $this->assertIsArray($placeholders);
        $this->assertNotEmpty($placeholders, 'an empty filler list disables the exact half of the guard');
        $this->assertContains('000000000', $placeholders);
        $this->assertGreaterThanOrEqual(1, (int) config('golden_profile.ssn.max_identities_per_hash'));
    }
```

`NoSsnSupportRemainsTest::test_the_ssn_config_block_is_gone` is its replacement — the same file
asserting the opposite fact.

- [ ] **Step 6: Remove the env vars**

In `.env.example`, delete lines 44–58 — the `# ---- shared SSN encryption (streamlineverify/security) ----`
banner, `GP_SSN_ENCRYPTION_KEY_ID`, the `GP_SSN_PLAINTEXT_KEY` block and the
`GP_SSN_LOCAL_MANAGER_KEY` block — leaving the `SRC_DB_CONNECT_TIMEOUT` line above and
`SESSION_DRIVER` below adjacent with one blank line between them.

Nothing replaces them. A developer whose own `.env` still carries the three variables is unaffected:
no code reads them, and they are not on the `GP_TEST_DB_*` path the harness uses.

- [ ] **Step 7: Run the guard and watch it pass**

Run: `vendor/bin/phpunit tests/Unit/NoSsnSupportRemainsTest.php`

Expected: PASS, 4 tests. Then confirm nothing anywhere still names a deleted symbol:

```bash
grep -rn "SsnHasher\|SsnHashGuard\|golden_profile\.ssn" --include=*.php app/ tests/ config/ database/
```

Expected output: nothing.

- [ ] **Step 8: Run the whole suite**

Run: `vendor/bin/phpunit`

Expected: PASS, 0 failures, 0 skipped. Test count moves to roughly 103 (−9 `SsnHasherTest`/
`SsnHashGuardTest` cases, −1 config case, +4 guard cases); take the exact number from the run.

- [ ] **Step 9: Commit**

```bash
vendor/bin/pint --dirty
git add -A app/GoldenProfile/Support config/golden_profile.php .env.example \
        tests/Unit/DeterministicKeyConfigTest.php tests/Unit/NoSsnSupportRemainsTest.php \
        tests/Unit/SsnHasherTest.php tests/Unit/SsnHashGuardTest.php
git commit -m "refactor(support)!: delete SsnHasher, SsnHashGuard and the ssn config block

Nothing referenced either class after the tier removals. Also removes the
golden_profile.ssn config block (plaintext key paths, the placeholder-SSN list
and the cardinality cap — all of which only meant something to the guard) and
the GP_SSN_* variables from .env.example.

Commit e64f73d's rewrite of SsnHasher is what makes this purely local: it
replaced the private streamlineverify/security dependency by replicating CAMI's
KeyManager lookup inline, so deleting the file needs no composer.json change, no
CI credentials story and no coordinated release. The file goes; the reason it
was cheap to remove was earned.

Adds tests/Unit/NoSsnSupportRemainsTest.php as the standing regression guard: the
classes are gone, the config key is gone, no pipeline class names either column,
and .env.example documents no GP_SSN_* variable. Its pipeline list deliberately
omits EvalRunner, which still stages ssn_hash while the column exists; the
migration commit adds it."
```

---

## Task 7: Drop the columns

Last, because every earlier task left the suite green with the columns present but unused, and
because `HubTestCase::setUp()` runs `migrate:fresh` — so the moment this migration exists, the test
schema has no SSN columns and anything still staging them breaks. `HubTestCase::stagePerson()` and
`EvalRunner` both do, and both are fixed in this same commit.

**Never edit `2026_07_20_140000_create_golden_profile_schema.php`.** It has run against the shared
hub. New behaviour is a new migration.

What has to go, and where the non-obvious pieces are:

| Object | Where it came from |
|---|---|
| `stg_person.ssn_hash`, `.ssn_last_four` | the schema migration |
| `stg_person` index `idx_ssn` | the schema migration |
| `stg_person` index `stg_ssn` | **runtime** — `SqlBackfill::indexStaging()` added it with an ad-hoc `ALTER`, so it exists only in hubs that have been backfilled |
| `gp_identity.ssn_hash`, index `idx_ssn` | the schema migration |
| `gp_identity_profile.ssn_hash`, `.ssn_last_four`, index `idx_ssn` | the schema migration |
| table `gp_ssn_hash_blocklist` | **runtime** — `SsnHashGuard::buildBlocklistTable()`'s `CREATE TABLE IF NOT EXISTS`; no migration ever made it |
| `gp_edge.edge_type` enum member `'ssn_hash'` | the schema migration |

Two of those seven are invisible from `database/migrations` alone. A migration written only from the
schema file would leave `stg_ssn` behind — and MySQL refuses to drop a column an index still
covers — so the drop would fail on exactly the hubs that have been backfilled, i.e. production.
Both runtime objects are handled with existence checks rather than assumptions.

**On `gp_edge`:** the enum member is inert. `gp_edge` has a schema, a model and **zero writers** —
the only code that names it is `Engine::applyMerge()`'s list of tables whose `identity_id` is
repointed on a merge. So the table is empty and an `ALTER` is instantaneous. It is narrowed here
rather than left because an enum that still advertises `'ssn_hash'` reads as though the hub can
record such an edge. The migration **counts the rows first and throws** if any exist: `MODIFY COLUMN`
on an enum whose values are in use truncates them to `''`, and silently corrupting provenance to
tidy a type is the wrong trade.

**On reversibility, decided:** `down()` restores the **shape and not the data**, and says so. That is
the honest maximum. The values cannot come back — repopulating them would mean re-reading SSN from
`streamline_local`, and Task 4 removed the code that does that, deliberately. A `down()` that threw
would be more dramatic and less useful: it would block rolling back any later migration batched with
this one, to protect data that is already gone. `gp_ssn_hash_blocklist` is **not** recreated by
`down()` — no migration ever created it, so recreating it would invent state that never had a
migration's authority. Nor is the `gp_edge` enum member restored: an inert value is not worth an
`ALTER` on the way back.

**Files:**
- Create: `database/migrations/2026_09_03_000000_drop_ssn_columns.php`
- Modify: `tests/Support/HubTestCase.php:140-163`
- Modify: `app/GoldenProfile/Eval/EvalRunner.php:39-63`
- Modify: `tests/Unit/NoSsnSupportRemainsTest.php` (add `EvalRunner` to `pipelineFiles()`)
- Create: `tests/Feature/SsnColumnsDroppedTest.php`

**Interfaces:**
- Consumes: `Tests\Support\HubTestCase::hub()` and `$this->hub()->getSchemaBuilder()`.
- Produces: `HubTestCase::stagePerson(array $overrides = []): int` — same signature, two fewer
  default keys, so **any caller passing `'ssn_hash'` or `'ssn_last_four'` as an override now throws**
  (`strict` is true on the connection, so an unknown column is an error, not a silent drop). Tasks 2,
  3 and 4 all wrote tests that pass those overrides; Step 4 below removes them.

- [ ] **Step 1: Confirm the rollout gate before touching a shared hub**

This step is a **decision, not a command**, and it applies only to running the migration against the
shared hub — not to committing it or to CI, which use scratch schemas.

Read `docs/EVALUATION.md`'s `## Plan 2 rollout decision` (Task 1). Compute
`P = projected extra identities ÷ active identities` and follow the row it lands in. Every row says
*proceed*; what changes is whether plan 5 must precede the next full rebuild. Record `P` and the row
in the PR description.

If the baseline is still `PENDING`: **commit the migration and run it on scratch schemas anyway**, and
hold only the shared-hub run and Task 8's script. The Delivery Checklist requirement is not
conditional on a row count, and blocking the code on a measurement nobody here can take would stall
the plan indefinitely.

- [ ] **Step 2: Write the failing schema test**

Create `tests/Feature/SsnColumnsDroppedTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\Support\HubTestCase;

/**
 * The Delivery Checklist §1 claim — "stream internal verified data via CDC (never
 * store SSN)" — is only provable from the schema. Code can stop writing a column
 * and the column still holds every value it was ever given; these assertions are
 * the ones a compliance reviewer can actually check.
 *
 * HubTestCase runs migrate:fresh over database/migrations, so this reads the real
 * post-migration schema rather than a hand-built one.
 */
class SsnColumnsDroppedTest extends HubTestCase
{
    public function test_no_hub_table_has_an_ssn_column(): void
    {
        $schema = $this->hub()->getSchemaBuilder();

        foreach (['stg_person', 'gp_identity', 'gp_identity_profile'] as $table) {
            $this->assertTrue($schema->hasTable($table), "$table is missing entirely");
            $this->assertFalse($schema->hasColumn($table, 'ssn_hash'), "$table.ssn_hash survives");
            $this->assertFalse($schema->hasColumn($table, 'ssn_last_four'), "$table.ssn_last_four survives");
        }
    }

    public function test_no_ssn_index_survives(): void
    {
        // idx_ssn came from the schema migration; stg_ssn was added at RUNTIME by
        // SqlBackfill::indexStaging(), so it exists only in hubs that have been
        // backfilled. MySQL refuses to drop a column an index still covers, so a
        // migration that forgot stg_ssn would fail on exactly those hubs — i.e.
        // production, and nowhere a developer would notice first.
        foreach ([['stg_person', 'idx_ssn'], ['stg_person', 'stg_ssn'],
            ['gp_identity', 'idx_ssn'], ['gp_identity_profile', 'idx_ssn']] as [$table, $index]) {
            $this->assertNull($this->hub()->selectOne(
                'SELECT 1 FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1',
                [$table, $index],
            ), "$table.$index survives");
        }
    }

    public function test_the_runtime_blocklist_table_is_gone(): void
    {
        // Created by SsnHashGuard::buildBlocklistTable() with CREATE TABLE IF NOT
        // EXISTS and by no migration at all, so it is invisible from
        // database/migrations and would have been orphaned rather than dropped.
        $this->assertFalse($this->hub()->getSchemaBuilder()->hasTable('gp_ssn_hash_blocklist'));
    }

    public function test_the_edge_type_enum_no_longer_advertises_ssn_hash(): void
    {
        // gp_edge has a schema, a model and zero writers, so the member was inert —
        // but an enum that still lists 'ssn_hash' reads as though the hub can
        // record such an edge.
        $type = $this->hub()->selectOne(
            'SELECT COLUMN_TYPE t FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            ['gp_edge', 'edge_type'],
        );

        $this->assertNotNull($type, 'gp_edge.edge_type is missing');
        $this->assertStringNotContainsString('ssn_hash', $type->t);
        $this->assertStringContainsString('name_dob', $type->t, 'the enum lost more than it should have');
    }

    public function test_staging_a_person_still_works(): void
    {
        // stagePerson() lost two default keys. The connection is strict, so an
        // unknown column is an error rather than a silent drop — this proves the
        // helper was updated and not merely left to throw on every call.
        $id = $this->stagePerson(['first_name' => 'Grace', 'last_name' => 'Adeyemi']);

        $this->assertGreaterThan(0, $id);
        $this->assertSame('Adeyemi', $this->hub()->table('stg_person')
            ->where('stg_person_id', $id)->value('last_name'));
    }
}
```

- [ ] **Step 3: Run it and watch it fail**

Run: `vendor/bin/phpunit tests/Feature/SsnColumnsDroppedTest.php`

Expected: 3 failures, 2 passes.
- `test_no_hub_table_has_an_ssn_column` — `stg_person.ssn_hash survives`.
- `test_the_edge_type_enum_no_longer_advertises_ssn_hash` — the enum still contains it.
- `test_no_ssn_index_survives` — `stg_person.idx_ssn survives`.
- `test_the_runtime_blocklist_table_is_gone` passes (nothing created it in a fresh schema).
- `test_staging_a_person_still_works` passes (the columns still exist).

- [ ] **Step 4: Write the migration**

Create `database/migrations/2026_09_03_000000_drop_ssn_columns.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remove every SSN-derived column from the hub.
 *
 * Confluence Delivery Checklist §1 requires that internal verified data stream via
 * CDC and that the SSN never be stored; the GPP Data Model has no SSN column
 * anywhere in the golden layer. gp-cami stored an ssn_hash on three tables plus an
 * ssn_last_four on two, and matched on the hash at 0.99 as the strongest key in
 * Pass A. The code stopped using all of it in the commits preceding this one; this
 * makes the claim provable from the schema instead of asserted by policy.
 *
 * ssn_last_four goes with the hash. It is not the SSN, and the checklist forbids
 * only the SSN — but CAMI is both the system of record for
 * employees.social_security_num and the only caller of these endpoints, so the hub
 * was storing PII the caller already owned and handing it back.
 *
 * TWO OF THE OBJECTS DROPPED HERE WERE NEVER IN A MIGRATION, and a migration
 * written from the schema file alone would miss both:
 *
 *   stg_person.stg_ssn        SqlBackfill::indexStaging() adds it with an ad-hoc
 *                             ALTER before transform, so it exists in every hub
 *                             that has been backfilled and in no fresh one. MySQL
 *                             refuses to drop a column an index still covers, so
 *                             forgetting it would fail on production and pass in
 *                             CI.
 *   gp_ssn_hash_blocklist     SsnHashGuard::buildBlocklistTable() creates it with
 *                             CREATE TABLE IF NOT EXISTS. Same asymmetry.
 *
 * Both are therefore handled with existence checks, not assumptions.
 *
 * REVERSIBILITY: down() restores the SHAPE and NOT THE DATA, and that is the
 * honest maximum. The values cannot come back — repopulating them would mean
 * re-reading SSN out of streamline_local, and the connector that did so was
 * removed on purpose. A down() that threw would block rolling back any later
 * migration batched with this one, to protect data that is already gone.
 * gp_ssn_hash_blocklist is deliberately NOT recreated: no migration ever created
 * it, so recreating it would invent state that never had a migration's authority.
 */
return new class extends Migration
{
    /** Tables and the SSN columns each one carries. */
    private const COLUMNS = [
        'stg_person' => ['ssn_hash', 'ssn_last_four'],
        'gp_identity' => ['ssn_hash'],
        'gp_identity_profile' => ['ssn_hash', 'ssn_last_four'],
    ];

    /**
     * Indexes covering those columns. idx_ssn is from the schema migration;
     * stg_ssn is the runtime one. Dropped before the columns, explicitly, rather
     * than relying on MySQL's implicit single-column index cleanup — being
     * explicit is what makes the runtime index impossible to forget.
     */
    private const INDEXES = [
        ['stg_person', 'idx_ssn'],
        ['stg_person', 'stg_ssn'],
        ['gp_identity', 'idx_ssn'],
        ['gp_identity_profile', 'idx_ssn'],
    ];

    private const EDGE_TYPES_WITHOUT_SSN = "'npi','dea','upin','name_dob','license_registry',"
        ."'credential','exclusion','probabilistic','manual'";

    public function up(): void
    {
        $conn = Schema::connection('golden_profile')->getConnection();

        foreach (self::INDEXES as [$table, $index]) {
            if ($this->indexExists($table, $index)) {
                $conn->statement("ALTER TABLE `$table` DROP INDEX `$index`");
            }
        }

        foreach (self::COLUMNS as $table => $columns) {
            $present = array_values(array_filter(
                $columns,
                fn ($c) => Schema::connection('golden_profile')->hasColumn($table, $c),
            ));

            if ($present !== []) {
                Schema::connection('golden_profile')->table($table, function (Blueprint $t) use ($present) {
                    $t->dropColumn($present);
                });
            }
        }

        // Runtime table, no migration ever made it — see the class docblock.
        $conn->statement('DROP TABLE IF EXISTS `gp_ssn_hash_blocklist`');

        // gp_edge has zero writers, so the enum member is inert and the ALTER is
        // instantaneous on an empty table. Refuse rather than truncate if that
        // assumption is ever false: MODIFY COLUMN on an enum whose values are in
        // use rewrites them to '', and corrupting provenance to tidy a type is the
        // wrong trade.
        $edgeRows = (int) $conn->selectOne('SELECT COUNT(*) c FROM `gp_edge`')->c;

        if ($edgeRows > 0) {
            throw new RuntimeException(
                "refusing to narrow gp_edge.edge_type: the table holds $edgeRows row(s). "
                ."It has had no writer in gp-cami, so this was expected to be empty. MODIFY COLUMN "
                ."would rewrite any 'ssn_hash' edge to ''. Inspect the rows, decide what they mean, "
                ."and drop this one statement from the migration if they must be preserved — the "
                ."enum member is inert either way."
            );
        }

        $conn->statement(
            'ALTER TABLE `gp_edge` MODIFY `edge_type` ENUM('.self::EDGE_TYPES_WITHOUT_SSN.') NOT NULL'
        );
    }

    /** Shape only — see the class docblock on reversibility. */
    public function down(): void
    {
        Schema::connection('golden_profile')->table('stg_person', function (Blueprint $t) {
            $t->string('ssn_hash', 255)->nullable()->after('date_of_birth');
            $t->char('ssn_last_four', 4)->nullable()->after('ssn_hash');
            $t->index('ssn_hash', 'idx_ssn');
        });

        Schema::connection('golden_profile')->table('gp_identity', function (Blueprint $t) {
            $t->string('ssn_hash', 255)->nullable()->after('canonical_dob');
            $t->index('ssn_hash', 'idx_ssn');
        });

        Schema::connection('golden_profile')->table('gp_identity_profile', function (Blueprint $t) {
            $t->string('ssn_hash', 255)->nullable()->after('date_of_birth');
            $t->char('ssn_last_four', 4)->nullable()->after('ssn_hash');
            $t->index('ssn_hash', 'idx_ssn');
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        return (bool) Schema::connection('golden_profile')->getConnection()->selectOne(
            'SELECT 1 FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1',
            [$table, $index],
        );
    }
};
```

- [ ] **Step 5: Stop the two remaining stagers writing the columns**

(a) In `tests/Support/HubTestCase.php::stagePerson()`, delete the two default keys (lines 151–152):

```php
            'ssn_hash' => null,
            'ssn_last_four' => null,
```

and extend the method docblock so the next person adding a column knows the constraint:

```php
    /**
     * Stage one person. Returns stg_person_id. Defaults mirror what the
     * StreamlineLocal connector produces, block_key included. A caller-supplied
     * block_key wins — the merge order matters, so overrides are applied last.
     *
     * There are no ssn_hash / ssn_last_four defaults: the columns were dropped by
     * 2026_09_03_000000_drop_ssn_columns. The connection is strict, so passing
     * either as an override throws rather than being silently ignored — which is
     * what you want, because a test staging an SSN is a test asserting something
     * the hub no longer does.
     */
```

(b) In `app/GoldenProfile/Eval/EvalRunner.php::run()`, delete the two staged keys (lines 50–51):

```php
                'ssn_hash' => $r['ssn_hash'] ?? null,
                'ssn_last_four' => null,
```

and add in their place:

```php
                // No ssn_hash. The fixture's ssn-* records still carry one as
                // documentation of what the retired tier matched on, and it is
                // deliberately not staged — the column is gone and the pair is a
                // known, accepted false split. See docs/EVALUATION.md.
```

(c) Remove the `'ssn_hash'` overrides from the tests written in Tasks 2, 3 and 4, which would now
throw. Each keeps its identity as a test; only the override goes, because the assertion is about
records that share nothing the resolver can use:

| File | Test | Change |
|---|---|---|
| `tests/Feature/ResolverLadderTest.php` | `test_a_shared_ssn_hash_no_longer_binds_two_rows` | Delete the `$hash` local and both `'ssn_hash' => $hash` overrides; rename to `test_two_records_of_one_person_with_no_shared_key_stay_separate` and keep the docblock explaining that a shared SSN used to bind them |
| `tests/Feature/ResolverLadderTest.php` | `test_no_link_is_ever_recorded_with_an_ssn_hash_match_key` | Delete the `$hash` local and the override; the `match_key` assertion is what matters and needs no SSN |
| `tests/Feature/SetBackfillParityTest.php` | `test_the_set_based_path_does_not_bind_two_rows_sharing_an_ssn_hash` | Delete the `$hash` local and both overrides; rename to `test_the_set_based_path_does_not_bind_two_records_with_no_shared_key` |
| `tests/Feature/SetBackfillParityTest.php` | `test_dedup_no_longer_merges_identities_that_share_an_ssn_hash` | The `gp_identity` insert's `'ssn_hash' => $hash` must go too. Give the two identities a shared `dea_number` instead and assert dedup DOES merge them — the SSN case is now unrepresentable, and this keeps the test proving dedup still works rather than deleting coverage. Rename to `test_dedup_still_merges_identities_that_share_a_dea_number` |
| `tests/Feature/ProfileHasNoSsnTest.php` | `test_a_rebuilt_profile_carries_no_ssn_value` | Delete both overrides and the two profile-column assertions (the columns no longer exist, and `SsnColumnsDroppedTest` covers that); keep the `gp_identity` and `last_name` assertions |
| `tests/Feature/ProfileHasNoSsnTest.php` | `test_survivorship_records_no_ssn_provenance` | Delete the override; the `gp_attribute` / `gp_survivorship_audit` assertions stand on their own |
| `tests/Feature/ProfileHasNoSsnTest.php` | `test_the_connector_does_not_read_ssn_from_the_source` | **Unchanged.** It builds a plain stub object, never touches `stg_person`, and is the single most valuable assertion in the plan — it proves the hub does not read SSN even when the source offers it |

(d) In `tests/Unit/NoSsnSupportRemainsTest.php`, add `EvalRunner` to `pipelineFiles()` and delete the
paragraph of its docblock that explained the omission:

```php
    private function pipelineFiles(): array
    {
        return [
            'GoldenProfile/Resolution/DeterministicResolver.php',
            'GoldenProfile/Resolution/Survivorship.php',
            'GoldenProfile/Materialize/SetFinalizer.php',
            'GoldenProfile/Materialize/ProfileMaterializer.php',
            'GoldenProfile/Connectors/StreamlineLocalConnector.php',
            'GoldenProfile/SqlBackfill.php',
            'GoldenProfile/Engine.php',
            'GoldenProfile/Eval/EvalRunner.php',
            'Http/Controllers/Api/V1/CredentialSearchController.php',
            'Http/Resources/IdentityProfileResource.php',
        ];
    }
```

- [ ] **Step 6: Run the schema test and watch it pass**

Run: `vendor/bin/phpunit tests/Feature/SsnColumnsDroppedTest.php`

Expected: PASS, 5 tests.

- [ ] **Step 7: Re-run the eval gate and confirm the numbers did not move**

Run: `vendor/bin/phpunit tests/Feature/EvalGateTest.php`

Expected: PASS, with the same report as Task 2 —
`precision 1.0000 recall 0.8889 f1 0.9412 — 0 false merge(s), 1 false split(s)`.

This is worth its own step. `EvalRunner` stopped staging a column it had been staging, and if the
gate's numbers *changed*, that would mean the fixture's `ssn_hash` was still influencing the result
somewhere after Task 2 claimed the tier was gone. Identical numbers are the confirmation that it
was not.

- [ ] **Step 8: Run the whole suite**

Run: `vendor/bin/phpunit`

Expected: PASS, 0 failures, 0 skipped, ~108 tests. `NoSsnSupportRemainsTest` is now fully green
including `EvalRunner`.

- [ ] **Step 9: Commit**

```bash
vendor/bin/pint --dirty
git add database/migrations/2026_09_03_000000_drop_ssn_columns.php \
        tests/Support/HubTestCase.php app/GoldenProfile/Eval/EvalRunner.php \
        tests/Unit/NoSsnSupportRemainsTest.php tests/Feature/SsnColumnsDroppedTest.php \
        tests/Feature/ResolverLadderTest.php tests/Feature/SetBackfillParityTest.php \
        tests/Feature/ProfileHasNoSsnTest.php
git commit -m "feat(schema)!: drop every SSN column from the hub

Drops ssn_hash from stg_person, gp_identity and gp_identity_profile, and
ssn_last_four from stg_person and gp_identity_profile, with their idx_ssn
indexes. Makes the Delivery Checklist §1 claim provable from the schema rather
than asserted by policy.

Also drops two objects that were never in any migration, which a migration
written from the schema file alone would have missed:

  * stg_person.stg_ssn — added by SqlBackfill::indexStaging() with an ad-hoc
    ALTER, so it exists in every backfilled hub and no fresh one. MySQL refuses
    to drop a column an index still covers, so forgetting it would have failed
    on production and passed in CI.
  * gp_ssn_hash_blocklist — created by SsnHashGuard with CREATE TABLE IF NOT
    EXISTS. Same asymmetry.

Narrows gp_edge.edge_type, which still advertised an 'ssn_hash' edge the hub has
never been able to write (zero writers). The migration counts the rows first and
refuses rather than let MODIFY COLUMN truncate a value in use.

down() restores the shape and not the data, deliberately: repopulating would mean
re-reading SSN from streamline_local, which is the thing being removed. A
throwing down() would block rolling back later migrations to protect data that
is already gone.

Removes the last two stagers of the columns — HubTestCase::stagePerson()'s
defaults and EvalRunner's insert — and the ssn_hash overrides in the tests from
the preceding commits, which would now throw against a strict connection. The
eval gate reports identical numbers before and after, confirming the fixture's
hash had stopped influencing the result when the tier was removed."
```

---

## Task 8: Record the retained bindings and the loss of rebuild reproducibility

Dropping the columns did not un-merge anything. Every `gp_source_link` row bound by the tier still
exists with `match_key = 'ssn_hash'`, still points at the identity it always did, and — because
`DeterministicResolver::resolve()` returns an existing link's `identity_id` before it consults any
tier — will keep doing so under `gp:sync` forever, with no pinning and no code.

This task writes that down in the hub itself and in the docs, which is the whole of the remaining
work. **Design decision 3 at the head of this plan is the argument for why there is no re-resolution
task**; this is its execution.

Why the retained links are recorded rather than left silent: `match_key = 'ssn_hash'` is now a key no
code can produce. A reader six months from now finds links attributing a merge to a tier that does
not exist and has no way to tell whether that is history or a bug. One `gp_resolution_log` row per
affected identity answers it. The `action` enum is
`create|merge|split|relink|override` — `'override'` is the member that fits: a binding the current
matcher would not produce, retained by decision.

`match_key` is deliberately **not** rewritten to something like `legacy_ssn_hash`. It records why the
link was made, and that is true; falsifying provenance to make it self-documenting is worse than
adding a row that explains it.

**What is genuinely forfeited, and the cost of paying it:** a future `gp:backfill` into an empty hub
will produce `projected_extra_identities` more identities than the hub holds today (Task 1, query 6).
That rebuild is not free — it is the original backfill again: 13.4M source rows staged, resolved by
the set-based tiers, deduped, survived and materialized. It is not something normal operation does,
which is exactly why the risk is a documentation problem rather than an engineering one. The
sequencing rule is Task 1's threshold table: land plan 5's compensating keys first if `P` is over
0.5%, so the rebuild recovers with better keys instead of fragmenting on none.

Note what this does **not** breach. The "rebuild produces a byte-identical profile" invariant is about
`Survivorship` (per-row) and `SetFinalizer` (set-based) agreeing **given the same set of links** —
which is why its fix was a tiebreak-ordering agreement (`link_id ASC`), not a resolution change.
Task 4 keeps both in step and pins it by reflection, and decision 1 strengthened it by deleting the
one field where the two already disagreed. Retaining historical links leaves it untouched.

**Files:**
- Create: `scripts/retire-ssn-tier-provenance.sql`
- Create: `tests/Feature/RetireSsnProvenanceScriptTest.php`
- Modify: `docs/EVALUATION.md` (append the reproducibility section)

**Interfaces:**
- Consumes: `docs/EVALUATION.md`'s `## Plan 2 rollout decision` (Task 1) for the go/no-go on the
  shared-hub run; `Tests\Support\HubTestCase::hub()`.
- Produces: `scripts/retire-ssn-tier-provenance.sql` — idempotent, and the only step in this plan
  that writes to the shared hub outside a migration.

- [ ] **Step 1: Write the failing script test**

Create `tests/Feature/RetireSsnProvenanceScriptTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\Support\HubTestCase;

/**
 * scripts/retire-ssn-tier-provenance.sql is run by hand against the shared hub,
 * which is precisely why it needs a test: nobody here can run it there, and a
 * committed-broken script fails in the one place where failing is expensive.
 *
 * It reads only gp_source_link.match_key and writes only gp_resolution_log, so it
 * needs none of the columns the migration dropped and can run at any point after
 * this branch lands.
 */
class RetireSsnProvenanceScriptTest extends HubTestCase
{
    private function runScript(): void
    {
        $sql = file_get_contents(base_path('scripts/retire-ssn-tier-provenance.sql'));

        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            // Skip fragments that are only comments — the file is commented
            // heavily on purpose, since its reader is a DBA with no context.
            if (preg_match('/^(--[^\n]*\n?)*$/', $statement)) {
                continue;
            }
            $this->hub()->statement($statement);
        }
    }

    /** One identity, two links, both keyed ssn_hash — bound ONLY by the retired tier. */
    private function seedSsnOnlyIdentity(): int
    {
        $identityId = (int) $this->hub()->table('gp_identity')->insertGetId([
            'identity_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'canonical_first' => 'Grace', 'canonical_last' => 'Adeyemi',
            'canonical_dob' => '1979-05-14', 'confidence' => 1.0, 'record_count' => 2,
            'status' => 'active', 'first_seen' => now(), 'last_updated' => now(),
        ]);

        foreach ([9001, 9002] as $sourceId) {
            $this->hub()->table('gp_source_link')->insert([
                'identity_id' => $identityId, 'system_id' => $this->systemId,
                'source_table' => 'employees', 'source_id' => $sourceId,
                'account_id' => 1, 'employeelist_id' => 1,
                'match_method' => 'deterministic', 'match_key' => 'ssn_hash',
                'match_score' => 0.99, 'match_state' => 'auto_match',
                'is_pinned' => 0, 'linked_at' => now(),
            ]);
        }

        return $identityId;
    }

    public function test_it_logs_one_row_per_ssn_only_identity(): void
    {
        $identityId = $this->seedSsnOnlyIdentity();

        $this->runScript();

        $rows = $this->hub()->table('gp_resolution_log')
            ->where('identity_id', $identityId)->where('match_key', 'ssn_hash')->get();

        $this->assertCount(1, $rows, 'expected exactly one provenance row for the identity');
        $this->assertSame('override', $rows->first()->action,
            "the action enum has no 'retain'; 'override' is the member that means "
            .'a binding the current matcher would not produce');
        $this->assertStringContainsString('retained', $rows->first()->reason);
    }

    public function test_it_is_idempotent(): void
    {
        // It will be run more than once — a DBA re-running a script after a
        // connection drop is normal, and a second row per identity would make the
        // log's own count meaningless.
        $identityId = $this->seedSsnOnlyIdentity();

        $this->runScript();
        $this->runScript();

        $this->assertSame(1, $this->hub()->table('gp_resolution_log')
            ->where('identity_id', $identityId)->where('match_key', 'ssn_hash')->count());
    }

    public function test_it_ignores_identities_that_have_other_evidence(): void
    {
        // An identity bound by ssn_hash AND something else keeps its binding on the
        // other key, so a rebuild reproduces it and there is nothing to record.
        // Logging it would inflate the count that Task 1 measured.
        $identityId = $this->seedSsnOnlyIdentity();
        $this->hub()->table('gp_source_link')->insert([
            'identity_id' => $identityId, 'system_id' => $this->systemId,
            'source_table' => 'employees', 'source_id' => 9003,
            'account_id' => 1, 'employeelist_id' => 1,
            'match_method' => 'deterministic', 'match_key' => 'npi',
            'match_score' => 0.99, 'match_state' => 'auto_match',
            'is_pinned' => 0, 'linked_at' => now(),
        ]);

        $this->runScript();

        $this->assertSame(0, $this->hub()->table('gp_resolution_log')
            ->where('identity_id', $identityId)->where('match_key', 'ssn_hash')->count());
    }

    public function test_it_does_not_touch_the_links_themselves(): void
    {
        // The decision is to RETAIN. If this script ever starts relinking or
        // rewriting match_key, it has stopped being a record and become a
        // migration — and an un-merge nobody asked for.
        $identityId = $this->seedSsnOnlyIdentity();
        $before = $this->hub()->table('gp_source_link')
            ->where('identity_id', $identityId)->orderBy('source_id')
            ->get(['identity_id', 'match_key', 'match_score', 'is_pinned'])->toArray();

        $this->runScript();

        $after = $this->hub()->table('gp_source_link')
            ->where('identity_id', $identityId)->orderBy('source_id')
            ->get(['identity_id', 'match_key', 'match_score', 'is_pinned'])->toArray();

        $this->assertEquals($before, $after, 'the script modified source links; it must only record');
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `vendor/bin/phpunit tests/Feature/RetireSsnProvenanceScriptTest.php`

Expected: 4 errors — `file_get_contents(...): Failed to open stream: No such file or directory` for
`scripts/retire-ssn-tier-provenance.sql`.

- [ ] **Step 3: Write the script**

Create `scripts/retire-ssn-tier-provenance.sql`:

```sql
-- Retire the ssn_hash tier: record the bindings it left behind.
--
-- Run ONCE against the golden_profile hub, after the branch that removes SSN
-- support has been deployed and 2026_09_03_000000_drop_ssn_columns has run.
-- Idempotent — safe to re-run after a dropped connection.
--
-- WHAT THIS DOES NOT DO: it does not un-merge anything, and it must never start
-- to. The tier ran at 0.99 behind a filler-hash guard (placeholder list plus a
-- cardinality cap of 3 distinct upstream people per hash), so a surviving
-- ssn_hash merge is a high-precision merge of records that genuinely shared an
-- SSN — the right answer. Removing the AUTHORITY to make such a merge is a
-- compliance requirement (Delivery Checklist §1); retroactively undoing merges
-- already made is not, and nothing in the checklist asks for it. Un-merging would
-- deliberately manufacture false splits, which is how an excluded provider gets
-- missed (see docs/EVALUATION.md).
--
-- WHY RECORD ANYTHING: match_key = 'ssn_hash' is now a key no code can produce.
-- Without a note, a reader finds links attributing merges to a tier that does not
-- exist and cannot tell history from a bug. match_key is deliberately NOT
-- rewritten to something self-documenting — it records why the link was made, and
-- that is true. Falsifying provenance to make it legible is worse than adding a
-- row that explains it.
--
-- THE COST THIS RECORDS: these identities are bound ONLY by the retired tier, so
-- a from-scratch rebuild will no longer reproduce them — it will mint one identity
-- per source row instead. Query 6 of scripts/baseline-key-mix.sql measures the
-- delta. Existing links are unaffected in normal operation:
-- DeterministicResolver::resolve() returns an existing link's identity_id before
-- it consults any tier, so gp:sync keeps today's clustering indefinitely.

INSERT INTO gp_resolution_log
    (action, identity_id, affected_ids, match_key, reason, actor, created_at)
SELECT 'override',
       t.identity_id,
       JSON_OBJECT('links', t.links, 'source_ids', t.source_ids),
       'ssn_hash',
       'binding retained: bound only by the ssn_hash tier, which the GPP conformance programme removed; not reproducible by a rebuild',
       'engine:gpp-ssn-removal',
       NOW()
FROM (
    SELECT identity_id,
           COUNT(*)                        AS links,
           GROUP_CONCAT(source_id ORDER BY source_id) AS source_ids
    FROM gp_source_link
    GROUP BY identity_id
    HAVING SUM(match_key <> 'ssn_hash') = 0
       AND SUM(match_key =  'ssn_hash') > 0
) t
-- Idempotency. A DBA re-running this after a dropped connection must not double
-- the rows, or the log's own count stops being the number of affected identities.
WHERE NOT EXISTS (
    SELECT 1 FROM gp_resolution_log g
    WHERE g.identity_id = t.identity_id
      AND g.match_key = 'ssn_hash'
      AND g.actor = 'engine:gpp-ssn-removal'
);

-- Read back: this count must equal query 3 of scripts/baseline-key-mix.sql
-- ("identities_bound_only_by_ssn"). If it does not, the hub changed between the
-- two runs — find out how before recording either number as the baseline.
SELECT COUNT(*) AS identities_recorded
FROM gp_resolution_log
WHERE match_key = 'ssn_hash' AND actor = 'engine:gpp-ssn-removal';
```

- [ ] **Step 4: Run the test and watch it pass**

Run: `vendor/bin/phpunit tests/Feature/RetireSsnProvenanceScriptTest.php`

Expected: PASS, 4 tests.

If `test_it_logs_one_row_per_ssn_only_identity` fails on the `reason` length, note that
`gp_resolution_log.reason` is `varchar(255)`; the string above is 156 characters and fits, but any
edit to it must be re-counted — MySQL in strict mode errors rather than truncating.

- [ ] **Step 5: Record the reproducibility loss in the docs**

Append to `docs/EVALUATION.md`:

```markdown
## Rebuild reproducibility after the SSN removal

**The hub's current clustering is no longer reproducible from a from-scratch rebuild.** This is the
one permanent consequence of plan 2 and it is deliberate.

The `ssn_hash` tier bound records at 0.99 behind a filler-hash guard, so every merge it made was
correct. Plan 2 removed the *authority* to make such a merge — Delivery Checklist §1 — but not the
merges themselves: un-merging would convert correct answers into false splits, which is how an
excluded provider gets missed and is the costlier of the two error modes.

Existing bindings survive with no code and no pinning: `DeterministicResolver::resolve()` looks up
`gp_source_link` by `(system_id, source_table, source_id)` and returns the existing
`identity_id` **before** it consults any tier. So `gp:sync` reproduces today's clustering
indefinitely. (Pinning them via `is_pinned` was considered and rejected — it would additionally
suppress `enrich()`, freezing those rows' licences and addresses, to buy something idempotency
already provides.)

What a rebuild loses:

| | |
|---|---|
| Identities bound only by the retired tier | see the baseline table above |
| Extra identities a rebuild would mint | see the baseline table above (`projected_extra_identities`) |
| Recorded in | `gp_resolution_log`, `action = 'override'`, `match_key = 'ssn_hash'`, `actor = 'engine:gpp-ssn-removal'` |
| Written by | `scripts/retire-ssn-tier-provenance.sql`, run once by hand |

**Before the next full rebuild**, read the `## Plan 2 rollout decision` threshold above. Above 0.5%,
land plan 5's compensating keys first — MMIS as a resolve-time tier, `(state, provider#)`, name+state
blocking — so the rebuild recovers those records on better evidence rather than fragmenting on none.
A rebuild is the original backfill again (13.4M rows staged, resolved, deduped, survived,
materialized); it is not something normal operation does, which is why this is a documentation
problem rather than an engineering one.

**This does not breach the byte-identical-profile invariant.** That invariant is about `Survivorship`
(per-row) and `SetFinalizer` (set-based) agreeing *given the same set of links* — hence its fix was a
tiebreak-ordering agreement (`link_id ASC`), not a resolution change. Both are kept in step and
`ProfileHasNoSsnTest` now pins their field maps as identical, which nothing did before. Dropping
`ssn_last_four` actually strengthened it: `ProfileMaterializer` picked that field with no `ORDER BY`
while `SetFinalizer` used `ROW_NUMBER()` ordered by `stg_person_id`, so the two could already
disagree on any identity whose linked rows carried two different last-fours.
```

- [ ] **Step 6: Hand the script over**

**Human step.** Same operator as Task 1 Step 3:

```bash
mysql -h <gp-host> -u <gp-user> -p golden_profile < scripts/retire-ssn-tier-provenance.sql
```

Expected: one result set, `identities_recorded`, equal to query 3 of `scripts/baseline-key-mix.sql`.
If the two disagree, the hub changed between the runs — find out how before recording either number.

Fill the two `see the baseline table above` cells with the actual figures once both are in hand, and
note in the PR description who ran it and when.

- [ ] **Step 7: Run the whole suite and commit**

Run: `vendor/bin/phpunit`

Expected: PASS, 0 failures, 0 skipped, ~112 tests.

```bash
vendor/bin/pint --dirty
git add scripts/retire-ssn-tier-provenance.sql \
        tests/Feature/RetireSsnProvenanceScriptTest.php docs/EVALUATION.md
git commit -m "docs(hub): record the retained ssn_hash bindings and the rebuild reproducibility loss

Dropping the columns did not un-merge anything, and deliberately so: the tier ran
at 0.99 behind a filler-hash guard, so every merge it made was correct. Plan 2
removes the AUTHORITY to make such a merge, not the merges — un-merging would
convert correct answers into false splits, the costlier error mode.

Existing bindings survive with no code and no pinning, because
DeterministicResolver::resolve() returns an existing link's identity_id before it
consults any tier. Pinning was considered and rejected: is_pinned also suppresses
enrich(), which would freeze those rows' licences and addresses to buy what
idempotency already gives.

What is forfeited is rebuild reproducibility, and that is now written down in
both the hub and docs/EVALUATION.md rather than left to be discovered. The script
writes one gp_resolution_log row per affected identity (action 'override', the
enum member for a binding the current matcher would not produce) and never
touches the links themselves — asserted by a test, because it is run by hand
against the shared hub and a committed-broken script fails where failing costs
most.

match_key is NOT rewritten to something self-documenting. It records why the link
was made, and that is true; falsifying provenance to make it legible is worse
than adding a row that explains it."
```

---

## Task 9: Correct the documentation and record the contract change

Three documents now describe a hub that no longer exists, and one of them
(`PROJECT_PLAN.md` §7) is the written record of a request contract *confirmed with CAMI on
2026-07-20*. Leaving them is worse than having never written them: a stale spec is read as current.

This task also carries the one obligation in this plan that reaches outside the repo — **saying who
has to be told, and what.** Task 5 changed a published API; nothing in the code can notify anyone.

| Document | What is wrong now |
|---|---|
| `docs/RUNNING.md:37-38` | "SSN: `ssn_hash = sha512(ssn + key)`, key resolved locally via the security package's LocalStrategy" — describes a class that no longer exists and a package that was already dropped in `e64f73d` |
| `docs/RUNNING.md:47` | Documents the client's `golden-profile:lookup --ssn=` flag, which still exists client-side but no longer narrows resolution |
| `docs/RUNNING.md:58` | "`ssn_hash` is never exposed to the client" — true but now trivially so |
| `docs/PROVISIONING.md:51-59, 74` | A whole provisioning section ("Shared SSN encryption key (before Phase 2)") and a checklist item for a key nothing reads |
| `PROJECT_PLAN.md:24` | §2 states the hub "stores CAMI-encrypted SSN + `ssn_last_four` (+ `ssn_hash` …)" |
| `PROJECT_PLAN.md:83` | Phase table lists Pass A "keys 1–6: ssn_hash, npi, dea, upin, name+dob, license+state" |
| `PROJECT_PLAN.md:108-117` | §7's request contract and the whole "SSN storage & matching — RESOLVED 2026-07-20" block |
| `PROJECT_PLAN.md:141,143` | Phase 2 and Phase 9 completion notes cite the SSN hasher |
| `PROJECT_PLAN.md:172,174` | §11 open items still list the shared SSN key as needed infrastructure |

**Files:**
- Modify: `docs/RUNNING.md:35-40`, `:45-50`, `:55-60`
- Modify: `docs/PROVISIONING.md:51-59`, `:74`
- Modify: `PROJECT_PLAN.md:24`, `:83`, `:105-117`, `:141`, `:143`, `:172`, `:174`

**Interfaces:**
- Consumes: the measured filler-SSN figures from the deleted `config/golden_profile.php` `ssn` block
  (17 hashes, 9,164 distinct people, worst hash on 9,072) — Task 6 flagged them for rehoming here.
- Produces: documentation only. Nothing reads it programmatically, so there is no test; Step 5 is a
  grep that stands in for one.

- [ ] **Step 1: Rewrite the `docs/RUNNING.md` SSN note as a removal record**

Replace the bullet at lines 37–38:

```markdown
- SSN: `ssn_hash = sha512(ssn + key)`, key resolved locally via the security package's
  LocalStrategy. Ciphertext is never used for matching; SSN is never returned (ssn_last_four only).
```

with:

```markdown
- **SSN: none.** The hub stores no SSN, no `ssn_hash` and no `ssn_last_four`, and reads none out of
  `streamline_local`. Confluence Delivery Checklist §1 requires internal verified data to stream via
  CDC with the SSN never stored, and the GPP Data Model has no SSN column in the golden layer. There
  is nothing to configure — no key, no `GP_SSN_*` variable, no `encryption_keys` access.

  It used to store a CAMI-encrypted SSN plus `ssn_hash` plus `ssn_last_four`, and matched on the hash
  at 0.99 as the strongest key in Pass A. Two figures from that era are worth keeping, because they
  are the measured reason the tier needed a guard at all and they will be relevant to anyone
  designing a future exact-match key: the source data carried **17 filler hashes covering 9,164
  distinct people, the worst single hash shared by 9,072 of them**. An exact 0.99 key with no name or
  DOB cross-check collapses every one of those people into a single identity. Any new exact key —
  plan 5 proposes MMIS and `(state, provider#)` — needs the same cardinality screen from the start.

  Removing the tier cost a known amount of recall (8/9 on the eval set) and un-merged nothing; see
  `docs/EVALUATION.md`.
```

- [ ] **Step 2: Correct the client-integration notes in `docs/RUNNING.md`**

At line 47, append a parenthetical to the `golden-profile:lookup` bullet:

```markdown
- `app/Console/Commands/GoldenProfileLookup.php` — `php artisan golden-profile:lookup {last} --first= --registry= --ssn=`.
  The `--ssn=` flag still exists client-side and still reaches the API, but it no longer narrows
  which identity resolves — the hub has no `ssn_hash` to match. It now only gates credential matches
  whose own scrape recorded an SSN. See the contract note in `PROJECT_PLAN.md` §7.
```

At line 58, replace the closing sentence of the verification paragraph:

```markdown
and `ssn_hash` is never exposed to the client.
```

with:

```markdown
and no SSN-derived value is exposed to the client — which is now structural rather than filtered:
there is no such column to expose. `ssn_last_four` was the last one and it was removed with the rest.
```

- [ ] **Step 3: Retire the provisioning step**

In `docs/PROVISIONING.md`, replace the whole `## 5. Shared SSN encryption key (before Phase 2)`
section (lines 51–59) with:

```markdown
## 5. Shared SSN encryption key — NOT REQUIRED (retired)

**Nothing to do.** This section used to require access to CAMI's shared plaintext SSN key and its
`encryption_keys` registry, so that gp-cami's ciphertext and `ssn_hash` would align with CAMI's. The
GPP conformance programme removed SSN storage and matching entirely (Confluence Delivery Checklist
§1), so there is no key to obtain, no `GP_SSN_ENCRYPTION_KEY_ID` to set and no registry access to
arrange.

Kept as a heading rather than deleted because the requirement was circulated: anyone holding a
provisioning checklist that lists it should know it was retired, not overlooked.
```

and at line 74 replace the checklist item:

```markdown
- [ ] Shared SSN key access confirmed; `GP_SSN_ENCRYPTION_KEY_ID` set.
```

with:

```markdown
- [x] ~~Shared SSN key access confirmed; `GP_SSN_ENCRYPTION_KEY_ID` set.~~ **Retired** — SSN storage
      and matching were removed; see §5.
```

- [ ] **Step 4: Correct `PROJECT_PLAN.md` and record the contract change**

Six edits.

(a) Line 24 (§2), replace the SSN clause:

```markdown
is **SELECT-only** by grant · hub stores **CAMI-encrypted SSN** + `ssn_last_four` (+ `ssn_hash` if CAMI encryption is non-deterministic); never plaintext, never returned in responses.
```

with:

```markdown
is **SELECT-only** by grant · hub stores **no SSN-derived data at all** — no ciphertext, no `ssn_hash`, no `ssn_last_four`. It does not even read those columns from the source. Superseded 2026-09-03 by the GPP conformance programme; see §7.
```

(b) Line 83 (the phase table), replace the Pass A key list:

```markdown
| 2 | Deterministic Pass A (keys 1–6: ssn_hash, npi, dea, upin, name+dob, license+state) + T1–T6, T8–T9 | precision ≥ 0.99 on fixtures **and held-out eval set** |
```

with:

```markdown
| 2 | Deterministic Pass A (keys 1–5: npi, dea, upin, license+state, name+dob) + T1–T6, T8–T9 | precision ≥ 0.99 on fixtures **and held-out eval set** |
```

(c) Replace §7's request-contract bullets (lines 105–111) with:

```markdown
- `POST /api/v1/credential-search` — → latest **qualifying** credential match + `prior_resolution`. Qualifying set from `MatchSummaryStatus` (`streamlineverify/sv`), filtered on `match_summary_status_code`, + `expiry_date` guard. `LIMIT 1` best match; log warning on multi-identity.
  - **Required:** `registry`, `first_name`, `last_name`
  - **Optional (narrow resolution):** `license_number`, `license_type`, `dob`
  - **Optional (credential gating only):** `ssn`
  - **Note:** supersedes master spec §10 assumption (`last_name`+`license_number` required). Confirmed with CAMI 2026-07-20.
```

(d) Replace the whole `**SSN storage & matching …— RESOLVED 2026-07-20 …**` block (lines 112–117)
with the contract-change record. This is the passage that has to be *communicated*, not just
corrected:

```markdown
**SSN storage & matching — REMOVED 2026-09-03 by the GPP conformance programme.**

Supersedes the 2026-07-20 resolution in full. That resolution had the hub storing a CAMI-encrypted
SSN (AES-256-CBC, random IV, so ciphertext could not be compared), matching on
`ssn_hash = sha512(plaintext_ssn + plaintext_key)`, and returning `ssn_last_four`. Confluence
Delivery Checklist §1 requires that internal verified data stream via CDC and that the SSN never be
stored, and the GPP Data Model has no SSN column in the golden layer. The contradiction was put to
the project owner, who chose to change the code rather than the docs.

The hub now stores no SSN-derived value, matches on none, and reads none from `streamline_local`.
The `streamlineverify/security` hard dependency was already gone (`e64f73d` replicated its one key
lookup inline), so nothing about this required a coordinated release.

**API CONTRACT CHANGE — BREAKING. Consumers must be notified.**

| Surface | Before | After |
|---|---|---|
| `credential-search` `ssn` param | Narrowed identity resolution **and** gated credential matches | **Gates credential matches only.** Still accepted, still optional, never persisted, never logged |
| `credential-search` `ssn` format | 9 digits, dashes/spaces optional | 9 digits **or a bare last-four** — so a caller can stop putting a whole SSN on the wire |
| `credential-search` `identity.ssn_last_four` | Returned | **Removed** |
| `identity-search` `ssn_last_four` | Returned | **Removed** |
| `credential-search` `warnings[]` | Could carry `ssn_not_used_for_identity_resolution` | Always `[]`. **The key stays** — it is published, and a future warning needs somewhere to go |
| HTTP statuses | 200 / 404 / 409 | Unchanged |

The `ssn` parameter was **kept**, not removed, and the reason matters for anyone re-reading this
later: it did two unrelated jobs and only one touched stored data. The surviving job compares the
caller's value against `credential_matches.match->request_params.ssn`, read live from the source per
request — no hub write, no hash, no key. Dropping it would have returned one person's credential for
a request about another, which is a precision regression with no compliance benefit at all.

**Who has to be told, and what to tell them.** All three are inside the organisation; none of this
is a public API.

1. **The CAMI integration owner who confirmed the 2026-07-20 contract.** Send the table above.
   Ask them to confirm whether any caller reads `ssn_last_four` from either response — that is the
   only change that can break a consumer at runtime rather than merely change behaviour.
   *(This document names no individual; get the name from the project owner recorded at the top of
   this file rather than guessing, and do not route it via a channel the request did not name.)*
2. **The client-side consumers, by exact path**, in the CAMI client repo:
   - `app/Services/GoldenProfile/GoldenProfileClient.php` — `identitySearch()` / `credentialSearch()`.
     Any local model or DTO with an `ssn_last_four` property needs it removed.
   - `app/Console/Commands/GoldenProfileLookup.php` — `--ssn=` still works and still helps, but no
     longer narrows resolution. Worth a line in its `--help`.
   - `config/services.php` → `golden_profile` block. Unchanged, but the same team owns it.
3. **The next design review**, for the two things this plan deliberately did not decide:
   `gp_identity_resolution` and `gp_board_action` remain unwritten (plan 6), and the recall lost here
   is plan 5's to recover.

**Timing.** Notify **before** the migration runs against the shared hub, not after. Once
`ssn_last_four` is dropped, `gp_identity_profile` cannot serve it even if a consumer still asks —
the field simply disappears from the JSON.
```

(e) Correct the two phase-completion notes. Line 141:

```markdown
- **Phase 2** ✓ deterministic Pass A (ssn_hash/npi/dea/upin/license+state/name+dob) — backfill 104 employees → 32 identities.
```

becomes:

```markdown
- **Phase 2** ✓ deterministic Pass A — backfill 104 employees → 32 identities. Originally six keys including `ssn_hash`; five as of 2026-09-03 (npi/dea/upin/license+state/name+dob). The 32-identity figure is from the six-key era and would be higher on a rebuild today; see `docs/EVALUATION.md`.
```

and line 143:

```markdown
- **Phase 9** ✓ both endpoints live under `auth:sanctum` (200/404/422/401 verified); SSN hasher reproduces `sha512(ssn+key)`; `ssn_hash` never returned.
```

becomes:

```markdown
- **Phase 9** ✓ both endpoints live under `auth:sanctum` (200/404/422/401 verified). The SSN hasher this note cited was deleted 2026-09-03 along with every SSN-derived column; nothing SSN-shaped is stored or returned.
```

(f) Replace the two §11 open items (lines 172 and 174) that still ask for SSN infrastructure:

```markdown
- **CAMI**: ~~required request fields for `credential-search`~~ ✓ confirmed 2026-07-20 (registry/first/last required; license#/type/dob/ssn optional). **Contract narrowed 2026-09-03** — `ssn` no longer narrows resolution and `ssn_last_four` is no longer returned; notification is tracked in §7. Still open: live `match_summary_status` qualifying codes.
- **Security** — ~~encryption deterministic?~~ ~~resolved: non-deterministic AES-256-CBC; match via `sha512(ssn+key)`~~ **moot as of 2026-09-03: the hub stores no SSN.** The shared plaintext key and `encryption_keys` access are **no longer needed** and are struck from the infrastructure list; `streamlineverify/security` was already dropped in `e64f73d`. See §7.
```

- [ ] **Step 5: Verify nothing in the repo still describes SSN as a live feature**

There is no test for prose, so this grep stands in for one:

```bash
grep -rn -i "ssn" --include=*.md --include=*.php --include=*.sql --include=*.yml \
    . 2>/dev/null | grep -v "^./vendor/" | grep -v "^./.superpowers/" \
  | grep -v "is_ssn_match" | grep -v "docs/superpowers/plans/"
```

Expected: every remaining hit is one of three kinds, and nothing else.
1. **Prose explaining the removal** — `docs/RUNNING.md`, `docs/PROVISIONING.md`, `PROJECT_PLAN.md`,
   `docs/EVALUATION.md`, the tombstone comment in `config/golden_profile.php`, and the "why this is
   absent" comments in the pipeline classes.
2. **The retained credential gate** — `CredentialSelector`, `CredentialSearchRequest`,
   `CredentialSearchController`, `CredentialIdentityGateTest`,
   `CredentialSearchRequestRulesTest`. These are about a request parameter, not stored data.
3. **The provenance and baseline scripts** — `scripts/baseline-key-mix.sql`,
   `scripts/retire-ssn-tier-provenance.sql`, and the tests and fixture notes that name the retired
   tier deliberately.

`is_ssn_match` is filtered out of the grep because it is out of scope by design: it records why
CAMI's own exclusion matcher fired and holds no SSN-derived value. Anything outside these three
categories is a leftover — fix it before committing.

- [ ] **Step 6: Run the whole suite one last time and commit**

Run: `vendor/bin/phpunit && vendor/bin/pint --test`

Expected: PASS, 0 failures, 0 skipped. Pint clean.

```bash
vendor/bin/pint --dirty
git add docs/RUNNING.md docs/PROVISIONING.md PROJECT_PLAN.md
git commit -m "docs: record the SSN removal and the credential-search contract change

Corrects three documents that described a hub which no longer exists, including
PROJECT_PLAN §7 — the written record of a request contract confirmed with CAMI on
2026-07-20. A stale spec is read as current, which is worse than never having
written it.

§7 now carries the contract-change record with the before/after table and, more
importantly, WHO has to be told: the CAMI integration owner who confirmed the
original contract, and the client-side consumers by exact path
(GoldenProfileClient::identitySearch/credentialSearch and the
golden-profile:lookup --ssn= flag). Notification goes out BEFORE the migration
runs against the shared hub — once ssn_last_four is dropped the field simply
disappears from the JSON, whether or not a consumer still asks for it.

docs/PROVISIONING.md §5 (the shared SSN key) is marked retired rather than
deleted: the requirement was circulated, so anyone holding that checklist should
know it was retired and not overlooked.

Rehomes the measured filler-SSN figures from the deleted config block into
docs/RUNNING.md — 17 filler hashes over 9,164 distinct people, the worst shared
by 9,072. They are the evidence for why an exact 0.99 key with no name or DOB
cross-check needs a cardinality screen, which is directly relevant to plan 5's
proposed MMIS and (state, provider#) keys."
```

---

## Self-review

### Spec coverage

Every item in the scope brief is traced to a task. The two places the plan **deviates** from the
brief's starting map are called out rather than buried:

| Brief item | Task | Note |
|---|---|---|
| `SsnHasher.php` delete | 6 | Commit `e64f73d`'s rewrite is credited in the commit message — it is what makes the deletion purely local |
| `SsnHashGuard.php` delete + runtime `gp_ssn_hash_blocklist` | 6 (class), 7 (table) | The table has no migration; the drop is `DROP TABLE IF EXISTS` |
| `DeterministicResolver` tier, guard calls, `createIdentity`, `backfillKeys` | 2 | |
| `SqlBackfill` `KEY_TIERS`, `stg_ssn`, blocklist call, `MAX(s.ssn_hash)` rollup, INSERT columns | 3 | Also `tierCreate`/`tierLink` guards and `IDENTITY_KEY_INDEXES` |
| Set-based path must agree with per-row | 3 | Proven by `SetBackfillParityTest`, the first test to drive `SqlBackfill` |
| `Engine::dedup` `mergeByColumn`, `SsnHashGuard` member | 3 | Plus `applyMerge`'s inherited-column list, which the brief did not list |
| `Survivorship::IDENTITY_FIELDS` | 4 | Also stops the hash reaching `gp_attribute` / `gp_survivorship_audit`, which the brief did not list |
| `SetFinalizer` + `ProfileMaterializer` `ssn_hash`, `ssn_last_four`, `idx_ssn`, `ssn4` | 4 | |
| `StreamlineLocalConnector` ingest | 4 | |
| `CredentialSearchRequest` + `Controller`, contract change, who to tell | 5 (change), 9 (record + notify) | **Deviation:** the `ssn` param and the credential gate are KEPT. Argued at decision 2 |
| `IdentityProfileResource` — confirm what remains correct | 5 | Confirmed: it never emitted `ssn_hash`, and that was structural. `ssn_last_four` removed |
| `config/golden_profile.php` `ssn` block + `deterministic_keys['ssn_hash']` | 2 (key), 6 (block) | |
| New `2026_09_*` migration; never edit existing; reversibility | 7 | `down()` restores shape not data, argued in the migration docblock |
| `SsnHasherTest`, `SsnHashGuardTest` delete | 6 | |
| `DeterministicKeyConfigTest`, `ResolverLadderTest` surgery | 2, 6, 7 | |
| `CredentialIdentityGateTest` surgery | — | **Deviation:** needs none. All 16 tests exercise `CredentialSelector` alone — no hasher, no hub, no controller |
| `docs/RUNNING.md`, `PROJECT_PLAN.md` §7 | 9 | Plus `docs/PROVISIONING.md` §5 and four more `PROJECT_PLAN` locations the brief did not list |
| **Eval gate will fail, and that is correct** | 2 | Numbers predicted, re-baselined onto exact integers, `ssn-*` records kept, deletion forbidden and asserted |
| **Blast radius unmeasured** | 1 | Task 1 is the gate; new query 6 measures the actual number; threshold table gives a decision, and every row says proceed |
| **Re-resolution, not just column drops** | 8 | Decision 3: retain, do not un-merge. Argued from the guard's precision and from `resolve()`'s idempotency short-circuit |
| `ssn_last_four` stays or goes | decision 1, executed in 4 and 5 | **Goes.** Three arguments, the strongest being that it removes a live per-row/set-based divergence |

Two things the brief listed that turned out to be **wrong in a useful way**, both verified in the
code rather than assumed:

- `SetFinalizer` was said to own the `idx_ssn` index handling. It does — but so does `SqlBackfill`,
  independently, with a duplicate constant. Task 3 and Task 4 fix both and Task 4 adds the reflection
  test that stops them diverging again.
- The brief implies `ProfileMaterializer` and `SetFinalizer` agree on `ssn_last_four`. They do not:
  one has no `ORDER BY`. That became the third argument for decision 1.

### Placeholder scan

No TBDs, no "similar to Task N", no "add appropriate error handling". Every code-changing step shows
its code. The three placeholders that remain are deliberate and each is a value only a production
query can supply:

- `<DATE>`, `<FILL>` in Task 1 Step 4's markdown table — the baseline measurements.
- `<gp-host>`, `<gp-user>` in Task 1 Step 3 and Task 8 Step 6 — the operator's own credentials, which
  must never be committed to a public repo.
- `see the baseline table above` in Task 8 Step 5's table — filled at Task 8 Step 6.

Task 9 Step 4's notification list names **roles and exact file paths, not people**, and says so
explicitly: `PROJECT_PLAN.md` records only one individual (the project owner) and inventing a CAMI
contact would be worse than instructing the executor to ask.

### Type consistency

- Every signature quoted in an **Interfaces** block was read from the file, not recalled. Constants
  read by reflection (`KEY_TIERS`, `IDENTITY_FIELDS`, `IDENTITY_KEY_INDEXES`) are `private const`;
  `ReflectionClass::getReflectionConstant()->getValue()` reads private constants in PHP 8.4.
- `MatchScorer::score()` applies no rounding, so `recall` is exactly `(float) 8/9`. That is why the
  re-baseline is on `false_splits === 1` and `true_positives === 8` (both `int`) rather than the
  float.
- `gp_resolution_log.action` is `enum('create','merge','split','relink','override')` — Task 8 uses
  `'override'` because there is no `'retain'`. `reason` is `varchar(255)`; the string is 156
  characters and the plan says to re-count on edit, because MySQL in strict mode errors rather than
  truncating.
- `SqlBackfill::SYSTEM_CODE` is `'streamline_local'` while `HubTestCase::seedSystem()` appends a
  `uniqid()`, so Task 3's test looks the id up instead of reusing `$this->systemId`. Getting this
  wrong is the most likely way Task 3 fails for a reason that has nothing to do with SSN.
- No task references a type or method no task defines.

### Known risks carried into execution

1. **Task 2 is large** — one commit spanning the resolver, the config, three test files, the fixture
   and the docs. That is not padding: the moment the tier stops firing, `EvalGateTest` goes red, so
   splitting it would leave CI broken between two tasks. Its 13 steps are individually small. If a
   reviewer insists on a split, the only safe cut is *nothing* — the re-baseline must ship with the
   removal, which the authoring brief also requires.
2. **Task 3's tests drive `SqlBackfill` for the first time in the repo's history.** The reasoning
   that they can (constructor and `resolveDeterministic()` are hub-only; only `stage()` reads
   `streamline_local`) was verified by reading the code, but it has never been executed. If
   `new SqlBackfill` unexpectedly reaches the source connection, `phpunit.xml` points it at
   `127.0.0.1:1` and the failure will be an immediate connection refusal, not a silent read — loud,
   and safe. Fall back to asserting the SQL by source inspection if so, and say in the PR that
   set-based parity went unproven.
3. **The `gp_edge` enum narrowing can refuse.** Task 7's migration throws if `gp_edge` holds any row.
   `gp_edge` has no writer in this repo, so it should be empty — but "should" is doing work. The
   error message tells the operator exactly what to do (inspect, then drop that one statement), and
   the member is inert either way, so a refusal costs nothing but a re-run.
4. **`down()` cannot restore the data, only the shape.** Anyone rolling back and expecting the hub to
   work as it did will find NULL columns and no code reading them. The migration docblock says this
   in as many words. There is no better answer available.
5. **The baseline may never arrive.** Task 1 Step 3 and Task 8 Step 6 both need production
   credentials nobody in this environment has. Task 1 Step 4's last paragraph and Task 7 Step 1 both
   define the fallback: merge the code and the migration, hold only Task 8's script, record the date
   it was handed over and to whom. The plan does not stall on it.
6. **Recall is lower after this plan than before, and stays lower until plan 5.** 8/9 on the eval set,
   and an unmeasured amount on the real hub. That is the accepted price of the scope decision, and
   the eval gate now ratchets on it, so nobody can accidentally regress *further* without the build
   saying so. Plan 5 is where it comes back.
7. **The notification is a human step with no automated backstop.** If it does not happen before the
   migration runs, a consumer reading `ssn_last_four` gets a missing field with no warning. Task 9
   Step 4 puts the timing requirement in bold; there is nothing in code that can enforce it.

### Scope, honestly

Nine tasks, at the top of the brief's 6–9 range, and the scope is genuinely there — the removal
reaches 22 files across resolution, materialization, the API, config, migrations, tests and four
documents. It did **not** need a split, and the reason is worth recording: only Task 2 is
irreducible, and the other eight are each independently reviewable and independently revertible. A
reviewer can reject Task 5's contract narrowing while approving Tasks 2–4, or reject Task 7's
`gp_edge` narrowing while approving the column drops.

The one thing that *was* pulled out of scope rather than crammed in: **a `pin_reason` column on
`gp_source_link`.** Task 8 considered pinning the retained links and rejected it (`is_pinned` also
suppresses `enrich()`), but the underlying gap is real — the hub cannot distinguish a steward's pin
from a machine's. That belongs to plan 6, which owns the steward writer layer, and this plan flags it
there rather than adding a fifth column to a migration about removing columns.

