# RFC #14939 cross-implementation check — PHPStan vs Psalm vs Mago vs Phan

Ran the RFC's array-shape/list-shape probes on five engines locally.

- **PHPStan** — bleedingEdge, current 2.2.x (BEFORE #6025), via playground API (`dumpType`).
- **Psalm** — release 6.16.1 (`@psalm-trace`, branch narrowing).
- **Mago** — 1.43.0 `mago analyze` (Rust; Psalm/PHPStan-annotation compatible;
  honours `@psalm-trace`, also has native `Mago\inspect($expr)`).
- **Phan** — 6.0.7 (`'@phan-debug-var $x'` **string-literal** annotation — NOT a
  docblock — dumps phpdoc type + `real` type; branch narrowing).
- **pzoom** — a Rust port of Psalm. **Reproduced Psalm on every probe**, so it
  is omitted from the table (see note below).

## Results (order: PHPStan, Psalm, Mago, Phan)

| # | probe | PHPStan | Psalm 6.16 | Mago 1.43 | Phan 6.0 |
| - | ----- | ------- | ---------- | --------- | -------- |
| T1 | keyless `array{int, string}` — is it a list? | **yes** | **yes** (`list{int,string}`) | **yes** | **maybe** |
| T2 | explicit `array{0:int, 1:string}` — is it a list? | **yes** | **no — never** | **yes** | **maybe** |
| T3 | `list{int, string}` — is it a list? | yes | yes¹ | yes | yes (as `list<mixed>`) |
| T4a | `array_values(array{a:1, b:2})` | `list{1, 2}` ✗ order-trusting | `list<1\|2>` ✓ | `list<1\|2>` ✓ | `list<1>\|list<2>` ✓ |
| T4b | `array_keys(array{a:1, b:2})` | `list{'a', 'b'}` ✗ | `list<'a'\|'b'>` ✓ | `list<'a'\|'b'>` ✓ | `list<string>` ✓ |
| T5 | `array{a?: string}` — is it a list? | **no** | **no** | **no** | **maybe** ✓ |
| C3 | reversed literal → `list{...}` param | accept (silent) | accept + coercion warn | accept (silent) | **REJECT** (TypeMismatchArgument) |
| C2 | reversed literal → `array{0:,1:}` param | accept | accept | accept | accept |

Notes:
- **pzoom** (Psalm's Rust port) matches Psalm on every row (order-agnostic T4,
  `array{0:int,1:string}` never-a-list T2, `array{a?:string}` never-a-list T5,
  coercion warning on C3). Omitted to avoid a redundant column.
- **¹** Psalm's `!array_is_list()` negation is imprecise — a declared
  `list{...}`'s false branch stays reachable — but the type is a list.
- PHPStan values are current bleedingEdge (pre-#6025). #6025 changes T5 to
  *maybe* (matching Phan), and under the RFC keyless T1 too.
- **Phan T4a caveat (recorded, not reported).** Phan prints
  `array_values(array{a:1, b:2})` as `list<1>|list<2>` — which is technically
  *unsound*: that union is "a list of all-1s" ∪ "a list of all-2s" and excludes
  the actual runtime value `[1, 2]`; the sound type is `list<1|2>` (Psalm/Mago).
  It's an array_values inference imprecision (distributes over the shape's
  distinct values instead of unioning them). **But it causes no observable false
  positive** — Phan keeps a sound `real` type `?list<?mixed>`, element access is
  `1|2`, and `$v === [1, 2]` / `$v === [2, 1]` are not flagged impossible — so
  it's a latent print-type imprecision, not a T5-style bug. For the RFC's
  order-trusting question it's still on the correct (order-agnostic) side: Phan
  does not commit to `list{1, 2}`. Verdict: worth noting, not worth a report.
- **How each engine exposes list-ness**: PHPStan `dumpType(array_is_list($a))`
  → `true`/`false`/`bool`; Mago flags the `if (array_is_list($a))` condition as
  always-true / impossible; Psalm's & Phan's `array_is_list()` return is always
  `bool`, so the verdict is read from tracing `$a` in each `if`/`else` branch
  (a `never`/unreachable branch = decided). Phan additionally exposes a `real`
  type; its is-list branch narrows `$a`'s real type to `list<…>`, the else
  branch to `non-empty-associative-array<…>` — both reachable ⇒ *maybe*.

## Takeaways for the RFC

1. **T4 — PHPStan is the lone order-truster (headline).** For `array_values` /
   `array_keys` on an associative shape, **Psalm, Mago, Phan and pzoom all
   return order-agnostic types** (`list<1|2>`, `list<string>`, …); only PHPStan
   commits to the declared order (`list{1, 2}` / `list{'a', 'b'}`). Four
   independent engines on the sound side is strong support for the RFC treating
   PHPStan's order-trusting projection as a bug.

2. **Phan is the closest to the RFC's target semantics.** It treats **both**
   `array{int,string}` and `array{0:int,1:string}` as *maybe*-a-list
   (order-agnostic; its is-list branch narrows to `list<…>`, its not-list branch
   to `non-empty-associative-array<…>`), gets `array{a?:string}` right as
   *maybe* (T5), **and is the only engine that hard-rejects a reversed literal at
   a `list{…}` param** (T4/C3) — i.e. it already enforces the acceptance
   tightening the RFC proposes.

3. **Psalm draws the keyless-vs-explicit split (differently from everyone).**
   Psalm parses keyless `array{int, string}` as `list{int, string}` (a list) but
   `array{0:int, 1:string}` as a non-list keyed shape (*never* a list). That is
   the keyless≠explicit distinction PR #3872 explores — Psalm is the only engine
   that draws it (though it lands on "never" for explicit, vs the RFC's "maybe").
   PHPStan and Mago treat both spellings as *always* a list; Phan treats both as
   *maybe*.

4. **T5 — the optional-key defect (#14938): PHPStan, Psalm, Mago all get it
   wrong; only Phan is right.** All three treat `array{a?: string}` as *never* a
   list, though `[]` is a valid list value; Phan alone says *maybe*. #6025's fix
   (→ *maybe*) brings PHPStan in line with Phan.

5. **C3 — acceptance strictness ranking.** Phan **rejects** the reversed literal
   at a `list{…}` param (strictest, = RFC's proposal); Psalm/pzoom **warn**
   (`ArgumentTypeCoercion`); PHPStan and Mago **accept silently** (both normalise
   array literals by key). So the RFC's list-shape acceptance tightening is
   already shipped by one mainstream analyzer (Phan).

## Repro

Snippets in `scratchpad/` (`rfc2.php` traces result types, `rfc3.php` traces
branch narrowing). Commands:

```
php ... phpstan analyse (bleedingEdge)          # or playground API bleedingEdge:true
psalm --no-cache --show-info=true rfc3.php      # release Psalm 6.16
mago analyze rfc3.php                            # Mago 1.43
pzoom analyze rfc3.php                           # Psalm Rust port
```
