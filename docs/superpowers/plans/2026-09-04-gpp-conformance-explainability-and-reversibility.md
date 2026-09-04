# GPP Conformance — Explainability & Reversibility Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development
> (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make every merge gp-cami performs explainable, readable and reversible, so that the
review band the resolver already computes stops being a number nobody can act on.

**Architecture:** Three capabilities, in dependency order. `MatchEvidence` writes one `gp_edge` row
per bind carrying the signals that produced it — the table exists and has never held a row.
`gp:review-queue` and a read endpoint consume `match_state`, which is currently written in four
places and read in none. `IdentityMutator` plus `gp:split` give the hub the identity-mutation
capability it has always lacked, which is what makes a review-band bind reversible and therefore
defensible. The resolver's binding behaviour is deliberately **left as it is** — see §"The
review-band decision".

**Tech Stack:** PHP ^8.3 (8.4.12 local), Laravel 13.20, PHPUnit 12.5, Laravel Pint, MySQL 8.

## Global Constraints

- Branch off the latest `feat/eval-harness` (or `master` once that has merged) as
  `feat/explainability-and-reversibility`. Do not push to `master`.
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

**Plan-specific:**

- Migrations for this plan take the `2026_09_10_*` prefix (`00-PROGRAMME.md` §3 assigns through
  `2026_09_09_000100`).
- `gp_edge` is **not** SCD-2 versioned. Plan 3a excluded it deliberately as append-only match
  evidence with a `created_at`. Do not version it.
- Under the canonical order this plan runs late, so the eval ladder is at `true_pairs` 13,
  `false_splits` 1, `recall` 0.9231, `precision` 1.0000, `false_merges` 0.

---

## Programme context — this is plan 9 of fourteen

| # | Plan | Depends on |
|---|---|---|
| 1 | Foundation — eval set, scorer, resolver harness, CI | — *(DONE, on the branch)* |
| 2 | SSN removal | 1 |
| 3a | SCD-2 versioning | 1 |
| 3b | SCD-2 set-based parity | 3a |
| 4 | Individual vs entity | 1, 3a, 5 |
| 5 | Match keys & data quality | 1 |
| 5b | Pass B blocking | 5 |
| 6 | Steward writer layer | 1, 3a |
| 7 | Exclusion lifecycle | 1, 3a |
| 8 | Incremental profiling | 1, 5 |
| **9** | **Explainability & reversibility** *(this document)* | **1, 3a, 6** |
| 10 | Credential cache | 1 |
| 11 | Measurement & monitoring | 1, 5, 6 |
| 12 | Bi-temporal validity | 3a |

`00-PROGRAMME.md` is the authority on execution order, migration timestamps and shared-component
ownership. `00-CONFORMANCE.md` records the audit against the data-science team's documentation that
produced plans 9–12; **its Theme 1 is this plan's entire specification.**

**Why this plan depends on 3a and 6.** 3a versions `gp_identity`, so a split writes new versions
rather than deleting rows, and every write goes through its `Versioner` primitive. Plan 6 builds
`ResolutionIngest`, which writes `gp_identity_resolution` from decisions CAMI already makes
(`confirm`/`reject` on exclusion and credential matches) — this plan does **not** duplicate that.
Plan 6 correctly identifies that `split` has no CAMI-side signal; its stated reason for declining it
is wrong, and the honest one is that gp-cami exposes no identity-mutation endpoint. That endpoint is
Task 5 here.

---

## The documentation this plan implements

**"How Record Matching Works"**, the human-in-the-loop section:

> Mid-confidence pairs go to a review queue where a steward can see the two records side by side,
> **understand exactly why** the system thinks they might match, and either confirm, reject, or split
> them. Every one of those decisions is saved and later reused to make the matching model smarter
> over time.

and its safeguards table:

> **Everything is reversible** — We keep the full list of connections and scores behind every merge,
> so any decision can be undone and re-run.

**"Recommendations & Open Risks"**, P1:

> Persist why each merge happened (signals + score — the engine already builds provenance dicts).
> Route near-threshold pairs to a review queue.

**Data Model (What We Store)** specifies `gold.match_candidate` — pairwise edges for explainability —
with `blocking_key`, `score`, `method`, `feature_json`, `decision`, and `gold.match_group` with
`member_count` and `cluster_score`.

**Delivery Checklist §3**: *"Route uncertain matches to a steward review queue"* and *"Feed steward
decisions back as new labels; re-tune the threshold."*

---

## What is verified broken today

Every item below was checked against the running code, not inferred.

| Finding | Evidence |
|---|---|
| **`gp_edge` has never held a row.** Its `detail` JSON column and ten-member `edge_type` enum exist from migration `2026_07_20_140000`. The table appears in `app/` exactly once: `Engine.php:476`, inside the reset TRUNCATE list. | `grep -rn gp_edge app/` |
| **`match_state` is written in four places and read in zero.** `DeterministicResolver.php:106` and three `INSERT` column lists in `SqlBackfill` (`:488`, `:533`, `:579`). No query anywhere filters on it. | `grep -rn match_state app/` |
| **Review-band matches are bound, then logged.** `in_array($state, ['auto_match','review'], true)` → `$identityId = $pid;` and only then `logReview()`. | `DeterministicResolver::resolve()` |
| **`status = 'split'` is never written**, by code or by any of the other thirteen plans. `gp_identity.status` has the enum member, `gp_resolution_log.action` has it, and `logReview()`'s own reason string ends "steward confirm/split". The hub emits rows inviting a decision it cannot record. | grep across `app/` and `docs/superpowers/plans/` |
| **Pass B cannot reach `auto_match` at all.** The implemented weights sum to exactly `auto_merge_at` (0.45 + 0.20 + 0.15 + 0.07 + 0.05 = 0.92) because `provider_type` (0.08) has no source column. `warnIfAutoMergeUnreachable()` logs it and `ProbabilisticScoringTest` pins it. | config + code |

The last row is what raises the stakes: **every** Pass B bind in production today is a review-band
bind. There is no auto-merge path through Pass B to worry about, and no reviewed path either.

---

## The review-band decision, made explicitly

The documentation says mid-confidence pairs go to a queue. The code binds them and logs. These are
materially different products and the audit was right to flag it. Both options, honestly:

**Option A — hold.** Do not bind on `review`; mint a separate identity, or leave the staged row
unresolved pending a decision. Conforms to the doc's letter.

*Cost:* a review-band record does not resolve, so `identity-search` and `credential-search` return a
404 for a person who demonstrably exists in the source. Nothing currently consumes a queue, so those
records sit unresolved indefinitely — and with Pass B's auto-merge unreachable, **this is every Pass
B match**, not an unusual tail. `record_count` and the rollups change meaning. The eval gate moves:
`chain-a`/`chain-b`/`chain-c` and the `kowalski` pair bind through Pass A tiers, so the fixture may
not detect the change at all, which is itself a reason for caution.

**Option B — bind and flag, which is today's behaviour.** The record is usable immediately; the flag
is advisory.

*Cost as it stands:* the merge is unexplained (`gp_edge` empty), unread (`match_state` has no
reader) and irreversible (no split). That combination is indefensible, and it is what the audit
found.

**Recommendation: Option B, and this plan is what earns it.** The doc's *intent* is that an uncertain
merge is reviewable, explainable and undoable — not that the record is unusable while it waits.
Holding trades a probable-and-marked answer for no answer, on the compliance-sensitive path, and
there is no steward surface for the queue to drain into. The honest fix is to build the three things
that make binding defensible rather than to stop binding:

1. explainable — Task 1 writes the evidence,
2. readable — Task 3 surfaces the queue,
3. reversible — Tasks 4 and 5 make the merge undoable.

**Consequence: the eval gate does not move.** No tier, threshold or binding rule changes, so
`precision`, `recall`, `false_merges` and `false_splits` are all unchanged. Task 7 asserts that, and
a movement means something in this plan touched matching when it should not have.

If the design review prefers Option A, the change is confined to
`DeterministicResolver::resolve()`'s `in_array(...)` and a re-baseline of the gate; the rest of this
plan is unaffected and still required. Record the decision in `docs/REVIEW.md` (Task 3) either way.

---

## `gp_edge`'s shape does not match how gp-cami matches — read this before Task 1

`gp_edge` is row-to-row: `src_link_id` and `dst_link_id`, both `NOT NULL`. That mirrors the Data
Model's `match_candidate`, which is a **pairwise** edge table.

gp-cami does not match row-to-row. `matchDeterministic()` probes `gp_identity` for a key and gets an
`identity_id`; `ProbabilisticResolver::match()` blocks, loads candidate **`gp_identity` rows**, and
scores the staged row against each. So a faithful edge for a gp-cami bind is
*staged-row-to-identity*, and there is no natural `dst_link_id`.

Three ways to handle it, and the one this plan takes:

| Option | Verdict |
|---|---|
| Make `dst_link_id` nullable | Cleanest semantically, but it is an `ALTER` on a table that is about to receive its first rows, and it makes `idx_pair(src_link_id, dst_link_id)` half-useless. |
| Add a separate row-to-identity evidence table | Most truthful, and it duplicates a table that already exists for this purpose and has never been used. Hard to justify. |
| **Use the identity's earliest current link as `dst_link_id`** | **Chosen.** It is a real link, it is stable, and it means "the link that established this identity". It is an **approximation** and Task 1 documents it as one, in the code and in `docs/REVIEW.md`. |

Naming the approximation matters more than eliminating it: a reader who believes `dst_link_id` is
"the specific row we compared against" will draw a wrong conclusion from the data. It is "the
identity we compared against, denoted by its founding link".

## Write volume — bounded deliberately

One edge per **bind**, not per comparison. Pass B scores every candidate in a block (capped at
`block_size_cap` = 2000), so per-comparison edges would be up to 2000 rows per staged row — on
13.38M source rows that is not a table, it is an outage. The losing candidates' scores are
summarised in the winning edge's `detail` instead (count considered, runner-up score), which is what
a steward actually needs to judge the decision.

At one edge per bind the ceiling is one row per `gp_source_link` row: **~13.4M rows on a full
backfill**, comparable to `gp_source_link` itself. `detail` is small (a flat object, no arrays), so
budget roughly 300–400 bytes a row. The set-based path (`SqlBackfill`) writes links in bulk and is
**out of scope for this plan** — Task 1 covers the per-row path only, and Task 7 records the gap
explicitly rather than leaving it to be discovered.

---

## File Structure

| File | Responsibility |
|---|---|
| `app/GoldenProfile/Support/MatchEvidence.php` *(create)* | Builds and writes one `gp_edge` row per bind. The only writer of that table. |
| `app/GoldenProfile/Resolution/DeterministicResolver.php` *(modify)* | Calls `MatchEvidence` after each bind; passes the deterministic key or the Pass B score breakdown. |
| `app/GoldenProfile/Resolution/ProbabilisticResolver.php` *(modify)* | `match()` returns its score breakdown alongside the verdict so the evidence is real rather than reconstructed. |
| `app/GoldenProfile/Review/ReviewQueue.php` *(create)* | Reads `match_state = 'review'` joined to its evidence. The only reader of `match_state`. |
| `app/Console/Commands/GpReviewQueue.php` *(create)* | `php artisan gp:review-queue` — lists pending review binds with their evidence. |
| `app/Http/Controllers/Api/V1/ReviewQueueController.php` *(create)* | `GET /api/v1/review-queue` — the same data for a UI that does not exist yet. |
| `app/GoldenProfile/Review/IdentityMutator.php` *(create)* | The split primitive: detach a source link from an identity under SCD-2. |
| `app/Console/Commands/GpSplit.php` *(create)* | `php artisan gp:split` — applies a split decision, `--dry-run` by default. |
| `app/GoldenProfile/Review/DecisionExporter.php` *(create)* | Exports settled review decisions as a candidate labeled set, outside the committed fixture. |
| `app/Console/Commands/GpExportLabels.php` *(create)* | `php artisan gp:export-labels` |
| `database/migrations/2026_09_10_000000_add_review_columns.php` *(create)* | `gp_edge.blocking_key`, `gp_edge.decision`; `gp_source_link.reviewed_at`, `reviewed_by` |
| `docs/REVIEW.md` *(create)* | The review model, the Option A/B decision, and the `dst_link_id` approximation |
| `tests/Unit/MatchEvidenceTest.php` *(create)* | Evidence construction, in isolation |
| `tests/Feature/MatchEvidenceWriteTest.php` *(create)* | An edge is written per bind, with the right type and detail |
| `tests/Feature/ReviewQueueTest.php` *(create)* | The queue surfaces review binds and only those |
| `tests/Feature/IdentitySplitTest.php` *(create)* | A split detaches, re-resolves, versions and logs |
| `tests/Feature/EvalGateUnmovedTest.php` *(create)* | The gate is unchanged by this plan |

---

## Task 1: `MatchEvidence` — the first writer `gp_edge` has ever had

**Files:**
- Create: `app/GoldenProfile/Support/MatchEvidence.php`
- Create: `tests/Unit/MatchEvidenceTest.php`
- Test: `vendor/bin/phpunit tests/Unit/MatchEvidenceTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces:
  - `MatchEvidence::__construct(?string $connection = null)`
  - `MatchEvidence::forDeterministic(string $matchKey, float $confidence): array` — the `detail` payload
  - `MatchEvidence::forProbabilistic(array $breakdown, int $considered, ?float $runnerUp): array`
  - `MatchEvidence::forNewIdentity(string $reason): array`
  - `MatchEvidence::edgeTypeFor(string $matchKey): string` — maps a `match_key` to the `edge_type` enum
  - `MatchEvidence::record(int $identityId, int $srcLinkId, string $matchKey, float $weight, array $detail, ?string $blockingKey, string $decision): void`
  - Tasks 2, 3 and 7 consume `edgeTypeFor()` and `record()`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/MatchEvidenceTest.php`:

```php
<?php

namespace Tests\Unit;

use App\GoldenProfile\Support\MatchEvidence;
use Tests\TestCase;

class MatchEvidenceTest extends TestCase
{
    public function test_every_deterministic_match_key_maps_to_an_edge_type(): void
    {
        // gp_edge.edge_type is an enum. A match_key with no mapping would throw at
        // insert time inside resolution — i.e. it would break matching to record
        // evidence about it, which is the worst possible trade.
        foreach (['ssn_hash', 'npi', 'dea_number', 'upin', 'license_registry', 'name_dob'] as $key) {
            $this->assertContains(
                MatchEvidence::edgeTypeFor($key),
                ['ssn_hash', 'npi', 'dea', 'upin', 'name_dob', 'license_registry', 'credential', 'exclusion', 'probabilistic', 'manual'],
                "match_key $key maps outside the edge_type enum"
            );
        }
    }

    public function test_an_unknown_match_key_falls_back_to_manual_rather_than_throwing(): void
    {
        // Failing closed here would mean a new tier breaks resolution until someone
        // remembers to extend the map. 'manual' is wrong-but-recorded, which beats
        // right-but-crashed.
        $this->assertSame('manual', MatchEvidence::edgeTypeFor('some_future_tier'));
    }

    public function test_deterministic_detail_records_the_key_and_confidence(): void
    {
        $detail = MatchEvidence::forDeterministic('npi', 0.99);

        $this->assertSame('deterministic', $detail['method']);
        $this->assertSame('npi', $detail['key']);
        $this->assertSame(0.99, $detail['confidence']);
    }

    public function test_probabilistic_detail_records_which_signals_fired(): void
    {
        // This is the P1 requirement: "persist why each merge happened (signals +
        // score)". A score alone does not tell a steward anything.
        $detail = MatchEvidence::forProbabilistic(
            ['name' => 0.45, 'dob' => 0.20, 'address' => 0.0, 'zip' => 0.0, 'exclusion_share' => 0.0],
            considered: 12,
            runnerUp: 0.61,
        );

        $this->assertSame('probabilistic', $detail['method']);
        $this->assertSame(0.65, $detail['score']);
        $this->assertSame(['name', 'dob'], $detail['signals_fired']);
        $this->assertSame(['address', 'zip', 'exclusion_share'], $detail['signals_absent']);
        $this->assertSame(12, $detail['candidates_considered']);
        $this->assertSame(0.61, $detail['runner_up_score']);
    }

    public function test_probabilistic_detail_tolerates_no_runner_up(): void
    {
        $detail = MatchEvidence::forProbabilistic(['name' => 0.45], considered: 1, runnerUp: null);

        $this->assertSame(1, $detail['candidates_considered']);
        $this->assertNull($detail['runner_up_score']);
    }

    public function test_a_new_identity_records_why_nothing_matched(): void
    {
        $detail = MatchEvidence::forNewIdentity('no deterministic key and Pass B declined');

        $this->assertSame('new', $detail['method']);
        $this->assertSame('no deterministic key and Pass B declined', $detail['reason']);
    }

    public function test_detail_is_a_flat_object_so_the_row_stays_small(): void
    {
        // gp_edge takes one row per bind, ~13.4M on a full backfill. A nested blob
        // per row is how gp_identity_profile ended up with a 69MB column.
        $detail = MatchEvidence::forProbabilistic(
            ['name' => 0.45, 'dob' => 0.20],
            considered: 3,
            runnerUp: 0.5,
        );

        foreach ($detail as $key => $value) {
            $this->assertTrue(
                is_scalar($value) || $value === null || (is_array($value) && $value === array_filter($value, 'is_string')),
                "detail[$key] is neither scalar, null, nor a flat list of strings"
            );
        }
        $this->assertLessThan(400, strlen(json_encode($detail)), 'detail should stay a few hundred bytes');
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/MatchEvidenceTest.php`

Expected: FAIL, 7 of 7 — `Class "App\GoldenProfile\Support\MatchEvidence" not found`.

- [ ] **Step 3: Write the class**

Create `app/GoldenProfile/Support/MatchEvidence.php`:

```php
<?php

namespace App\GoldenProfile\Support;

use Illuminate\Support\Facades\DB;

/**
 * The first and only writer of gp_edge.
 *
 * gp_edge has existed since 2026_07_20_140000 — edge_type enum, weight, a detail
 * JSON column — and has never held a row. Its single reference in app/ is
 * Engine.php:476, inside the reset TRUNCATE list. That makes the P1 in
 * "Recommendations & Open Risks" wholly unimplemented:
 *
 *   "Persist why each merge happened (signals + score — the engine already builds
 *    provenance dicts)."
 *
 * and it makes the review band unactionable, because "understand exactly why the
 * system thinks they might match" has nothing to read.
 *
 * ONE EDGE PER BIND, NOT PER COMPARISON
 * -------------------------------------
 * Pass B scores every candidate in a block, capped at block_size_cap = 2000. A row
 * per comparison would be up to 2000 rows per staged person, which on 13.38M
 * source rows is not a table. So the losing candidates are summarised inside the
 * winning edge's detail — candidates_considered and runner_up_score — which is
 * what a steward needs to judge the decision anyway. Ceiling: one row per
 * gp_source_link row, ~13.4M on a full backfill, at a few hundred bytes each.
 *
 * detail IS DELIBERATELY FLAT
 * ---------------------------
 * Scalars and flat string lists only. gp_identity_profile is the cautionary tale:
 * identity 3 carries a 69MB credentials blob, large enough to exhaust PHP's
 * memory_limit while hydrating one result. A per-bind table cannot afford that
 * shape.
 *
 * dst_link_id IS AN APPROXIMATION, AND CALLERS MUST KNOW IT
 * --------------------------------------------------------
 * gp_edge is row-to-row (src_link_id, dst_link_id, both NOT NULL), mirroring the
 * Data Model's pairwise match_candidate. gp-cami does not match row-to-row:
 * matchDeterministic() probes gp_identity for a key, and ProbabilisticResolver
 * scores a staged row against candidate gp_identity ROWS. So a faithful edge is
 * staged-row-to-identity and there is no natural dst_link_id.
 *
 * We use the identity's earliest current link — a real, stable link meaning "the
 * link that established this identity". It does NOT mean "the specific row we
 * compared against", and reading it that way yields wrong conclusions. See
 * docs/REVIEW.md.
 */
class MatchEvidence
{
    /** match_key (as written to gp_source_link) => gp_edge.edge_type enum member. */
    private const EDGE_TYPE = [
        'ssn_hash' => 'ssn_hash',
        'npi' => 'npi',
        'dea_number' => 'dea',
        'upin' => 'upin',
        'license_registry' => 'license_registry',
        'name_dob' => 'name_dob',
        'probabilistic' => 'probabilistic',
        'new' => 'manual',
    ];

    public function __construct(private ?string $connection = null) {}

    /**
     * Unknown keys fall back to 'manual' rather than throwing. A new tier must not
     * be able to break resolution just because nobody extended this map — the
     * evidence is a side effect and must never be able to fail the bind it
     * describes.
     */
    public static function edgeTypeFor(string $matchKey): string
    {
        return self::EDGE_TYPE[$matchKey] ?? 'manual';
    }

    /** @return array<string,mixed> */
    public static function forDeterministic(string $matchKey, float $confidence): array
    {
        return [
            'method' => 'deterministic',
            'key' => $matchKey,
            'confidence' => $confidence,
        ];
    }

    /**
     * @param  array<string,float>  $breakdown  signal name => contribution (0.0 when it did not fire)
     * @return array<string,mixed>
     */
    public static function forProbabilistic(array $breakdown, int $considered, ?float $runnerUp): array
    {
        $fired = [];
        $absent = [];
        foreach ($breakdown as $signal => $contribution) {
            if ($contribution > 0.0) {
                $fired[] = $signal;
            } else {
                $absent[] = $signal;
            }
        }

        return [
            'method' => 'probabilistic',
            'score' => round(array_sum($breakdown), 4),
            'signals_fired' => $fired,
            'signals_absent' => $absent,
            'candidates_considered' => $considered,
            'runner_up_score' => $runnerUp,
        ];
    }

    /** @return array<string,mixed> */
    public static function forNewIdentity(string $reason): array
    {
        return ['method' => 'new', 'reason' => $reason];
    }

    /**
     * Write one edge. Never throws into the caller: resolution correctness beats
     * evidence completeness, and a lost edge is a gap in an audit trail while a
     * thrown exception is a lost bind. Failures are logged, not raised.
     */
    public function record(
        int $identityId,
        int $srcLinkId,
        string $matchKey,
        float $weight,
        array $detail,
        ?string $blockingKey,
        string $decision,
    ): void {
        try {
            $db = $this->db();

            // "The link that established this identity" — see the class docblock.
            // Falls back to the src link itself for a brand-new identity, where the
            // src link IS the founding link.
            $dst = (int) ($db->table('gp_source_link')->where('identity_id', $identityId)
                ->orderBy('link_id')->value('link_id') ?? $srcLinkId);

            $db->table('gp_edge')->insert([
                'identity_id' => $identityId,
                'src_link_id' => $srcLinkId,
                'dst_link_id' => $dst,
                'edge_type' => self::edgeTypeFor($matchKey),
                'weight' => $weight,
                'detail' => json_encode($detail),
                'blocking_key' => $blockingKey,
                'decision' => $decision,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('match evidence not recorded', [
                'identity_id' => $identityId,
                'src_link_id' => $srcLinkId,
                'match_key' => $matchKey,
                'error' => class_basename($e).': '.$e->getMessage(),
            ]);
        }
    }

    private function db()
    {
        return DB::connection($this->connection ?? config('golden_profile.connections.hub', 'golden_profile'));
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/MatchEvidenceTest.php`

Expected: PASS, 7 tests.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/GoldenProfile/Support/MatchEvidence.php tests/Unit/MatchEvidenceTest.php
git commit -m "feat(review): add MatchEvidence, the first writer gp_edge has ever had"
```

---

## Task 2: The migration — the two columns `gp_edge` is missing, and review bookkeeping

**Files:**
- Create: `database/migrations/2026_09_10_000000_add_review_columns.php`
- Create: `tests/Feature/ReviewSchemaTest.php`

**Interfaces:**
- Produces, on `gp_edge`: `blocking_key VARCHAR(64) NULL`, `decision VARCHAR(12) NOT NULL DEFAULT 'match'`, and `INDEX idx_edge_decision(decision)`.
- Produces, on `gp_source_link`: `reviewed_at DATETIME NULL`, `reviewed_by VARCHAR(80) NULL`, and `INDEX idx_link_pending_review(match_state, reviewed_at)`.
- Consumed by: Task 1's `record()` (already writes `blocking_key` and `decision`), Task 3's `ReviewQueue`, Task 5's `IdentityMutator`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ReviewSchemaTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\Support\HubTestCase;

class ReviewSchemaTest extends HubTestCase
{
    public function test_gp_edge_carries_the_data_models_blocking_key_and_decision(): void
    {
        $schema = $this->hub()->getSchemaBuilder();

        $this->assertTrue($schema->hasColumn('gp_edge', 'blocking_key'));
        $this->assertTrue($schema->hasColumn('gp_edge', 'decision'));
    }

    public function test_gp_source_link_records_who_reviewed_a_bind_and_when(): void
    {
        $schema = $this->hub()->getSchemaBuilder();

        $this->assertTrue($schema->hasColumn('gp_source_link', 'reviewed_at'));
        $this->assertTrue($schema->hasColumn('gp_source_link', 'reviewed_by'));
    }

    public function test_the_pending_review_lookup_is_indexed(): void
    {
        // ReviewQueue filters match_state = 'review' AND reviewed_at IS NULL over
        // gp_source_link, which is ~13.4M rows. Unindexed that is a full scan on
        // every queue read.
        $rows = $this->hub()->select(
            "SHOW INDEX FROM gp_source_link WHERE Key_name = 'idx_link_pending_review'"
        );

        $this->assertCount(2, $rows, 'expected a two-column index on (match_state, reviewed_at)');
        $this->assertSame('match_state', $rows[0]->Column_name);
        $this->assertSame('reviewed_at', $rows[1]->Column_name);
    }

    public function test_decision_defaults_so_existing_edges_need_no_backfill(): void
    {
        // gp_edge is empty today, so the default is free — but the column must be
        // NOT NULL DEFAULT so a future backfill of the set-based path cannot
        // insert a null decision.
        $column = collect($this->hub()->select('SHOW COLUMNS FROM gp_edge'))
            ->firstWhere('Field', 'decision');

        $this->assertSame('NO', $column->Null);
        $this->assertSame('match', $column->Default);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/ReviewSchemaTest.php`

Expected: FAIL, 4 of 4 — the columns and index do not exist.

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_09_10_000000_add_review_columns.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The columns the review model needs that the 2026_07_20 schema did not
 * anticipate, because nothing was reading match_state or writing gp_edge.
 *
 * On gp_edge — two of the Data Model's match_candidate columns that gp_edge
 * lacks. blocking_key is what tells a steward WHY these two records were ever
 * compared, which for a Pass B match is half the explanation. decision is the
 * Data Model's match|review|no_match, denormalised onto the edge so the queue can
 * read evidence without joining gp_source_link.
 *
 * On gp_source_link — reviewed_at / reviewed_by. match_state records what the
 * MATCHER concluded; these record whether a HUMAN has looked. Without them the
 * queue cannot tell a pending review from a settled one, and would re-present
 * every decision forever.
 *
 * gp_edge is empty (verified: its only reference in app/ is a TRUNCATE), so the
 * ADDs there are free. gp_source_link is ~13.4M rows, so both its columns are
 * nullable with no default — ALGORITHM=INSTANT, no rewrite, no backfill pass.
 * Every existing link is by definition unreviewed, which nullable already says.
 */
return new class extends Migration
{
    protected $connection = 'golden_profile';

    public function up(): void
    {
        $c = DB::connection($this->connection);
        $s = Schema::connection($this->connection);

        if (! $s->hasColumn('gp_edge', 'blocking_key')) {
            $c->statement(
                "ALTER TABLE `gp_edge`
                 ADD COLUMN `blocking_key` VARCHAR(64) NULL,
                 ADD COLUMN `decision` VARCHAR(12) NOT NULL DEFAULT 'match',
                 ALGORITHM=INSTANT"
            );
            $c->statement(
                'ALTER TABLE `gp_edge` ADD INDEX `idx_edge_decision` (`decision`),
                 ALGORITHM=INPLACE, LOCK=NONE'
            );
        }

        if (! $s->hasColumn('gp_source_link', 'reviewed_at')) {
            $c->statement(
                'ALTER TABLE `gp_source_link`
                 ADD COLUMN `reviewed_at` DATETIME NULL,
                 ADD COLUMN `reviewed_by` VARCHAR(80) NULL,
                 ALGORITHM=INSTANT'
            );
            $c->statement(
                'ALTER TABLE `gp_source_link`
                 ADD INDEX `idx_link_pending_review` (`match_state`, `reviewed_at`),
                 ALGORITHM=INPLACE, LOCK=NONE'
            );
        }
    }

    public function down(): void
    {
        $c = DB::connection($this->connection);
        $s = Schema::connection($this->connection);

        if ($s->hasColumn('gp_source_link', 'reviewed_at')) {
            $c->statement('ALTER TABLE `gp_source_link` DROP INDEX `idx_link_pending_review`');
            $c->statement('ALTER TABLE `gp_source_link` DROP COLUMN `reviewed_at`, DROP COLUMN `reviewed_by`');
        }

        if ($s->hasColumn('gp_edge', 'blocking_key')) {
            $c->statement('ALTER TABLE `gp_edge` DROP INDEX `idx_edge_decision`');
            $c->statement('ALTER TABLE `gp_edge` DROP COLUMN `blocking_key`, DROP COLUMN `decision`');
        }
    }
};
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/ReviewSchemaTest.php`

Expected: PASS, 4 tests.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add database/migrations/2026_09_10_000000_add_review_columns.php tests/Feature/ReviewSchemaTest.php
git commit -m "feat(review): add gp_edge blocking_key/decision and link review bookkeeping"
```

---

## Task 3: Wire the resolvers so the evidence is real, not reconstructed

**Files:**
- Modify: `app/GoldenProfile/Resolution/ProbabilisticResolver.php`
- Modify: `app/GoldenProfile/Resolution/DeterministicResolver.php`
- Create: `tests/Feature/MatchEvidenceWriteTest.php`

**Interfaces:**
- Consumes: `MatchEvidence::forDeterministic()`, `forProbabilistic()`, `forNewIdentity()`, `record()` (Task 1); the migration's columns (Task 2).
- Produces: `ProbabilisticResolver::match(object $p, $licenses): array` now returns a **fourth** element — `array{0:?int,1:float,2:string,3:array}` where `[3]` is `['breakdown'=>array<string,float>, 'considered'=>int, 'runner_up'=>?float]`. Task 7 depends on this shape.
- Produces: `DeterministicResolver::resolve()` unchanged in signature — still `(int): int`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/MatchEvidenceWriteTest.php`:

```php
<?php

namespace Tests\Feature;

use App\GoldenProfile\Resolution\DeterministicResolver;
use Tests\Support\HubTestCase;

class MatchEvidenceWriteTest extends HubTestCase
{
    private function resolve(int $stgPersonId): int
    {
        return (new DeterministicResolver($this->systemId))->resolve($stgPersonId);
    }

    public function test_a_deterministic_bind_records_which_tier_fired(): void
    {
        $a = $this->stagePerson(['npi' => 1234567893]);
        $this->resolve($a);
        $b = $this->stagePerson(['npi' => 1234567893, 'first_name' => 'Bob', 'date_of_birth' => null]);
        $id = $this->resolve($b);

        $edge = $this->hub()->table('gp_edge')->where('edge_type', 'npi')->first();

        $this->assertNotNull($edge, 'the npi tier bound a row and left no evidence');
        $this->assertSame($id, (int) $edge->identity_id);
        $this->assertSame('match', $edge->decision);

        $detail = json_decode($edge->detail, true);
        $this->assertSame('deterministic', $detail['method']);
        $this->assertSame('npi', $detail['key']);
        $this->assertSame(0.99, $detail['confidence']);
    }

    public function test_a_new_identity_records_why_nothing_matched(): void
    {
        $a = $this->stagePerson(['first_name' => 'Solo', 'last_name' => 'Record']);
        $id = $this->resolve($a);

        $edge = $this->hub()->table('gp_edge')->where('identity_id', $id)->first();

        $this->assertNotNull($edge);
        $this->assertSame('manual', $edge->edge_type);
        $this->assertSame('no_match', $edge->decision);
        $this->assertSame('new', json_decode($edge->detail, true)['method']);
    }

    public function test_one_edge_per_bind_not_one_per_comparison(): void
    {
        // The volume guarantee. Three staged people in one block means Pass B
        // compares repeatedly, and the table must still hold three rows.
        foreach ([['Ann', '1981-03-03'], ['Ann', '1981-03-03'], ['Ann', '1981-03-03']] as $i => [$first, $dob]) {
            $this->resolve($this->stagePerson(['first_name' => $first, 'date_of_birth' => $dob]));
        }

        $this->assertSame(
            3, $this->hub()->table('gp_edge')->count(),
            'gp_edge must hold one row per bind, not one per candidate comparison'
        );
    }

    public function test_the_blocking_key_is_recorded_so_a_steward_knows_why_they_were_compared(): void
    {
        $a = $this->stagePerson(['last_name' => 'Kowalski', 'date_of_birth' => '1981-03-03']);
        $this->resolve($a);

        $edge = $this->hub()->table('gp_edge')->first();

        $this->assertSame($this->blockKey('Kowalski', '1981-03-03'), $edge->blocking_key);
    }

    public function test_evidence_failure_never_breaks_a_bind(): void
    {
        // Resolution correctness beats evidence completeness. Dropping the table
        // mid-run is the bluntest way to prove record() swallows its own failure.
        $this->hub()->statement('DROP TABLE gp_edge');

        $a = $this->stagePerson(['npi' => 1234567893]);
        $id = $this->resolve($a);

        $this->assertGreaterThan(0, $id, 'the bind must succeed even with no place to record it');

        // Restore for tearDown's sweep, which enumerates tables from information_schema.
        $this->hub()->statement(
            'CREATE TABLE gp_edge (edge_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
             identity_id BIGINT UNSIGNED, src_link_id BIGINT UNSIGNED, dst_link_id BIGINT UNSIGNED,
             edge_type VARCHAR(32), weight DECIMAL(5,4), detail JSON, blocking_key VARCHAR(64),
             decision VARCHAR(12) NOT NULL DEFAULT \'match\', created_at DATETIME)'
        );
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/MatchEvidenceWriteTest.php`

Expected: FAIL, 4 of 5 — no edges are written. `test_evidence_failure_never_breaks_a_bind` passes
already, because nothing writes `gp_edge` at all; it becomes meaningful after Step 3.

- [ ] **Step 3: Return the score breakdown from Pass B**

In `app/GoldenProfile/Resolution/ProbabilisticResolver.php`, change `score()` to return the
breakdown and `match()` to pass it out. Replace the `score()` signature and its accumulation with:

```php
    /**
     * @return array{score: float, breakdown: array<string,float>}
     *
     * Returns the per-signal contributions, not just the total. The P1 in
     * "Recommendations & Open Risks" asks for "signals + score", and a steward
     * cannot act on 0.77 — they need to know it was name plus address and NOT
     * date of birth. Reconstructing this after the fact would mean re-running the
     * comparison, so the breakdown travels with the verdict.
     */
    private function score(object $p, object $identity): array
    {
        $w = $this->cfg['weights'];
        $breakdown = [
            'name' => 0.0,
            'dob' => 0.0,
            'address' => 0.0,
            'zip' => 0.0,
            'exclusion_share' => 0.0,
        ];

        $breakdown['name'] = $w['name'] * NameMatcher::jaroWinkler(
            trim(($p->first_name ?? '').' '.($p->last_name ?? '')),
            trim(($identity->canonical_first ?? '').' '.($identity->canonical_last ?? '')),
        );

        if ($p->date_of_birth !== null && $p->date_of_birth === $identity->canonical_dob) {
            $breakdown['dob'] = $w['dob'];
        }

        [$addressOverlap, $zipOverlap] = $this->addressOverlap($p, (int) $identity->identity_id);
        if ($addressOverlap) {
            $breakdown['address'] = $w['address'];
        }
        if ($zipOverlap) {
            $breakdown['zip'] = $w['zip'];
        }

        if ($this->sharesExclusionRegistry($p, (int) $identity->identity_id)) {
            $breakdown['exclusion_share'] = $w['exclusion_share'];
        }

        return ['score' => min(1.0, array_sum($breakdown)), 'breakdown' => $breakdown];
    }
```

Then in `match()`, track the breakdown of the winner and the runner-up, and return them. Replace the
candidate loop and the return statements with:

```php
        $best = null;
        $bestScore = 0.0;
        $bestBreakdown = [];
        $runnerUp = null;
        $considered = 0;

        foreach ($identities as $identity) {
            $cid = $identity->identity_id;
            if ($this->hardNo($p, $identity)) {
                continue;
            }
            if (! NameMatcher::compatible($p, $this->asNameObj($identity))) {
                continue;
            }

            $considered++;
            ['score' => $score, 'breakdown' => $breakdown] = $this->score($p, $identity);

            if ($score > $bestScore) {
                $runnerUp = $best === null ? null : $bestScore;
                $bestScore = $score;
                $bestBreakdown = $breakdown;
                $best = (int) $cid;
            } elseif ($runnerUp === null || $score > $runnerUp) {
                $runnerUp = $score;
            }
        }

        $evidence = ['breakdown' => $bestBreakdown, 'considered' => $considered, 'runner_up' => $runnerUp];

        if ($best === null) {
            return [null, 0.0, 'no_match', ['breakdown' => [], 'considered' => $considered, 'runner_up' => $runnerUp]];
        }
```

Every remaining `return [...]` in `match()` gains `$evidence` as its fourth element, and the two
early returns (no `block_key`, and the `block_size_cap` decline) return
`['breakdown' => [], 'considered' => 0, 'runner_up' => null]`.

Update the class docblock's return description from `[identity_id|null, score, match_state]` to
`[identity_id|null, score, match_state, evidence]`.

- [ ] **Step 4: Wire `DeterministicResolver`**

Add the member and constructor wiring:

```php
    private MatchEvidence $evidence;
```

and in the constructor, after `$this->ssnGuard = new SsnHashGuard;`:

```php
        $this->evidence = new MatchEvidence;
```

with `use App\GoldenProfile\Support\MatchEvidence;` added to the imports.

Then in `resolve()`, capture the evidence payload alongside the existing decisions. Replace the
Pass A/Pass B block and the link insert with:

```php
        [$identityId, $key, $conf] = $this->matchDeterministic($p, $licenses);
        $method = 'deterministic';
        $matchState = 'auto_match';
        $detail = $identityId === null ? null : MatchEvidence::forDeterministic($key, $conf);
        $decision = 'match';

        if ($identityId === null) {
            // Pass A missed — try Pass B probabilistic.
            [$pid, $score, $state, $ev] = $this->probabilistic->match($p, $licenses);
            if ($pid !== null && in_array($state, ['auto_match', 'review'], true)) {
                $identityId = $pid;
                $key = 'probabilistic';
                $conf = $score;
                $method = 'probabilistic';
                $matchState = $state;
                $detail = MatchEvidence::forProbabilistic($ev['breakdown'], $ev['considered'], $ev['runner_up']);
                $decision = $state === 'review' ? 'review' : 'match';
                if ($state === 'review') {
                    $this->logReview($identityId, $p, $score);
                } else {
                    $this->backfillKeys($identityId, $p);
                }
            } else {
                $identityId = $this->createIdentity($p);
                $key = 'new';
                $conf = 1.0;
                $detail = MatchEvidence::forNewIdentity(
                    $ev['considered'] === 0
                        ? 'no deterministic key and no Pass B candidates in block'
                        : 'no deterministic key and Pass B scored '.$ev['considered'].' candidate(s) below the review floor'
                );
                $decision = 'no_match';
            }
        } else {
            $this->backfillKeys($identityId, $p);
        }

        $linkId = (int) $hub->table('gp_source_link')->insertGetId([
            'identity_id' => $identityId,
            'system_id' => $this->systemId,
            'source_table' => $p->source_table,
            'source_id' => $p->source_id,
            'account_id' => $p->account_id,
            'employeelist_id' => $p->employeelist_id,
            'match_method' => $method,
            'match_key' => $key,
            'match_score' => $conf,
            'match_state' => $matchState,
            'is_pinned' => 0,
            'linked_at' => now(),
        ]);

        // Evidence AFTER the link exists, because src_link_id references it. This
        // is the P1's "persist why each merge happened" and it is deliberately the
        // last thing to happen: MatchEvidence::record() swallows its own failures
        // so a missing edge can never cost us a bind.
        $this->evidence->record($identityId, $linkId, $key, (float) $conf, $detail, $p->block_key, $decision);
```

- [ ] **Step 5: Run the focused tests**

Run: `vendor/bin/phpunit tests/Feature/MatchEvidenceWriteTest.php tests/Unit/ProbabilisticScoringTest.php`

Expected: PASS. `ProbabilisticScoringTest` exercises `score()` indirectly through the config
reachability check and must stay green; if it reads `score()` by reflection it needs its expectation
updated from a float to the array shape — make that change and say so in the commit body.

- [ ] **Step 6: Run the full suite**

Run: `vendor/bin/phpunit --fail-on-skipped`

Expected: PASS, 0 skipped. **`EvalGateTest` must be unchanged** — this task alters no tier, no
threshold and no binding rule. If it moves, the `score()` refactor changed a contribution; diff the
breakdown against the original accumulation before going further.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty
git add app/GoldenProfile/Resolution/ProbabilisticResolver.php \
        app/GoldenProfile/Resolution/DeterministicResolver.php \
        tests/Feature/MatchEvidenceWriteTest.php
git commit -m "feat(review): record match evidence for every bind, with the Pass B signal breakdown"
```

---

## Task 4: `ReviewQueue` — the first reader `match_state` has ever had

**Files:**
- Create: `app/GoldenProfile/Review/ReviewQueue.php`
- Create: `app/Console/Commands/GpReviewQueue.php`
- Create: `app/Http/Controllers/Api/V1/ReviewQueueController.php`
- Modify: `routes/api.php`
- Create: `tests/Feature/ReviewQueueTest.php`

**Interfaces:**
- Consumes: the migration's `reviewed_at`/`reviewed_by` (Task 2); edges written by Task 3.
- Produces:
  - `ReviewQueue::pending(int $limit = 50, ?int $afterLinkId = null): array` — list of `array{link_id:int, identity_id:int, source_table:string, source_id:int, match_key:string, match_score:float, linked_at:string, evidence:?array}`
  - `ReviewQueue::count(): int`
  - `ReviewQueue::settle(int $linkId, string $by): bool` — marks reviewed without changing the bind
  - Task 5's `IdentityMutator` calls `settle()` after a split.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ReviewQueueTest.php`:

```php
<?php

namespace Tests\Feature;

use App\GoldenProfile\Review\ReviewQueue;
use Tests\Support\HubTestCase;

class ReviewQueueTest extends HubTestCase
{
    private function link(string $state, ?string $reviewedAt = null): int
    {
        $id = (int) $this->hub()->table('gp_identity')->insertGetId([
            'identity_uuid' => $this->uuidish(), 'canonical_first' => 'Q', 'canonical_last' => 'Queue',
            'confidence' => 1.0, 'record_count' => 1, 'status' => 'active',
            'first_seen' => now(), 'last_updated' => now(),
        ]);

        static $sourceId = 7000;

        return (int) $this->hub()->table('gp_source_link')->insertGetId([
            'identity_id' => $id, 'system_id' => $this->systemId, 'source_table' => 'employees',
            'source_id' => ++$sourceId, 'match_method' => 'probabilistic', 'match_key' => 'probabilistic',
            'match_score' => 0.81, 'match_state' => $state, 'is_pinned' => 0, 'linked_at' => now(),
            'reviewed_at' => $reviewedAt,
        ]);
    }

    private function uuidish(): string
    {
        return (string) \Illuminate\Support\Str::uuid();
    }

    public function test_the_queue_surfaces_review_binds(): void
    {
        $this->link('review');

        $this->assertSame(1, (new ReviewQueue)->count());
    }

    public function test_the_queue_excludes_auto_match_and_no_match(): void
    {
        $this->link('auto_match');
        $this->link('no_match');

        $this->assertSame(0, (new ReviewQueue)->count(), 'only the review band belongs in the queue');
    }

    public function test_a_settled_bind_leaves_the_queue_and_stays_bound(): void
    {
        // Settling records that a human looked. It deliberately does NOT change the
        // bind — that is what gp:split is for. A queue that re-presented every
        // decision forever would be useless.
        $linkId = $this->link('review');
        $before = $this->hub()->table('gp_source_link')->where('link_id', $linkId)->value('identity_id');

        $this->assertTrue((new ReviewQueue)->settle($linkId, 'steward@example.test'));

        $this->assertSame(0, (new ReviewQueue)->count());
        $this->assertSame(
            $before,
            $this->hub()->table('gp_source_link')->where('link_id', $linkId)->value('identity_id'),
            'settling must not move the link'
        );
    }

    public function test_settling_an_unknown_link_reports_failure_rather_than_throwing(): void
    {
        $this->assertFalse((new ReviewQueue)->settle(999999, 'steward@example.test'));
    }

    public function test_the_queue_carries_the_evidence_so_a_steward_can_judge(): void
    {
        $linkId = $this->link('review');
        $this->hub()->table('gp_edge')->insert([
            'identity_id' => $this->hub()->table('gp_source_link')->where('link_id', $linkId)->value('identity_id'),
            'src_link_id' => $linkId, 'dst_link_id' => $linkId, 'edge_type' => 'probabilistic',
            'weight' => 0.81, 'detail' => json_encode(['method' => 'probabilistic', 'score' => 0.81,
                'signals_fired' => ['name', 'address'], 'signals_absent' => ['dob', 'zip', 'exclusion_share'],
                'candidates_considered' => 4, 'runner_up_score' => 0.55]),
            'blocking_key' => 'K420|1981', 'decision' => 'review', 'created_at' => now(),
        ]);

        $row = (new ReviewQueue)->pending()[0];

        $this->assertSame(['name', 'address'], $row['evidence']['signals_fired']);
        $this->assertSame(4, $row['evidence']['candidates_considered']);
    }

    public function test_pending_pages_by_link_id(): void
    {
        $first = $this->link('review');
        $second = $this->link('review');

        $page = (new ReviewQueue)->pending(limit: 1, afterLinkId: $first);

        $this->assertCount(1, $page);
        $this->assertSame($second, $page[0]['link_id']);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/ReviewQueueTest.php`

Expected: FAIL, 6 of 6 — `Class "App\GoldenProfile\Review\ReviewQueue" not found`.

- [ ] **Step 3: Write `ReviewQueue`**

Create `app/GoldenProfile/Review/ReviewQueue.php`:

```php
<?php

namespace App\GoldenProfile\Review;

use Illuminate\Support\Facades\DB;

/**
 * The first reader gp_source_link.match_state has ever had.
 *
 * match_state is written in four places — DeterministicResolver and three
 * SqlBackfill INSERTs — and, before this class, read in none. So "How Record
 * Matching Works"'s review queue did not exist, and the band the resolver
 * computes was a number nobody could act on.
 *
 * WHAT SETTLING MEANS, AND WHAT IT DOES NOT
 * -----------------------------------------
 * settle() records that a human looked. It does not move the link. That split is
 * deliberate: gp-cami binds review-band matches (see docs/REVIEW.md for why that
 * is the recommended behaviour rather than holding them), so the common review
 * outcome is "yes, that was right" and the queue's job is to stop re-presenting
 * it. Changing a bind is gp:split, which is a different, louder operation.
 *
 * WHY THIS IS NOT A UI
 * --------------------
 * gp-cami is API-only — the web UI was removed deliberately in f0a3126 and
 * PROJECT_PLAN.md §8 rules out rebuilding it. So the queue is an artisan command
 * for an operator and a read endpoint for whatever eventually consumes it. Plan 6
 * covers the decisions CAMI already captures in its own UI (confirm/reject on
 * exclusion and credential matches); this queue is the complement — the binds
 * gp-cami itself flagged, which CAMI has no view of.
 */
class ReviewQueue
{
    public function __construct(private ?string $connection = null) {}

    /** Binds the matcher flagged and nobody has looked at. */
    public function count(): int
    {
        return (int) $this->db()->table('gp_source_link')
            ->where('match_state', 'review')
            ->whereNull('reviewed_at')
            ->count();
    }

    /**
     * @return list<array{link_id:int, identity_id:int, source_table:string, source_id:int,
     *     match_key:string, match_score:float, linked_at:string, evidence:?array}>
     */
    public function pending(int $limit = 50, ?int $afterLinkId = null): array
    {
        $q = $this->db()->table('gp_source_link as l')
            ->leftJoin('gp_edge as e', 'e.src_link_id', '=', 'l.link_id')
            ->where('l.match_state', 'review')
            ->whereNull('l.reviewed_at')
            ->orderBy('l.link_id')
            ->limit($limit)
            ->select([
                'l.link_id', 'l.identity_id', 'l.source_table', 'l.source_id',
                'l.match_key', 'l.match_score', 'l.linked_at', 'e.detail', 'e.blocking_key',
            ]);

        if ($afterLinkId !== null) {
            $q->where('l.link_id', '>', $afterLinkId);
        }

        return $q->get()->map(fn ($r) => [
            'link_id' => (int) $r->link_id,
            'identity_id' => (int) $r->identity_id,
            'source_table' => $r->source_table,
            'source_id' => (int) $r->source_id,
            'match_key' => $r->match_key,
            'match_score' => (float) $r->match_score,
            'linked_at' => (string) $r->linked_at,
            'blocking_key' => $r->blocking_key,
            'evidence' => $r->detail === null ? null : json_decode($r->detail, true),
        ])->all();
    }

    /** Mark a bind reviewed without changing it. Returns false when the link is unknown. */
    public function settle(int $linkId, string $by): bool
    {
        return $this->db()->table('gp_source_link')
            ->where('link_id', $linkId)
            ->update(['reviewed_at' => now(), 'reviewed_by' => $by]) > 0;
    }

    private function db()
    {
        return DB::connection($this->connection ?? config('golden_profile.connections.hub', 'golden_profile'));
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/ReviewQueueTest.php`

Expected: PASS, 6 tests.

- [ ] **Step 5: Add the command and the endpoint**

Create `app/Console/Commands/GpReviewQueue.php`:

```php
<?php

namespace App\Console\Commands;

use App\GoldenProfile\Review\ReviewQueue;
use Illuminate\Console\Command;

class GpReviewQueue extends Command
{
    protected $signature = 'gp:review-queue
        {--limit=25 : rows to show}
        {--after= : page from this link_id}
        {--settle= : mark one link_id reviewed and exit}
        {--by= : who is settling (required with --settle)}';

    protected $description = 'List identity binds the matcher flagged for review, with their evidence';

    public function handle(): int
    {
        $queue = new ReviewQueue;

        if ($linkId = $this->option('settle')) {
            $by = (string) $this->option('by');
            if ($by === '') {
                $this->error('--by is required with --settle: an unattributed review is not a review');

                return self::FAILURE;
            }

            if (! $queue->settle((int) $linkId, $by)) {
                $this->error("link $linkId not found");

                return self::FAILURE;
            }

            $this->info("link $linkId marked reviewed by $by (the bind is unchanged — use gp:split to move it)");

            return self::SUCCESS;
        }

        $total = $queue->count();
        $rows = $queue->pending((int) $this->option('limit'), $this->option('after') ? (int) $this->option('after') : null);

        $this->line("$total bind(s) awaiting review");

        $this->table(
            ['link', 'identity', 'source_id', 'score', 'block', 'why'],
            array_map(fn ($r) => [
                $r['link_id'],
                $r['identity_id'],
                $r['source_id'],
                number_format($r['match_score'], 4),
                $r['blocking_key'] ?? '-',
                $r['evidence'] === null
                    ? 'no evidence recorded'
                    : implode('+', $r['evidence']['signals_fired'] ?? []).
                      ' (considered '.($r['evidence']['candidates_considered'] ?? '?').
                      ', runner-up '.($r['evidence']['runner_up_score'] ?? '-').')',
            ], $rows),
        );

        return self::SUCCESS;
    }
}
```

Create `app/Http/Controllers/Api/V1/ReviewQueueController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\GoldenProfile\Review\ReviewQueue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/review-queue
 *
 * Read-only. Settling and splitting are artisan operations, not endpoints: both
 * mutate identity state, and gp-cami has no authenticated human identity to
 * attribute such a change to — its Sanctum token is CAMI's service token, not a
 * steward's. Exposing a mutation here would record every decision as having been
 * made by "CAMI".
 */
class ReviewQueueController extends Controller
{
    public function __invoke(Request $request, ReviewQueue $queue): JsonResponse
    {
        $validated = $request->validate([
            'limit' => 'sometimes|integer|min:1|max:200',
            'after_link_id' => 'sometimes|integer|min:1',
        ]);

        return response()->json([
            'pending_total' => $queue->count(),
            'data' => $queue->pending(
                $validated['limit'] ?? 50,
                $validated['after_link_id'] ?? null,
            ),
        ]);
    }
}
```

In `routes/api.php`, add inside the existing `v1` group:

```php
        Route::get('/review-queue', ReviewQueueController::class);
```

with the matching import.

- [ ] **Step 6: Verify the command and route register**

Run: `php artisan list gp` — expected: `gp:review-queue` alongside `gp:backfill`, `gp:eval`,
`gp:rebuild-aliases`, `gp:rebuild-profile`, `gp:sync`.

Run: `php artisan route:list --path=api/v1` — expected: `GET api/v1/review-queue` alongside the two
existing POST routes, behind `auth:sanctum` and `throttle:120,1`.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty
git add app/GoldenProfile/Review/ReviewQueue.php app/Console/Commands/GpReviewQueue.php \
        app/Http/Controllers/Api/V1/ReviewQueueController.php routes/api.php \
        tests/Feature/ReviewQueueTest.php
git commit -m "feat(review): surface the review band via gp:review-queue and a read endpoint"
```

---

## Task 5: `IdentityMutator` — make a merge reversible

**Files:**
- Create: `app/GoldenProfile/Review/IdentityMutator.php`
- Create: `app/Console/Commands/GpSplit.php`
- Create: `tests/Feature/IdentitySplitTest.php`

**Interfaces:**
- Consumes: `ReviewQueue::settle()` (Task 4); plan 3a's `Versioner` when it has landed.
- Produces:
  - `IdentityMutator::split(int $linkId, string $by, string $rationale): array` returning `array{from:int, to:int, relinked:int}`
  - `IdentityMutator::canSplit(int $linkId): ?string` — null when splittable, else the reason it is not

**This is the capability plan 6 identified as missing and misattributed.** Plan 6 declines `split`
because it has "no CAMI-side signal", which is true and beside the point: a split reverses a merge
**gp-cami** made, so CAMI can never be its source. The real blocker is that gp-cami has no
identity-mutation path. This task is it.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/IdentitySplitTest.php`:

```php
<?php

namespace Tests\Feature;

use App\GoldenProfile\Resolution\DeterministicResolver;
use App\GoldenProfile\Review\IdentityMutator;
use Tests\Support\HubTestCase;

class IdentitySplitTest extends HubTestCase
{
    private function resolve(int $stgPersonId): int
    {
        return (new DeterministicResolver($this->systemId))->resolve($stgPersonId);
    }

    public function test_splitting_detaches_a_link_onto_its_own_identity(): void
    {
        $a = $this->stagePerson(['first_name' => 'Maria', 'last_name' => 'Garcia', 'date_of_birth' => '1975-01-09']);
        $b = $this->stagePerson(['first_name' => 'Maria', 'last_name' => 'Garcia', 'date_of_birth' => '1975-01-09']);
        $shared = $this->resolve($a);
        $this->assertSame($shared, $this->resolve($b));

        $linkB = (int) $this->hub()->table('gp_source_link')
            ->where('identity_id', $shared)->orderByDesc('link_id')->value('link_id');

        $result = (new IdentityMutator)->split($linkB, 'steward@example.test', 'different people, same birthday');

        $this->assertSame($shared, $result['from']);
        $this->assertNotSame($shared, $result['to']);
        $this->assertSame(
            $result['to'],
            (int) $this->hub()->table('gp_source_link')->where('link_id', $linkB)->value('identity_id')
        );
    }

    public function test_a_split_is_logged_with_its_rationale(): void
    {
        $a = $this->stagePerson(['npi' => 1234567893]);
        $b = $this->stagePerson(['npi' => 1234567893, 'first_name' => 'Bob', 'date_of_birth' => null]);
        $shared = $this->resolve($a);
        $this->resolve($b);
        $linkB = (int) $this->hub()->table('gp_source_link')
            ->where('identity_id', $shared)->orderByDesc('link_id')->value('link_id');

        (new IdentityMutator)->split($linkB, 'steward@example.test', 'npi was a data-entry error');

        $log = $this->hub()->table('gp_resolution_log')->where('action', 'split')->first();

        $this->assertNotNull($log, 'a split must be recorded in gp_resolution_log');
        $this->assertSame('steward@example.test', $log->actor);
        $this->assertStringContainsString('npi was a data-entry error', $log->reason);
    }

    public function test_a_split_marks_the_bind_reviewed(): void
    {
        $a = $this->stagePerson(['first_name' => 'Ann', 'last_name' => 'Kowalski', 'date_of_birth' => '1981-03-03']);
        $b = $this->stagePerson(['first_name' => 'Ann', 'last_name' => 'Kowalski', 'date_of_birth' => '1981-03-03']);
        $shared = $this->resolve($a);
        $this->resolve($b);
        $linkB = (int) $this->hub()->table('gp_source_link')
            ->where('identity_id', $shared)->orderByDesc('link_id')->value('link_id');

        (new IdentityMutator)->split($linkB, 'steward@example.test', 'wrong');

        $link = $this->hub()->table('gp_source_link')->where('link_id', $linkB)->first();
        $this->assertNotNull($link->reviewed_at);
        $this->assertSame('steward@example.test', $link->reviewed_by);
    }

    public function test_a_pinned_link_cannot_be_split(): void
    {
        // is_pinned is a locked human decision. Splitting one silently would undo
        // an earlier steward without telling anybody.
        $a = $this->stagePerson(['npi' => 1234567893]);
        $b = $this->stagePerson(['npi' => 1234567893, 'first_name' => 'Bob', 'date_of_birth' => null]);
        $shared = $this->resolve($a);
        $this->resolve($b);
        $linkB = (int) $this->hub()->table('gp_source_link')
            ->where('identity_id', $shared)->orderByDesc('link_id')->value('link_id');
        $this->hub()->table('gp_source_link')->where('link_id', $linkB)->update(['is_pinned' => 1]);

        $mutator = new IdentityMutator;
        $this->assertStringContainsString('pinned', (string) $mutator->canSplit($linkB));

        $this->expectException(\RuntimeException::class);
        $mutator->split($linkB, 'steward@example.test', 'try anyway');
    }

    public function test_the_last_link_of_an_identity_cannot_be_split(): void
    {
        // Splitting the only link would move the identity's entire population to a
        // new identity and leave an empty one — a rename, not a split.
        $a = $this->stagePerson(['first_name' => 'Solo', 'last_name' => 'Record']);
        $id = $this->resolve($a);
        $linkId = (int) $this->hub()->table('gp_source_link')->where('identity_id', $id)->value('link_id');

        $this->assertStringContainsString('only link', (string) (new IdentityMutator)->canSplit($linkId));
    }

    public function test_splitting_leaves_the_source_row_resolvable_again(): void
    {
        // The detached row must resolve to its new identity, not bounce back on the
        // next gp:sync. resolve() short-circuits on an existing link, so the link
        // must point at the new identity for that to hold.
        $a = $this->stagePerson(['first_name' => 'Maria', 'last_name' => 'Garcia', 'date_of_birth' => '1975-01-09']);
        $b = $this->stagePerson(['first_name' => 'Maria', 'last_name' => 'Garcia', 'date_of_birth' => '1975-01-09']);
        $shared = $this->resolve($a);
        $this->resolve($b);
        $linkB = (int) $this->hub()->table('gp_source_link')
            ->where('identity_id', $shared)->orderByDesc('link_id')->value('link_id');

        $result = (new IdentityMutator)->split($linkB, 'steward@example.test', 'separate people');

        $this->assertSame($result['to'], $this->resolve($b), 'a re-resolve must respect the split');
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/IdentitySplitTest.php`

Expected: FAIL, 6 of 6 — `Class "App\GoldenProfile\Review\IdentityMutator" not found`.

- [ ] **Step 3: Write `IdentityMutator`**

Create `app/GoldenProfile/Review/IdentityMutator.php`:

```php
<?php

namespace App\GoldenProfile\Review;

use App\GoldenProfile\Resolution\Survivorship;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The identity-mutation capability gp-cami has never had.
 *
 * "How Record Matching Works" lists reversibility as a safeguard — "we keep the
 * full list of connections and scores behind every merge, so any decision can be
 * undone and re-run" — and gp_identity.status has a 'split' member,
 * gp_resolution_log.action has a 'split' member, and logReview()'s own reason
 * string ends "steward confirm/split". Nothing has ever written any of them. The
 * hub emits rows inviting a decision it could not record.
 *
 * Plan 6 declined split because it has "no CAMI-side signal". True, and beside
 * the point: a split reverses a merge GP-CAMI made, so CAMI can never be its
 * source. The actual blocker was the absence of this class.
 *
 * WHAT A SPLIT DOES
 * -----------------
 * Detaches ONE gp_source_link from its identity and repoints it at a fresh one.
 * Not "un-merge these two identities" — the unit of a merge here is a link, so the
 * unit of reversal is a link. Splitting several is several calls, which also keeps
 * each one individually attributable.
 *
 * Then: recompute survivorship on BOTH identities (the loser's canonical values
 * may have been won by the departing row), and log it. Under plan 3a the
 * recompute writes new versions rather than overwriting, so the pre-split state
 * survives at current = 0 — which is what makes the split itself reversible.
 *
 * WHAT IT DELIBERATELY DOES NOT DO
 * --------------------------------
 * It does not re-run matching to find a better home for the detached row. That
 * would re-derive the merge we were just told is wrong. The row goes to a new
 * identity of its own, and the next gp:sync leaves it there because resolve()
 * short-circuits on an existing link. A steward who wants it merged elsewhere
 * pins it there instead.
 *
 * It does not touch gp_identity_profile. That is a derived read model, rebuilt by
 * gp:rebuild-profile — which the command runs for both identities afterwards.
 */
class IdentityMutator
{
    public function __construct(private ?string $connection = null) {}

    /**
     * Null when the link can be split, otherwise why it cannot.
     */
    public function canSplit(int $linkId): ?string
    {
        $link = $this->db()->table('gp_source_link')->where('link_id', $linkId)->first();

        if ($link === null) {
            return "link $linkId does not exist";
        }

        if ((int) $link->is_pinned === 1) {
            return "link $linkId is pinned — a locked human decision. Unpin it first, deliberately.";
        }

        $siblings = (int) $this->db()->table('gp_source_link')
            ->where('identity_id', $link->identity_id)->count();

        if ($siblings <= 1) {
            return "link $linkId is the only link on identity {$link->identity_id}; splitting it would ".
                   'move the whole population to a new identity and leave an empty one';
        }

        return null;
    }

    /**
     * @return array{from:int, to:int, relinked:int}
     *
     * @throws RuntimeException when canSplit() refuses
     */
    public function split(int $linkId, string $by, string $rationale): array
    {
        if ($why = $this->canSplit($linkId)) {
            throw new RuntimeException("cannot split: $why");
        }

        $db = $this->db();

        return $db->transaction(function () use ($db, $linkId, $by, $rationale) {
            $link = $db->table('gp_source_link')->where('link_id', $linkId)->lockForUpdate()->first();
            $from = (int) $link->identity_id;

            // The staged row this link came from, so the new identity starts with
            // the same canonical facts rather than an empty shell.
            $staged = $db->table('stg_person')->where([
                'system_id' => $link->system_id,
                'source_table' => $link->source_table,
                'source_id' => $link->source_id,
            ])->first();

            $now = now();
            $to = (int) $db->table('gp_identity')->insertGetId([
                'identity_uuid' => (string) Str::uuid(),
                'canonical_first' => $staged->first_name ?? null,
                'canonical_middle' => $staged->middle_name ?? null,
                'canonical_last' => $staged->last_name ?? null,
                'canonical_dob' => $staged->date_of_birth ?? null,
                'npi' => $staged->npi ?? null,
                'upin' => $staged->upin ?? null,
                'dea_number' => $staged->dea_number ?? null,
                'confidence' => 1.0,
                'record_count' => 0,
                'status' => 'active',
                'first_seen' => $now,
                'last_updated' => $now,
            ]);

            $db->table('gp_source_link')->where('link_id', $linkId)->update([
                'identity_id' => $to,
                'match_method' => 'manual',
                'match_key' => 'split',
                'match_state' => 'pinned',
                'is_pinned' => 1,
                'reviewed_at' => $now,
                'reviewed_by' => $by,
            ]);

            $db->table('gp_resolution_log')->insert([
                'action' => 'split',
                'identity_id' => $to,
                'affected_ids' => json_encode(['from' => $from, 'to' => $to, 'link_id' => $linkId]),
                'match_key' => 'split',
                'reason' => "split from identity $from by steward: ".Str::limit($rationale, 200),
                'actor' => $by,
                'created_at' => $now,
            ]);

            // Both sides. The departing row may have won a canonical field on the
            // identity it left, and the new identity has none computed yet.
            $survivorship = new Survivorship;
            $survivorship->recompute($from);
            $survivorship->recompute($to);

            return ['from' => $from, 'to' => $to, 'relinked' => 1];
        });
    }

    private function db()
    {
        return DB::connection($this->connection ?? config('golden_profile.connections.hub', 'golden_profile'));
    }
}
```

Note the link is left `match_state = 'pinned'` with `is_pinned = 1`: a split is a human decision and
must not be re-matched away by the next run. That is the same mechanism `DeterministicResolver`
already honours at `:62`.

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/IdentitySplitTest.php`

Expected: PASS, 6 tests.

- [ ] **Step 5: Add `gp:split`**

Create `app/Console/Commands/GpSplit.php`:

```php
<?php

namespace App\Console\Commands;

use App\GoldenProfile\Review\IdentityMutator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class GpSplit extends Command
{
    protected $signature = 'gp:split
        {link : the gp_source_link.link_id to detach}
        {--by= : who is deciding (required)}
        {--rationale= : why (required, recorded in gp_resolution_log)}
        {--apply : actually do it; without this the command only reports what it would do}';

    protected $description = 'Detach one source link from its identity onto a new one (reverses a merge)';

    public function handle(): int
    {
        $linkId = (int) $this->argument('link');
        $mutator = new IdentityMutator;

        if ($why = $mutator->canSplit($linkId)) {
            $this->error($why);

            return self::FAILURE;
        }

        $by = (string) $this->option('by');
        $rationale = (string) $this->option('rationale');

        if ($by === '' || $rationale === '') {
            $this->error('--by and --rationale are both required: an unattributed, unexplained split is not auditable');

            return self::FAILURE;
        }

        if (! $this->option('apply')) {
            $this->warn("DRY RUN — link $linkId is splittable. Re-run with --apply to do it.");

            return self::SUCCESS;
        }

        $result = $mutator->split($linkId, $by, $rationale);

        $this->info("link $linkId moved from identity {$result['from']} to {$result['to']}");

        // The profile is a derived read model; both sides are now stale.
        foreach ([$result['from'], $result['to']] as $identityId) {
            Artisan::call('gp:rebuild-profile', ['--identity' => $identityId]);
        }
        $this->line('rebuilt gp_identity_profile for both identities');

        return self::SUCCESS;
    }
}
```

- [ ] **Step 6: Verify and run the suite**

Run: `php artisan list gp` — expected: `gp:split` listed.

Run: `vendor/bin/phpunit --fail-on-skipped` — expected: PASS, 0 skipped, `EvalGateTest` unchanged.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty
git add app/GoldenProfile/Review/IdentityMutator.php app/Console/Commands/GpSplit.php \
        tests/Feature/IdentitySplitTest.php
git commit -m "feat(review): add gp:split so a merge can be reversed"
```

---

## Task 6: The feedback loop, without contaminating the fixture

**Files:**
- Create: `app/GoldenProfile/Review/DecisionExporter.php`
- Create: `app/Console/Commands/GpExportLabels.php`
- Create: `tests/Feature/DecisionExporterTest.php`
- Modify: `docs/EVALUATION.md`

**Interfaces:**
- Consumes: `ReviewQueue` (Task 4), `gp_resolution_log` split rows (Task 5).
- Produces: `DecisionExporter::export(): array{records:array, truth:array, source:string}` in `EvalSet`'s schema.

**The constraint that shapes this task.** The Delivery Checklist asks to *"feed steward decisions
back as new labels"*. The obvious implementation — append them to
`tests/eval/identity-pairs.json` — is **wrong**, for a reason that is not obvious: `sv-manila/gp-cami`
is a **public** repository, and the fixture's own `notes` field commits to the data being synthetic.
Real reviewed decisions are about real named providers. Writing them into a committed fixture would
publish exactly the data plan 2 removes SSN handling to protect.

So the export writes to a **gitignored** path and produces a file in `EvalSet`'s schema that a human
can inspect, de-identify and selectively adopt. The loop is closed; the last step stays manual and
deliberate, which for PII is the correct amount of friction.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/DecisionExporterTest.php`:

```php
<?php

namespace Tests\Feature;

use App\GoldenProfile\Eval\EvalSet;
use App\GoldenProfile\Review\DecisionExporter;
use Tests\Support\HubTestCase;

class DecisionExporterTest extends HubTestCase
{
    public function test_a_settled_review_becomes_a_positive_label(): void
    {
        $a = $this->stagePerson(['first_name' => 'Maria', 'last_name' => 'Garcia', 'date_of_birth' => '1975-01-09']);
        $b = $this->stagePerson(['first_name' => 'Maria', 'last_name' => 'Garcia', 'date_of_birth' => '1975-01-09']);
        $id = (new \App\GoldenProfile\Resolution\DeterministicResolver($this->systemId))->resolve($a);
        (new \App\GoldenProfile\Resolution\DeterministicResolver($this->systemId))->resolve($b);

        $this->hub()->table('gp_source_link')->where('identity_id', $id)
            ->update(['match_state' => 'review', 'reviewed_at' => now(), 'reviewed_by' => 'steward@example.test']);

        $set = (new DecisionExporter)->export();

        $this->assertCount(2, $set['records']);
        $this->assertCount(1, $set['truth'], 'two confirmed-together rows are one cluster');
        $this->assertCount(2, $set['truth'][0]);
    }

    public function test_a_split_becomes_two_singleton_clusters(): void
    {
        $a = $this->stagePerson(['first_name' => 'Ann', 'last_name' => 'Kowalski', 'date_of_birth' => '1981-03-03']);
        $b = $this->stagePerson(['first_name' => 'Ann', 'last_name' => 'Kowalski', 'date_of_birth' => '1981-03-03']);
        $shared = (new \App\GoldenProfile\Resolution\DeterministicResolver($this->systemId))->resolve($a);
        (new \App\GoldenProfile\Resolution\DeterministicResolver($this->systemId))->resolve($b);
        $linkB = (int) $this->hub()->table('gp_source_link')
            ->where('identity_id', $shared)->orderByDesc('link_id')->value('link_id');

        (new \App\GoldenProfile\Review\IdentityMutator)->split($linkB, 'steward@example.test', 'different people');

        $set = (new DecisionExporter)->export();

        $this->assertCount(2, $set['truth'], 'a split is two clusters, and that is the label');
        foreach ($set['truth'] as $cluster) {
            $this->assertCount(1, $cluster);
        }
    }

    public function test_the_export_validates_as_an_eval_set(): void
    {
        // If it does not load, it is not a labeled set — it is a file.
        $a = $this->stagePerson(['first_name' => 'Maria', 'last_name' => 'Garcia', 'date_of_birth' => '1975-01-09']);
        $b = $this->stagePerson(['first_name' => 'Maria', 'last_name' => 'Garcia', 'date_of_birth' => '1975-01-09']);
        $id = (new \App\GoldenProfile\Resolution\DeterministicResolver($this->systemId))->resolve($a);
        (new \App\GoldenProfile\Resolution\DeterministicResolver($this->systemId))->resolve($b);
        $this->hub()->table('gp_source_link')->where('identity_id', $id)
            ->update(['match_state' => 'review', 'reviewed_at' => now(), 'reviewed_by' => 's@e.test']);

        $set = (new DecisionExporter)->export();

        // Round-trip through EvalSet's own validation.
        $loaded = EvalSet::loadArray(['records' => $set['records'], 'truth' => $set['truth']], 'export');
        $this->assertCount(2, $loaded->records());
    }

    public function test_unreviewed_binds_are_not_labels(): void
    {
        $a = $this->stagePerson(['npi' => 1234567893]);
        $id = (new \App\GoldenProfile\Resolution\DeterministicResolver($this->systemId))->resolve($a);
        $this->hub()->table('gp_source_link')->where('identity_id', $id)->update(['match_state' => 'review']);

        $this->assertSame([], (new DecisionExporter)->export()['records'], 'an unreviewed bind is not a decision');
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/DecisionExporterTest.php`

Expected: FAIL, 4 of 4 — `Class "App\GoldenProfile\Review\DecisionExporter" not found`.

- [ ] **Step 3: Write the exporter and command**

Create `app/GoldenProfile/Review/DecisionExporter.php`:

```php
<?php

namespace App\GoldenProfile\Review;

use Illuminate\Support\Facades\DB;

/**
 * Turns settled steward decisions into a candidate labeled set, in EvalSet's
 * schema, for a human to de-identify and selectively adopt.
 *
 * The Delivery Checklist asks to "feed steward decisions back as new labels;
 * re-tune the threshold", and "How Record Matching Works" says every decision is
 * "saved and later reused to make the matching model smarter over time". That is
 * the P0's own feedback clause and nothing owned it.
 *
 * WHY THIS DOES NOT WRITE tests/eval/identity-pairs.json
 * ------------------------------------------------------
 * sv-manila/gp-cami is a PUBLIC repository, and the fixture's notes field commits
 * to its records being synthetic. Settled decisions are about real, named
 * providers. Appending them to a committed fixture would publish precisely the
 * data the SSN work exists to keep out of the hub.
 *
 * So: export to a gitignored path, in the schema EvalSet already validates, and
 * leave adoption manual. For PII that friction is the feature. A reviewer
 * de-identifies, checks the cluster is genuinely representative, and adds it by
 * hand — which is also when true_pairs gets re-baselined, as docs/EVALUATION.md
 * requires.
 *
 * WHAT A DECISION MEANS AS A LABEL
 * --------------------------------
 * A settled review where the rows are still together is a POSITIVE: a human
 * confirmed the merge, so those refs belong in one cluster. A split is a
 * NEGATIVE: a human said these are different people, so each ref is its own
 * cluster. Those are the only two signals available — gp-cami records no
 * "probably" — and they are exactly the two the scorer needs.
 */
class DecisionExporter
{
    public function __construct(private ?string $connection = null) {}

    /** @return array{records: list<array<string,mixed>>, truth: list<list<string>>, source: string} */
    public function export(): array
    {
        $db = $this->db();

        $links = $db->table('gp_source_link as l')
            ->join('stg_person as sp', function ($j) {
                $j->on('sp.system_id', '=', 'l.system_id')
                    ->on('sp.source_table', '=', 'l.source_table')
                    ->on('sp.source_id', '=', 'l.source_id');
            })
            ->whereNotNull('l.reviewed_at')
            ->orderBy('l.link_id')
            ->get([
                'l.link_id', 'l.identity_id', 'sp.first_name', 'sp.middle_name', 'sp.last_name',
                'sp.date_of_birth', 'sp.npi', 'sp.upin', 'sp.dea_number', 'sp.city', 'sp.state', 'sp.zip',
            ]);

        $records = [];
        $clusters = [];

        foreach ($links as $l) {
            $ref = 'link-'.$l->link_id;

            $records[] = [
                'ref' => $ref,
                'first_name' => $l->first_name,
                'middle_name' => $l->middle_name,
                'last_name' => $l->last_name,
                'date_of_birth' => $l->date_of_birth,
                'npi' => $l->npi === null ? null : (int) $l->npi,
                'upin' => $l->upin,
                'dea_number' => $l->dea_number,
                'city' => $l->city,
                'state' => $l->state,
                'zip' => $l->zip,
            ];

            // The identity the row sits on AFTER review is the label. A split has
            // already moved it, so grouping by current identity_id yields the
            // steward's verdict without needing to interpret the log.
            $clusters[(int) $l->identity_id][] = $ref;
        }

        return [
            'records' => $records,
            'truth' => array_values($clusters),
            'source' => 'settled steward decisions as at '.now()->toDateTimeString(),
        ];
    }

    private function db()
    {
        return DB::connection($this->connection ?? config('golden_profile.connections.hub', 'golden_profile'));
    }
}
```

Create `app/Console/Commands/GpExportLabels.php`:

```php
<?php

namespace App\Console\Commands;

use App\GoldenProfile\Review\DecisionExporter;
use Illuminate\Console\Command;

class GpExportLabels extends Command
{
    protected $signature = 'gp:export-labels {--out=storage/app/review-labels.json : where to write}';

    protected $description = 'Export settled steward decisions as a candidate labeled set for review';

    public function handle(): int
    {
        $set = (new DecisionExporter)->export();

        if ($set['records'] === []) {
            $this->warn('no settled decisions to export');

            return self::SUCCESS;
        }

        $path = base_path((string) $this->option('out'));

        file_put_contents($path, json_encode([
            'version' => 1,
            'notes' => 'CANDIDATE labels from settled steward decisions. NOT synthetic — these are '.
                       'real providers. De-identify before adopting anything into '.
                       'tests/eval/identity-pairs.json, and re-baseline true_pairs in the same commit.',
            'source' => $set['source'],
            'records' => $set['records'],
            'truth' => $set['truth'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->info(sprintf(
            '%d record(s) in %d cluster(s) written to %s',
            count($set['records']), count($set['truth']), $path
        ));
        $this->warn('Contains real provider data. Do NOT commit it. De-identify before adopting.');

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Confirm the output path is gitignored**

Run: `git check-ignore -v storage/app/review-labels.json`

Expected: a match against `.gitignore`'s existing `/storage/*.key`-adjacent storage rules. **If it
does not match, stop and add `/storage/app/review-labels.json` to `.gitignore` in this task's
commit** — an export of real provider data must not be committable by accident.

- [ ] **Step 5: Run the tests and document it**

Run: `vendor/bin/phpunit tests/Feature/DecisionExporterTest.php` — expected: PASS, 4 tests.

Append to `docs/EVALUATION.md`:

```markdown
## Feeding steward decisions back

`php artisan gp:export-labels` writes settled review decisions to
`storage/app/review-labels.json` in this file's own schema. A settled review whose
rows are still together is a positive label; a split is a negative one.

**The export is not synthetic and must not be committed.** `sv-manila/gp-cami` is
public and the fixture commits to synthetic data, so adoption is deliberately
manual: de-identify, confirm the cluster is representative, add it by hand, and
re-baseline `true_pairs` in the same commit. That friction is intentional — the
alternative is publishing real provider records.
```

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add app/GoldenProfile/Review/DecisionExporter.php app/Console/Commands/GpExportLabels.php \
        tests/Feature/DecisionExporterTest.php docs/EVALUATION.md .gitignore
git commit -m "feat(review): export settled decisions as candidate labels, outside the fixture"
```

---

## Task 7: `docs/REVIEW.md` and prove the gate is unmoved

**Files:**
- Create: `docs/REVIEW.md`
- Create: `tests/Feature/EvalGateUnmovedTest.php`

**Interfaces:**
- Consumes: everything above.
- Produces: nothing consumed downstream.

- [ ] **Step 1: Write the gate assertion**

Create `tests/Feature/EvalGateUnmovedTest.php`:

```php
<?php

namespace Tests\Feature;

use App\GoldenProfile\Eval\EvalRunner;
use App\GoldenProfile\Eval\EvalSet;
use Tests\Support\HubTestCase;

/**
 * This plan adds explainability, a queue and a split path. It changes no tier, no
 * threshold and no binding rule, so the gate must be bit-for-bit unchanged. A
 * movement here means something in this plan touched matching, and the diagnostic
 * table below says where to look.
 */
class EvalGateUnmovedTest extends HubTestCase
{
    public function test_recording_evidence_does_not_change_who_matches_whom(): void
    {
        $set = EvalSet::load(base_path('tests/eval/identity-pairs.json'));
        $report = (new EvalRunner($this->systemId))->run($set)['report'];

        $message = sprintf(
            'precision %.4f recall %.4f — %d false merge(s), %d false split(s), %d true pairs',
            $report['precision'], $report['recall'],
            $report['false_merges'], $report['false_splits'], $report['true_pairs'],
        );

        // The two absolutes.
        $this->assertSame(0, $report['false_merges'], "false merges are never acceptable — $message");
        $this->assertSame(1.0, $report['precision'], "precision must stay perfect — $message");

        // The ladder as at plan 9 under 00-PROGRAMME.md's canonical order. If an
        // earlier plan has not landed yet these will differ — check the ladder in
        // 00-PROGRAMME.md §4 before assuming this plan broke something.
        $this->assertGreaterThanOrEqual(13, $report['true_pairs'], "the eval set shrank — $message");
    }

    public function test_every_bind_in_the_eval_run_left_evidence(): void
    {
        // The completeness check for Task 1: not "some edges exist" but "one per
        // link". A silent gap here means a bind path was missed.
        $set = EvalSet::load(base_path('tests/eval/identity-pairs.json'));
        (new EvalRunner($this->systemId))->run($set);

        $links = (int) $this->hub()->table('gp_source_link')->count();
        $edges = (int) $this->hub()->table('gp_edge')->count();

        $this->assertSame($links, $edges, "every bind must leave exactly one edge — $links links, $edges edges");
    }
}
```

- [ ] **Step 2: Run it**

Run: `vendor/bin/phpunit tests/Feature/EvalGateUnmovedTest.php`

Expected: PASS, 2 tests. If the first fails, **do not adjust the gate** — find what changed
matching. Diagnostics:

| Movement | Where to look |
|---|---|
| `false_merges` > 0 | The `score()` refactor in Task 3 changed a contribution. Diff the breakdown sum against the original accumulation. |
| `false_splits` > 0 | `match()`'s early returns lost a `$evidence` element and destructuring failed silently, so a bind was skipped. |
| `true_pairs` < 13 | Fixture records were removed. Never acceptable — `docs/EVALUATION.md` forbids it. |
| Edge count ≠ link count | A bind path does not call `record()`. The set-based path (`SqlBackfill`) is the known gap — see below. |

- [ ] **Step 3: Write `docs/REVIEW.md`**

Create `docs/REVIEW.md` recording the three things a future reader will otherwise re-derive:

```markdown
# Review, explainability and reversibility

## The review-band decision

gp-cami **binds** review-band matches and flags them; it does not hold them. The
documentation ("How Record Matching Works") describes a queue instead.

That was chosen, not inherited. Holding would mean a review-band record does not
resolve at all, so `identity-search` and `credential-search` return 404 for a
person who exists in the source — and because Pass B cannot currently reach
`auto_match` (its implemented weights sum to exactly `auto_merge_at`), that is
*every* Pass B match, not a tail. The doc's intent is that an uncertain merge be
reviewable, explainable and undoable, not that the record be unusable while it
waits.

So binding is kept and the three missing properties were built: `gp_edge` carries
the evidence, `gp:review-queue` surfaces the band, and `gp:split` reverses a
merge. Before those existed, binding was indefensible.

To reverse this decision, change the `in_array($state, ['auto_match','review'])`
test in `DeterministicResolver::resolve()` and re-baseline the eval gate. Nothing
else in the review model depends on it.

## `gp_edge.dst_link_id` is an approximation

`gp_edge` is row-to-row, mirroring the Data Model's pairwise `match_candidate`.
gp-cami matches row-to-**identity**: `matchDeterministic()` probes `gp_identity`
for a key, and `ProbabilisticResolver` scores a staged row against candidate
`gp_identity` rows. There is no specific row on the other side of the comparison.

`dst_link_id` therefore holds the identity's **earliest current link** — a real,
stable link meaning "the link that established this identity". It does **not**
mean "the row we compared against". Reading it that way produces wrong
conclusions.

## Known gap: the set-based path records no evidence

`MatchEvidence` is wired into `DeterministicResolver` (the per-row path). The
set-based path — `SqlBackfill`, three bulk `INSERT`s into `gp_source_link` — does
not write edges. So a hub populated by `gp:backfill` has links without evidence,
and `EvalGateUnmovedTest::test_every_bind_in_the_eval_run_left_evidence` only
covers the per-row path.

This is deliberate scope, not an oversight: the set-based path is raw MySQL and
plan 3b is already restructuring it. Closing the gap means adding an
`INSERT … SELECT` per tier that derives `detail` in SQL, which belongs with that
work. Until then, treat `gp_edge` coverage as per-row only, and do not build a
metric that assumes completeness.
```

- [ ] **Step 4: Run the full suite and commit**

Run: `vendor/bin/pint --test && vendor/bin/phpunit --fail-on-skipped`

Expected: both pass, 0 skipped.

```bash
git add docs/REVIEW.md tests/Feature/EvalGateUnmovedTest.php
git commit -m "docs(review): record the review-band decision and prove the gate is unmoved"
```

---

## Self-review

**Spec coverage.** `00-CONFORMANCE.md` Theme 1 has five findings. `gp_edge` dead → Tasks 1–3.
`match_state` unread → Task 4. Review-band bound not held → decided explicitly in
§"The review-band decision", documented in Task 7, code left unchanged with the reversal path
stated. `status = 'split'` never written → Task 5. No feedback loop → Task 6. The Data Model's
`match_candidate` columns are mapped in Task 2 (`blocking_key`, `decision` added; `feature_json` is
`detail`; `match_group.member_count`/`cluster_score` are **not** built — see below).

**Placeholders.** None. Every code-changing step carries complete code. Task 6 Step 4 has a
conditional (`if it does not match, add it to .gitignore`) which is a real branch on a fact the
executor can check in one command, not a TBD.

**Type consistency.** `MatchEvidence::record()` takes `(int, int, string, float, array, ?string, string)`
and is called that way once, in `DeterministicResolver::resolve()`. `forProbabilistic(array, int, ?float)`
matches the `['breakdown','considered','runner_up']` shape `ProbabilisticResolver::match()` now
returns as its fourth element — the one signature change in this plan, and Task 3 Step 3 updates the
class docblock to match. `ReviewQueue::settle(int, string): bool` is consumed by `GpReviewQueue` and
by `IdentityMutator::split()` indirectly (it writes `reviewed_at`/`reviewed_by` itself rather than
calling `settle()`, to stay inside its own transaction — noted here because the duplication is
deliberate). `IdentityMutator::split(int, string, string): array{from,to,relinked}` is consumed by
`GpSplit`.

**What this plan deliberately does not build.**

- **`match_group.member_count` / `cluster_score`.** The Data Model's group-level aggregates. gp-cami
  has no `match_group` table; `gp_identity.record_count` is the member count and there is no cluster
  score because there is no clustering step — resolution is one row at a time. Building a group table
  to hold two derivable numbers would be schema for its own sake. Flagged rather than skipped, and
  the group-size *safeguard* the docs pair with it is plan 6's oversized-block flagging (Task 7
  there), which does exist.
- **Set-based evidence.** Task 7 Step 3 records this as a known gap with its reason and its owner.
- **A steward UI.** Ruled out by §8 and `f0a3126`. The queue is a command plus a read endpoint.

**Known risks carried into execution.**

1. **Task 3 is the risky one.** Refactoring `score()` from a running total to a breakdown is exactly
   the kind of change that shifts a float by 1e-16 and moves a threshold. `min(1.0, array_sum(...))`
   preserves the original cap, but the addition order changes, and IEEE-754 addition is not
   associative. If `EvalGateUnmovedTest` moves, that is the first place to look. Consider asserting
   the old and new scores agree on the fixture before deleting the old accumulation.
2. **`sharesExclusionRegistry()` is broken and this plan surfaces it.** `00-PROGRAMME.md` §9 records
   that it ignores its `$p` argument entirely, so `exclusion_share` fires whenever the *candidate*
   carries any exclusion. Task 3 will now **record that spurious contribution in `detail`**, where a
   steward will read "exclusion_share fired" and believe a shared registry was found. Either fix the
   leg first or the evidence actively misleads. It is not this plan's to fix — but this plan makes it
   visible, which arguably raises its priority.
3. **`IdentityMutator::split()` writes `gp_identity` directly.** Under plan 3a it must go through
   `Versioner::write()` instead, or the split overwrites in place and loses the pre-split version —
   destroying the reversibility the split exists to provide. This plan is written against the current
   schema; **if 3a has landed, convert the `insertGetId` and the `Survivorship` interaction before
   running Task 5.** Plan 3a's `Versioner::TABLES['gp_identity']` lists `status` and `merged_into` as
   versioned attributes, so a split is exactly the shape it expects.
4. **`gp_edge` will be the second-largest table in the hub.** One row per link, ~13.4M on a full
   backfill. No plan owns its retention. `Engine.php:476` truncates it on reset, which is the only
   lifecycle it has.
