# Evaluating match quality

## Baseline — before the GPP conformance programme

**Not yet measured.** `scripts/baseline-key-mix.sql` has six queries; run it against
the `golden_profile` hub and fill the table below. Every query has been verified to
parse and execute against the scratch schema, which returns zeros — that is the only
verification available here, because nobody in this environment has production hub
credentials.

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
| **Projected extra identities after a rebuild** | PENDING |
| Filler hashes on the blocklist | PENDING |

**Reading this:** the three bold rows size what plan 2 costs. Plan 2 **retains** every
existing `gp_source_link` row, so nothing fragments when the tier is removed —
`DeterministicResolver::resolve()` returns an existing link's identity before it
consults any tier, so `gp:sync` keeps producing today's clustering indefinitely. The
cost is deferred to the next from-scratch rebuild, which will produce "projected extra
identities" more identities than the hub holds now. That number, not the link count, is
the one to act on.

## Plan 2 rollout decision

`P` = projected extra identities ÷ active identities.

| `P` | Decision |
|---|---|
| < 0.5% | Proceed. Fragmentation on the next rebuild is noise; no sequencing change. |
| 0.5% – 5% | Proceed. Land plan 5's compensating keys (MMIS resolve-time tier, `(state, provider#)`, name+state blocking) **before the next full rebuild**, and schedule that rebuild after plan 5. |
| > 5% | Proceed, and treat the rebuild as gated: plan 5 becomes a prerequisite for it, and the programme order after plan 2 is re-cut to put plan 5 next. Say so at the design review. |

**Every row of that table says "proceed."** This is a compliance decision, not a
performance one — the Delivery Checklist §1 requirement does not become conditional on
a row count, and the removal is not up for renegotiation. What `P` decides is *when the
next full rebuild happens* and *what must land first*, because a rebuild is the only
event at which the deferred fragmentation is actually paid.

Two corrections to what this section used to say, both of which mattered:

- It said "**Plan 2 is blocked until this table is filled in**". That was wrong. The
  *code* is not blocked — tasks 2 to 7 and 9 are local and tested, and land regardless.
  The *hub rollout* is what the number gates: running Task 7's column drop and Task 8's
  provenance script against the shared hub without knowing the projection means not
  knowing what the next rebuild produces.
- The old table had no way to express the cost, only the count of affected identities.
  Query 6 supplies it: `projected_extra_identities` is the source rows those identities
  hold minus the identities themselves, so a single-row identity bound only by
  `ssn_hash` contributes 0 — it was already effectively a singleton.

**If the measurement is still outstanding when the code is ready to merge** — which is
the state as of this commit — merge the code and the migration, and hold **Task 8's
provenance script**, the only step whose output depends on the number. Record the date
the script was handed over and to whom. As of 2026-09-07 it has not been handed over;
there is nobody in this environment to hand it to.

## Achieved — measured on this branch

These are **local eval-fixture** measurements from `gp:eval` / `EvalGateTest`
against the scratch hub. They are NOT production-hub measurements and say
nothing about the still-`PENDING` table above; the one number in plan 5 that
does need a real hub is `gp:npi-audit`'s (see below).

| Metric | Plan 1 (`bd80f55`) | Plan 5 (match keys & data quality) | Plan 2 (SSN removal) |
|---|---|---|---|
| Precision | 1.0000 | 1.0000 | **1.0000** |
| Recall | 1.0000 | 1.0000 | **0.9091** (10/11) |
| F1 | 1.0000 | 1.0000 | **0.9524** (20/21) |
| False merges | 0 | 0 | **0** |
| False splits | 0 | 0 | **1** |
| True pairs | 9 | 11 | **11** |
| True positives | 9 | 11 | **10** |
| Records | 17 | 23 | **23** |
| Clusters | 10 | 14 | **14** |

**Why `true_pairs` moved 9 → 11, and why that is not a relaxation.** Plan 5
promotes DEA and (state, MMIS) to real match keys, so the fixture gained two
merge pairs that exercise them — `mmis-a`/`mmis-b` and `dea-a`/`dea-b` — plus
two foils that must NOT merge (`mmis-other-state`, carrying the same MMIS
number in a different state, and `dea-other`, same name and a different DEA
number). The ratchet was raised to 11 in the same commit that added the
records. Precision stayed at 1.0000 rather than merely clearing its 0.99
floor, and `false_merges` stayed at 0, which is what the two foils are there to
prove.

**Why recall moved 1.0000 → 0.9091, and why that is correct.** The `ssn_hash`
tier was the only evidence binding `ssn-a` to `ssn-b`. Delivery Checklist §1
requires that the hub never store an SSN and the GPP Data Model has no SSN column
in the golden layer, so the tier was removed from both ladders and from
`Engine::dedup()`. That pair is now a **known, accepted false split** and stays in
the fixture permanently. Precision is unaffected — the tier only ever produced
correct merges, so nothing it used to do was wrong; the hub simply is not allowed
to do it. `00-PROGRAMME.md` §4 calls precision 1.0000 and `false_merges` 0
absolute at every step, and both are met exactly here, not merely above their
floors.

`EvalGateTest` now ratchets on `false_splits === 1` and `true_positives === 10` —
integer counts, because 10/11 has no exact decimal form and a float ratchet
invites a widened tolerance later. It additionally asserts *which* pair may be
split, so a future change cannot trade this split for a different one at the same
count. The floors (precision ≥ 0.99, recall ≥ 0.80) are untouched and were never
at risk.

**Both ladders were re-baselined in the same commit.** `EvalGateBothPathsTest`
carries the identical assertions against the set-based path — that test exists to
prove the two implementations of the ladder agree, so re-baselining one and not
the other would have turned a deliberate change into what looked like a parity
regression.

