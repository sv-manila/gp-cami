# GPP Conformance Programme — session handoff

Written 2026-09-04. Read this first, then `plans/00-PROGRAMME.md`, then `plans/00-CONFORMANCE.md`.

Everything needed to continue is committed to this repo. Nothing depends on a previous session's
memory.

---

## 1. Where things stand in one paragraph

`gp-cami` is being brought into conformance with the data-science team's Confluence design set. Two
scope decisions were made by the project owner and are **not open**: conformance stays inside the
Laravel/MySQL hub (no lakehouse, no ML matcher, no external government feeds), and where the code
and the docs contradict each other on SSN storage and SCD-2 versioning, **the code changes to match
the docs**. Plan 1 is built and green. Twelve further plan documents are written and committed.
Two more are specified but not written. Nothing has been pushed.

## 2. Branch state

```
branch  feat/eval-harness   (28 commits ahead of master @ 17d383d)
suite   95 tests, 270 assertions, 0 skipped
style   vendor/bin/pint --test clean
tree    clean
remote  NOT PUSHED — no PR opened
```

Verify with:

```bash
cd /c/projects/dramiel/gp-cami
git log --oneline 17d383d..HEAD | wc -l      # expect 28
vendor/bin/phpunit --fail-on-skipped         # expect 95/95
```

## 3. What is built (plan 1, executed)

The measurement foundation, which every later plan depends on:

| | |
|---|---|
| `tests/Support/HubTestCase.php` | Scratch-MySQL hub harness. Migrate-once, per-test rollback **plus an unconditional row sweep** |
| `tests/eval/identity-pairs.json` | Labeled answer key — 17 records, 10 clusters, 9 true pairs |
| `app/GoldenProfile/Eval/` | `EvalSet` · `MatchScorer` · `EvalRunner` |
| `app/Console/Commands/GpEval.php` | `php artisan gp:eval` |
| `.github/workflows/ci.yml` | Pint + `phpunit --fail-on-skipped`, MySQL service |
| `scripts/baseline-key-mix.sql` | Read-only production measurement — **still unrun, see §7** |

Measured gate: **precision 1.0000 · recall 1.0000 · F1 1.0000 · 0 false merges · 0 false splits**,
and it *ratchets* on those numbers rather than sitting below them.

Five defects were found and fixed while building it, all with tests pinning them: the `migrate:fresh`
guard that accepted the real `streamline_test` schema; the harness transaction that wasn't isolation
(MySQL implicitly commits on DDL and Laravel swallows the resulting rollback failure); the scorer's
pair-key collision; the fixture's `sha256("")` SSN hash; and the SSN key resolution, now replicated
from CAMI inline — which removed the last private composer dependency and with it the need for a CI
secret.

## 4. Environment — do not re-derive these

- **`composer install` needs no credentials.** All 78 production packages are public packagist. The
  last private one, `streamlineverify/security`, was removed in `e64f73d`. **CI needs no
  `COMPOSER_AUTH` secret.**
- **`streamlineverify/sv` is not a dependency** — declared as a repository, never required, never
  imported.
- **MySQL for tests: `192.168.56.22`, root/root, schema `gp_cami_test`.** A VirtualBox VM; boot with
  `vagrant up` in `C:\projects\dramiel\client`. The hostname `dramiel.app.streamlineverify.local`
  does **not** resolve — use the IP. The same server also hosts `streamline_local`,
  `streamline_test`, `streamline_integration` and `admin_dash_sb`; **`gp_cami_test` is the only safe
  write target.**
- **`mysql` CLI is not on PATH.** Use PHP PDO for ad-hoc SQL.
- **No production hub access.** Anything needing it is a human task.
- **Never propose SQLite for a test harness.** The schema reuses index names across tables
  (`idx_identity` is on eight of them); MySQL scopes index names per table, SQLite per database, so
  `migrate` dies at the second `gp_*` table. Plan 1 specified SQLite and was dead on arrival.
- `sv-manila/gp-cami` is a **public** repository.

## 5. The documents, and which is authoritative

Committed under `docs/superpowers/`:

