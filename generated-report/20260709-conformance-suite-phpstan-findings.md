# Conformance suite build + PHPStan findings (array-shape and beyond)

Session on the `php-typing-conformance` repo (cross-analyzer conformance suite:
PHPStan, PHPStan-strict, Psalm, Mago, Phan, NoVerify). Outcomes: (1) a new
regression track + a corpus divergence sweep, (2) a verified enumeration of
PHPStan array-shape / `ConstantArrayType` bugs and reinforcement points (Part 2),
(3) verified PHPStan gaps that do **not** involve `ConstantArrayType` (Part 4).

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

## Part 4 — Non-ConstantArrayType PHPStan findings

To answer "are there PHPStan gaps that do not involve `ConstantArrayType`?", a
PHPStan-only sweep was run over a 322-case non-array sample of the Mago corpus
(generics, callables, flow, scalars, inheritance, `issue_*`), phpstan-no-strict
max, compared to the `@mago-expect` baseline. FP direction (PHPStan reports on
mago-clean code) was the reliable signal; MISS direction produced mostly
classifier artifacts (16 of 17 "misses" were actually reported under a code not
yet mapped in `categories.json` — `method.childParameterType`, `clone.nonObject`,
`assign.propertyProtectedSet`, `notIdentical.alwaysTrue`, …). Verified below with
`\PHPStan\dumpType()`.

### E1 (strong) — class-string identity comparison has no negative-branch narrowing

PHPStan narrows the **positive** branch of a class-string identity check but does
**not** narrow the **negative** (else / `match` default / `switch` default) branch
— not even to subtract a `final` class that is fully excludable. This holds for
every spelling: `$x::class === X::class`, `!==`, `get_class($x) === X::class`, and
`$x::class === $y::class`. It is **comparison-specific**, not a general narrowing
limit: `instanceof`, `is_a()`, `gettype()`, enum-case identity, and literal-scalar
identity all narrow both directions. So the machinery exists; it is simply not
wired for class-string identity.

**Narrowing-symmetry matrix** (`if (cond) {POS} else {NEG}`, via `dumpType`):

| comparison form | POS | NEG |
| --------------- | --- | --- |
| `$x instanceof A` | ✓ | ✓ |
| `is_a($x, A::class)` | ✓ | ✓ |
| `gettype($v) === 'integer'` | ✓ | ✓ |
| enum `$e === E::X` | ✓ | ✓ |
| literal `$s === 'a'` / `$i === 1` | ✓ | ✓ |
| **`$x::class === A::class`** | ✓ | **✗ (keeps `A\|B`)** |
| **`get_class($x) === A::class`** | ✓ | **✗** |
| **`$x::class !== A::class`** | ✓ | **✗** |
| **`$x::class === $y::class`** | ✓ | **✗** |
| **backed enum `$e->value === 'p'`** | **✗** | **✗** (see E4) |

The `gettype()` vs `::class` rows are the sharpest contrast — same shape of
string-identity comparison, opposite outcome.

```php
final class A {} final class B {}
function neg(A|B $x): void {
    if ($x::class === A::class) { dumpType($x); }  // A   (positive: narrows)
    else                        { dumpType($x); }  // A|B (negative: NOT narrowed; should be B)
}
function ctrl(A|B $x): void {
    if ($x instanceof A) {} else { dumpType($x); } // B   (instanceof negative works)
}
```

Real-world impact (Mago case `narrow_non_final_class_string_match`): in
`match ($x::class) { C::class => …, A::class => …, B::class => …, default => $x }`
and the equivalent `switch`, the `default` arm keeps `A|B|C`, producing
false-positive `return.type` ("should return C but returns A|B|C") and
`staticMethod.notFound`. Non-array; control-flow narrowing on class-string
identity. **Belongs in the main cross-tool comparison table, not just as an edge
case** — the `instanceof`/`is_a`/`gettype` contrast makes it a crisp issue.

**Cross-tool note:** promoted to `regressions_class_string_negative_narrowing.php`.
**PHPStan and Psalm** both fail to narrow the negative branch (`return.type` /
`InvalidReturnType`+`InvalidReturnStatement`); **Mago and Phan** narrow to `B` and
stay clean. So the split is PHPStan+Psalm vs. Mago+Phan (contrast with E4, which
Mago also misses).

### E2 (minor) — redundant final arm in a `match (true)` `is_*` chain not flagged

```php
function f(int|string|float $v): string {
    return match (true) {
        is_int($v)    => 'int',
        is_float($v)  => 'float',
        is_string($v) => $v,   // always true here; PHPStan is silent (Mago: redundant-type-comparison)
    };
}
```

PHPStan does not propagate earlier-arm exclusions into a later arm's condition, so
the always-true final `is_string($v)` is not reported as already-narrowed. Genuine
silence (verified), minor precision gap. Non-array.