**The `ssn-*` records must not be deleted, and the answer key must not be re-cut
to separate them.** Either move would return the report to a perfect score for a
strictly worse matcher. `true_pairs >= 11` catches the first;
`EvalSetShapeTest::test_the_retired_ssn_pair_is_still_one_truth_cluster` catches
the second.

**Plan 5 already supplied the compensating keys, before this task ran.** MMIS as
a resolve-time tier and DEA promoted alongside it are why this baseline starts at
11 true pairs rather than 9 — not because either one recovers *this* pair.
Nothing but `ssn_hash` ever bound `ssn-a` to `ssn-b`, by fixture design, so no key
plan 5 adds recovers this specific split; the recall floor recovers overall,
across the fixture, not for this pair.

## SCD-2 versioning (plan 3a) — the gate did not move

| Metric | Before | After |
|---|---|---|
| Precision | 1.0000 | 1.0000 |
| Recall | 1.0000 | 1.0000 |
| F1 | 1.0000 | 1.0000 |
| False merges | 0 | 0 |
| False splits | 0 | 0 |
| True pairs | 11 | 11 |

**11, not the 9 plan 3a's own text predicts.** That plan was written against plan
1's baseline, and under the canonical order (`00-PROGRAMME.md` §2) plan 5 runs
first and raises `true_pairs` to 11. The identity of the before and after columns
is the assertion; the value they share comes from whatever ran before.

**That identity is the deliverable, not a footnote.** Plan 3a changed every write
path in the hub from overwrite to insert-new-version, and added a `current = 1`
filter to every read of a versioned table. None of that is supposed to alter which
records group together — the grouping is `gp_source_link`, and no threshold, weight
or tier moved. A gate that shifted in either direction would mean a read filter is
wrong:

- a false merge means a tier or Pass B query lost `current = 1` and matched a
  superseded version;
- a false split means a query kept `current = 1` but lost `status = 'active'`.

Both directions were observed during execution, which is why the gate is worth
running after every task rather than once at the end:

| What happened | How the gate showed it |
|---|---|
| `Versioner::only()` silently dropped the undeclared `state` column, so MMIS identifiers were written with a NULL state and the state-scoped tier stopped matching | recall fell to 0.9091 with 1 false split |
| Pass B's candidate query offered a merged identity through its own superseded (still `active`) version | caught by a test, not the gate — the plan's own fixture could not reach the review band, so the gate never saw it |

The ratchet assertions in `EvalGateTest` are therefore **unchanged** by this plan.
Plan 2, which deliberately removes the `ssn_hash` tier, is the one that has to
re-baseline them.
### Still needs a real hub

| Needed | How | Gates |
|---|---|---|
| Active identities whose `npi` fails the Luhn+80840 check digit, and the `gp_source_link` rows bound to them via the `npi` tier | `php artisan gp:npi-audit` (read-only) | Whether NPI validation can be enforced **retroactively**. Plan 5 validates at ingestion only; unbinding an identity a prior load already merged on a now-invalid NPI would split it, and the eval fixture cannot size that either way |

## Running the evaluation

```bash
vendor/bin/phpunit tests/Feature/EvalGateTest.php   # the CI gate
php artisan gp:eval                                 # against a scratch hub
```

`gp:eval` is scratch-only. Before touching any table it checks the *name* of the
database `GP_DB_DATABASE` — the connection itself — points at: the name must
start with `gp_` and contain `test` (e.g. `gp_cami_test`), the same rule
`HubTestCase::guardAgainstTheRealHub()` uses for the test suite. Point it at a
scratch schema and migrate it before running. A name that fails this check is
refused immediately, before the emptiness probes or any write — a freshly
provisioned (and therefore empty) production hub no longer passes just because
it happens to be empty. The command also runs its whole staging-and-resolve pass
inside a transaction it always rolls back, so a run leaves no residue and is
safe to repeat. Do not try to redirect it with `golden_profile.connections.hub`:
`DeterministicResolver` hardcodes the `golden_profile` connection and ignores
that key, so a mismatch would write identities into the real hub regardless of
what `GP_DB_DATABASE` says.

The database-backed tests use a different mechanism again — `GP_TEST_DB_*`,
which `HubTestCase` reads. Unset, they skip.

## Adding labeled pairs

Edit `tests/eval/identity-pairs.json`. Two rules the loader enforces:

1. Every `ref` appears in exactly one `truth` cluster. A singleton is a
   one-element cluster — not optional, because singletons detect false merges.
2. No duplicate refs, and no truth entry naming an unknown record.

Add the case that broke, not a case like it. When a production mis-match is
found, add its shape here first, watch the gate fail, then fix the matcher.

## Reading the report

| Metric | Meaning | Who it hurts |
|---|---|---|
| `false_merges` | Two real providers welded into one identity | Precision. Gated at 0 — nothing downstream undoes a merge |
| `false_splits` | One provider left as two identities | Recall. This is how an excluded provider gets missed |

Floors are `--min-precision=0.99` and `--min-recall=0.80`. The recall floor sits
below the PROJECT_PLAN target of 0.95 because Pass B cannot auto-merge today: its
implemented weights sum to exactly `auto_merge_at`. Raise the floor as
calibration lands. Never lower it to make a build pass.

**Plan 2 moved this baseline, not the floor.** The `ssn-a`/`ssn-b` pair was bound
by the `ssn_hash` tier; when the tier was removed the pair became a false split
and recall fell to a known, measured 10/11 (against plan 5's already-raised
11-pair fixture — see `00-PROGRAMME.md` §2 and §4). The floors were never
lowered: the two measured ratchets were re-baselined onto exact integer counts
and the records stayed in the fixture. That is the pattern for any future
capability removal — re-baseline the ratchet, keep the evidence, say so in the PR
description.