| File | What it is |
|---|---|
| `HANDOFF.md` | this file |
| `AUTHORING-BRIEF.md` | verified repo state, conventions, the Global Constraints block to copy verbatim, the traps. **Read before writing any new plan.** |
| `plans/00-PROGRAMME.md` | **the tiebreak.** Execution order, migration timestamps, the eval ladder, shared-component ownership, open design decisions, four live bugs |
| `plans/00-CONFORMANCE.md` | the audit against the DS docs, with a ranked action list |
| `compliance-{A,B,C}-*.md` | the three per-requirement audit tables, with quotes and citations |
| `plans/2026-09-03-*.md` (10 files) | plans 2, 3a, 3b, 4, 5, 5b, 6, 7, 8 and 1 |
| `plans/2026-09-04-gpp-conformance-explainability-and-reversibility.md` | plan 9 |
| `plans/2026-09-04-gpp-conformance-measurement-and-monitoring.md` | plan 11 |

**Where a plan disagrees with `00-PROGRAMME.md`, the programme file wins.**

Still gitignored and therefore local-only (`.gitignore:33` ignores `/.superpowers`): per-task briefs
and reports, review diff packages, and the session ledger `progress.md`. None is required to
continue — they are the audit trail of how the above was produced.

## 6. Canonical execution order

```
1 (done) → 5 → 3a → 3b → 2 → 5b → 4 → 6 → 7 → 8 → (9, 11, 10, 12)
```

Two constraints that are not obvious and are argued in `00-PROGRAMME.md` §2:

- **5 before 2.** Plan 2 deletes `ssn_hash`, the strongest deterministic key; plan 5 supplies the
  compensating ones. Plan 2's own blast-radius table makes plan 5 a prerequisite above 5% ssn-only
  binding, and that number is unmeasured, so 5-first is safe under either outcome.
- **3a and 3b before 2.** Plan 2's parity test drives DDL-issuing methods under `HubTestCase` and
  needs 3b's harness task; and 3a ships a `SetBasedPathGuard` that makes the bulk paths refuse to run
  until 3b lands.

## 7. What remains, in priority order

### Not written — two plans

| # | Plan | Why it is next / why it can wait |
|---|---|---|
| 10 | Credential cache | Hinges on an open decision (§8) that could make most of it moot. |
| 12 | Bi-temporal validity | Hinges on whether the source carries business-validity dates at all. Plan 7 already investigated exclusions and deferred with cause; licences are unknown. |

Each new plan takes the next free migration prefix: plan 10 → `2026_09_11_*`, plan 12 →
`2026_09_13_*`. Plan 9 took `2026_09_10_*` and plan 11 took `2026_09_12_*`.

### Not applied — four conformance edits to existing plans

From `00-CONFORMANCE.md`. All surgical; do **not** read those 70–320 KB files end to end, grep for
the spot.

1. **Plan 6** declines `split` because it has "no CAMI-side signal" — true and beside the point, since
   a split reverses a merge *gp-cami* made. Rewrite to give the honest cause (no identity-mutation
   endpoint) and hand `split` to **plan 9**, which now builds it.
2. **Plan 7**: `excl_specialty` is missing entirely — add it, built or deferred with a reason. And
   separate `excl_type` and `waiver_state` from the date deferral, whose stated cause cannot apply to
   non-dates; plan 7's own sample records `wvrstate` as present and unambiguous on LEIE.
3. **Plan 4**: maiden names have no golden home. SCD-2 gives one current name and `maiden` is a
   *concurrent* variant. `stg_person_alias.alias_type` has a `maiden` member;
   `gp_identity_alias.alias_part` does not.
4. **All plans**: their Programme-context tables list eight or ten documents. The programme is
   fourteen. Add rows for 9–12.

## 8. Decisions waiting on the project owner

None of these should be made by a plan.

1. **Run `scripts/baseline-key-mix.sql` query 3.** One query. It fills `docs/EVALUATION.md`'s
   all-`PENDING` baseline table and settles whether 5-before-2 is necessary or merely prudent. **Add a
   `match_key IS NOT NULL` guard first** — `SUM(match_key <> 'ssn_hash') = 0` silently ignores NULL
   rows, so an identity with an `ssn_hash` link plus a NULL-key link is miscounted as ssn-only.
