# DRAFT: comment for phpstan/phpstan#6963 (not posted)

Status: draft, 2026-08-22. Post only after explicit approval. Branches `float-nan` and `float-range-type` are local, not pushed. `<#PR_NAN>` and `<#PR_RANGE>` are placeholders for the PR numbers.

---

Proposal: a float range is a NaN-free interval with open or closed bounds, and `float` is `float<-inf, inf>|NAN`. I have a prototype in two PRs: <#PR_NAN> adds the type and `is_nan()`/`is_finite()`/`is_infinite()` narrowing without any PHPDoc syntax; <#PR_RANGE> (draft) adds the syntax and comparison narrowing on top. This is groundwork for #15094, #13859, #9250 and #13504. None of them is finished here.

## Why

PHP 8.5 warns on every coercion of NAN to string, bool or array (`"{$f}"`, `echo $f`, `(bool) $f`, `(array) $f`) and on every `(int)` cast of NAN, ±INF or an out-of-range float ([RFC](https://wiki.php.net/rfc/warnings-php-8-5)). To report those without flagging every `echo $float`, PHPStan has to be able to say "this float is not NaN". Today it cannot: `is_nan($f)` does not narrow, `float ~ NAN` is not representable (`NonRemoveableTypeTrait`), and `if ($f > 0.0)` leaves `$f` as `float`. The code already has the placeholders: `ConstantNumericComparisonTypeTrait` says `// subtract range when we support float-ranges` four times, `FilterFunctionReturnTypeHelper` says `// PHPStan does not yet support FloatRangeType`.

## Model

PHP's floats split into a totally ordered set O (`-INF` … `INF`, with `-0.0 === 0.0` as one point) and NAN, which compares as neither smaller, greater nor equal to anything.

- A range is a convex subset of O. NAN is never a member of a range.
- `float<-inf, inf>` is every float except NAN. Unlike `int<min, max>`, it does not normalize to `float`. `float` is `float<-inf, inf>|NAN`, and `TypeCombinator::union()` folds that union back into `float`.
- `is_nan($f)` narrows to `NAN` / `float<-inf, inf>`. `is_finite($f)` narrows to `float<(-inf, inf)>` / `-INF|INF|NAN`. `is_infinite($f)` narrows to `-INF|INF` / `float<(-inf, inf)>|NAN`.
- The truthy side of `$f > c` never contains NAN. The falsy side does: `!($f > c)` means `$f <= c` or `$f` is NAN. That is what makes `else { echo $f; }` reportable.

## Not a copy of `IntegerRangeType`

| | `IntegerRangeType` | `FloatRangeType` |
|---|---|---|
| unbounded side | `null` | `-INF` / `INF` are float values |
| bound inclusivity | closed only, `n±1` covers the rest | flag per bound; floats have no usable successor |
| full range | normalizes to `int` | stays `float<-inf, inf>`, excludes NAN |
| `tryRemove(point)` | `[a, p-1] ∪ [p+1, b]` | `[a, p) ∪ (p, b]` |
| touching ranges | `max + 1 == min` | the shared endpoint belongs to at least one side; `[0,1) ∪ (1,2]` has a hole |
| `getFiniteTypes()` | enumerates small ranges | always `[]` |
| falsy side of a comparison | complement | complement plus NAN |
| `accepts(int)` | n/a | coerces like `float`: `float<0.0, 1.0>` accepts `int<0, 1>` |

Open bounds are needed in three places: removing a point (`float ~ 0.0`), the truthy side of `$f > 0.0` (`(0.0, inf]`), and `is_finite()`. ±INF are values, so "open at `-inf`" means finite: `float<(-inf, inf)>`. Integers have no counterpart to the third.

On mvorisek's question about `(0.1, 0.2)`: the type is a set of doubles, not of reals. The PHPDoc literal `0.1` is the same double as the PHP literal `0.1`, and `float<(0.1, 0.2)>` is the set of doubles strictly between those two. The runtime comparison `$f > 0.1` compares the same doubles.

Doubles are finite and have a successor (`nextUp(0.0)` is `5.0E-324`, `nextUp(PHP_FLOAT_MAX)` is `INF`), so every open bound has a closed spelling at the adjacent double. Nobody wants to read `float<[5.0E-324, 1.0]>`, so the prototype keeps bounds as written for display and decides emptiness, containment, equality, merging, comparison narrowing and `(int)` truncation on the canonical closed bounds (the adjacent double is computed on 16-bit words, so it works on 32-bit builds). `float<(0.0, 5.0E-324)>` is `never`, `(0.0, 1.0]` equals `[5.0E-324, 1.0]`, and `(int)` of `(0.0, 1.0)` is `0`.

## Syntax

Closed ranges parse with today's phpdoc-parser:

```
float<0.0, 1.0>      // [0.0, 1.0]
float<0, 10>         // int literals are coerced
float<0.0, inf>      // 0.0 ≤ x ≤ INF
float<min, max>      // every float except NAN
```

phpdoc-parser cannot lex `-inf` (only numeric tokens may start with `-`; #13504 hits the same wall), so the prototype accepts `min`/`max` and prints `-inf`/`inf`. I would rather not keep `min` for floats: `PHP_FLOAT_MIN` is the smallest positive normal, and subnormals are smaller still. A lexer change for `-inf` is the first follow-up.

Open bounds use the vocabulary PHP 8.3 introduced with `Random\IntervalBoundary`:

```
float<0.0, 1.0, closed-open>   // [0.0, 1.0)
float<0.0, max, open-closed>   // (0.0, INF]
float<min, max, open-open>     // (-INF, INF), finite
```

`toPhpDocNode()` emits this form and it round-trips (`TypeToPhpDocNodeTest` checks `equals()` after re-parsing). `describe()` prints the bracket form, `float<(0.0, inf]>`, for readability; `mixed~(...)` is print-only today in the same way. Default is closed on both ends, like `int<a, b>`, `range()`, `random_int()` and `filter_var(FILTER_VALIDATE_FLOAT, min_range/max_range)`.

## Prototype

Both PRs pass the full suite and `make phpstan`. Two preparatory commits fix existing code that the new narrowing reaches:

- `ConstantFloatType::toInteger()/toString()/toBoolean()/toArrayKey()/toBitwiseNotType()` ran `(int) NAN`, `(string) NAN` and `(bool) NAN` inside PHPStan, so PHPStan itself raised the PHP 8.5 warnings when folding `(int) (0 * INF)`. NAN and the infinities now cast to 0 as PHP defines; a value outside the analysing host's int range has no defined result, so it becomes plain `int`. Nothing assumes a 64-bit host.
- `IntegerRangeType::createAllSmallerThan()/createAllGreaterThanOrEqualTo()` compared a float against `PHP_INT_MAX`, which PHP converts to `2^63` on 64-bit builds, so a float equal to `2^63` reached `(int) ceil(2^63)`. They now compare against `PHP_INT_MAX + 1.0`, exact on any int width.

<#PR_NAN> then adds `FloatRangeType` with its set algebra and canonical form, `FloatType::tryRemove()` for NAN, the infinities and ranges, the merging in `TypeCombinator`/`UnionType`/`UnionTypeHelper`, and the predicate extension. `is_nan()` narrows any argument; `is_finite()`/`is_infinite()` narrow only arguments known to be `int|float`, because `is_finite("1")` and `is_infinite("1e500")` are true at runtime. `toPhpDocNode()` widens to `float` until the syntax lands. Existing test expectations do not change.

<#PR_RANGE> adds the PHPDoc syntax, the comparison narrowing (the four placeholders, NAN kept on the falsy side, no narrowing when the other operand may be NAN, canonical bounds when a range is on the right-hand side), `float ~ 0.0`, `negate()`, and unary minus on unions member by member.

```php
function (float $f) {
    if ($f > 0.0)  { /* float<(0.0, inf]> */ }  else { /* NAN|float<-inf, 0.0> */ }
    if ($f === 0.0) { /* 0.0 */ } else { /* NAN|float<[-inf, 0.0)>|float<(0.0, inf]> */ }
    /* float */
    if (is_nan($f)) { /* NAN */ } else { /* float<-inf, inf> */ }
    if ($f < NAN) { /* never */ }   // no narrowing on the else side
};
/** @param float<0.0, 1.0> $u  @param float<-3.0, 1.0> $s */ function ($u, $s) {
    abs($u);  // float<0.0, 1.0>        (int) $u;  // int<0, 1>        -$s;  // float<-1.0, 3.0>
    if ($u !== 0.5) { /* float<[0.0, 0.5)>|float<(0.5, 1.0]> */ }
};
```

The draft changes 15 existing expectations, all in the direction the old comments predicted: `bug-5309.php` had `// could be '0.0' when we support float-ranges`, `dependent-variables-type-guard-same-as-type.php` had `// could be Yes, but float type is not subtractable`, `comparison-operators.php` goes from `float` to `float<(10.0, inf]>`. `TypeSpecifierTest` shows the cost: `if ($n < 3)` on `mixed` now prints `mixed~(NAN|float<3.0, inf>|int<3, max>|true)` instead of `mixed~(int<3, max>|true)`.

`FloatRangeType` is a `final` class that implements `CompoundType` directly, the way `ConstantArrayType` sits next to `ArrayType` since the 1.9 type refactoring: it does not extend `FloatType`. `FloatType::isSuperTypeOf()`/`accepts()` reach it through their `CompoundType` branch, and the float family is queried through `isFloat()`. Two review items remain: the new type is dispatched on with `instanceof FloatRangeType` in `TypeCombinator`, `UnionType`, `UnionTypeHelper`, `FloatType` and `InitializerExprTypeResolver`, the same sites that dispatch on `IntegerRangeType` (a representation check on a final class, but still concrete-type dispatch). And `isSubTypeOf()` adds one `phpstanApi.instanceofType` baseline entry for `instanceof IntersectionType`, the same exemption `IntegerRangeType` has; delegating to `CompoundType` in general recurses through `IntegerType::isSuperTypeOf()`. If you prefer both range types to go through `Type` methods, I will do that first.

Not included: arithmetic propagation, `positive-float`/`finite-float` aliases, return types for `abs`/`sqrt`/`sin`/`Randomizer::getFloat`/`FILTER_VALIDATE_FLOAT`, the PHP 8.5 rules themselves, `PHP_FLOAT_MAX`/`MIN`/`EPSILON` as constants, the `$x !== $x` false positive, and #14394 (`float === NAN`; arrays containing NAN make that its own change).

## Questions

1. Change phpdoc-parser's lexer for `-inf`?
2. `closed-open` keyword as the parseable form, bracket form for display only. Acceptable, or should the bracket form parse too?
3. `float<-inf, inf>` distinct from `float` in error messages: acceptable?
4. The longer `mixed~(...)` output.
5. An out-of-range float-to-int cast is modelled as plain `int` rather than as the host's wrapped constant. Acceptable? (#14948 is about the host/target distinction for ints in general.)
6. `@api` on the new class now or later? It is `final` without `@api` for now; opening either later is backward compatible.
7. `instanceof FloatRangeType` dispatch mirroring `IntegerRangeType`: acceptable, or `Type` methods first?

I would like to land <#PR_NAN> first. The draft shows where it leads.
