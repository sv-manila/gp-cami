# Execution kickoff prompt

Paste the block below into a fresh session in `C:\projects\dramiel\gp-cami`. It starts lane A at the
top of the canonical ladder. For lane B, use the variant at the bottom.

---

## Lane A — start the hub ladder (plan 5)

```
Execute the GPP conformance programme, starting at the top of the canonical order.

Read these first, in this order, before touching any code:
  1. docs/superpowers/HANDOFF.md
  2. docs/superpowers/plans/00-PROGRAMME.md          <- the tiebreak; it wins over any plan
  3. docs/superpowers/plans/2026-09-07-gpp-programme-extension-hub-and-dashboard.md
  4. docs/superpowers/AUTHORING-BRIEF.md
  5. docs/superpowers/plans/2026-09-03-gpp-conformance-match-keys-and-data-quality.md   <- plan 5, the work

Two owner decisions were settled 2026-09-07 and are recorded in the extension plan §7:
  - The dashboard (sv-manila/gp-cami-dashboard) IS the steward UI.
  - The credential cache IS to be self-sufficient.
Neither changes plan 5. Do not relitigate them.

STEP 0 — before any code. `feat/eval-harness` is 28 commits ahead of master@17d383d and has never
been pushed; plan 1 and all twelve plan documents exist only on this machine. Push it and open the
PR. CI needs no secret — every dependency is public packagist. Report the PR URL. If the push fails,
stop and tell me; do not start plan 5 with the work unbacked.

STEP 1 — execute plan 5 (match keys & data quality, 10 tasks) task by task using the
superpowers:executing-plans skill. Plan 5 is first in the canonical order for a reason argued in
00-PROGRAMME.md §2: it supplies the deterministic keys that plan 2 later removes with ssn_hash.

Non-negotiables, all verified and all previously violated by someone who assumed otherwise:
  - Migration prefix for plan 5 is 2026_09_05_* — the plan document and 00-PROGRAMME.md §3 already
    agree on this. The 2026_09_04_000000 in §3's table is its historical "Was" column (and is now
    plan 3a's prefix); do not "fix" the plan to match it.
  - The test harness is MySQL, never SQLite. The schema reuses index names across tables
    (idx_identity is on eight tables in the base schema), so migrate dies at the second gp_* table
    under SQLite.
    Extend Tests\Support\HubTestCase. Test DB: 192.168.56.22, root/root, schema gp_cami_test — the
    ONLY safe write target on that server. Boot it with `vagrant up` in C:\projects\dramiel\client.
    The hostname dramiel.app.streamlineverify.local does not resolve; use the IP. The mysql CLI is
    not on PATH — use PHP PDO for ad-hoc SQL.
  - The eval gate ratchets and is never relaxed. Never lower a floor, delete an assertion, or remove
    a fixture record to keep a number up. Plan 5 legitimately moves true_pairs 9 -> 11 (it adds an
    MMIS pair and a DEA pair); re-baseline in the SAME commit and state the new value and the reason.
    00-PROGRAMME.md §4 calls precision 1.0000 and false_merges 0 absolute at every step. The test is
    looser than the prose: EvalGateTest.php:34 pins false_merges with assertSame(0, ...) but line 35
    only asserts precision >= 0.99. Treat 1.0000 as the target, never lower the 0.99 floor, and if
    precision does fall below 1.0000 say so explicitly instead of letting the floor hide it.
    MatchScorer::score([], []) returns a perfect score, which is why true_pairs >= N exists.
  - Plan 5 Task 2 owns a real fix: the eval fixture's NPIs 1987654327 and 1112223339 fail the
    NPPES Luhn-over-80840 check. Correct values are 1987654328 and 1112223338.
  - Add AREALNULL to JunkKeyGuard's placeholder list — verified present in 22 of 84 local
    exclusion_records rows.
  - Style: `vendor/bin/pint --dirty` before every commit, never bare pint (it reformats the whole
    repo). 4-space indent, no declare(strict_types=1), docblocks that give the reason not the rule.
  - Tests are `public function test_snake_case(): void`. No PHPUnit attributes anywhere in tests/.
  - CI runs pint --test and phpunit --fail-on-skipped. A skipped test fails the build by design.
  - sv-manila/gp-cami is a PUBLIC repo. Never commit a key, token, credential, or real person's
    data. The eval fixture is synthetic and stays synthetic.
  - No production hub access exists here. Anything needing it is a human task — report it, do not
    fabricate the number. scripts/baseline-key-mix.sql query 3 is one of these.
  - Commit messages: `type(scope): imperative summary`.

Verify, don't infer. Most of what is valuable in these documents came from reading the code or
querying the live database rather than reasoning from the docs, and several confident claims in the
docs were wrong. If a plan step contradicts what the code actually does, check the code and say so.

Stop at plan 5's exit gate and report: the tasks done, the suite count, the eval numbers before and
after, and anything you found that the plan got wrong.
```