2. **`gp_board_action`'s shape.** The docs specify a separate append-only table; the source delivers
   board actions as `exclusion_lists.type = 'board_action'`, already flowing into
   `gp_identity_exclusion`. Spans plans 6 and 7. `00-PROGRAMME.md` §6.
3. **Whether the credential cache should be self-sufficient.** It currently queries
   `streamline_local` on every request because `gp_identity_credential` lacks `expiry_date`,
   `check_date`, search params and the raw `match`. Either mirror them (conforms; costs a backfill and
   a staleness window) or reclassify it as an index over the source and amend the doc. It is currently
   neither. **This gates plan 10.**
4. **Pass B recalibration.** Verified: a record with no DOB caps at **0.72** against a
   `review_band_floor` of 0.75, so it can never reach even the review band; and `blockKey()` returns
   null on a blank surname, so organizations never enter Pass B at all. Plans 5b and 6 deliver less
   than their scope implies until the weights move.
5. **A documentation contradiction no plan can fix.** The Delivery Checklist forbids storing SSN (§1)
   and requires proving full rebuild-from-scratch works (§4). Plan 2 establishes that removing
   `ssn_hash` makes the loss of rebuild reproducibility permanent. Once code-follows-docs is applied,
   the checklist contradicts itself. **The doc must be amended.**
6. **Push and open the PR.** Nothing is pushed. CI needs no secret.

## 9. Live bugs found while planning — fixable independently of any plan

Full detail in `00-PROGRAMME.md` §9. All verified against the running code or the live database.

1. **`ProbabilisticResolver::sharesExclusionRegistry()` ignores its `$p` argument**, so the
   `exclusion_share` weight fires whenever the *candidate* carries any exclusion. It is named "shares"
   and tests nothing of the kind. **Plan 9 raises this from latent to actively misleading**, because
   it writes that contribution into `gp_edge.detail` where a steward will read "exclusion_share fired"
   and believe a shared registry was found.
2. **Set-based `ON DUPLICATE KEY UPDATE` duplicates rows when a unique-key part is NULL.** Measured:
   three identical inserts into `gp_license` with a NULL `certification_state` produce **3 rows**;
   with non-null values, **1**. `gp_address`'s `uq_addr` has **four** nullable parts. So
   `license_count` and `address_count` are inflated in any hub backfilled more than once.
3. **`Engine::mergeIdentity()`'s version probe counts a refusal as a merge** — a null lookup casts to
   `0`, so `dedup()`'s loop can spin. Unreachable today; the guard sits upstream of the probe.
4. **`gp_edge` and `gp_identity.status = 'split'` are both dead** — no writer anywhere. Plan 9 fixes
   both.

## 10. Working rules that produced this and should continue

- **Verify, don't infer.** Nearly every valuable finding here came from reading the code or querying
  the live database rather than reasoning from the docs. Several of my own confident claims were
  wrong: the fixture NPIs were not check-digit valid, there is no business-name match path, MySQL's
  `SOUNDEX()` does not truncate like PHP's, and Laravel *swallows* the dead-transaction error rather
  than rethrowing it. A test proved that last one against me.
- **Defer with evidence, never stub.** Plans 5 and 7 both shrank their own scope after investigating
  the source, and the audit credited both. A column nothing can populate is worse than an honest gap.
- **Never relax the eval gate.** Never lower a floor, delete an assertion, or remove a fixture record
  to keep a number up. When a change legitimately moves a number, re-baseline the ratchet **in the
  same commit** and state the new value and the reason. `MatchScorer::score([], [])` returns a perfect
  score, which is why the `true_pairs >= N` assertion exists.
- **`vendor/bin/pint --dirty`, never bare `pint`** — the bare form reformats the whole repo, and the
  repo was never Pint-clean before this branch (12 pre-existing files), which is why a separate
  `style:` commit exists.
- **Write plans sequentially, one worker.** Parallel authoring produced five mechanical collisions
  that took a whole reconciliation pass to undo, and agents spawning their own subagents made the
  token burn steep.
