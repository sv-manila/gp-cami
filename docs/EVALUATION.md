# Evaluating match quality

## Baseline — before the GPP conformance programme

**Not yet measured.** Run `scripts/baseline-key-mix.sql` against the `golden_profile` hub and fill the table below.

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
| Filler hashes on the blocklist | PENDING |

**Reading this:** the two bold rows size what Plan 2 (SSN removal) will fragment.
Every identity in "bound only by `ssn_hash`" loses its binding evidence and splits
into one identity per source row unless another key covers it.

**Status: outstanding.** Nobody has run `scripts/baseline-key-mix.sql` yet — it
needs production hub credentials that are not available in this environment.
**Plan 2 is blocked until this table is filled in**, because Plan 2 sizes the
blast radius of removing the `ssn_hash` tier from these numbers; without them
there is no way to know how many identities the removal will fragment.

## Achieved — measured on this branch

These are **local eval-fixture** measurements from `gp:eval` / `EvalGateTest`
against the scratch hub. They are NOT production-hub measurements and say
nothing about the still-`PENDING` table above; the one number in plan 5 that
does need a real hub is `gp:npi-audit`'s (see below).

| Metric | Plan 1 (`bd80f55`) | Plan 5 (match keys & data quality) |
|---|---|---|
| Precision | 1.0000 | **1.0000** |
| Recall | 1.0000 | **1.0000** |
| F1 | 1.0000 | **1.0000** |
| False merges | 0 | **0** |
| False splits | 0 | **0** |
| True pairs | 9 | **11** |
| Records | 17 | **23** |
| Clusters | 10 | **14** |

**Why `true_pairs` moved 9 → 11, and why that is not a relaxation.** Plan 5
promotes DEA and (state, MMIS) to real match keys, so the fixture gained two
merge pairs that exercise them — `mmis-a`/`mmis-b` and `dea-a`/`dea-b` — plus
two foils that must NOT merge (`mmis-other-state`, carrying the same MMIS
number in a different state, and `dea-other`, same name and a different DEA
number). The ratchet was raised to 11 in the same commit that added the
records. Precision stayed at 1.0000 rather than merely clearing its 0.99
floor, and `false_merges` stayed at 0, which is what the two foils are there to
prove.

`EvalGateTest` ratchets on these numbers (`false_splits === 0`,
`recall === 1.0`) in addition to the pre-existing floors, so a regression against
this baseline fails the build even though it would still clear the floors. **Plan
2 must re-baseline these two ratchet assertions in the same PR that removes the
`ssn_hash` tier**, stating the new measured numbers in the PR description. Under
the canonical order (`00-PROGRAMME.md` §2) plan 2 sees `true_pairs` 11, so its
own stated `recall → 0.8889 (8/9)` becomes **0.9091 (10/11)**.

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

**Plan 2 will move this floor.** The `ssn-a`/`ssn-b` pair is bound by the
`ssn_hash` tier. When that tier is removed, they become a false split and recall
drops by a known, measured amount. Re-baseline the floor in that PR and say so in
the description — do not delete the records to keep the number up.