### E3 (gray / uncertain) — `@template T2 = T1` default not applied in the body

`maybe_transform()` with `@template T2 = T1` returns `$value` (T1) where T2 is
declared; PHPStan reports `return.type`. Treating template params as independent
inside the body is defensible, so this is **not** asserted as a bug — recorded for
consideration only.

### E4 (medium, same family as E1) — backed-enum `->value` comparison does not narrow the case

```php
enum BE: string { case P = 'p'; case Q = 'q'; }
function pos(BE $e): void { if ($e->value === 'p') { dumpType($e); } }        // BE   (want BE::P)
function neg(BE $e): void { if ($e->value === 'p') {} else { dumpType($e); } } // BE   (want BE::Q)
```

A backed enum's case↔value mapping is a bijection, so `$e->value === 'p'`
uniquely determines `$e === BE::P`. PHPStan narrows the case-identity form
(`$e === E::X`, see matrix) but **neither** branch of the `->value` form — it does
not use the value→case mapping. Same family as E1 (identity comparison whose
narrowing is not propagated to the union). Non-array.

**Cross-tool note (not PHPStan-specific):** promoting this to a conformance test
(`regressions_backed_enum_value_narrowing.php`) showed that **PHPStan, Psalm, and
Mago all share the gap** — a trailing single-arm `match ($s)` after
`if ($s->value === 'H') return …` is flagged non-exhaustive by all three
(`match.unhandled` / `UnhandledMatchCondition` / `match-not-exhaustive`). **Only
Phan** treats it as exhaustive. All three narrow enum *case identity*
(`$s === Suit::Hearts`); only the `->value` form is missed. So E4 is a common
ecosystem gap, not a PHPStan-only one — unlike E1, where Mago and Phan both narrow
and PHPStan/Psalm do not.

### E5 (strong, same family as E1) — object union not narrowed by a discriminating property

PHPStan narrows an **array-shape** discriminated union (`$a['tag'] === true` selects the
matching shape) but not the **object** equivalent — neither by a property's type
(`is_string($b->v)`) nor by a literal tag value (`$b->tag === true`). Not property-hook
specific; plain typed properties reproduce it.

```php
class Str { public function __construct(public string $v) {} }
class Flt { public function __construct(public float $v) {} }
function f(Str|Flt $x): void {
    if (is_string($x->v)) { dumpType($x); }  // Flt|Str  (want Str)
    else                  { dumpType($x); }  // Flt|Str  (want Flt)
}
// literal-tag discriminated union — the classic pattern — also not narrowed:
class TA { public true  $t = true; }
class TB { public false $t = false; }
function g(TA|TB $x): void { if ($x->t === true) { dumpType($x); } } // TA|TB (want TA)
// array-shape control — DOES narrow:
// array{tag:true,…}|array{tag:false,…} with $a['tag'] === true → each shape separately
```

The array-vs-object asymmetry is the crisp framing. Promoted to
`regressions_object_property_discriminant_narrowing.php`: **PHPStan and Psalm** both fail
(`return.type` / `InvalidReturnStatement`); **Mago and Phan** narrow — same split as E1.
Found from Mago `issue_1093` (`test_property_narrowing`). Non-array (about objects).

### E6 (MISS direction) — conflicting property types from two traits not detected

`LeftTrait::$prop: string` + `RightTrait::$prop: int` composed into one class is a
**PHP compile-time fatal**, yet PHPStan is fully silent. Psalm reports only an unrelated
`MissingConstructor`; **Mago** (`incompatible-property-type`) and **Phan**
(`PhanIncompatibleRealPropertyType`) detect the conflict. Promoted to
`regressions_trait_property_type_conflict.php`. From Mago `trait_property_type_conflicts`.
The one genuine MISS-direction gap surviving verification.

