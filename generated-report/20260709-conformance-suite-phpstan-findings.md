# Conformance suite build + PHPStan array-shape findings

Session on the `php-typing-conformance` repo (cross-analyzer conformance suite:
PHPStan, PHPStan-strict, Psalm, Mago, Phan, NoVerify). Two outcomes: (1) a new
regression track + a corpus divergence sweep, (2) a verified enumeration of
PHPStan array-shape / `ConstantArrayType` bugs and reinforcement points.

Engine versions used here (conformance repo's pinned binaries): **PHPStan 2.1.47**
(pre-#6025), Psalm 6.x, Mago 1.19.0, Phan 6.x, NoVerify 0.5.5. Where a probe was
re-run for this note, `\PHPStan\dumpType()` was used directly.

Related notes:
- [[20260709-issue-draft-optional-key-islist]] → issue #14938 (= finding **A1**).
- [[20260709-crosscheck-mago-psalm]] — RFC #14939 five-engine probe table
  (T5 = A1, T2 = explicit-key, C3 = B1). This note reproduces those from the
  conformance harness (diagnostics, not `dumpType`) and adds C2/C3.
- [[20260709-rfc-draft-array-list-shapes]], [[20260709-pr3872-array-list-shapes]],
  [[20260709-pr6025-vs-6026-ab-tradeoff]] — the isList/list-shape work these
  findings feed into.

---

## Part 1 — What was built (php-typing-conformance)

### 1a. `regressions` test group

A second axis beside the feature-based groups: edge cases distilled from analyzer
issue trackers **and** from replaying analyzer corpora. Hypothesis: a defect one
analyzer surfaces is latent in the others. Each test is plain PHP with inline
`// E<tool>` markers; the evaluator matches tool×line only (message text is not
compared), so a marker records "this tool emits here" and its absence asserts the
tool stays silent. Committed tests:

| test file | edge case | split |
| --------- | --------- | ----- |
| `regressions_optional_key_shape_is_list.php` | `array{a?:string}` × `array_is_list()` | PHPStan/Psalm/Mago wrong, Phan correct |
| `regressions_optional_extra_key_is_list.php` | `array{0:int, a?:string}` × `array_is_list()` | same |
| `regressions_keyless_tuple_is_list.php` | `array{int,string}` × `array_is_list()` | all correctly always-true (control) |
| `regressions_explicit_key_shape_is_list.php` | `array{0:string,1:string}` as `list<string>` | Psalm alone rejects |
| `regressions_reversed_literal_list_param.php` | `[1=>'x',0=>'y']` → `list{string,string}` | Phan+Psalm reject, PHPStan+Mago accept |
| `regressions_list_destructure_string_key.php` | `[$a,$b] = array<string,int>` | Psalm alone misses |
| `regressions_string_narrowing_assert_if_true.php` | bare `@assert-if-true non-empty-string` | PHPStan/Psalm don't narrow, Mago/Phan do |
| `regressions_array_element_null_subtraction.php` | `$arr===[null]` else narrowing | PHPStan/Psalm don't narrow, Mago/Phan do |

### 1b. Corpus divergence sweep (`conformance/corpus/`)

Replays an analyzer's own test corpus across all tools. **No redistribution**:
cases are read in place (`--cases-dir`), staged transiently in tmp for one
analysis, deleted; only behavioural facts persist. Baseline = Mago's inline
`@mago-expect analysis:<code>` pragmas (which suppress Mago's own output, so they
are parsed from the file, not read from live diagnostics). `categories.json`
normalizes each tool's diagnostic codes into categories with a `soundness` flag,
so style/parser/heuristic noise is dropped and divergences compare at scale.
Prototype over 53 Mago array/list/shape cases confirmed the method (≈28% genuine
soundness divergences after filtering; the rest style/parser noise).

---

## Part 2 — PHPStan array-shape / ConstantArrayType findings

Foundation: RFC https://github.com/phpstan/phpstan/discussions/14939.
`ConstantArrayType::isList()` internals are **recorded but deferred** (handled
separately). Below is verdict + root-cause hypothesis + repro only.

### A. Confirmed bug (accepted upstream, fix pending)

**A1 — `array_is_list()` wrongly "always false" for optional-key shapes.**
`array{a?:string}` admits `[]`; `array{0:int, a?:string}` admits `[0=>1]`. Both
are lists at runtime → `array_is_list()` is *maybe*. PHPStan emits
`function.impossibleType` and kills the branch. Hypothesis: `isList` collapses an
optional non-list key to `No` instead of `Maybe` (trinary needed). Also affects
the `assert(array_is_list($a))` form, not only `if(...)`.
- Refs: #14938, fix phpstan-src#6025. Repro: `regressions_optional_key_shape_is_list`,
  `regressions_optional_extra_key_is_list`.
- Reinforcement: fix must cover (a) pure optional, (b) required-list-key +
  optional-extra, (c) the `assert()` form.

### B. Soundness inconsistency — two sources of truth for isList (core of #14939)

**B1 — PHPStan reports `array_is_list()===false` for a type, yet accepts that same
type at a `list{...}` parameter.**

```php
dumpType([1 => 'x', 0 => 'y']);              // array{1: 'x', 0: 'y'}
dumpType(array_is_list([1=>'x',0=>'y']));    // false  (reported "always false")
/** @param list{string, string} $l */ function takesList(array $l): void {}
takesList([1 => 'x', 0 => 'y']);             // NO ERROR
```

The `isList` consulted by `array_is_list()` narrowing disagrees with the one used
in subtyping/argument acceptance: a proven-non-list `array{1:string,0:string}` is
treated as assignable to `list<string>`. This is exactly the divergence #14939
raises. A single corrected trinary `isList` resolves A1 and B1 together.
Repro: `regressions_reversed_literal_list_param`, `regressions_explicit_key_shape_is_list`.

### C. Precision / reinforcement (real gaps; C2 is design-dependent)

**C1 — No array-element subtraction on whole-array strict equality.**
`$arr = [$val]` (`string|null`); after `if ($arr === [null]) { return; }` the else
branch still `dumpType`s `array{string|null}` — the `[null]` value is not
subtracted, so it never narrows to `array{string}`. Mago and Phan narrow.
Repro: `regressions_array_element_null_subtraction`.

**C2 — Sealed shapes silently accept extra keys.**
`array{a: int}` accepts `array{a:1, b:2}` as argument, return, and assignment with
no diagnostic (native `array{a:1,b:2}` widened to the declared `array{a:int}`).
Mago reports `invalid-return-statement` / invalid-argument. Design-dependent: if
`array{...}` is meant to be sealed (exact key set), this is a soundness gap; if
"at least these keys", it is by design. Needs an explicit ConstantArrayType
sealing decision; interacts with the isList work.

```php
/** @param array{a: int} $x */ function takesSealed(array $x): void {}
takesSealed(['a' => 1, 'b' => 2]);           // NO ERROR (extra key 'b')
/** @return array{a: int} */ function r(): array { return ['a'=>1,'b'=>2]; } // NO ERROR
```

**C3 — Typed-unsealed shape syntax parsed then discarded.**
`array{a: int, ...<string, string>}` `dumpType`s as `array{a: int}` — the
`...<K,V>` rest constraint is dropped, so returning `['a'=>1, 'foo'=>42]`
(`foo:int` violating the string rest) is not flagged. Mago models and flags it.
ConstantArrayType carries no typed rest.

### D. Verified NOT a PHPStan issue (by design — excluded)

**D1 — bare `@assert-if-true` not honored.**
PHPStan narrows for **both** `@phpstan-assert-if-true non-empty-string $s`
(→ `non-empty-string`) and `@psalm-assert-if-true ...`. Only the non-prefixed bare
`@assert-if-true` is ignored (→ stays `string`) — not a recognized tag. By design.
(Several Mago corpus cases use the bare form, which made them look like PHPStan
gaps in the raw sweep — e.g. `reconcile_non_empty_string`, `string_reconciliation`.)

---

## Part 3 — Suggested sequencing (all rooted in ConstantArrayType/isList)

1. **A1 + B1 together** — one corrected trinary `isList` fixes the always-false
   bug and the accept-a-non-list inconsistency. Highest value; upstream momentum
   (#6025 / #14939). *(isList implementation to be handled separately.)*
2. **C2** — decide and enforce sealed-shape semantics (foundational).
3. **C3** — model the typed rest (`...<K,V>`) in ConstantArrayType.
4. **C1** — constant-array subtraction on equality (independent, lower priority).

## Appendix — provenance

- Conformance repo: `/Users/megurine/repo/php/php-typing-conformance`
  (regression tests under `conformance/tests/regressions_*.php`, sweep under
  `conformance/corpus/`, catalog `docs/regression-edge-cases.md`).
- Mago corpus (source of B/C leads): `/Users/megurine/repo/rust/mago/crates/analyzer/tests/cases`
  (`@mago-expect` = per-case expected diagnostics; framework asserts zero
  *unexpected* issues).
- Scratch dumpType experiments: `pstan-exp/{assert_forms,assert_prefixes,list_soundness,sealed,unsealed_parse}.php`.
