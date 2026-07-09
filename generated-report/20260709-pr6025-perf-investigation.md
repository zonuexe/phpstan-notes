# PR #6025 — phpbench "Test" CI failure: not a real regression

Investigation of the `Benchmark / Test` CI failure on
[phpstan-src#6025](https://github.com/phpstan/phpstan-src/pull/6025)
(+20–40% on `bug-7901.php`, `bug-10538.php`, `or-chain-resolve-type-blowup.php`,
`or-chain-falsey-blowup.php`). Also failing identically on the bot PR #6026.

## Verdict: the failure is an environment/stale-baseline artifact, not my code.

### How the bench CI actually compares (`.github/workflows/bench.yml`)

- The `Test` job runs `phpbench run --file=tests/bench/storage/baseline.xml`.
- `tests/bench/storage/baseline.xml` is a **committed file** (last regenerated
  2026-07-05 by Ondrej Mirtes, commit `1476c1d0a`). The `Test` job does **not**
  download the baseline artifact produced by the `baseline` job — it compares
  the PR's fresh run against the *committed* baseline.
- So the comparison is: **PR on the current GitHub runner** vs **a baseline
  captured on whatever machine regenerated the committed file**.

Evidence it's the committed file: the baseline numbers are byte-identical across
every re-run of my PR (e.g. `bug-7901` baseline = `572.433ms`, `or-chain` =
`793.902ms` in every run).

### Same-workload timings across environments (bug-7901.php)

| environment                                   | time        |
| --------------------------------------------- | ----------- |
| committed `baseline.xml` (2026-07-05 machine) | **572 ms**  |
| current CI runner (my PR's variant run)       | 693 ms (+21%) |
| my local Mac                                  | 783–802 ms (+37%) |

The committed baseline is faster than **both** the CI runners and my Mac, so
everything measured against it shows +20–40%. This hits **every** PR touching
`src/**`, not just #6025 (hence #6026 fails identically).

### The real code impact (controlled, same-machine A/B)

Generating a fresh baseline from **base (origin/2.2.x)** on my machine, then
running my branch against it (same machine, back-to-back):

| file                             | Δ time  |
| -------------------------------- | ------- |
| `bug-7901.php`                   | +2.63%  |
| `bug-10538.php`                  | +1.21%  |
| `or-chain-resolve-type-blowup.php` | +0.54% |

All within the benchmark's own noise (variance ±0.2–1.1%, retry threshold 10%,
per-file tolerance 20–50%). **No real regression from the PR's code.**

## Why no regression despite the added work

The PR's hot-path additions are cheap:
- `ArrayType::isSuperTypeOf()` — the extra `isConstantArray()` /
  `isIterableAtLeastOnce()` checks run only when the result is already `no`.
- `ConstantArrayType::mergeWith()`/`legacyMergeWith()` — `inferIsListFromShape()`
  is O(keys) and runs only on sealed merges.
- `ConstantArrayTypeBuilder::markNonListKey()` — a single trinary op.

## Recommendation

- Nothing to fix in the PR's code — no real regression exists.
- The bench red is a repo-wide artifact of the committed `baseline.xml` being
  from a faster environment than current CI runners. Resolving it means
  regenerating `tests/bench/storage/baseline.xml` on a representative runner —
  a maintainer/infra action, out of scope for this PR.
- Optional defensive micro-opt (not needed): the `mergeWith` recompute uses the
  `$mergedIsSealed ? $naiveIsList->or(inferIsListFromShape(...)) : $naiveIsList`
  combinator, which recomputes even when `$naiveIsList` is already `yes`. A
  guard could skip that, but it's a noise-level saving and the combinator form
  is what keeps the code free of the equivalent TrinaryLogic mutant (see
  [20260709-pr6025-vs-6026-ab-tradeoff.md](20260709-pr6025-vs-6026-ab-tradeoff.md)).
