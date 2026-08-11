# non-empty-mixed is recognized but not enforced at call/return boundaries

Status: **DRAFT** — preparing an issue + PR for phpstan/phpstan(-src) (2026-07-31).

## Context

Ondřej Mirtes replied to the php-typing-conformance report:

> I don't get why callable-array, non-empty-mixed, noreturn are marked as
> "not enforced" by PHPStan. Please open issues about those. AFAIK they work
> properly.

Verification split the three cases apart:

- `noreturn` — a display bug in *our* report (enforcement `0/0` renders as
  "Not enforced"; the test has zero `E?` probes). Fix on our side, no issue.
- `callable-array` — real PHPStan gap, separate issue (plain `callable`
  rejects `[new Greeter(), 'missing']` / `[1, 2]`, but
  `callable-array` = `ArrayType & CallableType` accepts both).
- `non-empty-mixed` — real PHPStan gap. **This note.**

## Phenomenon

`non-empty-mixed` resolves correctly (`\PHPStan\dumpType()` shows
`mixed~(0|0.0|''|'0'|array{}|false|null)`), and the subtraction is fully used
for narrowing and reachability — but it is completely ignored when a value is
*accepted into* the type, in both argument and return position. No level, flag,
or extension changes this.

### Repro (single file, level max)

```php
<?php

namespace Probe4;

/** @param non-empty-mixed $value */
function acceptsNonEmptyMixed($value): void
{
    if ($value === null) {         // identical.alwaysFalse — subtraction IS used here
        \PHPStan\dumpType($value); // *NEVER*
    }
    if ($value) {                  // if.alwaysTrue — and here
        \PHPStan\dumpType($value); // mixed~(0|0.0|''|'0'|array{}|false|null)
    } else {
        \PHPStan\dumpType($value); // *NEVER*
    }
}

acceptsNonEmptyMixed('');          // no error — expected argument.type
acceptsNonEmptyMixed(0);           // no error
acceptsNonEmptyMixed([]);          // no error
acceptsNonEmptyMixed(null);        // no error

/** @return non-empty-mixed */
function returnsEmpty()
{
    return '';                     // no error — expected return.type
}

/** @return non-empty-mixed */
function returnsNull()
{
    return null;                   // no error
}
```

The asymmetry is the selling point of the issue: **PHPStan itself reports
"always false" / "always true" from the very subtraction it then ignores at
the call boundary.** A caller can pass `null` into a parameter about which
PHPStan simultaneously believes `$value === null` is impossible.

### Verified on

- PHPStan 2.2.6, level max, bleeding edge + strict-rules (the conformance
  harness config) — zero diagnostics on the probe lines.
- PHPStan 2.2.6, level max, no config — same (only the narrowing diagnostics
  above fire).
- Latest dev via the playground API (`POST https://api.phpstan.org/analyse`,
  2026-07-31) — same: plain `callable` probes error, `non-empty-mixed` probes
  silent. So it is not fixed on 2.2.x-dev.

## Root cause

