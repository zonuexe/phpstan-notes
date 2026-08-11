# Issue draft: non-empty-mixed is used for narrowing but not enforced at call/return boundaries

Target: phpstan/phpstan (bug report template)
Playground link: https://phpstan.org/r/5aef2572-36cd-4482-b6a3-bde781ac0ad8
(created 2026-07-31 via the playground UI, level 10/max, default PHP version;
verified persistent and reproduces "Found 3 errors" — only the narrowing
diagnostics, zero argument.type/return.type — matching the bug description)

---

## Bug report

This follows up on the [php-typing-conformance](https://zonuexe.github.io/php-typing-conformance/)
report and Ondřej Mirtes's reply:
https://phpc.social/@OndrejMirtes/117004426404360657

`non-empty-mixed` resolves correctly (`\PHPStan\dumpType()` shows
`mixed~(0|0.0|''|'0'|array{}|false|null)`) and the subtraction is fully used
for narrowing and reachability — but it is completely ignored when a value is
*accepted into* the type, in both argument and return position, at every rule
level.

The contradiction is visible in a single function: PHPStan reports
`Strict comparison using === between mixed and null will always evaluate to
false.` inside the function body — and at the same time lets a caller pass
`null` into that very parameter without any error.

(This is the promised issue for the `non-empty-mixed` "not enforced" cell.
`callable-array` is reported separately.)

### Code snippet that reproduces the problem

https://phpstan.org/r/5aef2572-36cd-4482-b6a3-bde781ac0ad8

```php
<?php

/** @param non-empty-mixed $value */
function acceptsNonEmptyMixed($value): void
{
    if ($value === null) {         // identical.alwaysFalse — subtraction IS used here
        \PHPStan\dumpType($value); // *NEVER*
    }
}

acceptsNonEmptyMixed('');          // no error — expected argument.type
acceptsNonEmptyMixed(0);           // no error
acceptsNonEmptyMixed([]);          // no error
acceptsNonEmptyMixed(null);        // no error

/** @return non-empty-mixed */
function returnsNull()
{
    return null;                   // no error — expected return.type
}
```

### Expected output

`argument.type` errors on the four calls (the arguments are entirely inside
the subtracted type, so this is a definite mismatch, not a maybe), and a
`return.type` error on `return null;`.

Passing a *general* `string` (which may be `''` or `'0'`) should of course
stay accepted — only values provably inside the subtracted type should be
rejected, keeping `mixed`'s usual looseness for partial overlaps.

### Did PHPStan help you today? Did it make you happy in any way?

Our team is bringing on a new student part-time hire next week. We're
planning to have them use PHPStan and Rector to work through a lot of tasks.

---

## Analysis (for the PR body / optional issue comment)

Two independent swallow points, split by level:

1. Levels ≤8: `MixedType::accepts` returns `AcceptsResult::createYes()`
   unconditionally, never consulting `$this->subtractedType` — in contrast to
   `MixedType::isSuperTypeOf`, which does consult it and even carries a
   ready-made reason (`Type %s has already been eliminated from %s.`).
2. Levels 9/10 (`checkExplicitMixed`): `RuleLevelHelper::transformCommonType`
   rewrites the accepting explicit `MixedType` into `new StrictMixedType()`,
   discarding the subtracted type; `StrictMixedType::accepts` is also
   unconditionally Yes. So even with (1) fixed, level max stays silent.

The same gap affects any subtracted mixed (e.g. `mixed~null` carried through
narrowing), not just the `non-empty-mixed` spelling.
