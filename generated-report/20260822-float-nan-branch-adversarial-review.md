# Adversarial review: `float-nan`

Date: 2026-08-22

Review target:

- Branch: `float-nan`
- Frozen commit: `4055e3edc91f3a861ebf0d38fc92ae7a66fe7184`
- Base: `79a31bb78f2a0e24cbd63d4bca16736a86d2c188` (`2.2.x`)
- Commits: `3ee1452e8b`, `8cc4b70467`, `4055e3edc9`
- Diff: 14 files, +1425/-11
- PR draft reviewed: `20260822-pr-draft-float-nan.md`

## Verdict

**REQUEST CHANGES unless PHPStan first makes a 64-bit analyser host an explicit, enforced prerequisite.**

The branch is healthy on the intended/common 64-bit host in the focused test surface. It is not, however, merely imprecise on a 32-bit host. On an actual linux/386 PHP 8.5.9 runtime it:

1. emits a new PHP 8.5 warning while constructing the finite-float range;
2. throws if the active error handler promotes that warning;
3. fails its own new cast tests;
4. silently produces native 32-bit constant types where the PR draft promises host-independent 64-bit results.

There is no unconditional autoload-time fatal error, segfault, or default-CLI crash in the reproduced environment. The precise statement is: **default PHPStan CLI analysis consumed the `pack()` warning and completed, but direct/test/embedded use can terminate under warning promotion, and large float-to-int folds are wrong for the promised 64-bit model.**

## Findings

### High: host-independent 64-bit cast semantics cannot be represented by native `int` on a 32-bit analyser

The PR draft says the casts use “64-bit semantics regardless of the analysing host” (`20260822-pr-draft-float-nan.md:28`). The implementation nevertheless returns native PHP `int`:

- `src/Type/Constant/ConstantFloatType.php:103-119`: `castToInt(float): int` ends in `(int) $value` or `(int) $wrapped`.
- `src/Type/Constant/ConstantFloatType.php:144-146`: that native value becomes `ConstantIntegerType`.
- `src/Type/Constant/ConstantIntegerType.php:32`: the stored value is declared `int`.
- `src/Type/FloatRangeType.php:476-487`: range endpoints are also cast to native `int` after checking only the 64-bit bounds.

A 32-bit PHP process cannot store `-8446744073709551616` or `-9223372036854775808` in that representation. The live PHPStan CLI consequently inferred:

```text
(int) 1.0E19                 => -1981284352
(int) 9223372036854775808.0  => 0
(int) 2147483648.0           => -2147483648
```

The promised 64-bit results are respectively `-8446744073709551616`, `-9223372036854775808`, and `2147483648`.

This is a model/representation contradiction, not merely a missing 32-bit-project feature. PHP's official documentation says integer width is platform-dependent and an out-of-native-range float-to-int result is undefined. PHP 8.5 additionally warns for this cast.

### High: `nextUp()` is mathematically correct in the observed 32-bit runtime, but not warning-free or portable as claimed

`src/Type/FloatRangeType.php:97-120` says the two 32-bit halves make the implementation work on 32-bit PHP. The borrow path does this:

```php
private const LOW_WORD_MAX = 0xFFFFFFFF;
// ...
$low = self::LOW_WORD_MAX;
// ...
pack('NN', $high, $low);
```

On 32-bit PHP, `0xFFFFFFFF` is a `float`, not an `int`. PHP 8.5.9 emitted:

```text
The float 4294967295 is not representable as an int, cast occurred
```

This path is not obscure: `FloatRangeType::createFinite()` uses open `-INF`/`INF` bounds, immediately reaching `nextUp(-INF)`.

Observed behavior on linux/386 PHP 8.5.9:

```text
createFinite=float<(-inf, inf)>
nextUp(-1.0)=-0.99999999999999989
throwing_createFinite=ErrorException:The float 4294967295 is not representable as an int, cast occurred
```

Thus the returned adjacent double was correct when the warning was consumed on this x86 runtime, but the implementation is not warning-free. The official `pack()` documentation also calls the float-to-native-int step implementation-dependent, so this one successful bit pattern does not establish portability to every 32-bit platform.

An easy local repair is to keep every packed word native-int-representable, for example with four unsigned 16-bit halves, or use a signed `-1` word in the borrow case if verified for all supported PHP versions/platforms.

### High: the `IntegerRangeType` fix assumes a 64-bit host while reading native `PHP_INT_MAX`

The PR draft says “PHP converts `PHP_INT_MAX` to `2^63`” (`20260822-pr-draft-float-nan.md:29`). That is true on a 64-bit build because `(float) PHP_INT_MAX` rounds upward. On 32-bit PHP, `PHP_INT_MAX` is `2147483647` and is exactly representable as a float.

