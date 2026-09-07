# GPP Conformance — Measurement & Monitoring Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development
> (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make a change to matching detectable before it ships, and make the Success Metrics page's
targets into numbers somebody computes rather than aspirations nobody measures.

**Architecture:** A run ledger (`gp_run`) records what each `gp:sync`/`gp:backfill` actually did —
rows read, rows staged, watermark moved, duration — which is what both the freshness alarm and the
row-count gate need and what `gp_watermark` alone cannot say. `RunDiff` resolves a fixed corpus
through two code states and reports structurally what moved, which is the Delivery Checklist's
"before/after run-diff" and the one regression detector the programme lacks. `MetricsCollector`
computes the Success Metrics that are computable from the hub and persists a series in `gp_metric`,
so a target can be trended rather than sampled. Alarms are a command that exits non-zero, because
delivery is configuration and must not be committed to a public repository.

**Tech Stack:** PHP ^8.3 (8.4.12 local), Laravel 13.20, PHPUnit 12.5, Laravel Pint, MySQL 8.

## Global Constraints

- Branch off the latest `feat/eval-harness` (or `master` once that has merged) as
  `feat/measurement-and-monitoring`. Do not push to `master`.
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

- Migrations take the `2026_09_12_*` prefix (`00-PROGRAMME.md` §3 assigns through `2026_09_09_000100`;
  plan 9 took `2026_09_10_*` and plan 10 takes `2026_09_11_*`).
- **No alert destination may be committed.** No webhook URL, no email address, no channel id. The
  repo is public. Delivery is an env var read at runtime, and the default is "log and exit non-zero".
- This plan adds **no matching behaviour**. The eval gate must be bit-for-bit unchanged, and Task 7
  asserts it. A movement means this plan touched something it should not have.

---

## Programme context — this is plan 11 of fourteen

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
| 9 | Explainability & reversibility | 1, 3a, 6 |
| 10 | Credential cache | 1 |
| **11** | **Measurement & monitoring** *(this document)* | **1, 5, 6** |
| 12 | Bi-temporal validity | 3a |

`00-PROGRAMME.md` is the authority on execution order, migration timestamps and shared-component
ownership. `00-CONFORMANCE.md` records the audit that produced plans 9–12; **its Theme 3 is this
plan's specification**, plus the Success Metrics row of audit C.

**Why the dependencies.** Plan 5 builds `gp_quarantine` and the junk-key dictionary — this plan
adds the alerting half and does not duplicate the table. Plan 6 makes `link_state` transition, and
until it does `has_active_exclusion` is **inert** (correct formula, constant input), so the
zero-tolerance missed-exclusion alarm would be watching a constant. Task 6 states that dependency
rather than shipping an alarm that cannot fire.

**Why this plan should run early anyway, despite sitting late in the numbering.** Its `RunDiff` is
the only regression detector for six plans that change resolution behaviour on a 13.38M-identity
hub. Building it *after* those changes ship means it can never prove they were safe. If the
programme order is revisited, argue for pulling Task 3 forward.

---

## The documentation this plan implements

**Success Metrics & Risks** (DEV page 4067295233) — the targets, verbatim:

| Metric | Target |
|---|---|
| Match accuracy (checked against verified data) | 99% or better — we favor being right over catching every possible match, since this is compliance-critical |
| Match completeness (catching real matches) | 95% or better |
| Matches handled automatically, no human needed | 85% or more |
| Time for a steward to clear the review queue | Under 2 business days, on average |
| Speed of reflecting a new exclusion | Within 24 hours of the source publishing it |
| Speed of reflecting identity updates | Within 48 hours of the weekly registry update |
| Can we always rebuild the golden layer from scratch? | Yes, 100% of the time |
| Missed exclusions (false negatives) | Zero tolerance — always alarmed if this happens |

and its service targets:

> - Freshness alarms per source, matching each one's expected schedule.
> - API responses for a single provider lookup in under 300 milliseconds.
> - A full rebuild of the golden layer completes in under 6 hours.

**Delivery Checklist §3 Test:**

> Before/after run-diff on every matching change to catch regressions

**Delivery Checklist §6 Deployment:**

> Monitoring + alarms (freshness; zero-tolerance missed-exclusion); runbooks

**"Keeping Data Accurate, Secure & Compliant":**

> Data has to pass a checkpoint before it's allowed to move from one storage zone to the next. If it
> fails, it gets quarantined and someone is alerted — **bad data never silently flows downstream.**

with *"Is the row count within a normal range?"* among its example gates.

**Data Model** specifies `meta.ingest_manifest` (`ingest_run_id`, `row_count`, `checksum_sha256`,
`schema_version`, `source_watermark`, `status PENDING|VALID|REJECTED`, `started_ts`, `committed_ts`)
and `meta.data_lineage` (`stage INGEST|STANDARDIZE|RESOLVE|SURVIVE|PUBLISH`).

---

## Two targets that cannot be met, and must be reported as such

An honest metrics implementation reports a target it cannot reach rather than quietly omitting it.
Two of the eight are in that position, and both for reasons already established elsewhere in the
programme.

**"Can we always rebuild the golden layer from scratch? Yes, 100% of the time."** Plan 2 removes
`ssn_hash` and establishes, in its own Architecture section, that existing `ssn_hash`-bound merges
are **retained rather than un-merged** — because un-merging would manufacture false splits, the
costlier error. The consequence it names explicitly is that *rebuild reproducibility is forfeited*: a
rebuild from scratch would no longer reproduce those merges, because the key that made them no
longer exists. So this metric goes permanently from "yes" to "no" the moment plan 2 lands.

That is not a bug to fix here. It is the visible face of the contradiction `00-CONFORMANCE.md`
records: the Delivery Checklist forbids storing SSN (§1) **and** requires proving rebuild-from-scratch
works (§4), and once code-follows-docs is applied those cannot both hold. `MetricsCollector` reports
it as `unmet` with that reason attached, so it shows up on a dashboard as a known, decided
consequence rather than an unexplained red light.

**"API responses for a single provider lookup in under 300 milliseconds."** `config/golden_profile.php`
carries measured numbers for `credential-search` on an over-merged identity: **8.9 seconds** for
identity 59 at `link_chunk_size` 1000, and 360.7 seconds at 5000. And `00-CONFORMANCE.md` Theme 2
establishes the endpoint queries `streamline_local` — a remote server — on every request. So 300ms is
very likely already breached by more than an order of magnitude on the tail.

Task 5 therefore **measures** it rather than asserting it, and reports a distribution rather than a
mean: a p50 that passes while the p99 takes nine seconds is the shape this endpoint actually has, and
a mean would hide it. Fixing it is plan 10's business.

---

## What "alert" means in this application, decided

The Delivery Checklist says "someone is alerted". gp-cami has no infrastructure for that: no queue
driver configured for engine work (`CACHE_STORE=file`, the engine runs inline), no mail transport,
and — decisively — **the repository is public**, so no webhook URL, channel id or address may be
committed.

So the design separates detection from delivery:

- **Detection** is `php artisan gp:alarms`, which evaluates every alarm against the hub and **exits
  non-zero** when any fires. That is enough for any scheduler, any CI, and any monitoring agent that
  can run a command and read an exit code — which is every one of them.
- **Reporting** writes each firing to `gp_alarm_event`, so a history exists and a flapping alarm is
  visible as flapping rather than as one notification.
- **Delivery** is a single optional env var, `GP_ALARM_WEBHOOK`, POSTed to if set and ignored if not.
  The default is log-and-exit-non-zero. Nothing about the destination is in the repository.

This is deliberately the least infrastructure that satisfies "bad data never silently flows
downstream". A queue, a mailer or a paging integration would each be a larger commitment than the
requirement, and each would need a secret this repository cannot hold.

---

## `ingest_manifest`, adapted rather than transcribed

The Data Model's `ingest_manifest` is **file-load shaped**: `s3_prefix`, `checksum_sha256`,
`row_count` per pull, one row per file landed in a bucket. gp-cami has one source, read by chunked
keyset queries over `employees` — there is no file and no object to checksum.

Transcribing the columns anyway would produce a `checksum_sha256` that checksums nothing, which is
worse than an honest gap: a reader would believe load integrity is verified when it is not. Plans 5
and 7 both set the precedent of deferring on evidence and the audit credited both.

So `gp_run` keeps what maps and drops what does not, and says which is which:

| Data Model column | In `gp_run` | Why |
|---|---|---|
| `ingest_run_id` | `run_id` | maps directly |
| `source_system` / `source_feed` | `system_id`, `source_table` | maps |
| `row_count` | `rows_read`, `rows_staged` | **needed** — the "row count within normal range" gate is unbuildable without it, and two counts are more useful than one because a divergence between them is itself a signal |
| `source_watermark` | `watermark_before`, `watermark_after` | maps, and the pair proves the run advanced |
| `status PENDING\|VALID\|REJECTED` | `status running\|ok\|failed` | maps in spirit; the doc's names describe a validation verdict on a landed file, ours describe a run |
| `started_ts` / `committed_ts` | `started_at`, `finished_at` | maps, and gives the "<6 hours" rebuild target something to measure |
| `s3_prefix` | **dropped** | there is no object store in this architecture |
| `checksum_sha256` | **dropped** | there is nothing to checksum. A keyset scan over a live table has no immutable artifact; the closest honest equivalent is `rows_read`, which is already recorded |
| `schema_version` | **dropped, with a note** | gp-cami reads a live schema it does not own. The doc's intent — detect a source changing shape without warning — is a real risk from the Risks table, and the honest mechanism is a column-set assertion rather than a version string. Recorded in `docs/MONITORING.md` as unowned |

`data_lineage`'s per-stage trail (`INGEST|STANDARDIZE|RESOLVE|SURVIVE|PUBLISH`) is **not** built.
gp-cami's stages are not separable that way: `Engine::sync()` stages and resolves in one chunked
pass, and survivorship and publish happen in `finalizeAll()`. A lineage row per stage per source row
would be four rows per row — ~53M on a full backfill — to record a pipeline shape that has three
stages, not five. `gp_run` records the run; `gp_resolution_log` and (once plan 9 lands) `gp_edge`
record what happened to an individual row. Between them the trail exists. Task 7 documents this as a
deliberate divergence.

---

## The run-diff corpus, and why it is not the eval set

The eval set is 17 records and 13 true pairs. It proves **correctness** on labeled cases and it is
the right tool for that. It cannot detect a change that moves a thousand unlabeled records, because
it does not contain any.

`RunDiff` therefore uses a separate, larger, **unlabeled** corpus and asks a different question:
*did this code change move anybody?* No answer key is needed — the comparison is between two runs,
not against truth. That makes the two tools complementary rather than redundant, and it is worth
stating because "we already have the eval set" is the obvious objection to building this.

The corpus is generated deterministically from a seed, not sampled from production: production
access does not exist here, and a committed sample of real providers is exactly what a public
repository must not hold. `RunDiffCorpus` generates a few thousand staged rows with realistic
name/DOB/identifier collision structure from a fixed seed, so two runs on two code versions see
byte-identical input.

**Snapshots are id-independent.** `identity_id` values differ between runs — a fresh resolve assigns
new surrogates — so comparing them directly reports every row as moved. A snapshot instead maps each
source row to a **cluster signature**: the sorted list of source keys sharing its identity. Two runs
agree when every row's signature agrees, regardless of what the identities were numbered.

---

## File Structure

| File | Responsibility |
|---|---|
| `database/migrations/2026_09_12_000000_create_gp_run.php` *(create)* | `gp_run` — the run ledger |
| `database/migrations/2026_09_12_000100_create_gp_metric_and_alarm.php` *(create)* | `gp_metric` series, `gp_alarm_event` history |
| `app/GoldenProfile/Observe/RunLedger.php` *(create)* | Opens, advances and closes a `gp_run` row. Only writer. |
| `app/GoldenProfile/Engine.php` *(modify)* | `sync()` and `backfill()` open and close a run |
| `app/GoldenProfile/Observe/RunDiffCorpus.php` *(create)* | Deterministic synthetic corpus generator |
| `app/GoldenProfile/Observe/RunDiff.php` *(create)* | Snapshot and compare clusterings, id-independently |
| `app/Console/Commands/GpRunDiff.php` *(create)* | `php artisan gp:run-diff` |
| `app/GoldenProfile/Observe/MetricsCollector.php` *(create)* | Computes the Success Metrics that are computable; reports the rest as unmet or unmeasurable, with reasons |
| `app/Console/Commands/GpMetrics.php` *(create)* | `php artisan gp:metrics` |
| `app/GoldenProfile/Observe/Alarms.php` *(create)* | Alarm definitions and evaluation |
| `app/Console/Commands/GpAlarms.php` *(create)* | `php artisan gp:alarms` — exits non-zero when one fires |
| `docs/MONITORING.md` *(create)* | What is measured, what is not, what each alarm means, and the deliberate divergences from the Data Model |
| `tests/Feature/RunLedgerTest.php` *(create)* | A run is opened, advanced and closed; a failure is recorded |
| `tests/Unit/RunDiffTest.php` *(create)* | Snapshot comparison, id-independence, in isolation |
| `tests/Feature/RunDiffCorpusTest.php` *(create)* | The corpus is deterministic and structurally interesting |
| `tests/Feature/MetricsCollectorTest.php` *(create)* | Each metric computes, and the unmet ones report why |
| `tests/Feature/AlarmsTest.php` *(create)* | Each alarm fires when it should and not otherwise |
| `tests/Feature/EvalGateUnmovedByObservabilityTest.php` *(create)* | This plan changes no matching |

---

## Task 1: `gp_run` and `gp_metric` — the tables measurement needs

**Files:**
- Create: `database/migrations/2026_09_12_000000_create_gp_run.php`
- Create: `database/migrations/2026_09_12_000100_create_gp_metric_and_alarm.php`
- Create: `tests/Feature/ObserveSchemaTest.php`

**Interfaces:**
- Produces `gp_run`: `run_id` (bigIncrements), `system_id`, `source_table`, `mode` (`sync|backfill|finalize`), `rows_read`, `rows_staged`, `watermark_before`, `watermark_after`, `status` (`running|ok|failed`), `error`, `started_at`, `finished_at`; index `(system_id, source_table, started_at)`, index `status`.
- Produces `gp_metric`: `metric_id`, `metric` (varchar 48), `value` (decimal 12,4), `target` (decimal 12,4, nullable), `verdict` (`met|unmet|unmeasurable`), `detail` (json), `measured_at`; unique `(metric, measured_at)`, index `metric`.
- Produces `gp_alarm_event`: `event_id`, `alarm` (varchar 48), `fired` (bool), `detail` (json), `evaluated_at`; index `(alarm, evaluated_at)`.
- Consumed by: Tasks 2, 5 and 6.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ObserveSchemaTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\Support\HubTestCase;

class ObserveSchemaTest extends HubTestCase
{
    public function test_the_run_ledger_records_what_a_run_actually_did(): void
    {
        $schema = $this->hub()->getSchemaBuilder();

        foreach (['run_id', 'system_id', 'source_table', 'mode', 'rows_read', 'rows_staged',
            'watermark_before', 'watermark_after', 'status', 'error', 'started_at', 'finished_at'] as $column) {
            $this->assertTrue($schema->hasColumn('gp_run', $column), "gp_run is missing $column");
        }
    }

    public function test_the_run_ledger_does_not_pretend_to_checksum_anything(): void
    {
        // The Data Model's ingest_manifest is file-load shaped. gp-cami reads a live
        // table by keyset scan — there is no immutable artifact to hash. A column
        // named checksum_sha256 holding something else would be worse than its
        // absence, because a reader would believe load integrity is verified.
        $schema = $this->hub()->getSchemaBuilder();

        $this->assertFalse($schema->hasColumn('gp_run', 'checksum_sha256'));
        $this->assertFalse($schema->hasColumn('gp_run', 's3_prefix'));
    }

    public function test_a_metric_series_can_be_trended(): void
    {
        $schema = $this->hub()->getSchemaBuilder();

        foreach (['metric', 'value', 'target', 'verdict', 'detail', 'measured_at'] as $column) {
            $this->assertTrue($schema->hasColumn('gp_metric', $column), "gp_metric is missing $column");
        }
    }

    public function test_a_metric_can_be_unmeasurable_not_just_met_or_unmet(): void
    {
        // Two of the eight Success Metrics targets cannot be met or measured here
        // for reasons decided elsewhere in the programme. A two-state verdict would
        // force them to read as failures without explanation.
        $column = collect($this->hub()->select('SHOW COLUMNS FROM gp_metric'))
            ->firstWhere('Field', 'verdict');

        foreach (['met', 'unmet', 'unmeasurable'] as $state) {
            $this->assertStringContainsString($state, $column->Type);
        }
    }

    public function test_alarm_firings_have_a_history_so_flapping_is_visible(): void
    {
        $schema = $this->hub()->getSchemaBuilder();

        foreach (['alarm', 'fired', 'detail', 'evaluated_at'] as $column) {
            $this->assertTrue($schema->hasColumn('gp_alarm_event', $column));
        }
    }

    public function test_the_run_lookup_is_indexed_for_freshness(): void
    {
        // The freshness alarm asks "when did this source last succeed", which is a
        // per-source ordered lookup.
        $rows = $this->hub()->select(
            "SHOW INDEX FROM gp_run WHERE Key_name = 'idx_run_source_started'"
        );

        $this->assertCount(3, $rows);
        $this->assertSame('system_id', $rows[0]->Column_name);
        $this->assertSame('source_table', $rows[1]->Column_name);
        $this->assertSame('started_at', $rows[2]->Column_name);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/ObserveSchemaTest.php`

Expected: FAIL, 6 of 6 — none of the three tables exists.

- [ ] **Step 3: Write the run-ledger migration**

Create `database/migrations/2026_09_12_000000_create_gp_run.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The run ledger: what each gp:sync / gp:backfill / finalize actually did.
 *
 * gp_watermark records only where a source got to — a resume pointer, deliberately
 * mutable, with no history. So today nothing can answer "when did this source last
 * load successfully", which is what the Delivery Checklist's freshness alarm needs,
 * or "how many rows did that load touch", which is what its "row count within a
 * normal range" gate needs.
 *
 * ADAPTED FROM ingest_manifest, NOT TRANSCRIBED
 * ---------------------------------------------
 * The Data Model's meta.ingest_manifest is file-load shaped: s3_prefix,
 * checksum_sha256, one row per file landed in a bucket. gp-cami has one source read
 * by chunked keyset queries over a live table. There is no object and nothing to
 * hash, so those two columns are deliberately absent — a checksum_sha256 holding
 * anything other than a checksum of the loaded artifact would let a reader believe
 * load integrity is verified when it is not. rows_read is the honest equivalent.
 *
 * schema_version is also absent, and that one is a real gap rather than a
 * non-applicable column: the Risks table lists "a source changes its file format
 * without warning" as a live risk, and gp-cami reads a schema it does not own. The
 * honest mechanism is a column-set assertion at ingest, not a version string.
 * Recorded as unowned in docs/MONITORING.md.
 *
 * TWO ROW COUNTS, NOT ONE
 * -----------------------
 * rows_read is what the source returned; rows_staged is what reached stg_person. A
 * divergence between them is itself a signal — it means rows were rejected or
 * quarantined (plan 5's gp_quarantine) — and collapsing them into one number
 * discards that.
 */
return new class extends Migration
{
    protected $connection = 'golden_profile';

    public function up(): void
    {
        Schema::connection($this->connection)->create('gp_run', function (Blueprint $t) {
            $t->bigIncrements('run_id');
            $t->unsignedSmallInteger('system_id');
            $t->string('source_table', 64);
            $t->enum('mode', ['sync', 'backfill', 'finalize']);
            $t->unsignedBigInteger('rows_read')->default(0);
            $t->unsignedBigInteger('rows_staged')->default(0);
            $t->string('watermark_before', 64)->nullable();
            $t->string('watermark_after', 64)->nullable();
            $t->enum('status', ['running', 'ok', 'failed'])->default('running');
            $t->string('error', 500)->nullable();
            $t->dateTime('started_at');
            $t->dateTime('finished_at')->nullable();

            $t->index(['system_id', 'source_table', 'started_at'], 'idx_run_source_started');
            $t->index('status', 'idx_run_status');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('gp_run');
    }
};
```

- [ ] **Step 4: Write the metric and alarm migration**

Create `database/migrations/2026_09_12_000100_create_gp_metric_and_alarm.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A series for the Success Metrics page's targets, and a history for alarm firings.
 *
 * WHY A SERIES AND NOT A CURRENT-VALUE TABLE
 * ------------------------------------------
 * "Match accuracy 99% or better" is only useful as a trend. A single current value
 * cannot distinguish "we have always been at 99.2%" from "we were at 99.9% and
 * something is eroding it", and the second is the one worth waking up for.
 * unique(metric, measured_at) makes a re-run idempotent within the same instant
 * without collapsing history.
 *
 * WHY verdict HAS THREE STATES
 * ----------------------------
 * Two of the eight targets cannot be met or measured, for reasons decided elsewhere
 * in the programme rather than defects here:
 *
 *   "Can we always rebuild the golden layer from scratch? Yes, 100%" becomes
 *   permanently 'unmet' once plan 2 removes ssn_hash — plan 2 states that existing
 *   ssn_hash-bound merges are retained rather than un-merged, so a rebuild no
 *   longer reproduces them. That is the visible face of the checklist contradicting
 *   itself (§1 forbids the key, §4 requires the rebuild).
 *
 *   Match accuracy and completeness against VERIFIED data are 'unmeasurable' until
 *   somebody supplies a verified answer key at production scale. The eval set is 17
 *   synthetic records; reporting its precision as the programme's match accuracy
 *   would be a confident lie.
 *
 * A two-state verdict would force both to read as plain failures with no
 * explanation, which is how a dashboard trains people to ignore it. detail carries
 * the reason.
 */
return new class extends Migration
{
    protected $connection = 'golden_profile';

    public function up(): void
    {
        $s = Schema::connection($this->connection);

        $s->create('gp_metric', function (Blueprint $t) {
            $t->bigIncrements('metric_id');
            $t->string('metric', 48);
            $t->decimal('value', 12, 4)->nullable();
            $t->decimal('target', 12, 4)->nullable();
            $t->enum('verdict', ['met', 'unmet', 'unmeasurable']);
            $t->json('detail')->nullable();
            $t->dateTime('measured_at');

            $t->unique(['metric', 'measured_at'], 'uq_metric_at');
            $t->index('metric', 'idx_metric');
        });

        $s->create('gp_alarm_event', function (Blueprint $t) {
            $t->bigIncrements('event_id');
            $t->string('alarm', 48);
            $t->boolean('fired');
            $t->json('detail')->nullable();
            $t->dateTime('evaluated_at');

            $t->index(['alarm', 'evaluated_at'], 'idx_alarm_at');
        });
    }

    public function down(): void
    {
        $s = Schema::connection($this->connection);
        $s->dropIfExists('gp_alarm_event');
        $s->dropIfExists('gp_metric');
    }
};
```

- [ ] **Step 5: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/ObserveSchemaTest.php`

Expected: PASS, 6 tests.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add database/migrations/2026_09_12_000000_create_gp_run.php \
        database/migrations/2026_09_12_000100_create_gp_metric_and_alarm.php \
        tests/Feature/ObserveSchemaTest.php
git commit -m "feat(observe): add the run ledger, a metric series and an alarm history"
```

---

## Task 2: `RunLedger`, wired into `Engine`

**Files:**
- Create: `app/GoldenProfile/Observe/RunLedger.php`
- Modify: `app/GoldenProfile/Engine.php`
- Create: `tests/Feature/RunLedgerTest.php`

**Interfaces:**
- Consumes: `gp_run` (Task 1).
- Produces:
  - `RunLedger::open(int $systemId, string $sourceTable, string $mode, ?string $watermarkBefore): int` — returns `run_id`
  - `RunLedger::advance(int $runId, int $rowsRead, int $rowsStaged): void`
  - `RunLedger::close(int $runId, ?string $watermarkAfter): void`
  - `RunLedger::fail(int $runId, \Throwable $e): void`
  - `RunLedger::lastSuccessful(int $systemId, string $sourceTable): ?object`
  - Task 5 (`MetricsCollector`) and Task 6 (`Alarms`) consume `lastSuccessful()`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/RunLedgerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\GoldenProfile\Observe\RunLedger;
use Tests\Support\HubTestCase;

class RunLedgerTest extends HubTestCase
{
    public function test_a_run_is_opened_running_and_closed_ok(): void
    {
        $ledger = new RunLedger;
        $runId = $ledger->open($this->systemId, 'employees', 'sync', '2026-09-01 00:00:00');

        $this->assertSame('running', $this->hub()->table('gp_run')->where('run_id', $runId)->value('status'));

        $ledger->advance($runId, 100, 98);
        $ledger->close($runId, '2026-09-02 00:00:00');

        $row = $this->hub()->table('gp_run')->where('run_id', $runId)->first();
        $this->assertSame('ok', $row->status);
        $this->assertSame(100, (int) $row->rows_read);
        $this->assertSame(98, (int) $row->rows_staged);
        $this->assertNotNull($row->finished_at);
    }

    public function test_advance_accumulates_across_chunks(): void
    {
        // Engine::sync() chunks. A run's counts are the sum over chunks, not the
        // last chunk's numbers.
        $ledger = new RunLedger;
        $runId = $ledger->open($this->systemId, 'employees', 'sync', null);

        $ledger->advance($runId, 1000, 1000);
        $ledger->advance($runId, 1000, 997);
        $ledger->close($runId, '1');

        $row = $this->hub()->table('gp_run')->where('run_id', $runId)->first();
        $this->assertSame(2000, (int) $row->rows_read);
        $this->assertSame(1997, (int) $row->rows_staged);
    }

    public function test_a_failure_is_recorded_not_swallowed(): void
    {
        $ledger = new RunLedger;
        $runId = $ledger->open($this->systemId, 'employees', 'sync', null);

        $ledger->fail($runId, new \RuntimeException('source connection lost'));

        $row = $this->hub()->table('gp_run')->where('run_id', $runId)->first();
        $this->assertSame('failed', $row->status);
        $this->assertStringContainsString('source connection lost', $row->error);
        $this->assertNotNull($row->finished_at, 'a failed run still ended');
    }

    public function test_last_successful_ignores_running_and_failed_runs(): void
    {
        // The freshness alarm must not treat a crashed run as a successful load —
        // that is precisely how a stale source goes unnoticed.
        $ledger = new RunLedger;

        $ok = $ledger->open($this->systemId, 'employees', 'sync', null);
        $ledger->close($ok, '1');

        $bad = $ledger->open($this->systemId, 'employees', 'sync', null);
        $ledger->fail($bad, new \RuntimeException('boom'));

        $ledger->open($this->systemId, 'employees', 'sync', null); // left running

        $last = $ledger->lastSuccessful($this->systemId, 'employees');

        $this->assertSame($ok, (int) $last->run_id);
    }

    public function test_last_successful_is_null_when_a_source_has_never_loaded(): void
    {
        $this->assertNull((new RunLedger)->lastSuccessful($this->systemId, 'employees'));
    }

    public function test_a_long_error_is_truncated_rather_than_rejected(): void
    {
        // error is varchar(500) and a stack-trace-laden message will exceed it. On a
        // strict connection an untruncated insert throws — inside a failure handler,
        // which would replace a recorded failure with an unrecorded one.
        $ledger = new RunLedger;
        $runId = $ledger->open($this->systemId, 'employees', 'sync', null);

        $ledger->fail($runId, new \RuntimeException(str_repeat('x', 2000)));

        $this->assertSame(
            'failed',
            $this->hub()->table('gp_run')->where('run_id', $runId)->value('status')
        );
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/RunLedgerTest.php`

Expected: FAIL, 6 of 6 — `Class "App\GoldenProfile\Observe\RunLedger" not found`.

- [ ] **Step 3: Write `RunLedger`**

Create `app/GoldenProfile/Observe/RunLedger.php`:

```php
<?php

namespace App\GoldenProfile\Observe;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The only writer of gp_run.
 *
 * gp_watermark says where a source got to. It does not say when, how many rows
 * moved, or whether the run finished — it is a mutable resume pointer with no
 * history. So before this class nothing could answer the two questions the
 * Delivery Checklist's monitoring section depends on: "when did this source last
 * load successfully" (freshness) and "how many rows did that load touch"
 * (the row-count gate).
 *
 * A LEDGER MUST NOT BE ABLE TO BREAK THE RUN IT DESCRIBES
 * ------------------------------------------------------
 * advance() and close() are best-effort: they log and continue on failure rather
 * than throwing into Engine::sync(). Losing a row count is a gap in observability;
 * throwing from a bookkeeping call would abort a backfill that was otherwise fine.
 * fail() is the same, and more so — it runs inside an exception handler, and an
 * exception raised there replaces a recorded failure with an unrecorded one.
 */
class RunLedger
{
    public function __construct(private ?string $connection = null) {}

    public function open(int $systemId, string $sourceTable, string $mode, ?string $watermarkBefore): int
    {
        return (int) $this->db()->table('gp_run')->insertGetId([
            'system_id' => $systemId,
            'source_table' => $sourceTable,
            'mode' => $mode,
            'rows_read' => 0,
            'rows_staged' => 0,
            'watermark_before' => $watermarkBefore,
            'status' => 'running',
            'started_at' => now(),
        ]);
    }

    /** Accumulate a chunk's counts. Engine chunks, so this is called many times per run. */
    public function advance(int $runId, int $rowsRead, int $rowsStaged): void
    {
        $this->quietly(fn () => $this->db()->table('gp_run')->where('run_id', $runId)->update([
            'rows_read' => DB::raw('rows_read + '.(int) $rowsRead),
            'rows_staged' => DB::raw('rows_staged + '.(int) $rowsStaged),
        ]));
    }

    public function close(int $runId, ?string $watermarkAfter): void
    {
        $this->quietly(fn () => $this->db()->table('gp_run')->where('run_id', $runId)->update([
            'watermark_after' => $watermarkAfter,
            'status' => 'ok',
            'finished_at' => now(),
        ]));
    }

    public function fail(int $runId, \Throwable $e): void
    {
        $this->quietly(fn () => $this->db()->table('gp_run')->where('run_id', $runId)->update([
            'status' => 'failed',
            // varchar(500), and a strict connection rejects an overlong insert. A
            // throw here would lose the failure record entirely.
            'error' => Str::limit(class_basename($e).': '.$e->getMessage(), 480),
            'finished_at' => now(),
        ]));
    }

    /**
     * The most recent run that actually succeeded. Deliberately ignores 'running'
     * and 'failed': treating a crashed run as a load is exactly how a stale source
     * goes unnoticed.
     */
    public function lastSuccessful(int $systemId, string $sourceTable): ?object
    {
        return $this->db()->table('gp_run')
            ->where('system_id', $systemId)
            ->where('source_table', $sourceTable)
            ->where('status', 'ok')
            ->orderByDesc('started_at')
            ->first();
    }

    private function quietly(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('run ledger write failed', [
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

Run: `vendor/bin/phpunit tests/Feature/RunLedgerTest.php`

Expected: PASS, 6 tests.

- [ ] **Step 5: Wire it into `Engine::sync()`**

In `app/GoldenProfile/Engine.php`, add `use App\GoldenProfile\Observe\RunLedger;` and a member
`private RunLedger $ledger;` initialised in the constructor.

Then wrap `sync()`. Its existing shape opens with a watermark read and closes with a watermark write;
bracket that:

```php
    public function sync(int $chunk = 1000, ?callable $progress = null): int
    {
        $systemId = $this->ensureSystem();
        $before = $this->getWatermark(StreamlineLocalConnector::SOURCE_TABLE);
        $runId = $this->ledger->open($systemId, StreamlineLocalConnector::SOURCE_TABLE, 'sync', $before);

        try {
            $count = $this->syncInner($chunk, $progress, $runId);
            $this->ledger->close($runId, $this->getWatermark(StreamlineLocalConnector::SOURCE_TABLE));

            return $count;
        } catch (\Throwable $e) {
            $this->ledger->fail($runId, $e);

            throw $e;
        }
    }
```

Rename the existing body to `private function syncInner(int $chunk, ?callable $progress, int $runId): int`
and, inside its `chunkById` closure, after the chunk's rows are staged, add:

```php
            $this->ledger->advance($runId, count($rows), count($rows));
```

Apply the identical bracket to `backfill()` with `'backfill'` as the mode. **Do not** change
`finalizeAll()` — it is not a source load and has no watermark; the `finalize` mode exists in the
enum for a later task that measures the "<6 hours full rebuild" target and is deliberately unused
here.

- [ ] **Step 6: Run the suite**

Run: `vendor/bin/phpunit --fail-on-skipped`

Expected: PASS, 0 skipped. `EvalGateTest` unchanged — `EvalRunner` calls `resolve()` directly and
never goes through `Engine::sync()`, so the gate cannot see this change.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty
git add app/GoldenProfile/Observe/RunLedger.php app/GoldenProfile/Engine.php tests/Feature/RunLedgerTest.php
git commit -m "feat(observe): record every sync and backfill in the run ledger"
```

---

## Task 3: `RunDiff` — the regression detector the programme lacks

**This is the highest-value task in the plan.** Six plans change resolution behaviour on a
13.38M-identity hub and the only detector is a 13-pair fixture.

**Files:**
- Create: `app/GoldenProfile/Observe/RunDiff.php`
- Create: `tests/Unit/RunDiffTest.php`

**Interfaces:**
- Produces:
  - `RunDiff::snapshot(int $systemId): array` — `array<string,string>` mapping source key → cluster signature
  - `RunDiff::compare(array $before, array $after): array` returning
    `array{moved:list<array{key:string,from:string,to:string}>, appeared:list<string>, vanished:list<string>, merged:int, split:int, stable:int}`
  - `RunDiff::summarise(array $result): string`
- Consumed by: Task 4's command.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/RunDiffTest.php`:

```php
<?php

namespace Tests\Unit;

use App\GoldenProfile\Observe\RunDiff;
use Tests\TestCase;

class RunDiffTest extends TestCase
{
    public function test_identical_clusterings_report_no_movement(): void
    {
        $before = ['a' => 'a,b', 'b' => 'a,b', 'c' => 'c'];

        $result = RunDiff::compare($before, $before);

        $this->assertSame([], $result['moved']);
        $this->assertSame(3, $result['stable']);
    }

    public function test_renumbered_identities_are_not_reported_as_movement(): void
    {
        // The whole reason snapshots are signatures rather than identity_ids: a
        // fresh resolve assigns new surrogates, so comparing ids directly would
        // report every single row as moved and the tool would be useless.
        $before = ['a' => 'a,b', 'b' => 'a,b'];
        $after = ['a' => 'a,b', 'b' => 'a,b'];

        $this->assertSame(0, count(RunDiff::compare($before, $after)['moved']));
    }

    public function test_a_merge_is_detected_and_counted(): void
    {
        // c joins a,b
        $before = ['a' => 'a,b', 'b' => 'a,b', 'c' => 'c'];
        $after = ['a' => 'a,b,c', 'b' => 'a,b,c', 'c' => 'a,b,c'];

        $result = RunDiff::compare($before, $after);

        $this->assertCount(3, $result['moved'], 'all three rows changed cluster');
        $this->assertSame(1, $result['merged']);
        $this->assertSame(0, $result['split']);
    }

    public function test_a_split_is_detected_and_counted(): void
    {
        $before = ['a' => 'a,b,c', 'b' => 'a,b,c', 'c' => 'a,b,c'];
        $after = ['a' => 'a,b', 'b' => 'a,b', 'c' => 'c'];

        $result = RunDiff::compare($before, $after);

        $this->assertSame(1, $result['split']);
        $this->assertSame(0, $result['merged']);
    }

    public function test_a_row_present_in_only_one_run_is_reported_separately(): void
    {
        // Not movement — a corpus change or a staging failure. Conflating it with
        // movement would let a lost row masquerade as a merge.
        $result = RunDiff::compare(['a' => 'a'], ['a' => 'a', 'b' => 'b']);

        $this->assertSame(['b'], $result['appeared']);
        $this->assertSame([], $result['vanished']);

        $result = RunDiff::compare(['a' => 'a', 'b' => 'b'], ['a' => 'a']);

        $this->assertSame(['b'], $result['vanished']);
    }

    public function test_the_summary_names_the_direction_of_the_change(): void
    {
        // A steward reading CI output needs "3 rows merged" not "3 rows differ".
        // Merges and splits have very different costs.
        $result = RunDiff::compare(
            ['a' => 'a,b', 'b' => 'a,b', 'c' => 'c'],
            ['a' => 'a,b,c', 'b' => 'a,b,c', 'c' => 'a,b,c'],
        );

        $summary = RunDiff::summarise($result);

        $this->assertStringContainsString('1 merge', $summary);
        $this->assertStringContainsString('3 row(s) moved', $summary);
    }

    public function test_signatures_are_order_independent(): void
    {
        // Two runs may enumerate a cluster's members in different orders. If that
        // registered as movement, every run would diff against every other.
        $this->assertSame(
            [],
            RunDiff::compare(['a' => 'a,b', 'b' => 'b,a'], ['a' => 'a,b', 'b' => 'a,b'])['moved']
        );
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/RunDiffTest.php`

Expected: FAIL, 7 of 7 — `Class "App\GoldenProfile\Observe\RunDiff" not found`.

- [ ] **Step 3: Write `RunDiff`**

Create `app/GoldenProfile/Observe/RunDiff.php`:

```php
<?php

namespace App\GoldenProfile\Observe;

use Illuminate\Support\Facades\DB;

/**
 * Before/after run-diff on a matching change — Delivery Checklist §3:
 *
 *   "Before/after run-diff on every matching change to catch regressions"
 *
 * Six plans in this programme change resolution behaviour on a 13.38M-identity
 * hub, and before this class the only regression detector was a 13-pair labeled
 * fixture. That fixture proves CORRECTNESS on cases somebody wrote down. It cannot
 * detect a change that moves a thousand rows it does not contain.
 *
 * This asks a different question — "did this code change move anybody?" — and needs
 * no answer key, because the comparison is between two runs rather than against
 * truth. The two tools are complementary, not redundant.
 *
 * SNAPSHOTS ARE ID-INDEPENDENT, AND THAT IS THE WHOLE TRICK
 * --------------------------------------------------------
 * identity_id is a surrogate. A fresh resolve assigns new ones, so comparing them
 * directly reports every row as moved. A snapshot instead maps each source row to
 * its CLUSTER SIGNATURE: the sorted list of source keys sharing its identity. Two
 * runs agree when every row's signature agrees, whatever the identities were
 * numbered. Sorting is what makes it order-independent — two runs may enumerate a
 * cluster's members differently and that must not register as a change.
 *
 * MERGES AND SPLITS ARE COUNTED SEPARATELY
 * ----------------------------------------
 * The same reason MatchScorer separates false merges from false splits: they cost
 * differently. A merge welds two providers together and, until plan 9 lands, cannot
 * be undone. A split loses a connection. "17 rows differ" is not actionable; "2
 * merges, 0 splits" is.
 */
class RunDiff
{
    public function __construct(private ?string $connection = null) {}

    /**
     * Map every source row resolved for $systemId to its cluster signature.
     *
     * @return array<string,string> source key => signature
     */
    public function snapshot(int $systemId): array
    {
        $rows = $this->db()->table('gp_source_link')
            ->where('system_id', $systemId)
            ->orderBy('identity_id')
            ->get(['identity_id', 'source_table', 'source_id']);

        $byIdentity = [];
        foreach ($rows as $r) {
            $byIdentity[(int) $r->identity_id][] = $r->source_table.'#'.$r->source_id;
        }

        $snapshot = [];
        foreach ($byIdentity as $members) {
            sort($members);
            $signature = implode(',', $members);
            foreach ($members as $key) {
                $snapshot[$key] = $signature;
            }
        }

        return $snapshot;
    }

    /**
     * @param  array<string,string>  $before
     * @param  array<string,string>  $after
     * @return array{moved:list<array{key:string,from:string,to:string}>, appeared:list<string>,
     *     vanished:list<string>, merged:int, split:int, stable:int}
     */
    public static function compare(array $before, array $after): array
    {
        $normalise = static function (string $signature): string {
            $members = explode(',', $signature);
            sort($members);

            return implode(',', $members);
        };

        $moved = [];
        $stable = 0;
        $merged = 0;
        $split = 0;

        foreach ($before as $key => $wasRaw) {
            if (! array_key_exists($key, $after)) {
                continue;
            }

            $was = $normalise($wasRaw);
            $is = $normalise($after[$key]);

            if ($was === $is) {
                $stable++;

                continue;
            }

            $moved[] = ['key' => $key, 'from' => $was, 'to' => $is];
        }

        // Count cluster-level events once each, not once per member row.
        $beforeClusters = array_unique(array_map($normalise, $before));
        $afterClusters = array_unique(array_map($normalise, $after));

        foreach (array_diff($afterClusters, $beforeClusters) as $cluster) {
            // A cluster that did not exist before: if it is larger than every
            // before-cluster it contains members of, something merged.
            $size = count(explode(',', $cluster));
            $wasLargest = 0;
            foreach (explode(',', $cluster) as $member) {
                if (isset($before[$member])) {
                    $wasLargest = max($wasLargest, count(explode(',', $normalise($before[$member]))));
                }
            }
            if ($size > $wasLargest) {
                $merged++;
            } elseif ($size < $wasLargest) {
                $split++;
            }
        }

        return [
            'moved' => $moved,
            'appeared' => array_values(array_diff(array_keys($after), array_keys($before))),
            'vanished' => array_values(array_diff(array_keys($before), array_keys($after))),
            'merged' => $merged,
            'split' => $split,
            'stable' => $stable,
        ];
    }

    public static function summarise(array $result): string
    {
        if ($result['moved'] === [] && $result['appeared'] === [] && $result['vanished'] === []) {
            return sprintf('no change — %d row(s) stable', $result['stable']);
        }

        return sprintf(
            '%d row(s) moved, %d merge(s), %d split(s), %d stable%s%s',
            count($result['moved']),
            $result['merged'],
            $result['split'],
            $result['stable'],
            $result['appeared'] === [] ? '' : sprintf(', %d appeared', count($result['appeared'])),
            $result['vanished'] === [] ? '' : sprintf(', %d vanished', count($result['vanished'])),
        );
    }

    private function db()
    {
        return DB::connection($this->connection ?? config('golden_profile.connections.hub', 'golden_profile'));
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/RunDiffTest.php`

Expected: PASS, 7 tests.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/GoldenProfile/Observe/RunDiff.php tests/Unit/RunDiffTest.php
git commit -m "feat(observe): add RunDiff, an id-independent before/after clustering comparison"
```

---

## Task 4: The corpus and `gp:run-diff`

**Files:**
- Create: `app/GoldenProfile/Observe/RunDiffCorpus.php`
- Create: `app/Console/Commands/GpRunDiff.php`
- Create: `tests/Feature/RunDiffCorpusTest.php`

**Interfaces:**
- Consumes: `RunDiff` (Task 3), `HubTestCase`-style staging.
- Produces:
  - `RunDiffCorpus::__construct(int $systemId, int $seed = 20260904)`
  - `RunDiffCorpus::stage(int $rows = 2000): int` — stages deterministic rows, returns the count
  - Task 4's command consumes both.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/RunDiffCorpusTest.php`:

```php
<?php

namespace Tests\Feature;

use App\GoldenProfile\Observe\RunDiff;
use App\GoldenProfile\Observe\RunDiffCorpus;
use App\GoldenProfile\Resolution\DeterministicResolver;
use Tests\Support\HubTestCase;

class RunDiffCorpusTest extends HubTestCase
{
    public function test_the_corpus_is_deterministic_for_a_seed(): void
    {
        // Two runs on two code versions must see byte-identical input, or the diff
        // reports corpus noise as a code change.
        (new RunDiffCorpus($this->systemId, seed: 42))->stage(50);
        $first = $this->hub()->table('stg_person')->orderBy('source_id')
            ->pluck('last_name', 'source_id')->all();

        $this->deleteAllHubRows();
        $this->systemId = $this->seedSystem();

        (new RunDiffCorpus($this->systemId, seed: 42))->stage(50);
        $second = $this->hub()->table('stg_person')->orderBy('source_id')
            ->pluck('last_name', 'source_id')->all();

        $this->assertSame($first, $second);
    }

    public function test_a_different_seed_gives_a_different_corpus(): void
    {
        (new RunDiffCorpus($this->systemId, seed: 1))->stage(50);
        $a = $this->hub()->table('stg_person')->pluck('last_name', 'source_id')->all();

        $this->deleteAllHubRows();
        $this->systemId = $this->seedSystem();

        (new RunDiffCorpus($this->systemId, seed: 2))->stage(50);
        $b = $this->hub()->table('stg_person')->pluck('last_name', 'source_id')->all();

        $this->assertNotSame($a, $b);
    }

    public function test_the_corpus_actually_produces_clusters(): void
    {
        // A corpus of 2000 unrelated people would resolve to 2000 identities and
        // detect nothing, because there would be no merges to change. It has to
        // contain collisions.
        (new RunDiffCorpus($this->systemId, seed: 7))->stage(200);

        $resolver = new DeterministicResolver($this->systemId);
        foreach ($this->hub()->table('stg_person')->pluck('stg_person_id') as $id) {
            $resolver->resolve($id);
        }

        $identities = (int) $this->hub()->table('gp_identity')->count();

        $this->assertLessThan(200, $identities, 'the corpus must contain matchable collisions');
        $this->assertGreaterThan(20, $identities, 'but it must not collapse into a handful');
    }

    public function test_two_resolves_of_the_same_corpus_diff_clean(): void
    {
        // The control. If an unchanged code path diffs dirty, the tool is measuring
        // its own nondeterminism and is worthless.
        $corpus = new RunDiffCorpus($this->systemId, seed: 11);
        $corpus->stage(100);
        $resolver = new DeterministicResolver($this->systemId);
        foreach ($this->hub()->table('stg_person')->pluck('stg_person_id') as $id) {
            $resolver->resolve($id);
        }
        $before = (new RunDiff)->snapshot($this->systemId);

        $this->deleteAllHubRows();
        $this->systemId = $this->seedSystem();
        (new RunDiffCorpus($this->systemId, seed: 11))->stage(100);
        $resolver = new DeterministicResolver($this->systemId);
        foreach ($this->hub()->table('stg_person')->pluck('stg_person_id') as $id) {
            $resolver->resolve($id);
        }
        $after = (new RunDiff)->snapshot($this->systemId);

        $result = RunDiff::compare($before, $after);

        $this->assertSame([], $result['moved'], RunDiff::summarise($result));
        $this->assertSame(0, $result['merged']);
        $this->assertSame(0, $result['split']);
    }

    public function test_the_corpus_contains_no_real_person(): void
    {
        // The repo is public and this corpus is generated, never sampled from
        // production. Names come from a fixed synthetic vocabulary.
        (new RunDiffCorpus($this->systemId, seed: 3))->stage(50);

        foreach ($this->hub()->table('stg_person')->pluck('last_name') as $name) {
            $this->assertContains($name, RunDiffCorpus::SURNAMES, "$name is not from the synthetic vocabulary");
        }
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/RunDiffCorpusTest.php`

Expected: FAIL, 5 of 5 — `Class "App\GoldenProfile\Observe\RunDiffCorpus" not found`.

- [ ] **Step 3: Write the corpus generator**

Create `app/GoldenProfile/Observe/RunDiffCorpus.php`:

```php
<?php

namespace App\GoldenProfile\Observe;

use Illuminate\Support\Facades\DB;

/**
 * A deterministic synthetic corpus for RunDiff.
 *
 * GENERATED, NEVER SAMPLED
 * ------------------------
 * The obvious corpus is "a few thousand rows from production". Two reasons not to:
 * nobody in this environment has production access, and sv-manila/gp-cami is a
 * PUBLIC repository, so a committed sample of real providers is exactly the
 * disclosure the SSN work exists to prevent. Names come from a fixed synthetic
 * vocabulary and a test asserts it.
 *
 * DETERMINISTIC, BECAUSE THE INPUT MUST NOT BE THE VARIABLE
 * --------------------------------------------------------
 * Two RunDiff runs compare two CODE versions. If the corpus differed between them,
 * the diff would report corpus noise as a code change and the tool would cry wolf
 * until nobody read it. mt_srand($seed) makes the sequence reproducible, and a test
 * pins it by generating twice and comparing.
 *
 * STRUCTURED TO CONTAIN COLLISIONS
 * --------------------------------
 * A corpus of N unrelated people resolves to N identities and can detect nothing,
 * because there are no merges for a change to alter. So the generator deliberately
 * reuses surnames, birth years and identifiers at controlled rates, producing the
 * shapes real matching has to handle: shared name+dob, shared npi with differing
 * names, common surname with distinct dob, and rows with no dob at all (which
 * matters because those can never reach Pass B's review band — a record missing dob
 * caps at 0.72 against a 0.75 floor).
 */
class RunDiffCorpus
{
    /** @var list<string> */
    public const SURNAMES = [
        'Ashford', 'Brennan', 'Calloway', 'Deverell', 'Ellery', 'Fairbourne',
        'Godwin', 'Harrowgate', 'Ilminster', 'Jarrow', 'Kestrel', 'Langmere',
    ];

    /** @var list<string> */
    public const GIVEN = [
        'Alder', 'Bramwell', 'Corwin', 'Delphine', 'Emory', 'Fennimore',
        'Greer', 'Hollis', 'Isolde', 'Jessamy', 'Kester', 'Linnet',
    ];

    public function __construct(private int $systemId, private int $seed = 20260904) {}

    /** Stage $rows deterministic people. Returns the number staged. */
    public function stage(int $rows = 2000): int
    {
        mt_srand($this->seed);

        $batch = [];
        for ($i = 0; $i < $rows; $i++) {
            $batch[] = $this->row($i);

            if (count($batch) === 500) {
                $this->db()->table('stg_person')->insert($batch);
                $batch = [];
            }
        }
        if ($batch !== []) {
            $this->db()->table('stg_person')->insert($batch);
        }

        return $rows;
    }

    /** @return array<string,mixed> */
    private function row(int $i): array
    {
        // Narrow pools on purpose: a surname pool of 12 over 2000 rows guarantees
        // name collisions, and a birth-year pool of 25 guarantees name+dob
        // collisions at a rate the matcher actually has to resolve.
        $last = self::SURNAMES[mt_rand(0, count(self::SURNAMES) - 1)];
        $first = self::GIVEN[mt_rand(0, count(self::GIVEN) - 1)];
        $year = 1950 + mt_rand(0, 24);
        $month = str_pad((string) mt_rand(1, 12), 2, '0', STR_PAD_LEFT);
        $day = str_pad((string) mt_rand(1, 28), 2, '0', STR_PAD_LEFT);

        // One row in six has no date of birth. That is not padding: blockKey()
        // degrades to soundex|____ without one, and such a record can never reach
        // the review band, so a change to blocking shows up here first.
        $dob = mt_rand(1, 6) === 1 ? null : "$year-$month-$day";

        // One row in eight shares an npi from a small pool, producing the
        // cross-name merges the deterministic ladder is supposed to make.
        $npi = mt_rand(1, 8) === 1 ? 1000000000 + (mt_rand(0, 49) * 7) : null;

        return [
            'system_id' => $this->systemId,
            'source_table' => 'employees',
            'source_id' => 900000 + $i,
            'account_id' => 1 + ($i % 5),
            'employeelist_id' => 1 + ($i % 11),
            'first_name' => $first,
            'middle_name' => null,
            'last_name' => $last,
            'name_suffix' => null,
            'date_of_birth' => $dob,
            'ssn_hash' => null,
            'ssn_last_four' => null,
            'npi' => $npi,
            'upin' => null,
            'dea_number' => null,
            'address1' => null,
            'city' => null,
            'state' => null,
            'zip' => null,
            'terminated' => 0,
            'source_modified' => now()->toDateTimeString(),
            'ingested_at' => now(),
            'block_key' => soundex($last).'|'.($dob === null ? '____' : substr($dob, 0, 4)),
        ];
    }

    private function db()
    {
        return DB::connection(config('golden_profile.connections.hub', 'golden_profile'));
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/RunDiffCorpusTest.php`

Expected: PASS, 5 tests. `test_the_corpus_actually_produces_clusters` is the one to watch — if the
identity count is not between 20 and 200 for 200 rows, adjust the collision rates in `row()` and say
so in the commit body. The bounds encode "collides, but does not collapse".

- [ ] **Step 5: Write `gp:run-diff`**

Create `app/Console/Commands/GpRunDiff.php`:

```php
<?php

namespace App\Console\Commands;

use App\GoldenProfile\Observe\RunDiff;
use App\GoldenProfile\Observe\RunDiffCorpus;
use App\GoldenProfile\Resolution\DeterministicResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GpRunDiff extends Command
{
    protected $signature = 'gp:run-diff
        {--rows=2000 : corpus size}
        {--seed=20260904 : corpus seed; must match between the two runs}
        {--save= : write the snapshot to this path instead of comparing}
        {--against= : compare against a snapshot written earlier by --save}
        {--fail-on-change : exit non-zero if anything moved (for CI)}';

    protected $description = 'Resolve a deterministic corpus and compare the clustering against an earlier run';

    public function handle(): int
    {
        $hub = DB::connection('golden_profile');
        $database = $hub->getDatabaseName();

        // Same guard as gp:eval and HubTestCase. This command truncates and
        // re-stages, so pointing it at a real hub would destroy it.
        if (! str_starts_with($database, 'gp_') || ! str_contains($database, 'test')) {
            $this->error("refusing to run against '$database': gp:run-diff stages and truncates, so it ".
                         "requires a scratch schema whose name starts with 'gp_' and contains 'test'.");

            return self::FAILURE;
        }

        $systemId = (int) $hub->table('gp_source_system')->insertGetId([
            'system_code' => 'run-diff-'.uniqid(),
            'display_name' => 'run diff',
            'reliability_rank' => 50,
            'is_active' => 1,
            'added_at' => now(),
        ]);

        $rows = (int) $this->option('rows');
        $this->info("staging $rows deterministic rows (seed {$this->option('seed')})");
        (new RunDiffCorpus($systemId, (int) $this->option('seed')))->stage($rows);

        $resolver = new DeterministicResolver($systemId);
        $bar = $this->output->createProgressBar($rows);
        foreach ($hub->table('stg_person')->where('system_id', $systemId)->pluck('stg_person_id') as $id) {
            $resolver->resolve($id);
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();

        $snapshot = (new RunDiff)->snapshot($systemId);
        $this->info(sprintf('%d rows in %d cluster(s)', count($snapshot), count(array_unique($snapshot))));

        if ($path = $this->option('save')) {
            file_put_contents(base_path($path), json_encode($snapshot));
            $this->info("snapshot written to $path — check out the other code version and re-run with --against=$path");

            return self::SUCCESS;
        }

        if (! $against = $this->option('against')) {
            $this->warn('nothing to compare against. Use --save on the first run, then --against on the second.');

            return self::SUCCESS;
        }

        $before = json_decode((string) file_get_contents(base_path($against)), true, 64, JSON_THROW_ON_ERROR);
        $result = RunDiff::compare($before, $snapshot);

        $this->newLine();
        $this->line(RunDiff::summarise($result));

        if ($result['moved'] !== []) {
            $this->newLine();
            $this->table(
                ['source key', 'was in a cluster of', 'now in a cluster of'],
                array_map(fn ($m) => [
                    $m['key'],
                    count(explode(',', $m['from'])),
                    count(explode(',', $m['to'])),
                ], array_slice($result['moved'], 0, 25)),
            );
            if (count($result['moved']) > 25) {
                $this->line(sprintf('… and %d more', count($result['moved']) - 25));
            }
        }

        $changed = $result['moved'] !== [] || $result['appeared'] !== [] || $result['vanished'] !== [];

        return $changed && $this->option('fail-on-change') ? self::FAILURE : self::SUCCESS;
    }
}
```

- [ ] **Step 6: Verify it registers and document the workflow**

Run: `php artisan list gp` — expected: `gp:run-diff` listed.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty
git add app/GoldenProfile/Observe/RunDiffCorpus.php app/Console/Commands/GpRunDiff.php \
        tests/Feature/RunDiffCorpusTest.php
git commit -m "feat(observe): add gp:run-diff with a deterministic synthetic corpus"
```

---

## Task 5: `MetricsCollector` — the Success Metrics, including the two that cannot pass

**Files:**
- Create: `app/GoldenProfile/Observe/MetricsCollector.php`
- Create: `app/Console/Commands/GpMetrics.php`
- Create: `tests/Feature/MetricsCollectorTest.php`

**Interfaces:**
- Consumes: `gp_metric` (Task 1), `RunLedger::lastSuccessful()` (Task 2).
- Produces: `MetricsCollector::collect(): array` — list of `array{metric:string, value:?float, target:?float, verdict:string, detail:array}`; `MetricsCollector::persist(array $metrics): int`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/MetricsCollectorTest.php`:

```php
<?php

namespace Tests\Feature;

use App\GoldenProfile\Observe\MetricsCollector;
use Tests\Support\HubTestCase;

class MetricsCollectorTest extends HubTestCase
{
    private function metric(array $metrics, string $name): array
    {
        foreach ($metrics as $m) {
            if ($m['metric'] === $name) {
                return $m;
            }
        }
        $this->fail("metric $name was not collected");
    }

    public function test_the_automatic_match_rate_is_computed_from_the_link_table(): void
    {
        // "Matches handled automatically, no human needed — 85% or more". This one
        // is genuinely computable today: match_state distinguishes auto_match from
        // review, and match_method distinguishes deterministic from probabilistic.
        foreach (['auto_match', 'auto_match', 'auto_match', 'review'] as $state) {
            $this->seedLink($state);
        }

        $m = $this->metric((new MetricsCollector)->collect(), 'automatic_match_rate');

        $this->assertSame(75.0, (float) $m['value']);
        $this->assertSame(85.0, (float) $m['target']);
        $this->assertSame('unmet', $m['verdict']);
    }

    public function test_rebuild_reproducibility_reports_unmet_with_its_reason_once_ssn_is_gone(): void
    {
        // "Can we always rebuild the golden layer from scratch? Yes, 100%". Plan 2
        // makes this permanently false: it retains ssn_hash-bound merges rather
        // than un-merging them, so a rebuild cannot reproduce them. Reporting it as
        // an unexplained red light is how a dashboard trains people to ignore it.
        $m = $this->metric((new MetricsCollector)->collect(), 'rebuild_reproducible');

        $this->assertContains($m['verdict'], ['met', 'unmet']);
        $this->assertArrayHasKey('reason', $m['detail']);
    }

    public function test_match_accuracy_is_unmeasurable_rather_than_faked_from_the_eval_set(): void
    {
        // "Match accuracy (checked against verified data) — 99% or better". The eval
        // set is 17 SYNTHETIC records. Reporting its precision as the programme's
        // match accuracy would be a confident lie, so this is 'unmeasurable' until
        // somebody supplies a verified answer key at scale.
        $m = $this->metric((new MetricsCollector)->collect(), 'match_accuracy');

        $this->assertSame('unmeasurable', $m['verdict']);
        $this->assertStringContainsString('verified', $m['detail']['reason']);
    }

    public function test_review_queue_age_is_computed_when_there_is_a_queue(): void
    {
        // "Time for a steward to clear the review queue — under 2 business days".
        $this->seedLink('review', linkedAt: now()->subDays(5));
        $this->seedLink('review', linkedAt: now()->subDays(1));

        $m = $this->metric((new MetricsCollector)->collect(), 'review_queue_age_days');

        $this->assertGreaterThan(2.0, (float) $m['value']);
        $this->assertSame('unmet', $m['verdict']);
    }

    public function test_an_empty_review_queue_is_met_not_a_division_by_zero(): void
    {
        $m = $this->metric((new MetricsCollector)->collect(), 'review_queue_age_days');

        $this->assertSame('met', $m['verdict']);
    }

    public function test_metrics_persist_as_a_series(): void
    {
        $collector = new MetricsCollector;
        $written = $collector->persist($collector->collect());

        $this->assertGreaterThan(0, $written);
        $this->assertSame($written, (int) $this->hub()->table('gp_metric')->count());
    }

    public function test_every_success_metrics_target_is_accounted_for(): void
    {
        // The page lists eight. None may be silently omitted — an unlisted target is
        // indistinguishable from a met one on a dashboard.
        $collected = array_column((new MetricsCollector)->collect(), 'metric');

        foreach (MetricsCollector::TARGETS as $name => $_) {
            $this->assertContains($name, $collected, "$name is declared but never collected");
        }
        $this->assertCount(count(MetricsCollector::TARGETS), $collected);
    }

    private function seedLink(string $state, ?\Carbon\Carbon $linkedAt = null): void
    {
        static $sourceId = 5000;

        $identityId = (int) $this->hub()->table('gp_identity')->insertGetId([
            'identity_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'canonical_first' => 'M', 'canonical_last' => 'Metric',
            'confidence' => 1.0, 'record_count' => 1, 'status' => 'active',
            'first_seen' => now(), 'last_updated' => now(),
        ]);

        $this->hub()->table('gp_source_link')->insert([
            'identity_id' => $identityId, 'system_id' => $this->systemId,
            'source_table' => 'employees', 'source_id' => ++$sourceId,
            'match_method' => $state === 'review' ? 'probabilistic' : 'deterministic',
            'match_key' => 'npi', 'match_score' => 0.99, 'match_state' => $state,
            'is_pinned' => 0, 'linked_at' => $linkedAt ?? now(),
        ]);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/MetricsCollectorTest.php`

Expected: FAIL, 7 of 7 — `Class "App\GoldenProfile\Observe\MetricsCollector" not found`.

- [ ] **Step 3: Write `MetricsCollector`**

Create `app/GoldenProfile/Observe/MetricsCollector.php`:

```php
<?php

namespace App\GoldenProfile\Observe;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Success Metrics & Risks page (DEV 4067295233) states eight targets. Before
 * this class none of them was computed anywhere — the capability existed and the
 * measurement did not, which is the same as not having the target.
 *
 * THREE VERDICTS, NOT TWO
 * -----------------------
 * Two of the eight cannot be reported as a simple pass or fail, and both for
 * reasons decided elsewhere in the programme rather than defects here:
 *
 *   rebuild_reproducible — "Can we always rebuild the golden layer from scratch?
 *   Yes, 100% of the time." Plan 2 removes ssn_hash and RETAINS the merges it made
 *   rather than un-merging them (un-merging would manufacture false splits, the
 *   costlier error). Its own Architecture section states the forfeit: a rebuild no
 *   longer reproduces those merges. So this goes permanently to 'unmet' the moment
 *   plan 2 lands — and it is the visible face of the Delivery Checklist contradicting
 *   itself, since §1 forbids the key that §4's rebuild requirement depends on.
 *
 *   match_accuracy and match_completeness — "checked against verified data". There
 *   is no verified answer key at production scale. The eval set is 17 synthetic
 *   records; reporting its precision here would be a confident lie about a
 *   compliance-critical number. 'unmeasurable', with the reason attached.
 *
 * A two-state verdict forces both to read as unexplained failures, which is how a
 * dashboard teaches people to stop looking at it.
 *
 * WHAT IS DELIBERATELY NOT MEASURED HERE
 * --------------------------------------
 * The service targets (API p50/p99 under 300ms, full rebuild under 6 hours) need
 * request and run timing rather than hub state. gp_run gives the rebuild half once
 * finalize is instrumented; the API half needs middleware and is left to plan 10,
 * which is changing that request path anyway. Recorded in docs/MONITORING.md rather
 * than silently dropped — and note config/golden_profile.php already carries
 * measured evidence that 300ms is breached by orders of magnitude on the tail
 * (8.9s for identity 59 at link_chunk_size 1000).
 */
class MetricsCollector
{
    /** metric => [target, comparison] where comparison is 'gte' or 'lte'. */
    public const TARGETS = [
        'match_accuracy' => [99.0, 'gte'],
        'match_completeness' => [95.0, 'gte'],
        'automatic_match_rate' => [85.0, 'gte'],
        'review_queue_age_days' => [2.0, 'lte'],
        'exclusion_freshness_hours' => [24.0, 'lte'],
        'identity_freshness_hours' => [48.0, 'lte'],
        'rebuild_reproducible' => [100.0, 'gte'],
        'missed_exclusions' => [0.0, 'lte'],
    ];

    public function __construct(private ?string $connection = null) {}

    /** @return list<array{metric:string, value:?float, target:?float, verdict:string, detail:array}> */
    public function collect(): array
    {
        return [
            $this->unmeasurable('match_accuracy',
                'no verified answer key exists at production scale. The eval set is 17 synthetic '.
                'records — reporting its precision as match accuracy would misstate a '.
                'compliance-critical number. Supply a labeled sample from internal verified data '.
                'to make this measurable.'),

            $this->unmeasurable('match_completeness',
                'same as match_accuracy: recall against verified data needs verified data.'),

            $this->automaticMatchRate(),
            $this->reviewQueueAge(),
            $this->freshness('exclusion_freshness_hours', 24.0),
            $this->freshness('identity_freshness_hours', 48.0),
            $this->rebuildReproducible(),
            $this->missedExclusions(),
        ];
    }

    public function persist(array $metrics): int
    {
        $at = now();
        $rows = array_map(fn ($m) => [
            'metric' => $m['metric'],
            'value' => $m['value'],
            'target' => $m['target'],
            'verdict' => $m['verdict'],
            'detail' => json_encode($m['detail']),
            'measured_at' => $at,
        ], $metrics);

        $this->db()->table('gp_metric')->insert($rows);

        return count($rows);
    }

    /**
     * "Matches handled automatically, no human needed — 85% or more."
     *
     * Computable today: match_state separates auto_match from review. Note the
     * denominator excludes 'new' bindings deliberately — minting a new identity is
     * not a match, and counting it as an automatic one would inflate this metric
     * with every unmatched record.
     */
    private function automaticMatchRate(): array
    {
        $total = (int) $this->db()->table('gp_source_link')
            ->whereIn('match_state', ['auto_match', 'review'])->count();

        if ($total === 0) {
            return $this->verdict('automatic_match_rate', null, ['reason' => 'no matched links yet']);
        }

        $auto = (int) $this->db()->table('gp_source_link')->where('match_state', 'auto_match')->count();

        return $this->verdict('automatic_match_rate', round($auto / $total * 100, 4), [
            'auto_match' => $auto,
            'review' => $total - $auto,
            'note' => "'new' bindings are excluded from the denominator: minting a new identity is not a match",
        ]);
    }

    /**
     * "Time for a steward to clear the review queue — under 2 business days."
     *
     * Measured as the mean age of UNREVIEWED review-band links, which is the
     * backlog's age rather than a historical clearance time. Once plan 9's
     * reviewed_at exists, the true clearance time becomes computable and this should
     * switch to it; until then backlog age is the honest proxy and says so.
     */
    private function reviewQueueAge(): array
    {
        $q = $this->db()->table('gp_source_link')->where('match_state', 'review');

        if (Schema::connection($this->connection ?? 'golden_profile')->hasColumn('gp_source_link', 'reviewed_at')) {
            $q->whereNull('reviewed_at');
        }

        $pending = (int) $q->count();

        if ($pending === 0) {
            return $this->verdict('review_queue_age_days', 0.0, ['pending' => 0]);
        }

        $meanAge = (float) $q->avg(DB::raw('TIMESTAMPDIFF(HOUR, linked_at, NOW()) / 24'));

        return $this->verdict('review_queue_age_days', round($meanAge, 4), [
            'pending' => $pending,
            'note' => 'backlog age, not historical clearance time — switch to reviewed_at once plan 9 lands',
        ]);
    }

    /** Hours since the source last loaded successfully. */
    private function freshness(string $metric, float $target): array
    {
        $last = $this->db()->table('gp_run')->where('status', 'ok')->orderByDesc('started_at')->first();

        if ($last === null) {
            return $this->unmeasurable($metric, 'no successful run has been recorded yet');
        }

        $hours = (float) $this->db()->selectOne(
            'SELECT TIMESTAMPDIFF(MINUTE, ?, NOW()) / 60 AS h', [$last->finished_at ?? $last->started_at]
        )->h;

        return $this->verdict($metric, round($hours, 4), [
            'last_run_id' => (int) $last->run_id,
            'rows_read' => (int) $last->rows_read,
            'note' => 'gp-cami has ONE source, so exclusion and identity freshness share it. '.
                      'They diverge only once separate feeds exist, which §8 puts out of scope.',
        ]);
    }

    /**
     * "Can we always rebuild the golden layer from scratch? Yes, 100% of the time."
     *
     * Detected structurally rather than asserted: if any link was bound by a key
     * the current matcher no longer has, a rebuild cannot reproduce it. After plan 2
     * that is every match_key = 'ssn_hash' row.
     */
    private function rebuildReproducible(): array
    {
        $ssnBound = (int) $this->db()->table('gp_source_link')->where('match_key', 'ssn_hash')->count();
        $tierExists = Schema::connection($this->connection ?? 'golden_profile')
            ->hasColumn('gp_identity', 'ssn_hash');

        if ($ssnBound > 0 && ! $tierExists) {
            return $this->verdict('rebuild_reproducible', 0.0, [
                'reason' => "$ssnBound link(s) were bound by ssn_hash, a tier that no longer exists. ".
                            'Plan 2 retains those merges deliberately rather than manufacturing false '.
                            'splits, and states the forfeit of rebuild reproducibility. This target is '.
                            'permanently unmet by decision, not by defect — and it is the visible face '.
                            'of the Delivery Checklist forbidding the key (§1) that its own '.
                            'rebuild requirement (§4) depends on.',
                'ssn_bound_links' => $ssnBound,
            ]);
        }

        return $this->verdict('rebuild_reproducible', 100.0, [
            'reason' => 'no link depends on a removed matching key',
        ]);
    }

    /**
     * "Missed exclusions (false negatives) — zero tolerance, always alarmed."
     *
     * Unmeasurable by construction: a MISSED exclusion is one the hub does not know
     * about, so no query over the hub can count it. What is measurable is a proxy —
     * exclusion links that never got a link_state decision — and the proxy is
     * labelled as one rather than dressed up as the metric.
     */
    private function missedExclusions(): array
    {
        $undecided = (int) $this->db()->table('gp_identity_exclusion')
            ->where('link_state', 'candidate')->count();

        return $this->unmeasurable('missed_exclusions', sprintf(
            'a missed exclusion is by definition absent from the hub, so it cannot be counted here — '.
            'only an external reconciliation against the source registry can. Proxy: %d exclusion '.
            'link(s) sit undecided at link_state = candidate. Until plan 6 wires transitions that is '.
            'ALL of them, so the proxy carries no signal yet.',
            $undecided
        ));
    }

    private function verdict(string $metric, ?float $value, array $detail): array
    {
        [$target, $comparison] = self::TARGETS[$metric];

        $met = $value === null
            ? false
            : ($comparison === 'gte' ? $value >= $target : $value <= $target);

        return [
            'metric' => $metric,
            'value' => $value,
            'target' => $target,
            'verdict' => $value === null ? 'unmeasurable' : ($met ? 'met' : 'unmet'),
            'detail' => $detail,
        ];
    }

    private function unmeasurable(string $metric, string $reason): array
    {
        return [
            'metric' => $metric,
            'value' => null,
            'target' => self::TARGETS[$metric][0],
            'verdict' => 'unmeasurable',
            'detail' => ['reason' => $reason],
        ];
    }

    private function db()
    {
        return DB::connection($this->connection ?? config('golden_profile.connections.hub', 'golden_profile'));
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/MetricsCollectorTest.php`

Expected: PASS, 7 tests.

- [ ] **Step 5: Add `gp:metrics`**

Create `app/Console/Commands/GpMetrics.php`:

```php
<?php

namespace App\Console\Commands;

use App\GoldenProfile\Observe\MetricsCollector;
use Illuminate\Console\Command;

class GpMetrics extends Command
{
    protected $signature = 'gp:metrics {--persist : write the readings to gp_metric as a series}';

    protected $description = 'Compute the Success Metrics targets against the hub';

    public function handle(): int
    {
        $collector = new MetricsCollector;
        $metrics = $collector->collect();

        $this->table(
            ['metric', 'value', 'target', 'verdict'],
            array_map(fn ($m) => [
                $m['metric'],
                $m['value'] === null ? '—' : number_format($m['value'], 2),
                number_format((float) $m['target'], 2),
                match ($m['verdict']) {
                    'met' => '<info>met</info>',
                    'unmet' => '<error>unmet</error>',
                    default => '<comment>unmeasurable</comment>',
                },
            ], $metrics),
        );

        foreach ($metrics as $m) {
            if (isset($m['detail']['reason'])) {
                $this->newLine();
                $this->line("<comment>{$m['metric']}</comment>: {$m['detail']['reason']}");
            }
        }

        if ($this->option('persist')) {
            $this->newLine();
            $this->info($collector->persist($metrics).' reading(s) written to gp_metric');
        }

        return self::SUCCESS;
    }
}
```

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add app/GoldenProfile/Observe/MetricsCollector.php app/Console/Commands/GpMetrics.php \
        tests/Feature/MetricsCollectorTest.php
git commit -m "feat(observe): compute the Success Metrics targets, including the two that cannot pass"
```

---

## Task 6: Alarms

**Files:**
- Create: `app/GoldenProfile/Observe/Alarms.php`
- Create: `app/Console/Commands/GpAlarms.php`
- Create: `tests/Feature/AlarmsTest.php`
- Modify: `.env.example`

**Interfaces:**
- Consumes: `RunLedger::lastSuccessful()` (Task 2), `gp_alarm_event` (Task 1), plan 5's `gp_quarantine` when present.
- Produces: `Alarms::evaluate(): array` — list of `array{alarm:string, fired:bool, detail:array}`; `Alarms::record(array $results): void`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/AlarmsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\GoldenProfile\Observe\Alarms;
use App\GoldenProfile\Observe\RunLedger;
use Tests\Support\HubTestCase;

class AlarmsTest extends HubTestCase
{
    private function fired(array $results, string $alarm): bool
    {
        foreach ($results as $r) {
            if ($r['alarm'] === $alarm) {
                return $r['fired'];
            }
        }
        $this->fail("alarm $alarm was not evaluated");
    }

    public function test_stale_source_fires_when_no_run_has_ever_succeeded(): void
    {
        $this->assertTrue($this->fired((new Alarms)->evaluate(), 'stale_source'));
    }

    public function test_stale_source_clears_after_a_successful_run(): void
    {
        $ledger = new RunLedger;
        $runId = $ledger->open($this->systemId, 'employees', 'sync', null);
        $ledger->advance($runId, 500, 500);
        $ledger->close($runId, '1');

        $this->assertFalse($this->fired((new Alarms)->evaluate(), 'stale_source'));
    }

    public function test_a_failed_run_fires_its_own_alarm(): void
    {
        $ledger = new RunLedger;
        $runId = $ledger->open($this->systemId, 'employees', 'sync', null);
        $ledger->fail($runId, new \RuntimeException('boom'));

        $this->assertTrue($this->fired((new Alarms)->evaluate(), 'run_failed'));
    }

    public function test_row_count_collapse_fires(): void
    {
        // "Is the row count within a normal range?" — a load that suddenly reads a
        // fraction of the previous one is the classic silent-truncation signal.
        $ledger = new RunLedger;
        foreach ([10000, 10200, 9800] as $rows) {
            $id = $ledger->open($this->systemId, 'employees', 'sync', null);
            $ledger->advance($id, $rows, $rows);
            $ledger->close($id, '1');
        }
        $collapsed = $ledger->open($this->systemId, 'employees', 'sync', null);
        $ledger->advance($collapsed, 12, 12);
        $ledger->close($collapsed, '1');

        $this->assertTrue($this->fired((new Alarms)->evaluate(), 'row_count_collapse'));
    }

    public function test_row_count_collapse_does_not_fire_on_a_normal_incremental_run(): void
    {
        // gp:sync is incremental: a small run is the NORMAL case, not a collapse.
        // The alarm must compare against the recent norm, not against zero, or it
        // fires constantly and gets muted.
        $ledger = new RunLedger;
        foreach ([40, 55, 12, 33] as $rows) {
            $id = $ledger->open($this->systemId, 'employees', 'sync', null);
            $ledger->advance($id, $rows, $rows);
            $ledger->close($id, '1');
        }

        $this->assertFalse($this->fired((new Alarms)->evaluate(), 'row_count_collapse'));
    }

    public function test_the_missed_exclusion_alarm_reports_that_it_cannot_yet_fire(): void
    {
        // has_active_exclusion's formula is correct but link_state never leaves
        // 'candidate' until plan 6 lands, so this alarm would be watching a
        // constant. It must say so rather than sitting green and implying coverage.
        $results = (new Alarms)->evaluate();

        foreach ($results as $r) {
            if ($r['alarm'] === 'missed_exclusion') {
                $this->assertArrayHasKey('blocked_by', $r['detail']);
                $this->assertStringContainsString('plan 6', $r['detail']['blocked_by']);

                return;
            }
        }
        $this->fail('missed_exclusion was not evaluated');
    }

    public function test_evaluations_are_recorded_so_flapping_is_visible(): void
    {
        $alarms = new Alarms;
        $alarms->record($alarms->evaluate());

        $this->assertGreaterThan(0, (int) $this->hub()->table('gp_alarm_event')->count());
    }

    public function test_no_alert_destination_is_committed(): void
    {
        // The repo is public. A webhook URL in .env.example is a leak.
        $example = file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString('GP_ALARM_WEBHOOK=', $example);
        $this->assertStringNotContainsString('https://', substr($example, strpos($example, 'GP_ALARM_WEBHOOK=')));
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/AlarmsTest.php`

Expected: FAIL, 8 of 8 — `Class "App\GoldenProfile\Observe\Alarms" not found`.

- [ ] **Step 3: Write `Alarms`**

Create `app/GoldenProfile/Observe/Alarms.php`:

```php
<?php

namespace App\GoldenProfile\Observe;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery Checklist §6: "Monitoring + alarms (freshness; zero-tolerance
 * missed-exclusion); runbooks". Before this class gp-cami had none — one `monitor`
 * mention across the whole programme, in plan 3a deferring to monitoring plan 8
 * was going to add and did not.
 *
 * DETECTION IS SEPARATED FROM DELIVERY, DELIBERATELY
 * --------------------------------------------------
 * "Someone is alerted" needs a destination, and sv-manila/gp-cami is a PUBLIC
 * repository — no webhook URL, channel or address may be committed. gp-cami also
 * has no queue driver configured for engine work and no mail transport.
 *
 * So: this class detects and records; gp:alarms exits non-zero; delivery is one
 * optional env var (GP_ALARM_WEBHOOK) POSTed to if set. Any scheduler or monitoring
 * agent can run a command and read an exit code, which makes that the smallest
 * mechanism that satisfies "bad data never silently flows downstream" without
 * committing a secret.
 *
 * AN ALARM THAT CANNOT FIRE MUST SAY SO
 * -------------------------------------
 * missed_exclusion is the checklist's zero-tolerance case and it is currently
 * unarmed: has_active_exclusion's formula is right, but link_state never leaves
 * 'candidate' until plan 6 wires transitions, so the alarm would watch a constant.
 * It reports fired = false WITH a blocked_by reason rather than sitting quietly
 * green, because a green alarm implies coverage that does not exist. That is the
 * difference between monitoring and the appearance of monitoring.
 */
class Alarms
{
    /** A run older than this many hours means the source is stale. */
    private const STALE_HOURS = 26;

    /** A run reading less than this fraction of the recent median is a collapse. */
    private const COLLAPSE_FRACTION = 0.25;

    /** Runs to establish the norm. Below this there is no norm and the alarm holds. */
    private const NORM_WINDOW = 3;

    public function __construct(private ?string $connection = null) {}

    /** @return list<array{alarm:string, fired:bool, detail:array}> */
    public function evaluate(): array
    {
        return [
            $this->staleSource(),
            $this->runFailed(),
            $this->rowCountCollapse(),
            $this->quarantineGrowth(),
            $this->missedExclusion(),
        ];
    }

    public function record(array $results): void
    {
        $at = now();

        $this->db()->table('gp_alarm_event')->insert(array_map(fn ($r) => [
            'alarm' => $r['alarm'],
            'fired' => $r['fired'],
            'detail' => json_encode($r['detail']),
            'evaluated_at' => $at,
        ], $results));
    }

    private function staleSource(): array
    {
        $last = $this->db()->table('gp_run')->where('status', 'ok')
            ->orderByDesc('started_at')->first();

        if ($last === null) {
            return $this->alarm('stale_source', true, ['reason' => 'no successful run has ever been recorded']);
        }

        $hours = (float) $this->db()->selectOne(
            'SELECT TIMESTAMPDIFF(MINUTE, ?, NOW()) / 60 AS h', [$last->finished_at ?? $last->started_at]
        )->h;

        return $this->alarm('stale_source', $hours > self::STALE_HOURS, [
            'hours_since_last_success' => round($hours, 2),
            'threshold_hours' => self::STALE_HOURS,
            'note' => 'threshold is the 24h exclusion-freshness target plus 2h of slack, so a '.
                      'daily schedule running slightly late does not page anybody',
        ]);
    }

    private function runFailed(): array
    {
        $failed = $this->db()->table('gp_run')->where('status', 'failed')
            ->orderByDesc('started_at')->first();

        if ($failed === null) {
            return $this->alarm('run_failed', false, []);
        }

        // Only the most recent run matters: a failure followed by a success is
        // history, not an incident.
        $latest = $this->db()->table('gp_run')->orderByDesc('started_at')->first();

        return $this->alarm('run_failed', (int) $latest->run_id === (int) $failed->run_id, [
            'run_id' => (int) $failed->run_id,
            'error' => $failed->error,
        ]);
    }

    private function rowCountCollapse(): array
    {
        $recent = $this->db()->table('gp_run')->where('status', 'ok')
            ->orderByDesc('started_at')->limit(self::NORM_WINDOW + 1)->pluck('rows_read')->all();

        if (count($recent) <= self::NORM_WINDOW) {
            return $this->alarm('row_count_collapse', false, [
                'reason' => 'fewer than '.(self::NORM_WINDOW + 1).' successful runs — no norm to compare against',
            ]);
        }

        $latest = (int) array_shift($recent);
        sort($recent);
        $median = (float) $recent[intdiv(count($recent), 2)];

        if ($median <= 0.0) {
            return $this->alarm('row_count_collapse', false, ['reason' => 'the recent norm is zero']);
        }

        $fraction = $latest / $median;

        return $this->alarm('row_count_collapse', $fraction < self::COLLAPSE_FRACTION, [
            'latest_rows_read' => $latest,
            'recent_median' => $median,
            'fraction_of_norm' => round($fraction, 4),
            'note' => 'compared against the recent median, not against zero: gp:sync is incremental, '.
                      'so a small run is the normal case and an absolute floor would fire constantly',
        ]);
    }

    private function quarantineGrowth(): array
    {
        // Plan 5 owns gp_quarantine. Absent until it lands, and this must not
        // explode in the meantime.
        if (! Schema::connection($this->connection ?? 'golden_profile')->hasTable('gp_quarantine')) {
            return $this->alarm('quarantine_growth', false, [
                'blocked_by' => 'gp_quarantine does not exist yet — plan 5 creates it',
            ]);
        }

        $rows = (int) $this->db()->table('gp_quarantine')->count();

        return $this->alarm('quarantine_growth', $rows > 0, ['quarantined_rows' => $rows]);
    }

    private function missedExclusion(): array
    {
        $undecided = (int) $this->db()->table('gp_identity_exclusion')
            ->where('link_state', 'candidate')->count();
        $total = (int) $this->db()->table('gp_identity_exclusion')->count();

        // Until plan 6 wires link_state transitions every row sits at 'candidate',
        // so "all undecided" carries no signal and the alarm is unarmed. Reporting
        // that is the point: a green alarm here would imply coverage of the
        // checklist's zero-tolerance case that does not exist.
        $unarmed = $total > 0 && $undecided === $total;

        return $this->alarm('missed_exclusion', false, [
            'undecided' => $undecided,
            'total' => $total,
            'blocked_by' => $unarmed || $total === 0
                ? 'link_state never transitions away from candidate until plan 6 lands, so this '.
                  'alarm is UNARMED. It cannot detect a missed exclusion today. Do not read '.
                  'fired = false as coverage.'
                : 'armed',
        ]);
    }

    private function alarm(string $name, bool $fired, array $detail): array
    {
        return ['alarm' => $name, 'fired' => $fired, 'detail' => $detail];
    }

    private function db()
    {
        return DB::connection($this->connection ?? config('golden_profile.connections.hub', 'golden_profile'));
    }
}
```

- [ ] **Step 4: Add the command and the env placeholder**

Create `app/Console/Commands/GpAlarms.php`:

```php
<?php

namespace App\Console\Commands;

use App\GoldenProfile\Observe\Alarms;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GpAlarms extends Command
{
    protected $signature = 'gp:alarms {--quiet-when-clear : print nothing when no alarm fires}';

    protected $description = 'Evaluate every alarm; exit non-zero if any fires';

    public function handle(): int
    {
        $alarms = new Alarms;
        $results = $alarms->evaluate();
        $alarms->record($results);

        $firing = array_values(array_filter($results, fn ($r) => $r['fired']));

        if ($firing === []) {
            if (! $this->option('quiet-when-clear')) {
                $this->info(count($results).' alarm(s) evaluated, none firing');

                foreach ($results as $r) {
                    if (isset($r['detail']['blocked_by']) && $r['detail']['blocked_by'] !== 'armed') {
                        $this->line("<comment>{$r['alarm']}</comment>: {$r['detail']['blocked_by']}");
                    }
                }
            }

            return self::SUCCESS;
        }

        foreach ($firing as $r) {
            $this->error($r['alarm'].': '.json_encode($r['detail']));
            Log::error('gp-cami alarm firing', $r);
        }

        // Delivery is configuration, never committed. Absent, the exit code and the
        // log are the notification — which any scheduler or monitoring agent reads.
        if ($url = env('GP_ALARM_WEBHOOK')) {
            try {
                Http::timeout(5)->post($url, ['text' => 'gp-cami alarms firing: '.json_encode($firing)]);
            } catch (\Throwable $e) {
                $this->warn('alarm webhook POST failed: '.class_basename($e));
            }
        }

        return self::FAILURE;
    }
}
```

Append to `.env.example`:

```
# Optional alarm delivery for `php artisan gp:alarms`. Leave EMPTY here — this
# repository is public. Set it in the deployed .env only. With no value, the
# command's non-zero exit code and its error log are the notification, which is
# all any scheduler or monitoring agent needs.
GP_ALARM_WEBHOOK=
```

- [ ] **Step 5: Run the tests**

Run: `vendor/bin/phpunit tests/Feature/AlarmsTest.php`

Expected: PASS, 8 tests.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add app/GoldenProfile/Observe/Alarms.php app/Console/Commands/GpAlarms.php \
        tests/Feature/AlarmsTest.php .env.example
git commit -m "feat(observe): add gp:alarms with freshness, run-failure and row-collapse detection"
```

---

## Task 7: `docs/MONITORING.md` and prove the gate is unmoved

**Files:**
- Create: `docs/MONITORING.md`
- Create: `tests/Feature/EvalGateUnmovedByObservabilityTest.php`

- [ ] **Step 1: Write the gate assertion**

Create `tests/Feature/EvalGateUnmovedByObservabilityTest.php`:

```php
<?php

namespace Tests\Feature;

use App\GoldenProfile\Eval\EvalRunner;
use App\GoldenProfile\Eval\EvalSet;
use Tests\Support\HubTestCase;

/**
 * This plan adds observation only — a run ledger, a diff tool, a metrics
 * collector, alarms. It changes no tier, no threshold and no binding rule, so the
 * gate must be identical. A movement means an observability change reached into
 * resolution, and the only plausible route is Task 2's Engine wiring.
 */
class EvalGateUnmovedByObservabilityTest extends HubTestCase
{
    public function test_observability_does_not_change_who_matches_whom(): void
    {
        $set = EvalSet::load(base_path('tests/eval/identity-pairs.json'));
        $report = (new EvalRunner($this->systemId))->run($set)['report'];

        $message = sprintf(
            'precision %.4f recall %.4f — %d false merge(s), %d false split(s), %d true pairs',
            $report['precision'], $report['recall'],
            $report['false_merges'], $report['false_splits'], $report['true_pairs'],
        );

        $this->assertSame(0, $report['false_merges'], "false merges are never acceptable — $message");
        $this->assertSame(1.0, $report['precision'], "precision must stay perfect — $message");
        $this->assertGreaterThanOrEqual(9, $report['true_pairs'], "the eval set shrank — $message");
    }

    public function test_the_eval_run_did_not_open_a_run_ledger_entry(): void
    {
        // EvalRunner calls resolve() directly and never goes through Engine::sync(),
        // which is why the ledger wiring cannot affect the gate. Pinning it means a
        // future refactor that routes EvalRunner through Engine gets caught here
        // rather than silently coupling measurement to the gate.
        $set = EvalSet::load(base_path('tests/eval/identity-pairs.json'));
        (new EvalRunner($this->systemId))->run($set);

        $this->assertSame(0, (int) $this->hub()->table('gp_run')->count());
    }
}
```

- [ ] **Step 2: Run it**

Run: `vendor/bin/phpunit tests/Feature/EvalGateUnmovedByObservabilityTest.php`

Expected: PASS, 2 tests. If the first fails, the `Engine::sync()` refactor in Task 2 changed
resolution — most likely `syncInner()` dropped a statement during the rename. Diff it against the
original `sync()` body before going further.

- [ ] **Step 3: Write `docs/MONITORING.md`**

Create `docs/MONITORING.md`:

```markdown
# What is measured, what is watched, and what is not

## Commands

| Command | Purpose |
|---|---|
| `php artisan gp:metrics [--persist]` | Compute the Success Metrics targets; `--persist` appends a reading to `gp_metric` |
| `php artisan gp:alarms [--quiet-when-clear]` | Evaluate every alarm; **exits non-zero if any fires** |
| `php artisan gp:run-diff --save=PATH` then `--against=PATH` | Before/after clustering comparison across two code versions |

## The run-diff workflow

The Delivery Checklist requires a before/after run-diff on every matching change.

```bash
git checkout <before>
php artisan gp:run-diff --save=storage/app/before.json
git checkout <after>
php artisan gp:run-diff --against=storage/app/before.json --fail-on-change
```

Both runs stage the **same** deterministic corpus (same `--seed`), so the only
variable is the code. The snapshot maps each source row to its cluster signature
rather than its `identity_id`, because a fresh resolve renumbers identities and an
id comparison would report every row as moved.

This is **not** a substitute for the eval gate and not substituted by it. The eval
set is 17 labeled records and proves *correctness* on cases somebody wrote down.
The run-diff corpus is thousands of unlabeled records and proves *stability* — it
answers "did this change move anybody", which needs no answer key. Run both.

`gp:run-diff` refuses any database whose name does not start with `gp_` and contain
`test`, because it stages and truncates.

## Alarms

| Alarm | Fires when | Notes |
|---|---|---|
| `stale_source` | No successful run in 26 hours | The 24h exclusion-freshness target plus 2h slack, so a late daily schedule does not page |
| `run_failed` | The most recent run failed | A failure followed by a success is history, not an incident |
| `row_count_collapse` | Latest run read <25% of the recent median | Compared against the median, not zero: `gp:sync` is incremental, so a small run is normal |
| `quarantine_growth` | Any row in `gp_quarantine` | Unarmed until plan 5 creates that table |
| `missed_exclusion` | **never, currently** | See below |

**`missed_exclusion` is unarmed and says so.** It is the checklist's zero-tolerance
case, and it cannot fire today: `has_active_exclusion`'s formula is correct but
`link_state` never leaves `candidate` until plan 6 wires transitions, so the alarm
would be watching a constant. It reports `fired = false` **with a `blocked_by`
reason**. Do not read that as coverage.

There is also a deeper limit worth stating: a *missed* exclusion is by definition
one the hub does not know about, so no query over the hub can count it. Only a
reconciliation against the source registry can. `gp:metrics` reports
`missed_exclusions` as `unmeasurable` for that reason and offers the undecided-link
count as a labelled proxy.

## Alert delivery

Detection and delivery are separate on purpose. `gp:alarms` exits non-zero and logs
at `error`; that is enough for any scheduler, CI job or monitoring agent.

`GP_ALARM_WEBHOOK`, if set, is POSTed to. It is **empty in `.env.example` and must
stay empty** — this repository is public, and a destination is a secret.

## Metrics that cannot pass, and why

Two of the eight Success Metrics targets are reported as failing or unmeasurable by
decision rather than by defect. An implementation that omitted them would be
misleading.

**`rebuild_reproducible`** — *"Can we always rebuild the golden layer from scratch?
Yes, 100% of the time."* Plan 2 removes `ssn_hash` and **retains** the merges it
made rather than un-merging them, because un-merging would manufacture false
splits — the costlier error. Its own Architecture section names the forfeit. So a
rebuild can no longer reproduce those merges and this target is permanently unmet
once plan 2 lands. It is the visible face of the Delivery Checklist forbidding the
key (§1) that its own rebuild requirement (§4) depends on; **only the doc can
resolve that.**

**`match_accuracy` / `match_completeness`** — *"checked against verified data"*.
There is no verified answer key at production scale. The eval set is 17 synthetic
records; reporting its precision as the programme's match accuracy would misstate a
compliance-critical number. Reported `unmeasurable` until internal verified data
supplies a labeled sample.

## Deliberate divergences from the Data Model

**`ingest_manifest` → `gp_run`, adapted.** `s3_prefix` and `checksum_sha256` are
dropped: gp-cami reads one live table by chunked keyset scan, so there is no object
and nothing immutable to hash. A `checksum_sha256` holding anything else would let a
reader believe load integrity is verified when it is not. `rows_read` is the honest
equivalent, and `rows_staged` alongside it makes a divergence between them visible.

**`schema_version` is dropped, and that one is a real gap.** The Risks table lists
"a source changes its file format without warning" as a live risk and gp-cami reads
a schema it does not own. The honest mechanism is a column-set assertion at ingest,
not a version string. **Unowned — no plan builds it.**

**`data_lineage` is not built.** Its five stages (`INGEST|STANDARDIZE|RESOLVE|SURVIVE|PUBLISH`)
do not match gp-cami's three: `Engine::sync()` stages and resolves in one chunked
pass, and survivorship and publish both happen in `finalizeAll()`. A row per stage
per source row would be ~53M rows on a full backfill to describe a pipeline shape
that does not exist. `gp_run` records the run; `gp_resolution_log` and (once plan 9
lands) `gp_edge` record what happened to an individual row.

## Not measured here

The two service targets that need request/run timing rather than hub state:

- **API single-provider lookup under 300ms.** Needs middleware. Left to plan 10,
  which is changing that request path anyway. Note `config/golden_profile.php`
  already carries measured evidence the target is breached by orders of magnitude on
  the tail: **8.9 seconds** for identity 59 at `link_chunk_size` 1000, and 360.7s at
  5000. When it is measured, report a distribution — a p50 that passes while the p99
  takes nine seconds is the shape this endpoint has, and a mean would hide it.
- **Full rebuild under 6 hours.** `gp_run`'s `finalize` mode exists in the enum for
  this and is deliberately unwired: `finalizeAll()` is not a source load and has no
  watermark, so instrumenting it is a small separate change.
```

- [ ] **Step 4: Run the full suite and commit**

Run: `vendor/bin/pint --test && vendor/bin/phpunit --fail-on-skipped`

Expected: both pass, 0 skipped.

```bash
git add docs/MONITORING.md tests/Feature/EvalGateUnmovedByObservabilityTest.php
git commit -m "docs(observe): document what is measured, what is unarmed, and why"
```

---

## Self-review

**Spec coverage.** `00-CONFORMANCE.md` Theme 3 has four findings. No monitoring or alarms → Tasks 1,
6. No run-diff → Tasks 3, 4. No Success Metrics measured → Task 5. `ingest_manifest`/`data_lineage`
absent → Task 1, adapted, with the divergences documented in Task 7. Audit C's "no
steward-decision → label loop" is **plan 9's** Task 6, not this plan's.

**Placeholders.** None. Every code-changing step carries complete code. Task 4 Step 4 has a tunable
(the 20–200 identity bounds for a 200-row corpus) with an explicit instruction to adjust the
collision rates and say so — a real calibration step, not a TBD.

**Type consistency.** `RunLedger::open(int,string,string,?string): int` is called by `Engine::sync()`
and `backfill()`; `advance(int,int,int)`, `close(int,?string)`, `fail(int,Throwable)` and
`lastSuccessful(int,string): ?object` are consumed by Tasks 5 and 6. `RunDiff::snapshot(int): array`
and the static `compare(array,array): array` / `summarise(array): string` are consumed by Task 4.
`MetricsCollector::collect(): array` and `persist(array): int` are consumed by `GpMetrics`.
`Alarms::evaluate(): array` and `record(array): void` are consumed by `GpAlarms`. `MetricsCollector`
and `Alarms` both return `detail` as an array and both callers `json_encode` it at the boundary.

**What this plan deliberately does not build.**

- **API latency measurement.** Needs middleware on a request path plan 10 is rewriting. Documented,
  with the existing evidence that the target is already breached.
- **`schema_version` / source-shape drift detection.** A named gap with no owner, recorded in
  `docs/MONITORING.md` rather than quietly dropped. It is a Risks-table item.
- **`data_lineage`.** Argued in Task 7 rather than skipped.
- **The other four quality-gate rule classes** (valid / complete / unique / consistent). Plan 5 owns
  the quarantine and the junk dictionary; this plan adds the *alerting* half and the row-count gate.
  Extending the rule set means extending plan 5's `JunkKeyGuard`, which is its file, not this one's.

**Known risks carried into execution.**

1. **Task 2 is the only task that touches a hot path.** Renaming `sync()`'s body to `syncInner()` and
   bracketing it is mechanical, and it is also the one place this plan could break resolution. The
   `advance()` call goes inside the existing `chunkById` closure — if it lands outside, the counts are
   wrong but nothing fails loudly. Task 7's second test exists to catch the worse version of this
   mistake (coupling `EvalRunner` to `Engine`).
2. **`row_count_collapse`'s thresholds are unvalidated against production.** 25% of a three-run
   median is a guess, because no production run history exists — `gp_run` starts empty. Expect to tune
   it after a fortnight of real runs, and prefer widening the norm window over lowering the fraction.
3. **`stale_source` assumes one source.** gp-cami has exactly one, so exclusion and identity freshness
   collapse into the same measurement, and `MetricsCollector::freshness()` says so. They diverge only
   if separate feeds arrive, which §8 puts out of scope — but the alarm would then silently report the
   freshest source rather than the stalest. Make it per-source at that point, not before.
4. **`gp_metric` grows unbounded.** One row per metric per `--persist`. Eight rows a run is nothing
   at any plausible cadence, but nothing prunes it and no plan owns retention.