---

## Lane B — start the dashboard unlock (author H13)

Runs in parallel with lane A; it needs no hub table that does not already exist.

```
Author plan H13 — access control, PII masking and data contracts — for the GPP conformance
programme, then stop for review before any code.

Read first, in this order:
  1. docs/superpowers/AUTHORING-BRIEF.md            <- the plan shape and the Global Constraints
                                                       block to copy verbatim
  2. docs/superpowers/plans/00-PROGRAMME.md
  3. docs/superpowers/plans/2026-09-07-gpp-programme-extension-hub-and-dashboard.md   <- H13's row
                                                       in §4, and the D0/D1 rows in §5 it unblocks
  4. The wiki page it conforms to: "Keeping Data Accurate, Secure & Compliant"
     https://streamlineverify.atlassian.net/wiki/spaces/DEV/pages/4066607108

H13 is pulled forward to run parallel to the hub ladder because the dashboard is now the steward UI
(owner decision A, 2026-09-07) and H13 is the only thing blocking that whole second repository.
It needs no hub table that does not already exist, and no plan from 2 to 11 contains a GRANT, a
CREATE ROLE or a view — so roles, the access log and data contracts have nothing to wait for.

But the masked views ARE coupled, and the plan must say so. They cover DOB on gp_identity and the
address line on gp_address; both tables are in plan 3a's versioned register, so a view written
before 3a starts silently returning superseded versions once 3a lands. Plan 2 separately drops
gp_identity.ssn_hash and ssn_last_four, breaking any view enumerating them. Write the view
definitions current = 1-aware from the start and never enumerating plan 2's doomed columns; if that
cannot be done cleanly, split the view task out to land after plan 2 and ship the rest on schedule.

Scope, from the extension plan §4:
  - Four roles: ingest, steward, analyst, ml.
  - DOB and address line NULL for analyst/ml through a masked view.
  - Every read of an unmasked column writes an access-log row.
  - Each gp_source_system row carries a data contract (owner, freshness SLA, PII flag), and a
    contract violation fails the load.
  - Give `gpdash:index-advisor --apply` its own role. It issues CREATE INDEX DDL against the hub
    (gp-cami-dashboard/app/Console/Commands/GpDashIndexAdvisor.php:104) and must not ride on the
    dashboard's read credentials carrying INDEX privilege.
  - Migration prefix 2026_09_14_*. Verified free — zero occurrences repo-wide. 00-PROGRAMME.md §3
    assigns through 2026_09_09_000100 and HANDOFF.md §7 assigns 2026_09_10_* through 2026_09_13_*.
  - This plan adds NO matching behaviour. Assert the eval gate bit-for-bit unchanged. A movement
    means the plan touched something it should not have.

Same non-negotiables as lane A: MySQL harness never SQLite, gp_cami_test on 192.168.56.22 as the
only write target, pint --dirty, snake_case test methods, public repo so no secrets or real PII,
no production access.

One decision is open and you must not make it: decision D, the identity provider for D0 (SSO vs
local users vs hub-issued Sanctum tokens). Write H13 so it is correct under any of the three —
the hub-side roles, masked views and access log do not depend on how a human authenticates to the
dashboard. Flag it in the plan as the D0 blocker it is.

Write the plan document to docs/superpowers/plans/2026-09-07-gpp-conformance-access-control.md,
sequentially, one worker — parallel authoring previously produced five mechanical collisions that
took a whole reconciliation pass to undo. Then stop and show me the plan. Write no code.
```
