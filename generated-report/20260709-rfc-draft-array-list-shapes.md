# RFC draft — array-shape vs list-shape semantics (phpstan/phpstan Discussions, General)

Status: **POSTED** as https://github.com/phpstan/phpstan/discussions/14939 (2026-07-09).

**Title:**

```
RFC: Honest key-order semantics for array shapes vs list shapes (a migration path for 3.x)
```

**Body:**

---

This RFC proposes a coherent semantic model for `array{...}` vs `list{...}`
shapes and a staged migration path, following the rollout pattern established
by [sealed array shapes](https://phpstan.org/blog/phpstan-2-2-unsealed-array-shapes-safer-array-keys).
It grew out of re-examining phpstan-src PR [#3872](https://github.com/phpstan/phpstan-src/pull/3872)
and issue #12725, which — although I authored both — take *opposite* positions
on the same question. That contradiction turned out to be a symptom of a real
ambiguity worth fixing, not just my confusion.

## The problem, measured

On current 2.2.x, all four of these PHPDoc spellings resolve to the **identical
type**, all with `isList = yes`:

| PHPDoc source                  | resolved type            | isList |
| ------------------------------ | ------------------------ | ------ |
| `array{string, string}`        | `array{string, string}`  | yes    |
| `array{0: string, 1: string}`  | `array{string, string}`  | yes    |
| `list{string, string}`         | `array{string, string}`  | yes    |
| `array{string, string}&list`   | `array{string, string}`  | yes    |

Meanwhile **acceptance is order-insensitive**: all of them (except the general
`list<string>`) silently accept `[1 => 'x', 0 => 'y']` — a value PHPStan
itself infers as `array{1: 'x', 0: 'y'}` with `array_is_list(...) === false`:

| target type                       | accepts proven non-list `[1=>'x', 0=>'y']`? |
| --------------------------------- | ------------------------------------------- |
| `list<string>` (general)          | rejected ✓                                  |
| `list{string, string}` (shape)    | **silently accepted** ✗                     |
| `array{0: string, 1: string}`     | accepted (fine — but then isList must not be *yes*) |

So `array_is_list($param)` narrows to `true` on a parameter that can hold a
proven non-list at runtime. That is #12725. The dual defect — `isList = no`
on shapes that *do* admit list values (`array{a?: string}` admits `[]`) — is
#14938. Both come from the same root: the `isList` flag is not computed over
the set of values the type actually admits.

There is a second, older symptom of the same root. PHP arrays are ordered
maps, but a structural type that acceptance-checks only key→value pairs cannot
soundly predict order-dependent operations. PHPStan currently trusts the
*declared* key order inconsistently. For `/** @param array{a: 1, b: 2} $a */`
called as `f(['b' => 2, 'a' => 1])` (accepted, no error):

| operation on `$a`        | inferred          | sound? (runtime is `['b'=>2,'a'=>1]`) |
| ------------------------ | ----------------- | ------------------------------------- |
| `array_values($a)`       | `list{1, 2}`      | ✗ (runtime `[2, 1]`)                  |
| `array_keys($a)`         | `list{'a', 'b'}`  | ✗ (runtime `['b', 'a']`)              |
| `array_slice($a, 0, 1)`  | `array{a: 1}`     | ✗                                     |
| `array_reverse($a)`      | `array{b: 2, a: 1}` | ✗                                   |
| `array_shift` / `array_pop` | `1\|2`         | ✓                                     |
| `reset` / `end` / `array_key_first/last` | union | ✓                            |
| `foreach` first key/value | `'a'\|'b'` / `1\|2` | ✓                                 |

This RFC does **not** propose fixing the positional-projection rows (that
would be a large precision regression — `array_values(array{a: 1, b: 2})`
would have to become `list{1|2, 1|2}`); it proposes making list-*ness*
(identity, acceptance, `array_is_list` narrowing) honest, and documenting the
projection compromise explicitly.

## Proposed model

One sentence: **`array{...}` describes a key *set*; `list{...}` describes a
key *sequence*.**

- **`array{...}`** is an order-agnostic structural map. It accepts any
  permutation of its keys: `array{0: T, 1: U}` accepts `[1 => u, 0 => t]`,
  exactly as it does today. Keyless `array{T, U}` is mere shorthand for
  `array{0: T, 1: U}` (auto-indexing), so it means the same thing.
- **`list{...}`** (and `...&list`) is positional: keys are `0..n-1` *in
  ascending order*. Acceptance must reject a proven non-list, the same way
  general `list<T>` already does.
- **`isList` is defined denotationally** over the admitted value set:
  *yes* iff every admitted value passes `array_is_list()`, *no* iff none does,
  *maybe* otherwise. This gives, for sealed shapes:

  | shape                | admitted values                | isList |
  | -------------------- | ------------------------------ | ------ |
  | `array{}`            | `[]` only                      | yes    |
  | `array{0: T}`        | `[0=>t]` only (one key ⇒ order trivial) | yes |
  | `array{0?: T}`       | `[]`, `[0=>t]` — both lists    | yes    |
  | `array{0: T, 1: U}`  | both key orders                | **maybe** (today: yes ✗) |
  | `array{T, U}` (keyless) | same as above               | **maybe** (today: yes ✗) |
  | `array{a?: T}`       | `[]` is a list                 | **maybe** (today: no ✗ — #14938) |
  | `array{a: T}`, `array{1: T}` | none is a list         | no     |
  | `list{T, U}`         | `[t, u]` only                  | yes    |

Note this is *not* "any explicit key ⇒ maybe" (the approach in PR #3872,
which would wrongly demote `array{0: T}` and `array{}` from yes). The trinary
must follow the value set, and PHPStan's `TrinaryLogic` already expresses this
exactly — only the transitions need fixing.

Two consistency requirements come with the model:

1. **Intersection/subtraction must refine the flag**: `array{0:T,1:U} & list`
   ⇒ isList yes (this is the still-broken `$b` case of #12725);
   `assert(array_is_list($x))` narrows by flipping the flag. Because the type
   tracks key→type (not position→type) and that mapping is
   permutation-invariant, narrowing needs no structural surgery at all.
2. **`describe()` keeps round-trip faithfulness.** There is already a
   mechanism for this: `ConstantArrayType::shouldBeDescribedAsAList()` renders
   `list{0?: string, 1?: string}` today precisely because spelling it
   `array{...}` would lose list-ness. Once keyless `array{'a', 'b'}` parses to
   *maybe*, that same principle extends to every yes-list: they render as
   `list{'a', 'b'}`. (The alternative — keeping `array{...}` at value
   verbosity — guarantees "expects array{string, string}, array{string,
   string} given" errors, since the two types differ only in list-ness.)

## Migration path

Mirroring the sealed-shapes rollout:

1. **Now, on stable:** migrate `functionMap.php` returns that are genuinely
   lists from `array{0: X, 1: Y}` to `list{X, Y}` (`token_get_all`, `fgetcsv`,
   `Imagick::compareImages`, `Redis::time`, …). This is a semantic no-op today
   (both spellings resolve identically) but prevents those signatures from
   losing list-ness under the new semantics. I can send this PR immediately —
   it is the salvageable core of phpstan-src #3872, which I would close in
   favour of this plan.
2. **Behind bleeding edge, default in the next major:** the semantic bundle,
   shipped together so no intermediate state exists where explicit-key and
   keyless spellings disagree:
   - `array{0:T,1:U}` and keyless `array{T,U}` → isList *maybe*;
   - `list{...}` / `&list` acceptance enforces list-ness (rejects proven
     non-lists) with an `IsSuperTypeOfResult` reason 💡, e.g. *"list shape
     requires keys 0..n-1 in ascending order"* — same machinery as sealed
     rejections;
   - `describe()` renders yes-lists as `list{...}` (extension of the existing
     `shouldBeDescribedAsAList()`).
3. **No permanent config toggle.** A runtime switch would fork the meaning of
   the syntax forever.

Independently of the bundle, #14938 (optional keys forcing isList *no*) is a
plain bug fixable on stable now.

### Expected churn, honestly

- New errors wherever an `array{...}`-typed value flows into a `list<T>` /
  `list{...}` position (params, return covariance, property writes, template
  bounds). These are real findings — the value's key order was never
  guaranteed. The fix is to change the annotation to `list{...}` where a list
  was meant, which is also self-documenting.
- Test-expectation churn from `describe()`: in phpstan-src alone, roughly half
  of ~1,900 `array{...}` type assertions become `list{...}`. Mechanical to
  update; extension authors would see the same on their bleeding-edge runs.
  Precedent: accessory-type describe changes (e.g. `non-falsy-string`) were
  larger and shipped even in minors; this one is gated.

### A stricter alternative (for discussion)

If the keyless form's history (everyone has written `array{int, string}`
meaning a tuple) makes the silent meaning-flip too subtle, the stricter option
is to also **deprecate keyless `array{...}` in 3.x**, requiring `list{T, U}`
or `array{0: T, 1: U}` and eliminating the ambiguity outright. I lean against
it — it would warn on Psalm-compatible code and on legitimate "map with keys
0 and 1" usage, and the describe() change already surfaces the distinction —
but it deserves a mention as the maximal-clarity endpoint.

## Prior art / references

- #12725 (isList false positives; superseded analysis in this RFC)
- #14938 (the dual bug: optional keys force isList *no*)
- phpstan-src #3872 (earlier attempt; would be closed/split per this plan)
- #8789 (origin of `list{...}` shapes), #8438 (sealed shapes)
- [PHPStan 2.2: unsealed array shapes](https://phpstan.org/blog/phpstan-2-2-unsealed-array-shapes-safer-array-keys)
  (the rollout template this follows)

I'm happy to implement all pieces; what I'm looking for here is agreement on
the model (key *set* vs key *sequence*) and on the staging.

---

## Posting command (after approval)

```bash
gh api graphql -f query='mutation { createDiscussion(input: {repositoryId: "...", categoryId: "MDE4OkRpc2N1c3Npb25DYXRlZ29yeTMyMDE5ODM4", title: "...", body: "..."}) { discussion { url } } }'
```

(General category id: `MDE4OkRpc2N1c3Npb25DYXRlZ29yeTMyMDE5ODM4`)
