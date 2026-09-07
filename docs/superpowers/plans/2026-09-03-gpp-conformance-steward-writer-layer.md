# GPP Conformance — Steward Writer Layer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development
> (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn on the resolution-reuse loop the design set calls its headline payoff — by ingesting
the steward decisions CAMI *already makes* (`match_actions`, `credential_match_resolutions`) into
`gp_identity_resolution` and `gp_identity_exclusion.link_state` — without building a UI, without
losing those decisions on the next sync, and without moving the eval gate.

**Architecture:** A pure mapping layer (`ResolutionMapper`) turns two CAMI-native decision shapes
into one write shape; one writer (`ResolutionRecorder`) owns the SCD-2-by-hand bookkeeping
`gp_identity_resolution` already has (`is_current` + `uq_action`), so idempotency and currency live
in exactly one place; one orchestrator (`ResolutionIngest`) reads `streamline_local` on a watermark
and calls both. A pre-existing landmine in `Engine`'s versioned rollups — which would otherwise
revert every steward decision on the very next `gp:sync` — is fixed first, because writing decisions
into a table that gets silently overwritten a few minutes later is worse than not writing them at
all. Two dead config keys are resolved (one deleted, one relocated and wired), and the oversized-block
decline that currently mints a silent false split gets a resolution-log row and a `review` flag.
`gp_board_action` ingest is explicitly **out of scope** — see Self-review for why and where it goes.

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
- **`SRC_DB_*` is also a dead socket in tests** (`phpunit.xml` points it at `127.0.0.1:1`, same as
  `GP_DB_*`). Nothing in `tests/` has ever connected to a real `streamline_local` — `Engine::sync()`,
  `backfill()`, `rollupCredentials()`, `rollupExclusions()` are all consequently untested against a
  live source today. This plan follows the same precedent rather than inventing a new source-test
  harness: every method that reads `streamline_local` is kept thin (fetch + chunk only), and all its
  decision logic is extracted into a sibling method that takes an already-fetched `Collection` and is
  fully tested against the hub alone.
- **No new migrations are needed by this plan.** Every column this plan writes to
  (`gp_identity_resolution.*`, `gp_identity_exclusion.link_state`) already exists. If your
  implementation finds it needs one, stop and reconsider — that would mean a design assumption below
  was wrong.

---

## Programme context — this is plan 6 of 8

| # | Plan | Depends on | Status |
|---|---|---|---|
| 1 | Foundation — eval set, scorer, resolver harness, CI | — | **DONE, merged into this branch** |
| 2 | SSN removal | 1 | to write |
| 3 | SCD-2 versioning | 1 | **written, not yet executed** |
| 4 | Individual vs entity | 1, 3 | to write |
| 5 | Match keys & data quality | 1 | to write |
| **6** | **Steward writer layer** | **1, 3** | **this plan** |
| 7 | Exclusion lifecycle | 1, 3 | to write |
| 8 | Incremental profiling | 1, 5 | to write |

This plan sits after SCD-2 versioning because its central write — an exclusion `link_state`
transition — is specified by the design set as a new *version*, not an in-place update, and
`gp_identity_exclusion` only gains the columns and the `Versioner` primitive that make that possible
once plan 3 has executed. It sits before plan 7 (exclusion lifecycle) because plan 7's automated
lifecycle rules (e.g. re-screening cadence, expiry) are more useful once there is a real
`link_state`/`gp_identity_resolution` history to reason about, and before plan 8 (incremental
profiling) because a correct `gp_identity_resolution` is part of what a profile should reflect.

**What this plan assumes has landed (plan 3), stated explicitly because plan 3 is written but not
executed and this plan's code is written against its target shape, not the current repo:**

- `App\GoldenProfile\Support\Versioner` exists exactly as specified in
  `docs/superpowers/plans/2026-09-03-gpp-conformance-scd2-versioning.md` Task 5:
  `Versioner::write(string $table, array $key, array $attributes, array $derived = [], array $onCreate = []): array{version_no:int,new_version:bool}`,
  `Versioner::current(string $table, array $key): ?object`, `Versioner::retire(string $table, array $key): int`,
  `new Versioner` with no constructor argument resolves the connection from
  `config('golden_profile.connections.hub')`, i.e. `'golden_profile'`.
- `gp_identity_exclusion` and `gp_identity_credential` are versioned: each row now carries
  `version_no`, `current` (the SCD-2 flag — bookkeeping only, do not confuse with CAMI's own
  currency flag), `date_created`, `date_updated`, and `link_state` is a declared `Versioner`
  **attribute** on both tables (per plan 3 Task 5's `TABLES` const). A `link_state` transition is
  therefore always a `Versioner::write()` call in this plan, never a plain `update()`.
- `gp_identity_credential.current` (CAMI's own mirrored currency flag) has been renamed to
  `source_current` (plan 3 Task 2). This plan's one write to that table's row shape
  (`writeVersionedCredentialRollup`, Task 3 below) uses `source_current`, never `current`.
- `Engine::rollupCredentials()` / `Engine::rollupExclusions()` have been converted from a batched
  `upsert()` to a per-row `$this->versioner->write(...)` call, exactly as plan 3 Task 8 Step 5
  specifies (quoted in full in Task 3 below, since this plan's own Modify step needs the complete
  before-code, not a cross-reference). `Engine` holds a `private Versioner $versioner` set in its
  constructor, mirroring `Survivorship`'s.
- `gp_identity_resolution` is explicitly **untouched** by plan 3 — it is called out as "already
  SCD-2" via its own pre-existing `is_current` column and is left alone on purpose. This plan writes
  to it using that existing column directly; it does not use `Versioner` for this table (the natural
  key for currency, `(identity_id, domain, target_key)`, differs from the key for de-duplication,
  `(system_id, source_action_table, source_action_id)`, which `Versioner`'s one-natural-key model
  does not support).
- `is_pinned` on `gp_source_link` is **unrelated to plan 3** and already fully implemented today:
  `DeterministicResolver::resolve()` skips both re-matching and `enrich()` for a pinned link. The
  GPP steward-decision vocabulary's `pin_match` is therefore already solved and needs no work in
  this plan.

---

## The design questions, answered

### 1. gp-cami is API-only — what is the steward surface?

**Ingest of decisions CAMI already makes.** Verified against `sv-manila/client` (2026-09-04):

- **Exclusion matches** already have a full steward decision log: `match_actions` (id, `action_type`,
  `match_id` → `matches.id`, `user_id`, `note`, `status`, `date_created`, plus `resolution_metadata`,
  `is_auto_resolved`, `resolved_via` added later). `Streamlineverify\SV\MatchAction\MatchAction`
  defines `TYPE_CONFIRMED = 'confirm'` (→ `matches.status` becomes `CONFIRMED_MATCHES`) and
  `TYPE_RESOLVED = 'resolve'` (→ `MATCHES_RESOLVED`, i.e. the steward dismissed it as not a real
  hit). This is a direct, verified source for `gp_identity_exclusion.link_state`'s
  `confirmed`/`rejected` transition.
- **Credential matches** already have their own reuse-by-key mechanism, and it is the literal
  in-code ancestor of `gp_identity_resolution`: `CredentialMatch::getEquivalentMatchWithResolution()`
  joins `credential_match_resolutions` (id, `credential_match_id`, `note`, `created_at`) to reuse a
  prior manual "this INVALID/name-mismatch match is actually VALID" decision
  (`CredentialMatch::resolve($note)`, called from the `ResolveCredentialMatch` command), scoped by
  `CredentialMatch::scopeWithKey($employeeId, $registry, $credentialId, $licenseTypeId)`. There is no
  credential-side "reject" in CAMI today — `resolve()` is one-directional (invalid → valid) — so this
  plan's credential ingest only ever produces a `confirm` decision, and says so rather than inventing
  a `reject` path with no source.
- `credential_match_actions` (mentioned in the brief) turned out on inspection to be a *different*
  table — system-generated notes for `expired`/`name_mismatch` events, not a steward decision log —
  so it is not ingested here; `credential_match_resolutions` is the correct source.
- A `steward_decision` table with `decision in (pin_match, split, confirm, reject)` is **not built**.
  `pin_match` is already implemented (`is_pinned`, see Programme context). `split` has **no CAMI-side
  signal at all** — un-merging two golden identities is a gp-cami-internal judgement with nothing in
  `match_actions` or `credential_match_resolutions` that means "these two employee records are not
  the same person" — so it is honestly left for a future plan rather than fabricated. `confirm`/
  `reject` are exactly what this plan ingests.
- No UI is built, per §8 of the design set and commit `f0a3126` ("make gp-cami API-only"), both of
  which explicitly rule it out. The surface is one artisan command, `gp:ingest-resolutions`, run on
  the same cadence as `gp:sync`.

### 2. Resolution reuse — what must `target_key` contain, and what is the over-merge risk?

`CredentialSearchController::priorResolution()` (already-shipped code, unmodified by this plan)
builds `target_key = "{registry}:{license_number}"`, with `:{license_type}` appended when present,
and looks it up scoped to `(identity_id, domain='credential', target_key, is_current=1)`. This
plan's ingest **must construct the identical string** from the source's `credential_matches.registry`
/ `credential_matches.credential_id` / `credential_matches.license_type_id` columns — verified real
columns, not license-number in gp-cami's own vocabulary but the same underlying value (CAMI's own
`CredentialMatch::scopeWithKey()` uses `credential_id` for exactly this purpose). A mismatch between
what ingest writes and what search reads would silently break reuse with no error anywhere, so
`ResolutionMapper::credentialTargetKey()` is the **one** place this string is built, and both the
ingest test and a dedicated unit test pin it byte-for-byte (Task 1).

**The over-merge risk, stated precisely by comparing to what CAMI itself does:** CAMI's own reuse
(`getEquivalentMatchWithResolution()`) is scoped by `scopeWithKey($employeeId, ...)` — **one
employee**. It never reuses a decision across two different employee records, even ones CAMI itself
would consider the same real person on re-check. gp-cami's `target_key` reuse, by design, is scoped
to `identity_id` — every source record folded into that golden identity, potentially many employees
across many accounts. That is the entire payoff (a decision made once, reused everywhere the same
person appears) and it is *exactly* where an over-merged identity turns dangerous: if two different
people were wrongly folded into one `gp_identity` (a matching-quality failure that plans 4/5/7 and
the eval gate are the actual defenses against), a steward's `confirm` for one of them auto-applies to
the other's employee record too. This plan does not add a new guard against over-merging — that is
not this plan's problem to solve, and building one without knowing WHICH over-merges are wrong risks
manufacturing false confidence — but it does add the one guard that is in scope and genuinely
new: a **decay window** (design question 3) bounding how long a reused decision stays valid without
being reaffirmed, so a resolution against a since-corrected merge does not auto-apply forever. This
is stated as a real, un-eliminated risk in Self-review, not something this plan claims to have
solved.

No change to `CredentialSearchController`'s matching/response contract is needed for reuse itself —
it already reads `gp_identity_resolution` correctly; the only thing that was missing was real data in
the table. The one controller change this plan makes is the decay window (Task 8).

### 3. `status_severity` and `internal_verified_decay_days` — wire or delete?

**`status_severity`: deleted.** Its value set (`revoked`/`suspended`/`excluded`/`lapsed`/`expired`/
`active`) describes a license-or-exclusion *status* conflict. Verified across the whole ingest path
that no such vocabulary exists anywhere upstream of gp-cami:

- `stg_person_license` (the staging shape): license_number, certification_state/board, type, type_id,
  registry, is_primary — no status column.
- `StreamlineLocalConnector`'s license-flattening (`addLic()` closures, both the primary-employee and
  alt-license-set variants): maps exactly those same fields, never a status.
- `src_exclusion_record` (the exclusion transport mirror): `id`, `exclusion_list_prefix` only.
- The real CAMI `exclusion_records.match` column holds the raw per-registry payload as unstructured
  JSON — confirmed by inspecting `Streamlineverify\SV\Match\Presenter`, which normalizes ~10 raw
  field-name variants (`action_dt`, `active_date`, `begindate`, `action_date`, `begin_date`, …) just
  for ONE date attribute, and that normalization differs per registry. There is no status field
  mirrored into gp-cami at all.

`Survivorship::recompute()` only ever resolves `IDENTITY_FIELDS` (name/dob/npi/upin/dea/ssn_hash)
from `stg_person` columns; its set-based twin `SetFinalizer::survivorship()` mirrors exactly the same
scope (verified: both key off `authorityRankSql`/`authorityRank`, `field_authority.identity`, no other
field group). Neither has a hook for a license- or exclusion-level status at all — wiring
`status_severity` there would mean **inventing** a capability, not fixing an existing one, and doing
so on a fabricated data source. Deleted, with a code comment explaining exactly this (Task 8) so a
future engineer does not treat the deletion as an oversight.

**`internal_verified_decay_days`: wired, but relocated.** Its placement under `survivorship` never
had anywhere to plug in for the same reason above — no per-license/per-credential verification
timestamp ever reaches `Survivorship`. But the *concept* — an internal verification stops being
trustworthy after N days — maps exactly onto the one place this plan gives the hub a real internal
verification with a timestamp: a steward's `resolved_at` on `gp_identity_resolution`, read by
`CredentialSearchController::priorResolution()`'s reuse check (design question 2). Moved to
`golden_profile.resolution.reuse_decay_days` (same value, 365, carried over), consumed by a new
`CredentialSearchController::isWithinReuseDecayWindow()` static helper (Task 8). This is the
concrete "new capability, not a wiring" the assignment asks to distinguish: **new** — nothing today
reads `resolved_at` for a decay purpose, this plan's own Task 2/4/5 are what first populate it.

### 4. Oversized-block review flagging

`ProbabilisticResolver::match()` declines to score a block over `block_size_cap` and returns
`[null, 0.0, 'no_match']` — indistinguishable, to the caller, from an ordinary "no candidate found"
result. `DeterministicResolver::resolve()` then mints a brand-new identity and stamps its
`gp_source_link.match_state` with the default `'auto_match'`, with no log line anywhere. The config
comment ("oversized blocks flagged for steward, never truncated") describes only the "never
truncated" half.

**The fix (Task 7):** `match()` returns a third, distinct state — `'declined_oversized'` — only for
this case (an ordinary no-candidate-scored-high-enough result still returns `'no_match'`).
`DeterministicResolver::resolve()` checks for it specifically: the identity is still created (the
`SqlBackfill` residual-create precedent already treats "no key hit" as "new identity" — this plan
does not change that outcome), but `match_state` is set to the already-existing `'review'` enum value
instead of `'auto_match'`, and a `gp_resolution_log` row is written (`action='create'`,
`match_key='oversized_block'`, `reason` naming the measured block size and the configured cap).

**What a steward does with it, stated honestly:** there is no endpoint to act on this today (API-only,
no UI, and this plan does not add identity-mutation endpoints — that is out of scope). The
remediation path today is the same as every other identity-level correction in this hub: a support
engineer queries `gp_source_link WHERE match_state = 'review'` joined to
`gp_resolution_log WHERE match_key = 'oversized_block'`, decides by hand whether the new identity is
correct or should be pointed at an existing one, and (if repointing) uses the existing `is_pinned`
mechanism to lock the correction in place. Building a `gp:steward-review` command or an endpoint to
do this without a manual SQL touch is real, valuable follow-on work — explicitly not this plan's
scope, which is the *writer* layer (data reaching the hub), not a new steward workflow surface.

**`SqlBackfill` needs no equivalent change.** Its own docblock states Pass B is intentionally skipped
on the bulk path ("on this single source Pass A resolves every non-distinct record; Pass B otherwise
just creates a new identity, which the residual step does anyway") — confirmed by grep, `SqlBackfill`
never calls `ProbabilisticResolver` at all. The oversized-block decline can only happen on the
per-row (`DeterministicResolver`) path.

---

## File Structure

| File | Responsibility |
|---|---|
| `app/GoldenProfile/Support/ResolutionMapper.php` | **Create.** Pure mapping: CAMI action vocabulary → gp-cami `link_state`/`decision`; the two `target_key` builders. No database. |
| `app/GoldenProfile/Support/ResolutionRecorder.php` | **Create.** The one place `gp_identity_resolution` is written: idempotent on `(system_id, source_action_table, source_action_id)`, retires the prior current row per `(identity_id, domain, target_key)`. |
| `app/GoldenProfile/Engine.php` | **Modify.** Extracts the per-row versioned-rollup write into two public methods and stops them from reverting a steward's `link_state` on every sync. |
| `app/GoldenProfile/ResolutionIngest.php` | **Create.** Orchestrator: `processExclusionActions()`/`processCredentialResolutions()` (hub-only, fully tested), `ingestExclusionActions()`/`ingestCredentialResolutions()` (thin `streamline_local` fetch + watermark, untested against a live source — same precedent as `Engine::sync()`). |
| `app/Console/Commands/GpIngestResolutions.php` | **Create.** `gp:ingest-resolutions` — thin CLI wrapper, no logic of its own. |
| `app/GoldenProfile/Resolution/ProbabilisticResolver.php` | **Modify.** `match()` returns `'declined_oversized'` instead of `'no_match'` when the block-size cap declines to score. |
| `app/GoldenProfile/Resolution/DeterministicResolver.php` | **Modify.** Handles `'declined_oversized'`: `match_state = 'review'` + a `gp_resolution_log` row. |
| `app/Http/Controllers/Api/V1/CredentialSearchController.php` | **Modify.** Adds `isWithinReuseDecayWindow()` and applies it to `auto_resolvable` in `priorResolution()`. |
| `config/golden_profile.php` | **Modify.** Deletes `survivorship.status_severity`; relocates `survivorship.internal_verified_decay_days` to `resolution.reuse_decay_days`. |
| `tests/Unit/ResolutionMapperTest.php` | **Create.** |
| `tests/Feature/ResolutionRecorderTest.php` | **Create.** |
| `tests/Feature/VersionedRollupPreservesLinkStateTest.php` | **Create.** |
| `tests/Feature/ResolutionIngestExclusionTest.php` | **Create.** |
| `tests/Feature/ResolutionIngestCredentialTest.php` | **Create.** |
| `tests/Feature/ResolutionIngestWatermarkTest.php` | **Create.** |
| `tests/Feature/OversizedBlockReviewFlagTest.php` | **Create.** |
| `tests/Unit/CredentialResolutionDecayTest.php` | **Create.** |
| `docs/EVALUATION.md` | **Modify.** One paragraph recording that the gate was re-run and held. |

---

## Task 1: `ResolutionMapper` — pure mapping, no database

**Files:**
- Create: `app/GoldenProfile/Support/ResolutionMapper.php`
- Test: `tests/Unit/ResolutionMapperTest.php`

**Interfaces:**
- Produces:
  - `ResolutionMapper::exclusionAction(string $actionType): array{link_state:string,decision:string}`
  - `ResolutionMapper::exclusionTargetKey(string $registry, int $exclusionRecordId): string`
  - `ResolutionMapper::credentialTargetKey(string $registry, string $credentialId, ?string $licenseTypeId): string`
- Consumed by: `ResolutionIngest` (Tasks 4, 5).

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/ResolutionMapperTest.php`:

```php
<?php

namespace Tests\Unit;

use App\GoldenProfile\Support\ResolutionMapper;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Pure mapping, verified against sv-manila/client (2026-09-04):
 * Streamlineverify\SV\MatchAction\MatchAction::TYPE_CONFIRMED = 'confirm',
 * TYPE_RESOLVED = 'resolve'. 'confirm' moves matches.status to
 * CONFIRMED_MATCHES; 'resolve' moves it to MATCHES_RESOLVED — i.e. a steward
 * dismissed the match as not a real hit. No database needed — same pattern as
 * CredentialSelector/NameMatcher, which test mapping logic without a hub.
 */
class ResolutionMapperTest extends TestCase
{
    public function test_confirm_maps_to_confirmed_link_state_and_confirm_decision(): void
    {
        $map = ResolutionMapper::exclusionAction('confirm');

        $this->assertSame('confirmed', $map['link_state']);
        $this->assertSame('confirm', $map['decision']);
    }

    public function test_resolve_maps_to_rejected_link_state_and_reject_decision(): void
    {
        // CAMI's 'resolve' on an exclusion match means the steward dismissed it
        // as not a real hit (matches.status -> MATCHES_RESOLVED) — the OPPOSITE
        // of what the credential domain means by a resolution (see
        // ResolutionIngest::processCredentialResolutions, which never calls
        // this method and uses its own fixed 'confirm' decision instead).
        $map = ResolutionMapper::exclusionAction('resolve');

        $this->assertSame('rejected', $map['link_state']);
        $this->assertSame('reject', $map['decision']);
    }

    public function test_unknown_action_type_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown match_actions.action_type: bogus');

        ResolutionMapper::exclusionAction('bogus');
    }

    public function test_exclusion_target_key_is_registry_colon_exclusion_record_id(): void
    {
        $this->assertSame('LEIE:4821', ResolutionMapper::exclusionTargetKey('LEIE', 4821));
    }

    public function test_credential_target_key_matches_credential_search_controller_exactly(): void
    {
        // Must byte-for-byte match CredentialSearchController::priorResolution()'s
        // construction: registry.':'.license_number, plus ':'.license_type only
        // when present. A mismatch here silently breaks reuse — ingest would
        // write one key, search would look up another, and nothing would error.
        $this->assertSame('NPDB:A123456', ResolutionMapper::credentialTargetKey('NPDB', 'A123456', null));
        $this->assertSame('NPDB:A123456', ResolutionMapper::credentialTargetKey('NPDB', 'A123456', ''));
        $this->assertSame('NPDB:A123456:MD', ResolutionMapper::credentialTargetKey('NPDB', 'A123456', 'MD'));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/ResolutionMapperTest.php`
Expected: FAIL — `Class "App\GoldenProfile\Support\ResolutionMapper" not found`.

- [ ] **Step 3: Write the implementation**

Create `app/GoldenProfile/Support/ResolutionMapper.php`:

```php
<?php

namespace App\GoldenProfile\Support;

use InvalidArgumentException;

/**
 * Pure mapping from CAMI's own steward-decision vocabulary onto gp-cami's
 * gp_identity_resolution / link_state vocabulary. No database access — see
 * ResolutionRecorder for the write side, kept separate so this class needs no
 * hub connection to test (same split as CredentialSelector/NameMatcher).
 *
 * SOURCE OF TRUTH (verified against sv-manila/client, 2026-09-04):
 *
 *   match_actions.action_type — Streamlineverify\SV\MatchAction\MatchAction:
 *     TYPE_CONFIRMED = 'confirm'  -> matches.status becomes CONFIRMED_MATCHES
 *     TYPE_RESOLVED  = 'resolve'  -> matches.status becomes MATCHES_RESOLVED,
 *                                    i.e. a steward dismissed it as not a real
 *                                    hit. Deliberately NOT reused for the
 *                                    credential domain, whose "resolve" means
 *                                    the opposite thing (see ResolutionIngest).
 *
 *   credential_match_resolutions — Streamlineverify\SV\CredentialMatch\
 *     Resolution, written only by CredentialMatch::resolve(), which always
 *     means "an INVALID/name-mismatch match was manually confirmed VALID".
 *     There is no credential-side reject in CAMI's current implementation, so
 *     this class exposes no exclusionAction()-style method for it —
 *     ResolutionIngest::processCredentialResolutions() hardcodes decision =
 *     'confirm' rather than pretending a mapping exists for a case CAMI
 *     cannot produce.
 */
class ResolutionMapper
{
    /** @return array{link_state:string,decision:string} */
    public static function exclusionAction(string $actionType): array
    {
        return match ($actionType) {
            'confirm' => ['link_state' => 'confirmed', 'decision' => 'confirm'],
            'resolve' => ['link_state' => 'rejected', 'decision' => 'reject'],
            default => throw new InvalidArgumentException("unknown match_actions.action_type: $actionType"),
        };
    }

    /**
     * A specific exclusion-registry listing, independent of which employee's
     * screening surfaced it — exclusion_record_id identifies the listing
     * itself (see src_exclusion_record / matches.exclusion_record_id), the
     * same way credentialTargetKey() below keys on the credential itself
     * rather than on the employee.
     */
    public static function exclusionTargetKey(string $registry, int $exclusionRecordId): string
    {
        return $registry.':'.$exclusionRecordId;
    }

    /**
     * MUST match CredentialSearchController::priorResolution()'s construction
     * exactly: registry.':'.license_number, plus ':'.license_type only when
     * present. $credentialId is CAMI's credential_matches.credential_id — the
     * same value gp-cami's own request/response vocabulary calls
     * "license_number" (verified: CredentialMatch::scopeWithKey() uses
     * credential_id for the identical reuse-scoping purpose CAMI's own code
     * already implements, per-employee).
     */
    public static function credentialTargetKey(string $registry, string $credentialId, ?string $licenseTypeId): string
    {
        $key = $registry.':'.$credentialId;

        if ($licenseTypeId !== null && $licenseTypeId !== '') {
            $key .= ':'.$licenseTypeId;
        }

        return $key;
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/ResolutionMapperTest.php`
Expected: PASS, 5 tests.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/GoldenProfile/Support/ResolutionMapper.php tests/Unit/ResolutionMapperTest.php
git commit -m "feat(resolution): add pure mapping from CAMI's decision vocabulary to gp-cami's"
```

---

## Task 2: `ResolutionRecorder` — the one writer of `gp_identity_resolution`

**Files:**
- Create: `app/GoldenProfile/Support/ResolutionRecorder.php`
- Test: `tests/Feature/ResolutionRecorderTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: `ResolutionRecorder::__construct(int $systemId)`,
  `ResolutionRecorder::record(array $row): bool` — `$row` keys: `identity_id`, `domain`, `target_key`,
  `action_type`, `decision`, `decision_status`, `resolution_metadata` (array), `resolved_by`,
  `resolved_at`, `source_action_table`, `source_action_id`, `is_auto_resolvable`. Returns `true` if a
  new row was written, `false` if `(system_id, source_action_table, source_action_id)` was already
  ingested.
- Consumed by: `ResolutionIngest` (Tasks 4, 5).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ResolutionRecorderTest.php`:

```php
<?php

namespace Tests\Feature;

use App\GoldenProfile\Support\ResolutionRecorder;
use Tests\Support\HubTestCase;

class ResolutionRecorderTest extends HubTestCase
{
    private function recorder(): ResolutionRecorder
    {
        return new ResolutionRecorder($this->systemId);
    }

    private function baseRow(array $overrides = []): array
    {
        return array_merge([
            'identity_id' => 1,
            'domain' => 'exclusion',
            'target_key' => 'LEIE:4821',
            'action_type' => 'confirm',
            'decision' => 'confirm',
            'decision_status' => null,
            'resolution_metadata' => ['note' => 'test'],
            'resolved_by' => 7,
            'resolved_at' => '2026-08-01 10:00:00',
            'source_action_table' => 'match_actions',
            'source_action_id' => 555,
            'is_auto_resolvable' => true,
        ], $overrides);
    }

    public function test_records_a_new_current_resolution(): void
    {
        $wrote = $this->recorder()->record($this->baseRow());

        $this->assertTrue($wrote);
        $row = $this->hub()->table('gp_identity_resolution')
            ->where('source_action_table', 'match_actions')->where('source_action_id', 555)->first();
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row->is_current);
        $this->assertSame('confirm', $row->decision);
    }

    public function test_re_ingesting_the_same_source_action_id_is_a_no_op(): void
    {
        $this->recorder()->record($this->baseRow());
        $wroteAgain = $this->recorder()->record($this->baseRow());

        $this->assertFalse($wroteAgain);
        $count = $this->hub()->table('gp_identity_resolution')
            ->where('source_action_table', 'match_actions')->where('source_action_id', 555)->count();
        $this->assertSame(1, $count);
    }

    public function test_a_later_decision_on_the_same_target_retires_the_earlier_one(): void
    {
        $this->recorder()->record($this->baseRow(['source_action_id' => 1, 'decision' => 'confirm']));
        $this->recorder()->record($this->baseRow([
            'source_action_id' => 2, 'decision' => 'reject', 'action_type' => 'resolve',
        ]));

        $rows = $this->hub()->table('gp_identity_resolution')
            ->where('identity_id', 1)->where('domain', 'exclusion')->where('target_key', 'LEIE:4821')
            ->orderBy('source_action_id')->get();

        $this->assertSame(0, (int) $rows[0]->is_current);
        $this->assertSame(1, (int) $rows[1]->is_current);
        $this->assertSame('reject', $rows[1]->decision);
    }

    public function test_a_different_target_key_on_the_same_identity_is_independent(): void
    {
        $this->recorder()->record($this->baseRow(['source_action_id' => 1, 'target_key' => 'LEIE:1']));
        $this->recorder()->record($this->baseRow(['source_action_id' => 2, 'target_key' => 'LEIE:2']));

        $current = $this->hub()->table('gp_identity_resolution')
            ->where('identity_id', 1)->where('is_current', 1)->count();
        $this->assertSame(2, $current);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/ResolutionRecorderTest.php`
Expected: FAIL — `Class "App\GoldenProfile\Support\ResolutionRecorder" not found`.

- [ ] **Step 3: Write the implementation**

Create `app/GoldenProfile/Support/ResolutionRecorder.php`:

```php
<?php

namespace App\GoldenProfile\Support;

use Illuminate\Support\Facades\DB;

/**
 * The one place gp_identity_resolution is written. That table is already
 * SCD-2 (is_current + uq_action(system_id, source_action_table,
 * source_action_id) + idx_reuse(identity_id, domain, target_key, is_current))
 * — plan 3 (SCD-2 versioning) deliberately left it alone rather than adding a
 * second current column, so this class implements the currency rule by hand
 * rather than through Versioner, whose one-natural-key model does not fit: the
 * key for DE-DUPLICATION here (system_id, source_action_table,
 * source_action_id — "have I already ingested this exact CAMI event?") is
 * different from the key for CURRENCY (identity_id, domain, target_key —
 * "what does this identity/fact currently resolve to?").
 */
class ResolutionRecorder
{
    public function __construct(private int $systemId) {}

    private function hub()
    {
        return DB::connection('golden_profile');
    }

    /**
     * @param  array{identity_id:int,domain:string,target_key:string,
     *     action_type:string,decision:string,decision_status:?int,
     *     resolution_metadata:array,resolved_by:?int,resolved_at:string,
     *     source_action_table:string,source_action_id:int,
     *     is_auto_resolvable:bool}  $row
     * @return bool true if a new row was written, false if this exact source
     *     event was already ingested (idempotent command re-runs).
     */
    public function record(array $row): bool
    {
        return $this->hub()->transaction(function () use ($row) {
            $alreadyIngested = $this->hub()->table('gp_identity_resolution')->where([
                'system_id' => $this->systemId,
                'source_action_table' => $row['source_action_table'],
                'source_action_id' => $row['source_action_id'],
            ])->exists();

            if ($alreadyIngested) {
                return false;
            }

            // Retire whatever this identity/domain/target_key currently
            // considers current BEFORE inserting the new one — idx_reuse
            // assumes at most one is_current = 1 row per key. Guarded by the
            // alreadyIngested check above: without it, re-running this method
            // for an event already ingested would flip its OWN row to
            // is_current = 0 and never re-insert it (the insert is skipped),
            // permanently losing currency on an idempotent re-run.
            $this->hub()->table('gp_identity_resolution')
                ->where('identity_id', $row['identity_id'])
                ->where('domain', $row['domain'])
                ->where('target_key', $row['target_key'])
                ->where('is_current', 1)
                ->update(['is_current' => 0]);

            $this->hub()->table('gp_identity_resolution')->insert([
                'identity_id' => $row['identity_id'],
                'domain' => $row['domain'],
                'target_key' => $row['target_key'],
                'action_type' => $row['action_type'],
                'decision' => $row['decision'],
                'decision_status' => $row['decision_status'] ?? null,
                'resolution_metadata' => json_encode($row['resolution_metadata'] ?? []),
                'resolved_by' => $row['resolved_by'] ?? null,
                'resolved_at' => $row['resolved_at'],
                'system_id' => $this->systemId,
                'source_action_table' => $row['source_action_table'],
                'source_action_id' => $row['source_action_id'],
                'is_auto_resolvable' => $row['is_auto_resolvable'] ?? true,
                'is_current' => 1,
            ]);

            return true;
        });
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/ResolutionRecorderTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/GoldenProfile/Support/ResolutionRecorder.php tests/Feature/ResolutionRecorderTest.php
git commit -m "feat(resolution): add the single writer for gp_identity_resolution"
```

---

## Task 3: Stop `Engine`'s versioned rollups from reverting a steward's `link_state`

This is a landmine in code that does not exist yet in this repo — it is the shape Plan 3 Task 8
Step 5 leaves `Engine::rollupCredentials()` / `Engine::rollupExclusions()` in, quoted here in full
per this document's "repeat code rather than cross-reference" rule. Both methods build an `$upserts`
row per source record with a **hardcoded default** `link_state` (`'confirmed'` for credentials,
`'candidate'` for exclusions) and pass it to `Versioner::write()` as an attribute on every call,
sync included. `Versioner::write()` mints a new version whenever a declared attribute differs from
the current one — so the very first incremental sync *after* this plan's Task 4 transitions an
exclusion's `link_state` to `'confirmed'`/`'rejected'` would see `'confirmed' -> 'candidate'` (or the
reverse) as a change and silently mint a version that reverts the steward's decision. Writing steward
decisions into a table a routine job overwrites minutes later is worse than not writing them, so this
is fixed before Task 4 gives it anything to revert.

**Files:**
- Modify: `app/GoldenProfile/Engine.php` (`rollupCredentials()`, `rollupExclusions()` — as left by
  Plan 3 Task 8 Step 5; if Plan 3's merged code differs in shape, apply the same principle described
  below rather than this literal diff)
- Test: `tests/Feature/VersionedRollupPreservesLinkStateTest.php`

**Interfaces:**
- Produces: `Engine::writeVersionedCredentialRollup(array $row): void`,
  `Engine::writeVersionedExclusionRollup(array $row): void` — both `public`, specifically so this
  task's test (and any future one) can exercise them without a live `streamline_local` connection,
  matching `Engine::rebuildProfile()`'s existing public-for-testability precedent.
- Consumes: `Versioner::current()`, `Versioner::write()` (Plan 3).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/VersionedRollupPreservesLinkStateTest.php`:

```php
<?php

namespace Tests\Feature;

use App\GoldenProfile\Engine;
use App\GoldenProfile\Support\Versioner;
use Tests\Support\HubTestCase;

/**
 * Guards the landmine Plan 3 Task 8 leaves behind: rollupCredentials() and
 * rollupExclusions() build their $upserts row with a HARDCODED link_state on
 * every call, sync included. Versioner::write() mints a new version whenever
 * an attribute differs from the current one, so once gp:ingest-resolutions
 * (this plan, Task 4) has transitioned a link's link_state away from that
 * default, the very next sync would see it as a change and silently revert
 * the steward's decision. Proven here by calling the extracted per-row write
 * directly — the same way ResolverLadderTest calls DeterministicResolver
 * directly — because SRC_DB_* is a dead socket in phpunit.xml and this method
 * has no other seam that avoids a live streamline_local connection.
 */
class VersionedRollupPreservesLinkStateTest extends HubTestCase
{
    private function exclusionRow(array $overrides = []): array
    {
        return array_merge([
            'system_id' => $this->systemId,
            'match_id' => 9001,
            'identity_id' => 1,
            'exclusion_record_id' => 4821,
            'registry' => 'LEIE',
            'is_ssn_match' => 1,
            'is_npi_match' => 0,
            'is_canonical_name_match' => 1,
            'is_upin_match' => 0,
            'is_license_number_match' => 0,
            'link_state' => 'candidate',
        ], $overrides);
    }

    public function test_a_second_rollup_does_not_revert_a_confirmed_exclusion_link(): void
    {
        $engine = new Engine;

        // First rollup: mints version 1 with the default 'candidate'.
        $engine->writeVersionedExclusionRollup($this->exclusionRow());

        // A steward decision lands in between — what ResolutionIngest does.
        (new Versioner)->write(
            'gp_identity_exclusion',
            ['system_id' => $this->systemId, 'match_id' => 9001],
            ['link_state' => 'confirmed'],
        );

        // Second rollup: the same source row comes back unchanged on the next sync.
        $engine->writeVersionedExclusionRollup($this->exclusionRow());

        $current = $this->hub()->table('gp_identity_exclusion')
            ->where(['system_id' => $this->systemId, 'match_id' => 9001])
            ->where('current', 1)->first();

        $this->assertSame('confirmed', $current->link_state);
        $this->assertSame(2, (int) $current->version_no, 'the steward write should be the only version after 1, not 3');
    }

    public function test_the_first_rollup_still_sets_the_default_link_state(): void
    {
        $engine = new Engine;
        $engine->writeVersionedExclusionRollup($this->exclusionRow(['match_id' => 9002, 'registry' => 'SAM']));

        $current = $this->hub()->table('gp_identity_exclusion')
            ->where(['system_id' => $this->systemId, 'match_id' => 9002])
            ->where('current', 1)->first();

        $this->assertSame('candidate', $current->link_state);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/VersionedRollupPreservesLinkStateTest.php`
Expected: FAIL — `Call to undefined method App\GoldenProfile\Engine::writeVersionedExclusionRollup()`.

- [ ] **Step 3: Write the implementation**

In `app/GoldenProfile/Engine.php`, this is the code Plan 3 Task 8 Step 5 leaves in place (quoted in
full as the starting point):

```php
    private function rollupCredentials(array $employeeIds): void
    {
        if (! $employeeIds) {
            return;
        }
        $excludeCodes = config('golden_profile.credential_search.rollup_exclude_status_codes', []);
        $rows = $this->src()->table('credential_matches')->whereIn('employee_id', $employeeIds)->get();
        $identityMap = $this->identityMapFor($employeeIds);

        $upserts = [];
        $deleteIds = [];
        foreach ($rows as $c) {
            $identityId = $identityMap[(int) $c->employee_id] ?? null;
            if (! $identityId) {
                continue;
            }
            if (in_array((int) $c->match_summary_status_code, $excludeCodes, true)) {
                $deleteIds[] = (int) $c->id;
                continue;
            }
            $upserts[] = [
                'system_id' => $this->systemId,
                'credential_match_id' => (int) $c->id,
                'identity_id' => $identityId,
                'registry' => $c->registry,
                'match_summary_status' => $c->match_summary_status,
                'match_summary_status_code' => $c->match_summary_status_code,
                'match_is_valid' => $c->match_is_valid,
                'source_current' => $c->current,
                'date_resolved' => $this->dt($c->date_resolved),
                'link_state' => 'confirmed',
            ];
        }

        foreach ($upserts as $row) {
            $this->versioner->write(
                'gp_identity_credential',
                ['system_id' => $row['system_id'], 'credential_match_id' => $row['credential_match_id']],
                array_intersect_key($row, array_flip([
                    'registry', 'match_summary_status', 'match_summary_status_code',
                    'match_is_valid', 'source_current', 'date_resolved', 'link_state',
                ])),
                [],
                ['identity_id' => $row['identity_id']],
            );
        }

        foreach ($deleteIds as $credentialMatchId) {
            $this->versioner->retire('gp_identity_credential', [
                'system_id' => $this->systemId,
                'credential_match_id' => $credentialMatchId,
            ]);
        }
    }

    private function rollupExclusions(array $employeeIds): void
    {
        if (! $employeeIds) {
            return;
        }
        $rows = $this->src()->table('matches')->whereIn('employee_id', $employeeIds)->get();

        $recordIds = $rows->pluck('exclusion_record_id')->filter()->unique()->all();
        $registryMap = $recordIds
            ? $this->src()->table('exclusion_records')->whereIn('id', $recordIds)
                ->pluck('exclusion_list_prefix', 'id')->all()
            : [];

        $identityMap = $this->identityMapFor($employeeIds);

        $upserts = [];
        foreach ($rows as $m) {
            $identityId = $identityMap[(int) $m->employee_id] ?? null;
            if (! $identityId) {
                continue;
            }
            $registry = $m->exclusion_record_id ? ($registryMap[$m->exclusion_record_id] ?? null) : null;
            $upserts[] = [
                'system_id' => $this->systemId,
                'match_id' => (int) $m->id,
                'identity_id' => $identityId,
                'exclusion_record_id' => $m->exclusion_record_id,
                'registry' => $registry,
                'is_ssn_match' => $m->is_ssn_match,
                'is_npi_match' => $m->is_npi_match,
                'is_canonical_name_match' => $m->is_canonical_name_match,
                'is_upin_match' => $m->is_upin_match,
                'is_license_number_match' => $m->is_license_number_match,
                'link_state' => 'candidate',
            ];
        }

        foreach ($upserts as $row) {
            $this->versioner->write(
                'gp_identity_exclusion',
                ['system_id' => $row['system_id'], 'match_id' => $row['match_id']],
                array_intersect_key($row, array_flip([
                    'exclusion_record_id', 'registry', 'is_ssn_match', 'is_npi_match',
                    'is_canonical_name_match', 'is_upin_match', 'is_license_number_match', 'link_state',
                ])),
                [],
                ['identity_id' => $row['identity_id']],
            );
        }
    }
```

Replace the two `foreach ($upserts as $row) { $this->versioner->write(...); }` blocks with calls to
two new extracted, public methods, and add those methods:

```php
        foreach ($upserts as $row) {
            $this->writeVersionedCredentialRollup($row);
        }

        foreach ($deleteIds as $credentialMatchId) {
            $this->versioner->retire('gp_identity_credential', [
                'system_id' => $this->systemId,
                'credential_match_id' => $credentialMatchId,
            ]);
        }
    }
```

```php
        foreach ($upserts as $row) {
            $this->writeVersionedExclusionRollup($row);
        }
    }

    /**
     * Public so it can be exercised directly in a test with no live
     * streamline_local connection (SRC_DB_* is a dead socket in phpunit.xml —
     * see VersionedRollupPreservesLinkStateTest).
     *
     * link_state stops being "whatever the last sync said" the moment
     * gp:ingest-resolutions (ResolutionIngest) writes a steward decision onto
     * this row. A rollup only gets to set link_state's default on the row's
     * FIRST version; repeating the default on every later sync would make
     * Versioner see a "change" back to it and mint a version that silently
     * reverts the steward's call. This is checked with an extra read rather
     * than an insert-only write because Versioner::TABLES's attribute/onCreate
     * split is table-wide, not per-call — there is no way to tell write()
     * "only set this one column on create" for a single invocation.
     */
    public function writeVersionedCredentialRollup(array $row): void
    {
        $key = ['system_id' => $row['system_id'], 'credential_match_id' => $row['credential_match_id']];
        $attrs = array_intersect_key($row, array_flip([
            'registry', 'match_summary_status', 'match_summary_status_code',
            'match_is_valid', 'source_current', 'date_resolved', 'link_state',
        ]));

        if ($this->versioner->current('gp_identity_credential', $key) !== null) {
            unset($attrs['link_state']);
        }

        $this->versioner->write('gp_identity_credential', $key, $attrs, [], ['identity_id' => $row['identity_id']]);
    }

    /** See writeVersionedCredentialRollup() above — same rule, the domain that
     * actually receives steward decisions today via gp:ingest-resolutions. */
    public function writeVersionedExclusionRollup(array $row): void
    {
        $key = ['system_id' => $row['system_id'], 'match_id' => $row['match_id']];
        $attrs = array_intersect_key($row, array_flip([
            'exclusion_record_id', 'registry', 'is_ssn_match', 'is_npi_match',
            'is_canonical_name_match', 'is_upin_match', 'is_license_number_match', 'link_state',
        ]));

        if ($this->versioner->current('gp_identity_exclusion', $key) !== null) {
            unset($attrs['link_state']);
        }

        $this->versioner->write('gp_identity_exclusion', $key, $attrs, [], ['identity_id' => $row['identity_id']]);
    }
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/VersionedRollupPreservesLinkStateTest.php`
Expected: PASS, 2 tests.

Run: `vendor/bin/phpunit`
Expected: PASS, no regressions (numbers will already reflect whatever Plan 3 leaves the suite at;
no test here should newly fail).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/GoldenProfile/Engine.php tests/Feature/VersionedRollupPreservesLinkStateTest.php
git commit -m "fix(engine): stop versioned rollups from reverting a steward's link_state"
```

---

## Task 4: `ResolutionIngest::processExclusionActions()` — exclusion domain

**Files:**
- Create: `app/GoldenProfile/ResolutionIngest.php` (this task adds the constructor and this one
  method; Task 5 and Task 6 add the rest)
- Test: `tests/Feature/ResolutionIngestExclusionTest.php`

**Interfaces:**
- Consumes: `ResolutionMapper::exclusionAction()`, `::exclusionTargetKey()` (Task 1),
  `ResolutionRecorder::record()` (Task 2), `Versioner::current()`, `::write()` (Plan 3).
- Produces: `ResolutionIngest::__construct(int $systemId)`,
  `ResolutionIngest::processExclusionActions(Collection $actions): array{recorded:int,skipped:int,already_ingested:int}`.
  `$actions` elements are plain objects shaped like `match_actions` rows: `id`, `action_type`,
  `match_id`, `user_id`, `note`, `date_created`, `is_auto_resolved`, `resolved_via`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ResolutionIngestExclusionTest.php`:

```php
<?php

namespace Tests\Feature;

use App\GoldenProfile\ResolutionIngest;
use App\GoldenProfile\Support\Versioner;
use Illuminate\Support\Collection;
use Tests\Support\HubTestCase;

class ResolutionIngestExclusionTest extends HubTestCase
{
    private function seedExclusionLink(int $matchId, int $identityId, string $registry = 'LEIE', int $exclusionRecordId = 4821): void
    {
        (new Versioner)->write(
            'gp_identity_exclusion',
            ['system_id' => $this->systemId, 'match_id' => $matchId],
            [
                'exclusion_record_id' => $exclusionRecordId,
                'registry' => $registry,
                'is_ssn_match' => 1, 'is_npi_match' => 0, 'is_canonical_name_match' => 1,
                'is_upin_match' => 0, 'is_license_number_match' => 0,
                'link_state' => 'candidate',
            ],
            [],
            ['identity_id' => $identityId],
        );
    }

    private function action(array $overrides = []): object
    {
        return (object) array_merge([
            'id' => 501,
            'action_type' => 'confirm',
            'match_id' => 9001,
            'user_id' => 42,
            'note' => 'confirmed on review',
            'date_created' => '2026-08-15 09:00:00',
            'is_auto_resolved' => 0,
            'resolved_via' => 'user',
        ], $overrides);
    }

    public function test_confirm_transitions_link_state_and_writes_a_current_resolution(): void
    {
        $this->seedExclusionLink(9001, 1);
        $ingest = new ResolutionIngest($this->systemId);

        $result = $ingest->processExclusionActions(new Collection([$this->action()]));

        $this->assertSame(['recorded' => 1, 'skipped' => 0, 'already_ingested' => 0], $result);

        $link = $this->hub()->table('gp_identity_exclusion')
            ->where(['system_id' => $this->systemId, 'match_id' => 9001])->where('current', 1)->first();
        $this->assertSame('confirmed', $link->link_state);

        $resolution = $this->hub()->table('gp_identity_resolution')
            ->where('source_action_table', 'match_actions')->where('source_action_id', 501)->first();
        $this->assertSame('exclusion', $resolution->domain);
        $this->assertSame('LEIE:4821', $resolution->target_key);
        $this->assertSame('confirm', $resolution->decision);
        $this->assertSame(42, $resolution->resolved_by);
    }

    public function test_resolve_transitions_link_state_to_rejected(): void
    {
        $this->seedExclusionLink(9001, 1);
        $ingest = new ResolutionIngest($this->systemId);

        $ingest->processExclusionActions(new Collection([$this->action(['id' => 502, 'action_type' => 'resolve'])]));

        $link = $this->hub()->table('gp_identity_exclusion')
            ->where(['system_id' => $this->systemId, 'match_id' => 9001])->where('current', 1)->first();
        $this->assertSame('rejected', $link->link_state);
    }

    public function test_an_action_for_a_match_not_yet_rolled_up_is_skipped_not_fatal(): void
    {
        $ingest = new ResolutionIngest($this->systemId);

        $result = $ingest->processExclusionActions(new Collection([$this->action(['match_id' => 999999])]));

        $this->assertSame(['recorded' => 0, 'skipped' => 1, 'already_ingested' => 0], $result);
    }

    public function test_reingesting_is_idempotent(): void
    {
        $this->seedExclusionLink(9001, 1);
        $ingest = new ResolutionIngest($this->systemId);

        $ingest->processExclusionActions(new Collection([$this->action()]));
        $result = $ingest->processExclusionActions(new Collection([$this->action()]));

        $this->assertSame(['recorded' => 0, 'skipped' => 0, 'already_ingested' => 1], $result);
        $count = $this->hub()->table('gp_identity_resolution')
            ->where('source_action_table', 'match_actions')->where('source_action_id', 501)->count();
        $this->assertSame(1, $count);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/ResolutionIngestExclusionTest.php`
Expected: FAIL — `Class "App\GoldenProfile\ResolutionIngest" not found`.

- [ ] **Step 3: Write the implementation**

Create `app/GoldenProfile/ResolutionIngest.php`:

```php
<?php

namespace App\GoldenProfile;

use App\GoldenProfile\Support\ResolutionMapper;
use App\GoldenProfile\Support\ResolutionRecorder;
use App\GoldenProfile\Support\Versioner;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ingests the steward decisions CAMI already makes into gp_identity_resolution
 * and gp_identity_exclusion.link_state. Deliberately split into a thin,
 * source-reading half (ingestExclusionActions/ingestCredentialResolutions,
 * Task 6) and this hub-only, fully-tested half — the same split
 * Engine::rollupCredentials/rollupExclusions already have, and for the same
 * reason: phpunit.xml points SRC_DB_* at a dead socket, so nothing here can
 * test a live streamline_local query, but the actual decision logic touches
 * only the hub and is fully testable.
 */
class ResolutionIngest
{
    private ResolutionRecorder $recorder;

    private Versioner $versioner;

    public function __construct(private int $systemId)
    {
        $this->recorder = new ResolutionRecorder($systemId);
        $this->versioner = new Versioner;
    }

    private function hub()
    {
        return DB::connection('golden_profile');
    }

    private function src()
    {
        return DB::connection('streamline_local');
    }

    /**
     * @param  Collection<int,object>  $actions  match_actions rows: id,
     *     action_type, match_id, user_id, note, date_created,
     *     is_auto_resolved, resolved_via.
     * @return array{recorded:int,skipped:int,already_ingested:int}
     */
    public function processExclusionActions(Collection $actions): array
    {
        $recorded = 0;
        $skipped = 0;
        $alreadyIngested = 0;

        foreach ($actions as $action) {
            $key = ['system_id' => $this->systemId, 'match_id' => (int) $action->match_id];
            $current = $this->versioner->current('gp_identity_exclusion', $key);

            if (! $current) {
                // Not rolled up yet (or the employee's link doesn't exist) —
                // nothing to attach the decision to. Counted, not thrown: one
                // bad row must not fail the whole batch.
                $skipped++;

                continue;
            }

            $map = ResolutionMapper::exclusionAction($action->action_type);

            $this->versioner->write('gp_identity_exclusion', $key, ['link_state' => $map['link_state']]);

            $registry = $current->registry ?? 'unknown';
            $exclusionRecordId = $current->exclusion_record_id ?? (int) $action->match_id;

            $wasNew = $this->recorder->record([
                'identity_id' => (int) $current->identity_id,
                'domain' => 'exclusion',
                'target_key' => ResolutionMapper::exclusionTargetKey($registry, (int) $exclusionRecordId),
                'action_type' => $action->action_type,
                'decision' => $map['decision'],
                'decision_status' => null,
                'resolution_metadata' => [
                    'note' => $action->note,
                    'is_auto_resolved' => (bool) ($action->is_auto_resolved ?? false),
                    'resolved_via' => $action->resolved_via ?? null,
                ],
                'resolved_by' => $action->user_id,
                'resolved_at' => $action->date_created,
                'source_action_table' => 'match_actions',
                'source_action_id' => (int) $action->id,
                'is_auto_resolvable' => true,
            ]);

            $wasNew ? $recorded++ : $alreadyIngested++;
        }

        return ['recorded' => $recorded, 'skipped' => $skipped, 'already_ingested' => $alreadyIngested];
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/ResolutionIngestExclusionTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/GoldenProfile/ResolutionIngest.php tests/Feature/ResolutionIngestExclusionTest.php
git commit -m "feat(resolution): ingest exclusion match_actions into link_state and gp_identity_resolution"
```

---

## Task 5: `ResolutionIngest::processCredentialResolutions()` — credential domain

**Files:**
- Modify: `app/GoldenProfile/ResolutionIngest.php`
- Test: `tests/Feature/ResolutionIngestCredentialTest.php`

**Interfaces:**
- Consumes: `ResolutionMapper::credentialTargetKey()` (Task 1), `ResolutionRecorder::record()` (Task 2).
- Produces: `ResolutionIngest::processCredentialResolutions(Collection $resolutions): array{recorded:int,skipped:int,already_ingested:int}`.
  `$resolutions` elements are plain objects: `id`, `credential_match_id`, `note`, `created_at`,
  `registry`, `credential_id`, `license_type_id` (already joined from `credential_matches` — see
  Task 6).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ResolutionIngestCredentialTest.php`:

```php
<?php

namespace Tests\Feature;

use App\GoldenProfile\ResolutionIngest;
use Illuminate\Support\Collection;
use Tests\Support\HubTestCase;

class ResolutionIngestCredentialTest extends HubTestCase
{
    private function seedCredentialLink(int $credentialMatchId, int $identityId, string $registry = 'NPDB'): void
    {
        $this->hub()->table('gp_identity_credential')->insert([
            'system_id' => $this->systemId,
            'credential_match_id' => $credentialMatchId,
            'identity_id' => $identityId,
            'registry' => $registry,
            'match_summary_status' => 'Valid',
            'match_summary_status_code' => 20,
            'match_is_valid' => 1,
            'source_current' => 1,
            'date_resolved' => now(),
            'link_state' => 'confirmed',
            'version_no' => 1,
            'current' => 1,
            'date_created' => now(),
            'date_updated' => now(),
        ]);
    }

    private function resolution(array $overrides = []): object
    {
        return (object) array_merge([
            'id' => 701,
            'credential_match_id' => 8001,
            'note' => 'confirmed valid on manual review',
            'created_at' => '2026-08-15 09:00:00',
            'registry' => 'NPDB',
            'credential_id' => 'A123456',
            'license_type_id' => null,
        ], $overrides);
    }

    public function test_writes_a_current_confirm_resolution_keyed_for_reuse(): void
    {
        $this->seedCredentialLink(8001, 1);
        $ingest = new ResolutionIngest($this->systemId);

        $result = $ingest->processCredentialResolutions(new Collection([$this->resolution()]));

        $this->assertSame(['recorded' => 1, 'skipped' => 0, 'already_ingested' => 0], $result);

        $resolution = $this->hub()->table('gp_identity_resolution')
            ->where('source_action_table', 'credential_match_resolutions')->where('source_action_id', 701)->first();
        $this->assertSame('credential', $resolution->domain);
        $this->assertSame('NPDB:A123456', $resolution->target_key);
        $this->assertSame('confirm', $resolution->decision);
        $this->assertNull($resolution->resolved_by);
    }

    public function test_a_credential_match_id_with_no_rolled_up_link_is_skipped(): void
    {
        $ingest = new ResolutionIngest($this->systemId);

        $result = $ingest->processCredentialResolutions(new Collection([
            $this->resolution(['credential_match_id' => 999999]),
        ]));

        $this->assertSame(['recorded' => 0, 'skipped' => 1, 'already_ingested' => 0], $result);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/ResolutionIngestCredentialTest.php`
Expected: FAIL — `Call to undefined method App\GoldenProfile\ResolutionIngest::processCredentialResolutions()`.

- [ ] **Step 3: Write the implementation**

Add to `app/GoldenProfile/ResolutionIngest.php` (after `processExclusionActions()`):

```php
    /**
     * @param  Collection<int,object>  $resolutions  credential_match_resolutions
     *     joined to credential_matches: id, credential_match_id, note,
     *     created_at, registry, credential_id, license_type_id.
     * @return array{recorded:int,skipped:int,already_ingested:int}
     */
    public function processCredentialResolutions(Collection $resolutions): array
    {
        $recorded = 0;
        $skipped = 0;
        $alreadyIngested = 0;

        foreach ($resolutions as $r) {
            $link = $this->hub()->table('gp_identity_credential')->where([
                'system_id' => $this->systemId,
                'credential_match_id' => (int) $r->credential_match_id,
            ])->first();

            if (! $link) {
                $skipped++;

                continue;
            }

            // CAMI's credential_match_resolutions is one-directional (see
            // ResolutionMapper's docblock): every row here means "an
            // INVALID/name-mismatch match was manually confirmed VALID". There
            // is no source signal for a credential-side reject, so this is not
            // routed through ResolutionMapper::exclusionAction() — that method
            // exists for a vocabulary this domain does not have.
            $wasNew = $this->recorder->record([
                'identity_id' => (int) $link->identity_id,
                'domain' => 'credential',
                'target_key' => ResolutionMapper::credentialTargetKey(
                    $r->registry, $r->credential_id, $r->license_type_id
                ),
                'action_type' => 'credential_match_resolution',
                'decision' => 'confirm',
                'decision_status' => null,
                'resolution_metadata' => ['note' => $r->note],
                'resolved_by' => null, // credential_match_resolutions carries no user_id
                'resolved_at' => $r->created_at,
                'source_action_table' => 'credential_match_resolutions',
                'source_action_id' => (int) $r->id,
                'is_auto_resolvable' => true,
            ]);

            $wasNew ? $recorded++ : $alreadyIngested++;
        }

        return ['recorded' => $recorded, 'skipped' => $skipped, 'already_ingested' => $alreadyIngested];
    }
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/ResolutionIngestCredentialTest.php`
Expected: PASS, 2 tests.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/GoldenProfile/ResolutionIngest.php tests/Feature/ResolutionIngestCredentialTest.php
git commit -m "feat(resolution): ingest credential_match_resolutions for reuse"
```

---

## Task 6: Watermarks, the source-reading halves, and `gp:ingest-resolutions`

**Files:**
- Modify: `app/GoldenProfile/ResolutionIngest.php` (add watermark methods + the two thin fetch
  methods)
- Create: `app/Console/Commands/GpIngestResolutions.php`
- Test: `tests/Feature/ResolutionIngestWatermarkTest.php`

**Interfaces:**
- Produces: `ResolutionIngest::watermark(string $table): ?int`,
  `ResolutionIngest::setWatermark(string $table, int $value): void`,
  `ResolutionIngest::ingestExclusionActions(int $chunk = 500): array`,
  `ResolutionIngest::ingestCredentialResolutions(int $chunk = 500): array`.
- Consumes: `processExclusionActions()` (Task 4), `processCredentialResolutions()` (Task 5).

Only the watermark methods are tested directly (hub-only). `ingestExclusionActions()` /
`ingestCredentialResolutions()` are kept intentionally thin — one query, one `chunk()`, one call
into the already-tested processor — for the same reason `Engine::sync()` itself has no test: they
require a live `streamline_local` connection that `phpunit.xml` deliberately does not provide.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ResolutionIngestWatermarkTest.php`:

```php
<?php

namespace Tests\Feature;

use App\GoldenProfile\ResolutionIngest;
use Tests\Support\HubTestCase;

class ResolutionIngestWatermarkTest extends HubTestCase
{
    public function test_watermark_is_null_until_set(): void
    {
        $ingest = new ResolutionIngest($this->systemId);

        $this->assertNull($ingest->watermark('match_actions'));
    }

    public function test_watermark_round_trips(): void
    {
        $ingest = new ResolutionIngest($this->systemId);
        $ingest->setWatermark('match_actions', 12345);

        $this->assertSame(12345, $ingest->watermark('match_actions'));
    }

    public function test_watermarks_for_different_tables_are_independent(): void
    {
        $ingest = new ResolutionIngest($this->systemId);
        $ingest->setWatermark('match_actions', 10);
        $ingest->setWatermark('credential_match_resolutions', 20);

        $this->assertSame(10, $ingest->watermark('match_actions'));
        $this->assertSame(20, $ingest->watermark('credential_match_resolutions'));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/ResolutionIngestWatermarkTest.php`
Expected: FAIL — `Call to undefined method App\GoldenProfile\ResolutionIngest::watermark()`.

- [ ] **Step 3: Write the implementation**

Add to `app/GoldenProfile/ResolutionIngest.php`:

```php
    /** gp_watermark reuses Engine's own table — 'match_actions' and
     * 'credential_match_resolutions' are new source_table values on the same
     * (system_id, source_table) primary key Engine already uses for
     * 'employees'. */
    public function watermark(string $table): ?int
    {
        $v = $this->hub()->table('gp_watermark')
            ->where(['system_id' => $this->systemId, 'source_table' => $table])->value('high_water');

        return $v === null ? null : (int) $v;
    }

    public function setWatermark(string $table, int $value): void
    {
        $this->hub()->table('gp_watermark')->updateOrInsert(
            ['system_id' => $this->systemId, 'source_table' => $table],
            ['high_water' => (string) $value, 'updated_at' => now()],
        );
    }

    /** @return array{recorded:int,skipped:int,already_ingested:int} */
    public function ingestExclusionActions(int $chunk = 500): array
    {
        $totals = ['recorded' => 0, 'skipped' => 0, 'already_ingested' => 0];
        $maxId = $this->watermark('match_actions') ?? 0;

        $this->src()->table('match_actions')
            ->where('id', '>', $maxId)
            ->where('status', 1) // MatchAction::scopeActive() — skip reversed/deactivated rows
            ->orderBy('id')
            ->chunk($chunk, function ($rows) use (&$totals, &$maxId) {
                $result = $this->processExclusionActions($rows);
                $totals['recorded'] += $result['recorded'];
                $totals['skipped'] += $result['skipped'];
                $totals['already_ingested'] += $result['already_ingested'];
                $maxId = max($maxId, (int) $rows->max('id'));
            });

        $this->setWatermark('match_actions', $maxId);

        return $totals;
    }

    /** @return array{recorded:int,skipped:int,already_ingested:int} */
    public function ingestCredentialResolutions(int $chunk = 500): array
    {
        $totals = ['recorded' => 0, 'skipped' => 0, 'already_ingested' => 0];
        $maxId = $this->watermark('credential_match_resolutions') ?? 0;

        $this->src()->table('credential_match_resolutions as r')
            ->join('credential_matches as cm', 'cm.id', '=', 'r.credential_match_id')
            ->where('r.id', '>', $maxId)
            ->orderBy('r.id')
            ->select('r.id', 'r.credential_match_id', 'r.note', 'r.created_at',
                'cm.registry', 'cm.credential_id', 'cm.license_type_id')
            ->chunk($chunk, function ($rows) use (&$totals, &$maxId) {
                $result = $this->processCredentialResolutions($rows);
                $totals['recorded'] += $result['recorded'];
                $totals['skipped'] += $result['skipped'];
                $totals['already_ingested'] += $result['already_ingested'];
                $maxId = max($maxId, (int) $rows->max('id'));
            });

        $this->setWatermark('credential_match_resolutions', $maxId);

        return $totals;
    }
```

Create `app/Console/Commands/GpIngestResolutions.php`:

```php
<?php

namespace App\Console\Commands;

use App\GoldenProfile\Engine;
use App\GoldenProfile\ResolutionIngest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The steward surface for gp-cami's API-only design: not a review queue
 * nobody would open, but ingest of the decisions CAMI's own UI already
 * captures (match_actions, credential_match_resolutions). Run on the same
 * cadence as gp:sync.
 */
class GpIngestResolutions extends Command
{
    protected $signature = 'gp:ingest-resolutions {system=streamline_local} {--chunk=500}';

    protected $description = 'Ingest steward decisions CAMI already captures into gp_identity_resolution and gp_identity_exclusion.link_state.';

    public function handle(): int
    {
        $ingest = new ResolutionIngest($this->systemId());
        $chunk = (int) $this->option('chunk');

        $ex = $ingest->ingestExclusionActions($chunk);
        $this->info("exclusion decisions: {$ex['recorded']} recorded, {$ex['already_ingested']} already ingested, {$ex['skipped']} skipped (no link yet)");

        $cr = $ingest->ingestCredentialResolutions($chunk);
        $this->info("credential decisions: {$cr['recorded']} recorded, {$cr['already_ingested']} already ingested, {$cr['skipped']} skipped (no link yet)");

        return self::SUCCESS;
    }

    private function systemId(): int
    {
        return (int) DB::connection('golden_profile')->table('gp_source_system')
            ->where('system_code', Engine::SYSTEM_CODE)->value('system_id');
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/ResolutionIngestWatermarkTest.php`
Expected: PASS, 3 tests.

Run: `php artisan list | grep gp:ingest-resolutions`
Expected: the command is registered (Laravel auto-discovers `app/Console/Commands/*`).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/GoldenProfile/ResolutionIngest.php app/Console/Commands/GpIngestResolutions.php \
  tests/Feature/ResolutionIngestWatermarkTest.php
git commit -m "feat(resolution): add gp:ingest-resolutions with per-table watermarks"
```

---

## Task 7: Oversized-block review flagging

**Files:**
- Modify: `app/GoldenProfile/Resolution/ProbabilisticResolver.php:80-93`
- Modify: `app/GoldenProfile/Resolution/DeterministicResolver.php` (the `else` branch in `resolve()`)
- Test: `tests/Feature/OversizedBlockReviewFlagTest.php`

**Interfaces:**
- `ProbabilisticResolver::match()`'s return type widens from `array{0:?int,1:float,2:string}` where
  the string was `'auto_match'|'review'|'no_match'` to also allow `'declined_oversized'`. Every
  existing caller already treats anything other than `'auto_match'|'review'` as "no bind, caller
  makes a new identity" (`in_array($state, ['auto_match', 'review'], true)` in
  `DeterministicResolver::resolve()`), so this is additive and does not change behaviour for any
  existing caller other than the one being modified here.
- Produces: `DeterministicResolver::logOversizedBlockDecline(int $identityId, object $p): void`
  (private).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/OversizedBlockReviewFlagTest.php`:

```php
<?php

namespace Tests\Feature;

use App\GoldenProfile\Resolution\DeterministicResolver;
use Tests\Support\HubTestCase;

class OversizedBlockReviewFlagTest extends HubTestCase
{
    public function test_a_decline_over_the_cap_flags_the_new_link_for_review(): void
    {
        config(['golden_profile.probabilistic.block_size_cap' => 2]);

        // Three people already staged sharing one block_key (soundex(last) +
        // dob year) and no other deterministic key — over the cap of 2, so
        // Pass B declines to score any of them without ever comparing.
        $a = $this->stagePerson(['first_name' => 'Ann', 'last_name' => 'Devereaux', 'date_of_birth' => '1980-01-01']);
        $b = $this->stagePerson(['first_name' => 'Ben', 'last_name' => 'Devereaux', 'date_of_birth' => '1980-06-06']);
        $c = $this->stagePerson(['first_name' => 'Cai', 'last_name' => 'Devereaux', 'date_of_birth' => '1980-09-09']);

        $resolver = new DeterministicResolver($this->systemId);
        $resolver->resolve($a);
        $resolver->resolve($b);
        $thirdIdentity = $resolver->resolve($c);

        $link = $this->hub()->table('gp_source_link')
            ->where(['system_id' => $this->systemId, 'source_table' => 'employees'])
            ->where('identity_id', $thirdIdentity)->first();

        $this->assertSame('review', $link->match_state);

        $log = $this->hub()->table('gp_resolution_log')
            ->where('identity_id', $thirdIdentity)->where('match_key', 'oversized_block')->first();
        $this->assertNotNull($log);
        $this->assertSame('create', $log->action);
        $this->assertStringContainsString('cap=2', $log->reason);
    }

    public function test_an_ordinary_new_identity_under_the_cap_still_gets_auto_match(): void
    {
        config(['golden_profile.probabilistic.block_size_cap' => 2000]);

        $a = $this->stagePerson(['first_name' => 'Zora', 'last_name' => 'Quillfeather', 'date_of_birth' => '1990-02-02']);
        $resolver = new DeterministicResolver($this->systemId);
        $identityId = $resolver->resolve($a);

        $link = $this->hub()->table('gp_source_link')
            ->where(['system_id' => $this->systemId, 'identity_id' => $identityId])->first();
        $this->assertSame('auto_match', $link->match_state);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/OversizedBlockReviewFlagTest.php`
Expected: FAIL — the first test asserts `'review'`, current code produces `'auto_match'`.

- [ ] **Step 3: Write the implementation**

In `app/GoldenProfile/Resolution/ProbabilisticResolver.php`, replace:

```php
        $cap = (int) ($this->cfg['block_size_cap'] ?? 0);
        if ($cap > 0) {
            $blockSize = (int) $this->hub()->table('stg_person')
                ->where('block_key', $p->block_key)->count();
            if ($blockSize > $cap) {
                return [null, 0.0, 'no_match'];
            }
        }
```

with:

```php
        $cap = (int) ($this->cfg['block_size_cap'] ?? 0);
        if ($cap > 0) {
            $blockSize = (int) $this->hub()->table('stg_person')
                ->where('block_key', $p->block_key)->count();
            if ($blockSize > $cap) {
                // Not an ordinary no_match: the block was never scored, so
                // this is a refusal to look, not evidence of "no candidate".
                // DeterministicResolver::resolve() treats this state
                // specially — it still creates a new identity (same as any
                // other no_match), but flags the resulting link for review
                // instead of silently stamping auto_match on a decision that
                // was never actually evaluated.
                return [null, 0.0, 'declined_oversized'];
            }
        }
```

Also update the class docblock's return-value summary (just above `class ProbabilisticResolver`) to
add the new state:

```php
 * Returns [identity_id|null, score, match_state]:
 *   >= auto_merge_at     -> [id, score, 'auto_match']          bind to candidate
 *   review band          -> [id, score, 'review']              bind but flag for steward
 *   below floor          -> [null, score, 'no_match']          caller makes a new identity
 *   block over the cap   -> [null, 0.0, 'declined_oversized']  caller makes a new identity,
 *                                                               flagged for steward review —
 *                                                               see DeterministicResolver::resolve()
```

In `app/GoldenProfile/Resolution/DeterministicResolver.php`, replace:

```php
        if ($identityId === null) {
            // Pass A missed — try Pass B probabilistic.
            [$pid, $score, $state] = $this->probabilistic->match($p, $licenses);
            if ($pid !== null && in_array($state, ['auto_match', 'review'], true)) {
                $identityId = $pid;
                $key = 'probabilistic';
                $conf = $score;
                $method = 'probabilistic';
                $matchState = $state;
                if ($state === 'review') {
                    $this->logReview($identityId, $p, $score);
                } else {
                    $this->backfillKeys($identityId, $p);
                }
            } else {
                $identityId = $this->createIdentity($p);
                $key = 'new';
                $conf = 1.0;
            }
        } else {
            $this->backfillKeys($identityId, $p);
        }
```

with:

```php
        if ($identityId === null) {
            // Pass A missed — try Pass B probabilistic.
            [$pid, $score, $state] = $this->probabilistic->match($p, $licenses);
            if ($pid !== null && in_array($state, ['auto_match', 'review'], true)) {
                $identityId = $pid;
                $key = 'probabilistic';
                $conf = $score;
                $method = 'probabilistic';
                $matchState = $state;
                if ($state === 'review') {
                    $this->logReview($identityId, $p, $score);
                } else {
                    $this->backfillKeys($identityId, $p);
                }
            } else {
                $identityId = $this->createIdentity($p);
                $key = 'new';
                $conf = 1.0;

                // block_size_cap declined to score this row at all (see
                // ProbabilisticResolver::match()). The identity is still
                // minted blind — same outcome as any other no_match — but the
                // resulting link needs a steward's eyes, not the default
                // auto_match stamp: cheapest correct fix is to keep the
                // decline and make it visible instead of indistinguishable
                // from an ordinary new person.
                if ($state === 'declined_oversized') {
                    $matchState = 'review';
                    $this->logOversizedBlockDecline($identityId, $p);
                }
            }
        } else {
            $this->backfillKeys($identityId, $p);
        }
```

Add a new private method to `DeterministicResolver`, alongside `logReview()`:

```php
    /** Oversized-block decline (see ProbabilisticResolver::match()) made this
     * identity blind — no candidate was ever compared. */
    private function logOversizedBlockDecline(int $identityId, object $p): void
    {
        $blockSize = $this->hub()->table('stg_person')->where('block_key', $p->block_key)->count();
        $cap = (int) config('golden_profile.probabilistic.block_size_cap', 0);

        $this->hub()->table('gp_resolution_log')->insert([
            'action' => 'create',
            'identity_id' => $identityId,
            'affected_ids' => json_encode([
                'source_id' => (int) $p->source_id,
                'stg_person_id' => (int) $p->stg_person_id,
                'block_key' => $p->block_key,
            ]),
            'match_key' => 'oversized_block',
            'reason' => "oversized probabilistic block declined (block_size=$blockSize > cap=$cap) — steward confirm or split",
            'actor' => 'engine',
            'created_at' => now(),
        ]);
    }
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/OversizedBlockReviewFlagTest.php`
Expected: PASS, 2 tests.

Run: `vendor/bin/phpunit tests/Unit/ProbabilisticScoringTest.php tests/Feature/ResolverLadderTest.php`
Expected: PASS — confirms the new `'declined_oversized'` state does not disturb the existing ladder
or scoring tests (none of them configure a `block_size_cap` low enough to trigger it).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/GoldenProfile/Resolution/ProbabilisticResolver.php \
  app/GoldenProfile/Resolution/DeterministicResolver.php \
  tests/Feature/OversizedBlockReviewFlagTest.php
git commit -m "feat(resolver): flag oversized-block declines for steward review instead of silent auto_match"
```

---

## Task 8: Config keys — delete `status_severity`, relocate and wire `internal_verified_decay_days`

**Files:**
- Modify: `config/golden_profile.php`
- Modify: `app/Http/Controllers/Api/V1/CredentialSearchController.php` (`priorResolution()`)
- Test: `tests/Unit/CredentialResolutionDecayTest.php`

**Interfaces:**
- Produces: `CredentialSearchController::isWithinReuseDecayWindow(string $resolvedAt): bool` (public
  static — no controller instance or database needed to test).
- Consumes: `config('golden_profile.resolution.reuse_decay_days')`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/CredentialResolutionDecayTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\V1\CredentialSearchController;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Pure function of config + a date string — no controller instance, no
 * database. See config/golden_profile.php's resolution.reuse_decay_days for
 * why this moved here from survivorship.internal_verified_decay_days.
 */
class CredentialResolutionDecayTest extends TestCase
{
    public function test_a_resolution_within_the_window_is_still_auto_resolvable(): void
    {
        config(['golden_profile.resolution.reuse_decay_days' => 365]);

        $recent = Carbon::now()->subDays(10)->toDateTimeString();
        $this->assertTrue(CredentialSearchController::isWithinReuseDecayWindow($recent));
    }

    public function test_a_resolution_past_the_window_is_no_longer_auto_resolvable(): void
    {
        config(['golden_profile.resolution.reuse_decay_days' => 365]);

        $stale = Carbon::now()->subDays(400)->toDateTimeString();
        $this->assertFalse(CredentialSearchController::isWithinReuseDecayWindow($stale));
    }

    public function test_zero_or_negative_disables_decay(): void
    {
        config(['golden_profile.resolution.reuse_decay_days' => 0]);

        $ancient = Carbon::now()->subYears(10)->toDateTimeString();
        $this->assertTrue(CredentialSearchController::isWithinReuseDecayWindow($ancient));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/CredentialResolutionDecayTest.php`
Expected: FAIL — `Call to undefined method App\Http\Controllers\Api\V1\CredentialSearchController::isWithinReuseDecayWindow()`.

- [ ] **Step 3: Write the implementation**

In `config/golden_profile.php`, replace the `survivorship` block:

```php
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
```

with:

```php
    'survivorship' => [
        'field_authority' => [
            'identity' => ['verified', 'nppes', 'streamline_local', 'state_license', 'scraped_license'],
            'license' => ['state_license', 'nppes', 'scraped_license', 'streamline_local'],
            'address' => ['nppes', 'state_license', 'streamline_local', 'scraped_license'],
            'exclusion' => ['leie', 'sam', 'state_exclusion', 'streamline_local'],
        ],
        // status_severity (revoked=5 ... active=1, "most-restrictive wins") was
        // DELETED here 2026-09 (GPP conformance plan 6). It had no reader — the
        // same dead shape as ssn.return_full_ssn_in_api and
        // probabilistic.weights.provider_type below — but unlike those two,
        // wiring it was not a config fix: verified against stg_person_license,
        // StreamlineLocalConnector, and src_exclusion_record, NONE of which
        // carry a license/exclusion status vocabulary (active/revoked/
        // suspended/lapsed/expired) anywhere in the ingest path.
        // exclusion_records.match holds something like it, per registry, as
        // unstructured JSON (Streamlineverify\SV\Match\Presenter normalizes
        // ~10 raw field-name variants just for one date attribute, and the
        // set differs per registry) — extracting it safely is a connector /
        // data-quality task, not a config wire-up, and guessing at the JSON
        // shape here would risk writing confidently wrong severity data into
        // a compliance-adjacent field. Left for a future plan.
    ],

    /*
    | Resolution reuse. See App\GoldenProfile\ResolutionIngest (writes
    | gp_identity_resolution from CAMI's own match_actions /
    | credential_match_resolutions) and
    | App\Http\Controllers\Api\V1\CredentialSearchController::
    | isWithinReuseDecayWindow() (reads it).
    */
    'resolution' => [
        // Moved from survivorship.internal_verified_decay_days, which had no
        // reader: Survivorship::recompute() (and its set-based twin
        // SetFinalizer::survivorship()) only ever resolve IDENTITY_FIELDS
        // (name/dob/npi/upin/dea/ssn_hash) from stg_person columns — never a
        // per-credential or per-license verification timestamp — so there was
        // nowhere for a decay to plug in under survivorship. The concept — an
        // internal verification stops being trustworthy after N days — maps
        // exactly onto the one place the hub now records an internal
        // verification WITH a timestamp: a steward's resolved_at on
        // gp_identity_resolution. Same number (365) carried over; only the
        // wiring and the location are new.
        'reuse_decay_days' => 365,
    ],
```

In `app/Http/Controllers/Api/V1/CredentialSearchController.php`, add the import and modify
`priorResolution()`:

```php
use Illuminate\Support\Carbon;
```

Replace:

```php
        if (! $row) {
            return null;
        }

        return [
            'decision' => $row->decision,
            'action_type' => $row->action_type,
            'resolved_by' => $row->resolved_by,
            'resolved_at' => (string) $row->resolved_at,
            'auto_resolvable' => (bool) $row->is_auto_resolvable,
        ];
    }
```

with:

```php
        if (! $row) {
            return null;
        }

        return [
            'decision' => $row->decision,
            'action_type' => $row->action_type,
            'resolved_by' => $row->resolved_by,
            'resolved_at' => (string) $row->resolved_at,
            'auto_resolvable' => (bool) $row->is_auto_resolvable
                && self::isWithinReuseDecayWindow((string) $row->resolved_at),
        ];
    }

    /**
     * is_auto_resolvable says gp-cami is ALLOWED to reuse a resolution; it
     * says nothing about how long ago it was decided. Left unchecked, a
     * years-old steward confirm would auto-resolve forever — this is the
     * load-bearing use of golden_profile.resolution.reuse_decay_days (see
     * that config comment: this setting used to live under
     * survivorship.internal_verified_decay_days with no reader anywhere).
     * Public and static so it needs no controller instance or database to
     * test — see CredentialResolutionDecayTest.
     */
    public static function isWithinReuseDecayWindow(string $resolvedAt): bool
    {
        $decayDays = (int) config('golden_profile.resolution.reuse_decay_days', 0);

        if ($decayDays <= 0) {
            return true; // 0 or negative = decay disabled
        }

        return Carbon::parse($resolvedAt)->diffInDays(now()) <= $decayDays;
    }
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/CredentialResolutionDecayTest.php`
Expected: PASS, 3 tests.

Run: `grep -rn "status_severity\|internal_verified_decay_days" app/ config/ tests/`
Expected: no matches for `internal_verified_decay_days` outside this task's own docblocks; no
matches for `status_severity` at all.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add config/golden_profile.php app/Http/Controllers/Api/V1/CredentialSearchController.php \
  tests/Unit/CredentialResolutionDecayTest.php
git commit -m "fix(config): delete unwireable status_severity, relocate and wire the reuse decay window"
```

---

## Task 9: Prove the eval gate is unmoved

None of this plan's changes alter *which* identity a source row binds to — the oversized-block fix
(Task 7) changes only `match_state` and adds a log row on a path that already created the same
identity; the resolution ingest (Tasks 4–6) writes tables `MatchScorer` never reads. This task states
that plainly and proves it, rather than re-baselining anything.

**Files:**
- Modify: `docs/EVALUATION.md`

**Interfaces:** none — verification only.

- [ ] **Step 1: Run the gate as it stands today**

Run: `vendor/bin/phpunit tests/Feature/EvalGateTest.php`
Expected: PASS — `precision 1.0000 · recall 1.0000 · F1 1.0000 · 0 false merges · 0 false splits`,
matching the committed baseline in `docs/EVALUATION.md`.

- [ ] **Step 2: Run the full suite**

Run: `vendor/bin/phpunit --fail-on-skipped`
Expected: PASS, 0 skipped — every test added by Tasks 1–8 runs (none are conditionally skipped; all
`Feature/` tests here extend `HubTestCase`, which only skips when `GP_TEST_DB_DATABASE` is unset,
same as every other `Feature/` test in the suite).

- [ ] **Step 3: Record it**

Add a paragraph to `docs/EVALUATION.md`, immediately after the existing "Achieved" table:

```markdown
**Plan 6 (steward writer layer, 2026-09):** re-ran `EvalGateTest` after adding resolution ingest and
oversized-block review flagging. Numbers unchanged from the table above — neither change alters which
identity a source row binds to. Resolution ingest writes `gp_identity_resolution` and
`gp_identity_exclusion.link_state`, neither of which `MatchScorer` reads. The oversized-block fix
changes `gp_source_link.match_state` and adds a `gp_resolution_log` row on a path that already
created the same identity it does today; it does not change the identity created. No ratchet
re-baseline needed.
```

- [ ] **Step 4: Verify the addition doesn't break doc tooling (none exists) and commit**

Run: `vendor/bin/phpunit tests/Feature/EvalGateTest.php`
Expected: PASS (unchanged from Step 1 — this step exists to catch a merge conflict in the doc edit
having somehow touched code, which it should not have).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add docs/EVALUATION.md
git commit -m "docs(eval): record that plan 6 held the gate unchanged"
```

---

## Self-review

**Spec coverage.**

| Requirement | Where |
|---|---|
| Design question 1 (steward surface) | Answered above; implemented as `gp:ingest-resolutions` (Task 6) over `ResolutionIngest` (Tasks 4–6), with `pin_match` noted as already-implemented and `split` noted as having no source signal. |
| Design question 2 (`target_key` correctness, over-merge risk) | Answered above; `ResolutionMapper::credentialTargetKey()` (Task 1) pinned byte-identical to `CredentialSearchController::priorResolution()`'s existing construction; the risk is named, not silently assumed solved, and partially bounded by the decay window (Task 8). |
| Design question 3 (`status_severity`, `internal_verified_decay_days`) | `status_severity` deleted with the ingest-path evidence inline (Task 8); `internal_verified_decay_days` relocated to `resolution.reuse_decay_days` and wired into `CredentialSearchController` (Task 8), explicitly named as a new capability rather than a wiring of `Survivorship`. |
| Design question 4 (oversized-block flagging) | Task 7: `'declined_oversized'` state, `match_state = 'review'`, `gp_resolution_log` row; "what a steward does with it" answered honestly (manual SQL touch today, no new UI). |
| Eval gate (brief §5) | Task 9: run, confirm unmoved, documented reasoning for why no ratchet moves. |
| `is_pinned` already works | Read and cited in Programme context and design question 1; not re-implemented. |
| New migrations only, `2026_09_*` | None needed — stated explicitly in Global Constraints and true: every column this plan writes already exists once Plan 3 lands. |
| No real person's data in fixtures | All test fixtures use invented names (Devereaux, Quillfeather, Kowalski-style already in the codebase) and synthetic ids. |

**Placeholder scan.** No `TODO`, no "similar to Task N", no "write tests for the above". Every task's
Step 3 contains the complete method or class body, not a diff fragment relying on the reader to
infer surrounding code (Task 3 quotes the full pre-change method bodies for exactly this reason,
since they come from a plan document rather than the current repo).

**Type consistency.** `ResolutionMapper`'s three methods are the only producers of `link_state`/
`decision`/target-key strings in this plan; `ResolutionRecorder::record(array): bool` and
`ResolutionIngest::process*(Collection): array{recorded:int,skipped:int,already_ingested:int}` are
each defined once and consumed with the identical shape everywhere they are called (Tasks 4, 5, 6).
`Engine::writeVersioned*Rollup(array $row): void` takes exactly the row shape `rollupCredentials()`/
`rollupExclusions()` already build (unchanged by this plan) — no new field is invented on that side.
`CredentialSearchController::isWithinReuseDecayWindow(string): bool` is pure and used in exactly one
call site.

**Known risks carried into execution.**

1. **Task 3 is written against code that does not exist in this repo yet.** It is Plan 3 Task 8's
   own output, quoted in full, but if Plan 3's implementation or review changes that method's shape
   before merging, this task's literal "replace X with Y" will not apply cleanly. The principle
   survives regardless: *whatever* writes `gp_identity_exclusion`/`gp_identity_credential` on a
   routine sync must exclude `link_state` from the attributes passed to `Versioner::write()` once a
   current version already exists, or Tasks 4/5's writes get silently reverted on the next sync. An
   implementer hitting a shape mismatch should re-derive the fix from this principle, not skip it.
2. **The over-merge risk in design question 2 is named, not eliminated.** The decay window (Task 8)
   bounds how long a bad reuse can persist; it does not detect or prevent one. This is deliberate —
   detecting over-merges is plans 4/5/7's job (matching quality) and the eval gate's job (measurement),
   not this plan's.
3. **`gp_board_action` ingest is recommended as a split, not delivered here.** Verified during
   research (not merely assumed): board actions are not a separate CAMI data source — they are
   ordinary exclusion matches whose list carries `ExclusionList::BOARD_ACTION`
   (`Streamlineverify\SV\Matcher\BoardAction extends BatchExclusionList`), already flowing into
   `gp_identity_exclusion` today with no code change needed to detect their *existence*. What is
   missing is the separate `gp_board_action` table's richer columns
   (`action_type`/`action_date`/`resolution_date`/`description`/`source_url`), which live inside
   `exclusion_records.match` as **unstructured, per-registry JSON** — confirmed by inspecting
   `Streamlineverify\SV\Match\Presenter`, which normalizes on the order of ten raw field-name
   variants for a single date attribute alone, and the set of variants differs by registry. Writing
   a plausible-looking but wrong mapping here is worse than not writing it: `has_active_board_action`
   specifically depends on correctly distinguishing a null `resolution_date` (still active) from one
   CAMI recorded under some other key entirely (this plan would misread as "still active" too, or
   worse, "resolved" — there is no safe default). `IdentityProfileResource` also does not yet expose
   any of the three board-action rollup fields, meaning this work spans the connector, the
   materializer, and the API resource — a second, comparably-sized plan, not a ninth task here. The
   concrete boundary for that follow-on plan: build the per-registry JSON-to-board-action field
   mapping as its own connector task (mirroring `Match\Presenter`'s alias tables), verified against
   real sample payloads per registry rather than inferred from vendor source, before any write to
   `gp_board_action` is attempted. Of the five pieces named in the assignment, **resolution ingest
   carries the most value** — it is the one the design set calls the headline payoff, it is the one
   piece with a directly verified, already-implemented CAMI-side source, and delivering it also fixes
   `link_state` and lights up `IdentityProfileResource`'s already-wired `resolutions` field for free.
4. **`gp_identity_resolution`'s `resolved_by` is `null` for the entire credential domain** —
   `credential_match_resolutions` genuinely carries no `user_id` in CAMI's schema (verified: `id`,
   `credential_match_id`, `note`, `created_at` only). This is not a gap in the ingest; it is a gap in
   what CAMI itself records. Left honest rather than backfilled with a guess.
5. **Task 7's test seeds all three `stg_person` rows before resolving any of them**, so the FIRST
   `resolve()` call already sees a block of size 3 (over the cap of 2) — not just the third. This
   still proves the fix correctly (the third identity's link is asserted `review`), but does not
   exercise the "block crosses the cap mid-stream" ordering specifically; that distinction does not
   matter to the code (the check re-counts `stg_person` fresh every call) but is worth knowing when
   reading the test.