**Methodology correction:** PHPStan emits trait-method diagnostics with a
`path.php (in context of class X):LINE:` prefix, not `path.php:LINE:`. An earlier
silence check grepped `path.php:` and so dropped those lines, falsely marking several cases
"silent". Re-checked with `grep -F path.php`. In particular
`inheritance_method_signature_through_trait_bad` (trait method whose param violates the
implemented interface's LSP) **is** reported by PHPStan (`method.childParameterType`, in the
using class's context) — **not** a gap. Only E2, E6, and `scalars_intdiv_by_zero`
(literal `intdiv(x, 0)`) are truly PHPStan-silent among the 40 MISS; the other 37 are
reported (many under codes the classifier lacked) or by design (uninitialized-on-read;
`'foo' . true` is legal PHP so Mago is over-strict there).

### Full non-array sweep — triage (1286 cases, improved classifier)

34 FP + 40 MISS. After per-case verification the genuine, non-array, not-by-design PHPStan
findings are E1, E2, E4, E5 (above). The rest resolve as:

- **PHPStan is correct, Mago misses** — `issue_1045` (array_walk non-by-ref), `issue_1117`
  (`filter_var(FILTER_SANITIZE_EMAIL)` → `string|false`), redundant-comparison /
  already-narrowed reports (`scalars_*_compare`, `flow_switch_basic_narrow`, `is_array`/
  `is_string` always-true), LSP contravariance (`method_signature_template_substitution`).
- **By design** (unrecognized non-prefixed tags) — bare `@assert*` (`docblock_assert_*`),
  bare `@type` aliases (`type_alias_*`, `docblock_self_referential_type_alias`). Same class
  as D1.
- **Mago-specific language features** — `partial_application_*` (6 cases); PHPStan does not
  model Mago's partial application, so "MISS" is not meaningful.
- **Mago over-strict / PHP-legal** — `scalars_bool_concat`, `strings_concat_with_bool_invalid`
  (bool coerces to string in concatenation).
- **Gray / conservative-by-design** — `issue_1089` (integer range upper bound widened to
  `max` across a loop `++`), `template_default_references_other_template` (E3),
  `callables_closure_bind_to_class` (`$this` type in an unbound closure — overlaps existing
  [[20260708-pr4081-closure-bind-scope]]).
- **Verified reported (were false MISS from classifier gaps)** — readonly writes
  (`property.readOnlyAssignNotInConstructor`), PHP 8.4 asymmetric visibility
  (`assign.propertyPrivateSet`) and property-hook contravariance
  (`propertySetHook.nativeParameterType`), abstract instantiation (`new.abstract`), invalid
  default values (`parameter.defaultValue`), undefined class const (`classConstant.notFound`)
  — all now mapped in `categories.json`. PHPStan reports every one.
- **By design (uninitialized-on-read)** — `classes_uninitialized_typed_property`,
  `classes_constructor_no_body_required`, `trait_property_initialization`: PHPStan flags an
  uninitialized typed property when it is *read*, not at declaration; Mago flags eagerly.
- **Genuine MISS gaps** — only E2 (match-true redundant last arm ×2), E6 (trait property
  type conflict), and `scalars_intdiv_by_zero`.

### Excluded (by design or false signal)

- **Bare `@type` alias** (`type_alias_complex_types`) — PHPStan uses
  `@phpstan-type` / `@phpstan-import-type`; the non-prefixed `@type` is not a
  recognized tag. By design, same class as D1's bare `@assert-if-true`.
- **`issue_1045`** — `array_walk()` with a non-by-ref callback does not change the
  array's value type, so `array<string,int>` stays and the declared
  `array<string,string>` return is correctly flagged. **PHPStan is right; Mago
  misses it.**
- **16 of 17 MISS candidates** — PHPStan did report; the codes were simply
  unmapped in `categories.json` (adding them: `method.childParameterType` →
  argument-type, `clone.nonObject` → operand, `assign.propertyProtectedSet` →
  assignment-type, `notIdentical.alwaysTrue`/`smaller.alwaysFalse` → narrowing,
  `argument.missing`/`argument.unknown` → arity, `plus.leftNonNumeric` → operand,
  `property.writeOnly` → assignment-type, `new.enum` → undefined-symbol).

### Method notes

- FP direction is the trustworthy signal; MISS needs the classifier map completed
  first. Sweep covered a **1/4 sample (322 cases) × phpstan-no-strict only** — the
  remaining 3/4 and strict rules likely hold more.
- Both E1 and E2 are control-flow narrowing gaps (class-string identity, `match`
  arm exclusion), a theme distinct from the `ConstantArrayType`/isList work.

## Appendix — provenance

- Conformance repo: `/Users/megurine/repo/php/php-typing-conformance`
  (regression tests under `conformance/tests/regressions_*.php`, sweep under
  `conformance/corpus/`, catalog `docs/regression-edge-cases.md`).
- Mago corpus (source of B/C leads): `/Users/megurine/repo/rust/mago/crates/analyzer/tests/cases`
  (`@mago-expect` = per-case expected diagnostics; framework asserts zero
  *unexpected* issues).
- Scratch dumpType experiments: `pstan-exp/{assert_forms,assert_prefixes,list_soundness,sealed,unsealed_parse}.php`
  (Part 2) and `pstan-exp/{classneg,narrow_probe,narrowing_matrix,narrow_confirm,more_forms}.php`
  (Part 4, incl. the E1 symmetry matrix and E4 backed-enum probe) and
  `pstan-exp/{prop_discriminate,arr_vs_obj}.php` (E5 array-vs-object discriminant).
- PHPStan-only sweep tool: `scratchpad/sweep_phpstan.php` (Mago baseline compare);
  full run over 1286 non-array cases → `scratchpad/pstan-sweep-full.txt` (34 FP / 40 MISS).
- Conformance regressions promoted this session: `regressions_{class_string_negative_narrowing,
  backed_enum_value_narrowing,object_property_discriminant_narrowing}.php` (E1, E4, E5).
