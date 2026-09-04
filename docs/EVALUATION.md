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

## Running the evaluation

```bash
vendor/bin/phpunit tests/Feature/EvalGateTest.php   # the CI gate
php artisan gp:eval                                 # against a scratch hub
```

`gp:eval` refuses to run against a hub that already holds staged people or
identities. To use it, point **`GP_DB_DATABASE`** — the connection itself — at an
empty scratch schema and migrate it. Do not try to redirect it with
`golden_profile.connections.hub`: `DeterministicResolver` hardcodes the
`golden_profile` connection and ignores that key, so a mismatch would write
identities into the real hub.

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
