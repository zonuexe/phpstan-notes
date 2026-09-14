# DRAFT: PR body for branch `float-nan` (not submitted)

Target: `phpstan/phpstan-src`, base `2.2.x`. Title:

> Separate NAN and the infinities from float: `is_nan()`/`is_finite()`/`is_infinite()` narrowing

---

Part of https://github.com/phpstan/phpstan/issues/6963, groundwork for https://github.com/phpstan/phpstan/issues/15094. Design: <link to the #6963 comment>. The full prototype is <#PR_RANGE>; its first three commits are this PR.

NAN compares as neither smaller, greater nor equal to anything, so no ordered set contains it. PHP 8.5 warns on every coercion of NAN to string, bool or array and on non-representable `(int)` casts ([RFC](https://wiki.php.net/rfc/warnings-php-8-5)). To report those without flagging every `echo $float`, PHPStan has to be able to say "this float is not NaN". Today `is_nan($f)` does not narrow and `float ~ NAN` is not representable (`NonRemoveableTypeTrait`).

This PR adds `FloatRangeType`, a convex set of non-NaN floats with open or closed bounds, so that `float` reads as `float<-inf, inf>|NAN`, and uses it for one thing: separating NAN and the infinities from the rest.

```php
function (float $f) {
    if (is_nan($f)) { /* NAN */ } else { /* float<-inf, inf> */ }
    if (is_finite($f)) { /* float<(-inf, inf)> */ } else { /* -INF|INF|NAN */ }
    if (is_infinite($f)) { /* -INF|INF */ } else { /* NAN|float<(-inf, inf)> */ }
    if ($f === INF) { /* INF */ } else { /* NAN|float<[-inf, inf)> */ }
    /* float */
};
function (int $i) { if (is_nan($i)) { /* never */ } }
```

Commits:

1. `ConstantFloatType` casts of NAN, INF and out-of-range values. `toInteger()`/`toString()`/`toBoolean()`/`toArrayKey()`/`toBitwiseNotType()` ran `(int) NAN` and friends inside PHPStan, so PHPStan raised the PHP 8.5 warnings itself when folding `(int) (0 * INF)`. NAN and the infinities now cast to 0 as PHP defines; a value outside the analysing host's int range has no defined result (PHP wraps and warns), so it becomes plain `int`. Nothing here assumes a 64-bit host.
2. `IntegerRangeType::createAllSmallerThan()`/`createAllGreaterThanOrEqualTo()` compared a float against `PHP_INT_MAX`, which PHP converts to `2^63` on 64-bit builds, so a float equal to `2^63` reached `(int) ceil(2^63)`. They now compare against `PHP_INT_MAX + 1.0`, the first float above `PHP_INT_MAX` on any int width.
3. The NaN separation: `FloatType::tryRemove()` replaces `NonRemoveableTypeTrait`; `FloatRangeType`, a `final` class implementing `CompoundType` directly (no `FloatType` subclass, like `ConstantArrayType` next to `ArrayType`), with its set algebra and a canonical form (`(0.0, 1.0]` is the same set as `[5.0E-324, 1.0]`, `(0.0, 5.0E-324)` is empty; the adjacent double is computed on 16-bit words, so it works on 32-bit builds too); merging in `TypeCombinator`/`UnionType`/`UnionTypeHelper` (`float<-inf, inf>|NAN` folds back into `float`); the `is_nan`/`is_finite`/`is_infinite` extension.

Not in this PR: PHPDoc syntax (`toPhpDocNode()` widens to `float`), comparison narrowing, removing a finite point from plain `float` (`float ~ 0.0` only pays off with comparison narrowing, and alone it makes `$f > 10.0` print `NAN|float<[-inf, 0.0)>|float<(0.0, inf]>` through the existing `0.0` subtraction), the PHP 8.5 rules.

Review notes:

- `is_finite()`/`is_infinite()` only narrow arguments known to be `int|float`. `is_finite("1")` and `is_infinite("1e500")` are true at runtime, so a `numeric-string|float` argument stays as it is. `is_nan()` narrows any type; only NAN makes it true.
- `instanceof FloatRangeType` appears in `TypeCombinator`, `UnionType`, `UnionTypeHelper` and `FloatType`, the sites that dispatch on `IntegerRangeType`. The class is `final`, so that is a representation check; semantic questions go through `isFloat()`. One `phpstanApi.instanceofType` baseline entry (`instanceof IntersectionType` in `isSubTypeOf()`), the exemption `IntegerRangeType` has; delegating to `CompoundType` in general recurses through `IntegerType::isSuperTypeOf()`. If you prefer `Type` methods for both range types, I will do that first.
- Existing expectations are unchanged. New tests: `FloatRangeTypeTest`, `ConstantFloatTypeTest`, `IntegerRangeTypeTest`, nsrt `float-nan.php`, `TypeToPhpDocNodeTest`.
