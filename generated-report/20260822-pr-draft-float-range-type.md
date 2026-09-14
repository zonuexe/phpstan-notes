# DRAFT: PR body for branch `float-range-type` (Draft PR, not submitted)

Target: `phpstan/phpstan-src`, base `2.2.x`, opened as Draft. Title:

> [WIP] Float ranges: `float<a, b>`, comparison narrowing, finite-point removal

---

Prototype for https://github.com/phpstan/phpstan/issues/6963 (design: <link to the #6963 comment>). The first three commits are <#PR_NAN>; review them there. This PR is about the fourth commit and will be rebased once <#PR_NAN> lands.

The fourth commit adds:

- PHPDoc: `float<a, b>` (closed), `min`/`max`/`inf` for the unbounded ends (phpdoc-parser cannot lex `-inf`; #13504 hits the same wall), and an optional boundary keyword from PHP 8.3's `Random\IntervalBoundary`: `float<0.0, 1.0, closed-open>`, `float<min, max, open-open>` (finite). `toPhpDocNode()` emits this form and round-trips; `describe()` prints `float<[0.0, 1.0)>`.
- Comparison narrowing: the four `// subtract range when we support float-ranges` placeholders in `ConstantNumericComparisonTypeTrait`. `$f > 0.0` gives `float<(0.0, inf]>`, the falsy side `NAN|float<-inf, 0.0>`. The falsy side keeps NAN and is not narrowed when the other operand may be NAN (`!($f < NAN)` holds for every `$f`). A range on the right-hand side narrows on its canonical bounds.
- `float ~ 0.0` gives `NAN|float<[-inf, 0.0)>|float<(0.0, inf]>`; `$f !== 1.0` now makes a guarded variable certain.
- `negate()`, `toInteger()` on the canonical bounds (`(int)` of `(0.0, 1.0)` is `0`, of `(1.0, 2.0)` is `1`), unary minus on unions member by member.

Changed expectations: 15 tests, all in the direction the old comments predicted. `bug-5309.php` had `// could be '0.0' when we support float-ranges`; `comparison-operators.php` goes from `float` to `float<(10.0, inf]>`; `TypeSpecifierTest` goes from `mixed~(int<3, max>|true)` to `mixed~(NAN|float<3.0, inf>|int<3, max>|true)`. Feedback on the longer `mixed~(...)` output is welcome.

Not here: arithmetic propagation, `positive-float`/`finite-float` aliases, return types for `abs`/`sqrt`/`sin`/`Randomizer::getFloat`/`FILTER_VALIDATE_FLOAT`, the PHP 8.5 rules, `PHP_FLOAT_MAX`/`MIN`/`EPSILON` as constants, `$x !== $x`, #14394.

Open questions are in the #6963 comment.