The new `>= PHP_INT_MAX` branches at `src/Type/IntegerRangeType.php:93-96` and `:165-168` therefore classify the exact 32-bit maximum as already beyond every int. The new test derives both its input and expected strings from native `PHP_INT_MAX` (`tests/PHPStan/Type/IntegerRangeTypeTest.php:23-31`), so it passes while asserting the wrong 32-bit ordering semantics.

If 32-bit hosts remain supported, this branch must distinguish the rounded 64-bit boundary from the exact 32-bit boundary. If they are intentionally unsupported, the runtime/package prerequisite should make this code unreachable.

### Medium: 32-bit project modelling and a 32-bit analyser host are different policy questions

The maintainer response in [#11711](https://github.com/phpstan/phpstan/issues/11711) says full 32-bit modelling is extremely rare and potentially annoying to 64-bit users. It does not state that PHPStan itself may only run on 64-bit PHP.

[#14948](https://github.com/phpstan/phpstan/issues/14948) explicitly highlights the analyser-host distinction: native `PHP_INT_SIZE` describes the PHP process running PHPStan, and `ConstantIntegerType` cannot receive a 64-bit value on a 32-bit host.

The frozen `composer.json` requires `php: ^8.2` but not Composer's `php-64bit` virtual platform package. There is therefore no enforced 64-bit-host contract that makes these failures out of scope today.

### Medium: known Type-polymorphism standard violation remains

The branch introduces direct `instanceof FloatRangeType` dispatch in `TypeCombinator`, `UnionType`, `UnionTypeHelper`, `FloatType`, and related code. This conflicts with the repository's `CLAUDE.md` rule to query behavior through `Type` polymorphism instead of concrete type checks. The new baseline entry for `instanceof IntersectionType` suppresses one instance rather than resolving it.

This is already disclosed in the PR draft and can reasonably be posed as a maintainer design question. It is not the cause of the 32-bit failures.

## Runtime evidence

### 64-bit PHP 8.5.9, exact frozen source

```text
OK (152 tests, 213 assertions)
```

The focused files were `ConstantFloatTypeTest`, `FloatRangeTypeTest`, and `IntegerRangeTypeTest`.

### linux/386 PHP 8.5.9, exact frozen source

```text
Tests: 152, Assertions: 198, Errors: 3, Warnings: 1.
```

- All three errors were the large out-of-range rows in `ConstantFloatTypeTest`; its error handler promoted the new casts' warnings.
- The warning group came from `src/Type/FloatRangeType.php:120` and affected 37 `FloatRangeTypeTest` cases.
- There were no adjacent-double assertion failures in the observed environment; the bit stepping result was correct after warning consumption.

### linux/386 PHPStan CLI

With the new predicate extension explicitly registered, the real CLI produced:

```text
is_finite() truthy branch => float<(-inf, inf)>
```

It did not expose the internal `pack()` warning or terminate. Its analysis-time error handler consumed the warning because it originated in PHPStan's own source, not the analysed file. This prevents a blanket claim that the ordinary CLI always crashes.

The same CLI silently emitted native 32-bit constants for the three large casts listed above. That is the more important production risk if the intended model is 64-bit.

## Recommended disposition

Choose and enforce one policy before presenting the branch as PR-ready:

1. **Recommended given #11711/#14948: require a 64-bit analyser host.** Add an enforceable `php-64bit`/startup requirement for the distributable paths, amend the PR draft to say this is a prerequisite rather than claiming it works regardless of host, and test the rejection path on 32-bit.
2. **If 32-bit analyser hosts remain supported:** do not attempt precise 64-bit constants with native `int`. Widen affected results or introduce an architecture-independent integer representation; fix the `IntegerRangeType` boundary condition per host; and add a linux/386 PHP 8.5 CI/probe.
3. **In either policy:** make `nextUp()` warning-free. Its 32-bit portability is independent of whether PHPStan models 32-bit target projects and is inexpensive to repair.

The #6963 design proposal can still be discussed. The current `float-nan` branch should not be handed off as a mergeable PR until the analyser-host policy is explicit and the implementation matches it.

## Review-lane summary

| Axis | Verdict | Worst issue |
|---|---|---|
| Standards | REQUEST CHANGES | concrete `Type` dispatch conflicts with repository guidance |
| Spec | REQUEST CHANGES | host-independent 64-bit semantics cannot be stored on 32-bit PHP |
| Goal/constraints | FAIL | PHP 8.5 warning and native-int contradiction |
| QA execution | FAIL | linux/386 focused suite: 3 errors, 1 warning; uncaught warning promotion exits 255 |
| Code quality | FAIL | native-int and `pack('N')` portability blockers |
| Security | PASS | no security-specific issue in the diff |
| Context | FAIL | package metadata does not exclude 32-bit analyser hosts |

Summary: Standards 1 hard finding plus one known design issue; Spec 3 findings, with native-int representability the worst. Overall review fails until the 64-bit analyser-host policy is enforced or the 32-bit paths are made sound.
