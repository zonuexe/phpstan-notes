# Issue draft — optional-key isList bug (phpstan/phpstan)

Status: **POSTED** as https://github.com/phpstan/phpstan/issues/14938 (2026-07-09).

**Title:**

```
array_is_list() reported as always false for shapes whose only non-list keys are optional
```

**Body:**

---

### Bug report

`ConstantArrayType`'s `isList` flag is computed as if every key were always
present: adding a string key sets `isList = No` unconditionally, ignoring
optionality. But an optional key may be absent at runtime, so `[]` is a valid
value of `array{a?: string}` — and `array_is_list([]) === true`. The correct
trinary answer for such shapes is *maybe*, not *no*.

```php
/** @param array{a?: string} $a */
function withOptionalStringKey(array $a): void
{
	if (array_is_list($a)) { // reported "always evaluate to false" — false positive
		\PHPStan\dumpType($a); // *NEVER* — but withOptionalStringKey([]) reaches this branch
	}
}

/** @param array{0: int, a?: string} $b */
function withOptionalExtraKey(array $b): void
{
	if (array_is_list($b)) { // reported "always evaluate to false" — false positive
		\PHPStan\dumpType($b); // list{int} — the narrowing itself is correct,
		                       // directly contradicting the "always false" report
	}
}

withOptionalStringKey([]);      // array_is_list([]) === true
withOptionalExtraKey([0 => 1]); // array_is_list([0 => 1]) === true
```

Note the internal inconsistency in the second case: the `impossibleType` check
claims the condition is always false, while TypeSpecifier's truthy narrowing
correctly produces `list{int}` for the "unreachable" branch.

Expected trinary semantics (`isList` = yes iff every possible value is a list,
no iff none is, maybe otherwise):

| shape                     | inhabitants include        | isList  | current |
| ------------------------- | -------------------------- | ------- | ------- |
| `array{a?: string}`       | `[]` (a list), `['a'=>…]`  | maybe   | no ✗    |
| `array{0: int, a?: string}` | `[0=>1]` (a list)        | maybe   | no ✗    |
| `array{a: string}`        | none is a list             | no      | no ✓    |
| `array{1: string}`        | none is a list             | no      | no ✓    |

The likely mechanism: `ConstantArrayTypeBuilder::setOffsetValueType()` assigns
`TrinaryLogic::createNo()` for non-list-compatible keys regardless of the
`$optional` flag; when the key is optional it should instead degrade with
`->and(TrinaryLogic::createMaybe())`.

This is the dual of #12725: there, shapes that also admit *non-list*
realisations report `isList` *yes*; here, shapes that also admit *list*
realisations report *no*. Both are the same trinary computation ignoring part
of the value set.

### Code snippet that reproduces the problem

https://phpstan.org/r/f3311d36-cc14-4079-8ed9-3e2391d7ff7f

### Expected output

The two `array_is_list()` calls are not reported as always false, and the
truthy branches are not `*NEVER*` — e.g. the dumped types could be `array{}`
and `list{int}`.

### Did PHPStan help you today? Did it make you happy in any way?

This came out of re-examining the semantics of array-shapes vs list-shapes
around #12725 — PHPStan's trinary logic made it possible to state precisely
what the answer *should* be, which is a pleasure in itself.

---

## Posting command (after approval)

```bash
gh issue create --repo phpstan/phpstan \
  --title 'array_is_list() reported as always false for shapes whose only non-list keys are optional' \
  --body-file <extracted body>
```
