# callable-array (array&callable) is never enforced — acceptance collapses entirely

Status: **POSTED** — findings commented on the existing upstream tracker
https://github.com/phpstan/phpstan/issues/13114 (open feature-request,
2026-05-30) as
https://github.com/phpstan/phpstan/issues/13114#issuecomment-5143932168
(2026-07-31), with playground repro
https://phpstan.org/r/175c5935-be80-487a-9114-3739a92e03e9.
No separate issue — this thread already covers the gap.

## Context

Same origin as [20260731-non-empty-mixed-not-enforced.md]: Ondřej Mirtes
questioned the conformance report's "not enforced" verdicts. For
`callable-array` the verdict is not only correct — the hole is wider than the
suite measures.

## Phenomenon

`callable-array` (and the explicit spellings `array&callable`,
`array<mixed>&callable`, `non-empty-list&callable`) is recognized —
`dumpType` shows `non-empty-list&callable(): mixed` — but **accepts every
value on every acceptance surface**, not just bad callable pairs:

```php
/** @param array&callable $value */
function f($value): void {}

f(42);                         // no error — not even an array
f(strtoupper(...));            // no error — callable but not an array
f(['x' => 1]);                 // no error
f([new Greeter(), 'missing']); // no error
f([1, 2]);                     // no error
```

Same silence in return position (`return 42;` against `@return
callable-array`) and property assignment (`/** @var array&callable */`
`$h->p = 42;`). Giving the callable a signature
(`array<mixed>&callable(): void`) changes nothing.

Contrast (all verified, PHPStan 2.2.6 level max AND 2.2.x-dev `bin/phpstan`):

| spelling | `42` | `[1,2]` | `[obj,'missing']` |
|---|---|---|---|
| `callable` | rejected | rejected | rejected |
| `array` | rejected | — | — |
| `callable&array{object\|class-string, string}` | rejected | rejected | **rejected** |
| `callable-array` / `array&callable` / `array<mixed>&callable` | accepted | accepted | accepted |

The shaped row matters twice: `[new Greeter(), 'missing']` *satisfies* the
array shape, so its rejection there proves the CallableType member CAN veto
inside an intersection. The collapse is specific to a *generic* array member
(`array<mixed>`) intersected with callable.

## Where the swallowing is NOT

- Not `IntersectionType::accepts`: standalone, both a hand-built
  `IntersectionType([ArrayType(mixed,mixed), CallableType()])` and the type
  resolved through the real DI container (`TypeStringResolver->resolve(
  'callable-array')`) return **No** for `accepts(ConstantIntegerType(42))`.
- Not `RuleLevelHelper::accepts`: it just forwards to `Type::accepts`
  (checkForUnion=true at level max).
- Not one rule: argument, return, and property-assignment paths are all
  silent, so the common ancestor is the *signature/phpdoc resolution* layer,
  not `FunctionCallParametersCheck`.

So the type system knows the answer; the resolved type the rules actually
receive must not be this intersection. Tell-tale: `missingType.callable`
("no signature specified for callable") fires on these declarations on dev,
and `missingType.iterableValue` does NOT fire for `array&callable` although
it does for plain `array` — the phpdoc type appears to be discarded (or
replaced) during phpdoc→signature merge when a generic array is intersected
with callable. Next debugging step in phpstan-src: instrument
`TypehintHelper::decideType` / the `ResolvedPhpDocBlock` parameter merge and
compare `$parameter->getType()` at rule time against the TypeStringResolver
result. (Scope-level narrowing keeps the real type — `dumpType` inside the
function shows the intersection — which is why recognition probes pass.)

Matches the observation in #13114 that `callable&array{class-string|object,
string}` and `callable&array<string|object>` work while `callable&array<mixed>`
and `callable&array` do not ("the intersection is not consistently resolved
as 'and'").

## What this means for the conformance suite

- The `phpdoc_advanced_fallback_callable_array` measurement (recognized /
  enforcement none, 0/2) is correct and, if anything, understates the gap.
- Worth adding the #13114 link to the `notes` field of
  `conformance/results/phpstan{,-strict}/phpdoc_advanced_fallback_callable_array.toml`
  so the cell explains itself. Keep the verdict "Not enforced" (it is not
  "by design": upstream treats it as a gap, not a decision).

## Next steps

1. ~~Comment on phpstan/phpstan#13114~~ — DONE 2026-07-31, see Status above.
2. Optional PR after localizing the merge-layer bug in phpstan-src (separate
   session, same workflow as non-empty-mixed).
3. Add the upstream link to the two result tomls' notes
   (`conformance/results/phpstan{,-strict}/phpdoc_advanced_fallback_callable_array.toml`).