`MixedType::accepts` unconditionally accepts, never looking at
`$this->subtractedType`
([src/Type/MixedType.php:101](https://github.com/phpstan/phpstan-src/blob/2.2.x/src/Type/MixedType.php)):

```php
public function accepts(Type $type, bool $strictTypes): AcceptsResult
{
    return AcceptsResult::createYes();
}
```

Contrast `MixedType::isSuperTypeOf` (same file, ~line 137), which *does*
consult the subtraction and even carries a ready-made reason string:

```php
$result = $this->subtractedType->isSuperTypeOf($type)->negate();
if ($result->no()) {
    return IsSuperTypeOfResult::createNo([
        sprintf('Type %s has already been eliminated from %s.', ...),
    ]);
}
```

So subtype checking knows `''` is not a `non-empty-mixed`; acceptance never
asks. (Local phpstan-src checkout: `/Users/megurine/repo/php/phpstan-src`,
at commit `3bd3b714` era, 2.2.x.)

## Root cause, revised (2026-07-31, verified by prototype in phpstan-src)

The single-cause story above is incomplete. **There are two independent
swallow points, split by level:**

1. **Levels ≤8 (`checkExplicitMixed=false`)** — `MixedType::accepts` is an
   unconditional `AcceptsResult::createYes()` (src/Type/MixedType.php:101).
   Applying the fix sketch below makes argument + return errors fire at these
   levels. Verified.
2. **Levels 9/10 (`checkExplicitMixed=true`, i.e. level max)** —
   `RuleLevelHelper::transformCommonType` (src/Rules/RuleLevelHelper.php:70-78)
   rewrites every explicit `MixedType` on the accepting side into
   `new StrictMixedType()`, **discarding the subtractedType**, and
   `StrictMixedType::accepts` is *also* an unconditional Yes. So fixing
   `MixedType::accepts` alone changes nothing at level max (verified: rule
   tests with `checkExplicitMixed=true` stay silent). `non-empty-mixed` from
   PHPDoc is `new MixedType(true, StaticTypeFactory::falsey())` — explicit —
   so real-world level-max users always hit path 2.

This explains the report's "No level, flag, or extension changes this": the
reason differs by level band, and both must be fixed.

**Third finding — message verbosity.** When the accepts fix works, the actual
messages are `expects mixed, null given` / `should return mixed but returns
string` (sic — even `''` is rendered as `string`), because
`VerbosityLevel::getRecommendedLevelByType` does not escalate for subtracted
mixed. The `AcceptsResult` reasons do surface as a 💡 tip
(`Type 0|0.0|''|'0'|array{}|false|null has already been eliminated from
mixed.`), which carries the actual explanation. PR options: (a) escalate
`getRecommendedLevelByType` to `precise()` when a subtracted `MixedType` is
involved so the message reads `expects mixed~(0|0.0|''|'0'|array{}|false|null),
'' given.`; (b) keep `expects mixed` + tip. Going with (a) first, falling back
to (b) if it churns unrelated test expectations.

## Fix sketch (PR direction)

Make `accepts` consult the subtraction, but only reject on a *definite* hit so
plain `mixed` looseness is preserved and no Maybe-noise appears (passing a
general `string` to `non-empty-mixed` must stay accepted — `'x'` is fine, only
values fully inside the subtracted type are provably wrong):

```php
public function accepts(Type $type, bool $strictTypes): AcceptsResult
{
    if ($this->subtractedType !== null
        && $this->subtractedType->isSuperTypeOf($type)->yes()) {
        return AcceptsResult::createNo();
    }

    return AcceptsResult::createYes();
}
```

Open questions to settle while writing the PR:

- Whether to route through `isSuperTypeOf(...)->toAcceptsResult()` instead —
  rejected for now because its Maybe results would make `non-empty-mixed`
  params noisy for every overlapping argument type, which is out of character
  for `mixed`.
- `NeverType` argument: `isSuperTypeOf` special-cases it; `subtractedType->
  isSuperTypeOf(NeverType)` is yes, so the guard above would reject `never`
  args — need `$type instanceof NeverType` early-return like `isSuperTypeOf`
  has.
- Interaction with `checkExplicitMixed` / `isExplicitMixed`: the reject branch
  should apply regardless of explicitness (the subtraction, not the mixedness,
  is what's violated).
- Same gap presumably affects any subtracted `MixedType`, not just the
  `non-empty-mixed` spelling — e.g. `mixed~null` from `@param mixed $x` +
  narrowing carried through a closure? Only the accepts side matters for the
  PR; test both spellings.

## PR work log (2026-07-31)

- Branch: `non-empty-mixed-accepts` off phpstan-src `2.2.x`.
- Regression tests written FIRST and confirmed failing against unfixed HEAD
  (zero errors reported in every variant):
  - `tests/PHPStan/Type/MixedTypeTest.php::testAccepts` — 11-case accepts
    contract: definite subtraction hit → No; partial overlap (general `string`
    into `mixed~falsey`) stays Yes; `NeverType` stays Yes; plain/explicit
    mixed unaffected; `mixed~null` spelling covered alongside
    `non-empty-mixed`.
  - `tests/PHPStan/Rules/Functions/CallToFunctionParametersRuleTest.php::
    testNonEmptyMixedParameter` + `data/non-empty-mixed-parameter.php` —
    all 7 falsey values rejected, truthy values + general string/int/bool
    accepted, plain `mixed` param untouched; data provider runs both
    `checkExplicitMixed=false` and `=true`.
  - `tests/PHPStan/Rules/Functions/ReturnTypeRuleTest.php::
    testNonEmptyMixedReturn` + `data/non-empty-mixed-return.php` — `''`,
    `null`, `[]` rejected; `'x'`, general string, plain mixed accepted;
    both flag variants.
- Design settled (implementation delegated to an Opus subagent):
  1. `MixedType::accepts` — subtraction guard, No only on definite hit,
     `NeverType` early accept, reason string mirroring `isSuperTypeOf`.
  2. `StrictMixedType` gains optional `?Type $subtractedType`;
     `transformCommonType` passes it through instead of dropping it.
  3. `VerbosityLevel::getRecommendedLevelByType` escalation (option (a)),
     fallback to tip-only wording if unrelated-test churn is too high.

## Implementation result (2026-07-31, committed)

Commit `e8428d174` on branch `non-empty-mixed-accepts` (11 files, +332/−5).
Implemented by an Opus subagent to the settled design, reviewed and adjusted
by the coordinator (deduplicated `describeSubtractedType` into the existing
`SubstractableTypeTrait` instead of a private copy).

- `MixedType::accepts` + `StrictMixedType::accepts`: definite-hit guard,
  `NeverType` exempt, reason string identical to the `isSuperTypeOf` one.
- `StrictMixedType`: gained `?Type $subtractedType` ctor param,
  `getSubtractedType()`, subtraction-aware `equals()` and `describe()`
  (`strict-mixed~…` at cache level so cache keys can't collide);
  `TemplateStrictMixedType` needed a `parent::__construct()` call.
- `RuleLevelHelper::transformCommonType` passes the subtraction through.
- `VerbosityLevel::getRecommendedLevelByType`: option (a) shipped — a
  standalone pre-traversal that returns `precise()` when the accepting type
  contains a subtracted (Strict)MixedType, **stopping at TemplateType** (a
  naive traversal descends into template *bounds* — `T of mixed~IntBox` —
  and wrongly escalated bug-13190's messages). Unrelated-test churn: exactly
  one line, a genuine improvement
  (`ImpossibleInArrayHaystackFiniteTypesRuleTest`: needle now described as
  `mixed~'installed'` instead of a bare, self-contradictory `mixed`).
- Adjacent surfaces checked: `TemplateMixedType::accepts` (variance strategy,
  not affected), `MixedType::isAcceptedBy` (routes through `isSuperTypeOf`,
  always had the check), `ErrorType` (subtraction always null), no Turbo
  shadow on any touched class (`VerbosityLevel` is only
  `#[ReferencedByTurboExtension]` — class-reference table, no C++ port).
- Verification: full Type/, Rules/{Functions,Methods,Properties,Comparison}/,
  AnalyserTest + AnalyserIntegrationTest all green; `make phpstan` no errors;
  `make cs` clean; level-max smoke on the repro now errors on all 4 calls and
  both returns with
  `expects mixed~(0|0.0|''|'0'|array{}|false|null), null given.` etc., while
  the narrowing diagnostics stay unchanged.

Playground link created 2026-07-31 via the real playground UI (Browser tool,
level 10/max): https://phpstan.org/r/5aef2572-36cd-4482-b6a3-bde781ac0ad8 —
verified persistent (fresh tab, fresh load) and reproduces "Found 3 errors"
(narrowing diagnostics only, zero argument.type/return.type), matching the
bug description.

**Review follow-up (2026-08-03):** staabm asked whether the other
`new StrictMixedType()` sites should also carry the subtraction
(discussion_r3703249749). Both remaining sites were observably wrong:
`RuleLevelHelper::findTypeToCheck()` reported a narrowed `mixed~null` as bare
`mixed` (disagreeing with the neighbouring `AccessPropertiesCheck` message
describing the same value), and `TemplateMixedType::toStrictMixedType()`
collapsed `T of mixed~null` to `T of mixed` (also reached via
`transformCommonType()` and the intersection branch). Fixed in `68774100d`
(2 source lines + `ClassConstantRuleTest` regression covering both sites,
fail-before verified per site; full `make tests` 17841 green, phpstan/cs
clean). The branch had been force-rebased onto newer 2.2.x (`a5e98b464`)
meanwhile — cherry-picked on top, ff-pushed. Subtraction stays on the
template's *bound*, not the `TemplateStrictMixedType` wrapper
(`TemplateTypeTrait` delegates all subtraction handling to the bound).

**Review follow-up 2 (2026-08-05):** staabm asked whether the extra
VerbosityLevel pre-pass could be merged into the existing
`$moreVerboseCallback` (discussion_r3718430277). Done, but not naively:
stateful gating (depth counter / boolean flag) of the template-bound
exclusion trips PHPStan's own self-analysis (`identical.alwaysTrue` /
`booleanNot.alwaysTrue` — it cannot see the callback re-entering through
`$traverse`). Final shape is state-free: the existing branch block became
`$flagsCallback`; `$moreVerboseCallback` now short-circuits, maps template
subtrees with `$flagsCallback` (wrapper-level branch evaluation preserved
exactly), rejects on subtracted (Strict)MixedType, and otherwise delegates
to `$flagsCallback`. Side effect (accepted): the accepted-type traversal in
the invariant-generic path now also detects subtracted mixed. Also rebased
onto latest phpstan/2.2.x per Kenta's instruction (no squash; commits now
`11b329605` + `f5a63a1b4` + `14d40d36a`), full `make tests` 21282 green,
phpstan/cs clean, force-with-lease pushed.

**CI triage (2026-08-07):** After the amend-retrigger (head `f6205525c`),
727/736 checks pass. The 3 failures are all environmental:
1. *Benchmark (PHP 8.5)* — 6/74 assertions over tolerance, nearly all subjects
   +10-25% vs the committed baseline XML. Verified NOT a real regression:
   (a) full local phpbench A/B (base `8e2efc0ce` vs branch, same machine)
   passes every CI assertion (exit 0); (b) the three worst CI subjects
   (bug-14996, bug-14972, bug-14972-concat — error-transform worst case and
   mixed-heavy scope stress) re-measured with interleaved single-run harness
   (`bench-one.php`, warmup + 1 rev, 5-6 alternating pairs): 0% diff, variant
   occasionally faster. The uniform CI shift = runner-generation variance vs
   the committed baseline numbers; the local full-suite +11-14% on those
   subjects was thermal drift over the 40-min sequential run (interleaving
   eliminates it).
2. *Turbo macos 8.5 nts `make phpstan`* — one child crossed the 450M limit;
   the same self-analysis passes on 30+ other cells incl. macos `make tests`,
   and the identical job passed upstream on our exact base. Memory-ceiling
   flake.
3. *shopsys integration* — failed in Setup (dependency install), never
   reached analysis.
None are actionable in the PR; maintainer rerun should clear 2 and 3, and 1
clears on a quieter runner (or a baseline refresh).

**Submitted 2026-08-01:**
- Issue: https://github.com/phpstan/phpstan/issues/15033
- PR: https://github.com/phpstan/phpstan-src/pull/6167 (branch
  `non-empty-mixed-accepts` on `zonuexe/phpstan-src` → `phpstan/phpstan-src`
  base `2.2.x`, commit `e8428d174`, `Closes` wired to the issue above).

## PR draft (phpstan-src, base 2.2.x)

- **Title**: `Check the subtracted type when a subtracted mixed accepts a value`
  (describes the change, not the bug — per repo convention).
- **Commit shape**: single commit, fix + tests together.
- **Body sketch**:
  - `Closes https://github.com/phpstan/phpstan/issues/<n>` (fill after filing).
  - One paragraph: `MixedType::accepts` (and `StrictMixedType` after
    `RuleLevelHelper`'s explicit-mixed transformation) accepted every value
    unconditionally, so `non-empty-mixed` / any `mixed~X` rejected nothing at
    call/return boundaries while the same subtraction powered narrowing.
  - Note the deliberate asymmetry: acceptance only turns to No on a
    *definite* hit (`subtractedType->isSuperTypeOf($given)->yes()`); partial
    overlaps (general `string` into `non-empty-mixed`) stay accepted to
    preserve `mixed` looseness — i.e. this is not
    `isSuperTypeOf()->toAcceptsResult()`.
  - Mention `NeverType` early accept (mirrors `isSuperTypeOf`).
  - If the VerbosityLevel escalation ships, one line on the improved message
    rendering (`expects mixed~(…)` instead of `expects mixed`).

## Where the tests live

- phpstan-src has essentially no `non-empty-mixed` coverage: only
  `tests/PHPStan/Analyser/nsrt/more-types.php` mentions it (narrowing side).
- Argument-position rule tests:
  `tests/PHPStan/Rules/Functions/CallToFunctionParametersRuleTest` + data dir.
- Return-position: `tests/PHPStan/Rules/Functions/ReturnTypeRuleTest`.
- Type-level unit test: `tests/PHPStan/Type/MixedTypeTest` (accepts cases).

## Conformance-suite anchors

- Test: `conformance/tests/phpdoc_advanced_fallback_non_empty_mixed.php`
  (4 `E?` probes: `''`, `0`, `[]`, `null`).
- Current measurement: `conformance/results/phpstan/phpdoc_advanced_fallback_non_empty_mixed.toml`
  — `recognition = "recognized"`, `enforcement = "none"`, `enforced_lines = "0/4"`.
- After the upstream fix lands, re-measure with the update-analyzers skill and
  the cell should flip to Enforced 4/4.

## Next steps

1. File the issue on phpstan/phpstan with the repro above + playground link
   (create one via the playground UI so the issue has a clickable repro).
2. PR against phpstan-src 2.2.x: `MixedType::accepts` change + regression
   tests (argument, return, `never` argument, plain `mixed` unaffected).
3. Reply to Ondřej: non-empty-mixed and callable-array reproduce on latest
   dev (issues incoming); noreturn was our report's rendering bug, fixed on
   our side.
