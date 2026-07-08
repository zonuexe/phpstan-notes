# PR #3872 — array-shapes vs list-shapes semantics

Working report for a `/grill-with-docs` session reconsidering
[phpstan/phpstan-src#3872](https://github.com/phpstan/phpstan-src/pull/3872)
("Treat array-shapes without keys as tuples"), refs
[phpstan/phpstan#12725](https://github.com/phpstan/phpstan/issues/12725).

Status: **PROPOSAL READY** — design converged via `/grill-with-docs`. See
[Proposal](#proposal--what-3872-becomes) at the end.

---

## What the PR does (as written, March 2025)

In `TypeNodeResolver::resolveArrayShapeNode()`:

- `list{...}` / `non-empty-list{...}` → `isList = Yes` (unchanged).
- `array{T1, T2}` with **no explicit keys** → `isList = Yes` ("treated as a tuple").
- `array{...}` with **any explicit key** (`array{0: T, 1: T}`, `array{a: T}`) →
  builder seeded with `createEmptyIndeterminate()` → `isList = Maybe`.

Plus a `functionMap.php` migration: several return types written as
`array{0: X, 1: Y}` were rewritten to `list{X, Y}` to preserve `isList = Yes`
(e.g. `imageaffinematrixget`, `Imagick::compareImages`, `Redis::time`,
`token_get_all`, `fgetcsv`'s `array{0: null}` → `list{null}`).

The PR predates the **sealed-array** feature, which has since rewritten most of
`resolveArrayShapeNode()` (unsealed key types, `makeUnsealed`,
`disableArrayDegradation`, explicit-key tracking). So it no longer applies
cleanly and must be re-derived, not rebased.

## Note: the issue and the PR disagree

Both #12725 and #3872 are by zonuexe. But:

- **Issue #12725** asserts `array{string, string}` should be **maybe-a-list**
  (`array_is_list($a)` should *not* be reported as always-true), and only
  `array{string,string}&list` should be always-a-list.
- **PR #3872** makes keyless `array{string, string}` **always-a-list** (Yes),
  and instead demotes the *explicit-key* `array{0: string, 1: string}` to Maybe.

These are opposite treatments of the keyless case. Reconciling them is the
core of this session.

---

## Ground truth on current 2.2.x (measured, not assumed)

All four of these **collapse to the identical type** `array{string, string}`,
all with `isList = Yes`:

| PHPDoc source                    | describe()             | isList |
| -------------------------------- | ---------------------- | ------ |
| `array{string, string}`          | `array{string, string}`| Yes    |
| `array{0: string, 1: string}`    | `array{string, string}`| Yes    |
| `list{string, string}`           | `array{string, string}`| Yes    |
| `array{string, string}&list`     | `array{string, string}`| Yes    |

Three separate layers are tangled:

1. **describe() layer** — `list{...}` is invisible; it renders as `array{...}`.
   There is currently no surface distinction between a list-shape and an
   array-shape.
2. **isList() metadata layer** — every sequential-key shape reports `Yes`.
   This is the layer the PR edits.
3. **acceptance layer** — `array{string, string}` (isList Yes) **accepts**
   `array{1: 'x', 0: 'y'}` (a value PHPStan itself infers as isList **No**).
   No `argument.type` error is raised. The list "promise" is not enforced on
   assignment/argument-passing at all.

So `array_is_list($keyless)` narrows to `true` (layer 2) even though a proven
non-list value can flow into `$keyless` (layer 3). The PR only touches layer 2,
and only for the explicit-key spelling.

---

## The deeper root: ordered map vs structural set (from interview)

zonuexe's reframing: PHP arrays are **ordered maps**. A structural type that
records only key→value pairs cannot soundly predict order-dependent operations.
PHPStan's `ConstantArrayType` stores a *declared* key order, but **acceptance is
order-insensitive** — so declared order is a best-effort annotation, not a
runtime guarantee. Measured, PHPStan is internally **inconsistent** about
whether it trusts that order:

For `/** @param array{a: 1, b: 2} $a */` called as `f(['b' => 2, 'a' => 1])`
(accepted, no error):

| Operation        | PHPStan infers   | Sound? | runtime for `['b'=>2,'a'=>1]` |
| ---------------- | ---------------- | ------ | ----------------------------- |
| `array_values`   | `list{1, 2}`     | ✗ UNSOUND | `[2, 1]`                   |
| `array_keys`     | `list{'a','b'}`  | ✗ UNSOUND | `['b', 'a']`               |
| `foreach` 1st k  | `'a'\|'b'`       | ✓ sound | either                       |
| `foreach` 1st v  | `1\|2`           | ✓ sound | either                       |
| `reset`          | `1\|2`           | ✓ sound | either                       |

The sound-but-imprecise form of `array_values(array{a:1,b:2})` is
`list{1|2, 1|2}`. PHPStan deliberately returns `list{1, 2}` — **precision over
soundness**, a known, tolerated compromise.

### The list-shape acceptance hole (measured)

A *proven* non-list `array{1:'x', 0:'y'}` (isList No) is:

| Target type                | Rejected? |
| -------------------------- | --------- |
| `list<string>` (general)   | ✓ error (correct) |
| `list{string, string}` (shape) | ✗ silently accepted |
| `array{0:string,1:string}` (shape) | ✗ silently accepted |

So the general `list<T>` enforces list-ness at acceptance; the **shape** forms
do not. This is the concrete mechanism behind #12725's `$b` false negative.

### Consequence for the A/B question

The isList false-positive and the `array_values` unsoundness are **the same
root**: order-dependent inference trusting a declared order that acceptance
never enforces. So "keyless = tuple (isList Yes)" (position A) is exactly as
(un)sound as the already-shipped `array_values` behavior — it is *consistent*
with how PHPStan already treats declared order. Position B is *more sound* but
inconsistent with `array_values` unless that is also fixed.

A principled split emerges:
- **Positional arrays** (list / tuple): order **is** identity and **is**
  runtime-observable (a list's keys really are `0..n-1` in order). Here isList
  Yes is sound *iff* acceptance also enforces it.
- **Associative / explicit-int-keyed** maps `array{a:1,b:2}` / `array{0:T,1:T}`:
  declared order is cosmetic; only the key *set* is real ⇒ isList should reflect
  the set (`array{0:T,1:T}` can be realised as a non-list ⇒ Maybe).

This re-derives the PR's keyless-Yes / explicit-int-key-Maybe distinction from a
principle, rather than from syntax alone.

## Glossary (in progress)

- **ordered map** — PHP's actual array semantics: keys retain insertion order.
- **structural set view** — treating an array type as an unordered key→value
  set; sound for order-independent ops, imprecise for order-dependent ones.
- **declared order (best-effort annotation)** — the key order written in a
  ConstantArrayType; trusted by array_values/array_keys/isList, ignored by
  foreach/reset and by acceptance. Not a runtime guarantee.
- **positional array** — list/tuple; order == identity, runtime-observable.

- **array-shape** — `array{...}` PHPDoc syntax; `ArrayShapeNode` with
  `kind = KIND_ARRAY`.
- **list-shape** — `list{...}` / `non-empty-list{...}`; `kind = KIND_LIST` /
  `KIND_NON_EMPTY_LIST`. Intersected with `AccessoryArrayListType`.
- **keyless array-shape** — `array{T1, T2}`; every item has `keyName === null`.
- **explicit-key array-shape** — at least one item has a `keyName`
  (`array{0: T}`, `array{a: T}`).
- **tuple** — informal: a fixed-length, positionally-indexed list. In this
  discussion, the candidate meaning for a keyless array-shape.
- **isList (Yes/Maybe/No)** — `ConstantArrayType::isList()`; the promise that
  keys are `0..n-1` in ascending order (⇒ `array_is_list()` is true).

## Open questions

(filled during the interview)

## Decisions

### D1 — `array{0: T1, 1: T2}` is NOT the same type as `list{T1, T2}`

Settled by zonuexe. An explicit-int-keyed array-shape describes only the key
**set** `{0, 1}`, so it must accept any permutation, e.g.
`[1 => 'foo', 0 => 'bar']`. Therefore:

- `array{0: T1, 1: T2}` → **isList = Maybe** (order-agnostic; accepts reversed).
- `list{T1, T2}` → **isList = Yes** (positional; must reject reversed).

Current 2.2.x already *accepts* the reversed literal for `array{0:…,1:…}`
(correct); the only defect there is that it reports `isList = Yes`. So the fix
for the explicit-int-keyed case is narrow: seed the builder with
`isList = Maybe` (the PR's `createEmptyIndeterminate`).

For `list{T1, T2}` the defect is the opposite: it must *reject* the reversed
literal at the acceptance layer (currently it silently accepts).

## Emerging model (candidate, pending D2)

- **`array{...}`** = structural set of key→value. Order-agnostic. Accepts
  permutations. `isList` = Maybe (all-int-sequential) or No (any string /
  non-sequential key) — **never Yes on its own**.
- **`list{...}` / `&list`** = positional. `isList` = Yes. Rejects permutations
  (acceptance must enforce).

### D2 — keyless `array{T1, T2}` is *shorthand* for `array{0:T1, 1:T2}` (⇒ Maybe *in principle*)

zonuexe: keyless `array{T1, T2}` is merely shorthand for `array{0:T1, 1:T2}`,
so *in principle* it should accept `[1 => 'foo', 0 => 'bar']` and be
`isList = Maybe`. **But** an enormous body of existing PHPDoc (external libs,
older PHPStan extensions) wrote keyless `array{T1, T2}` *meaning a tuple/list*,
and flipping stable behavior would break integration with them. A permanent
runtime config toggle is rejected — it forks the syntax's meaning permanently
and adds confusion. Therefore the flip is **correct but must be deferred behind
`bleedingEdge`**, becoming default in a future major (3.0), exactly as sealed
arrays are done.

## ADR-0001 — Roll out list-shape strictification via functionMap-now + bleedingEdge-3.0

**Status:** proposed (this session)

**Context.** The clean model (D1+D2: `array{...}` uniformly order-agnostic /
never isList-Yes on its own; `list{...}`/`&list` uniformly positional /
isList-Yes / enforced at acceptance) is agreed on principle, but keyless
`array{T1,T2}` → Maybe is a heavy BC break on existing tuple-intent PHPDoc.
The mechanism in this code area is `BleedingEdgeToggle::isBleedingEdge()`
(sealed arrays use it; no named flag). Permanent runtime toggles are out.

**Decision.**

1. **functionMap migration ships to stable now**, on its own: rewrite genuinely
   list-returning `array{0:X,1:Y}` entries to `list{X,Y}` (the salvageable core
   of PR #3872). Pure precision gain, no engine semantics touched, and it is a
   *prerequisite* for the strict world (otherwise those returns lose list-ness
   under it).
2. **All isList / acceptance / describe() semantic changes ride one
   bleedingEdge-gated change**, flipping to default in 3.0, implemented together
   so stable never bakes in the surprising asymmetry (explicit=Maybe while
   keyless=Yes):
   - `array{0:T,1:T}` and keyless `array{T1,T2}` → `isList = Maybe`;
   - `list{...}`/`&list` acceptance **enforces** list-ness (reject a proven
     `array{1:x,0:y}`), matching general `list<T>`;
   - `describe()` renders list-shapes as `list{...}` so users can see and
     migrate.
3. **No permanent runtime config option.**

**Consequences.** Stable stays coherent and low-risk; the concrete #12725 `$b`
false-negative and the explicit-key false-positive are fixed in bleedingEdge;
the thorny keyless demotion is quarantined to 3.0 with a visible describe() so
users can migrate `array{int,string}` → `list{int,string}`. PR #3872 is thus
*split*, not rebased: its functionMap diff becomes a small stable PR; its
TypeNodeResolver idea is re-derived on top of sealed arrays behind bleedingEdge.

**Open:** whether `array{0:T,1:T}`→Maybe is safe enough to also ship stable
(loosening only), or must wait with the rest in bleedingEdge (kept together for
coherence — current lean: together).

## Precedent: how sealed array shapes were rolled out (the template)

From [PHPStan 2.2 blog, 2026-05-28](https://phpstan.org/blog/phpstan-2-2-unsealed-array-shapes-safer-array-keys):

- **Mechanism:** opt-in via bleeding edge now, *"it will become the only
  behaviour in the next major PHPStan version."* Exactly ADR-0001's plan.
- **BC framing (quotable template):** *"It's a big disruption — real-world
  projects will typically see hundreds of new errors when they turn this on.
  These errors are valuable and important to fix, but we decided to gate it
  behind bleeding edge because of PHPStan's backward compatibility promise."*
- **No step-by-step migration doc.** Guidance lives in error-message 💡 tips
  and the playground.
- **No syntax deprecation.** Sealed simply flips the default meaning. → our
  keyless change can follow the same "flip meaning, don't deprecate syntax"
  path unless we deliberately choose to go further (see D3).
- **Rejection-reason mechanism (💡):** e.g. *"Sealed array shape can only accept
  a constant array. Extra keys are not allowed."* Built on `IsSuperTypeOfResult`
  reasons ([#5827](https://github.com/phpstan/phpstan-src/pull/5827),
  [#6003](https://github.com/phpstan/phpstan-src/pull/6003)). **Reuse this** for
  the `list{}` acceptance enforcement: reject `array{1:x,0:y}` with a reason
  like "List shape requires keys 0..n-1 in order."

### Orthogonality note

Sealedness (key-set closure) and list-ness (key order) are **independent axes**.
A sealed `array{0:string, 1:string}` still admits `[1=>'x', 0=>'y']` (key set
`{0,1}` satisfied, order not) — sealed but non-list. So list-ness is a separate
accessory concern (`AccessoryArrayListType`) that must be enforced on its own;
sealing does not deliver it.

### Related issues

- [#8789 List shapes](https://github.com/phpstan/phpstan/issues/8789) — origin
  of `list{}` syntax (zonuexe).
- [#8438 sealed/unsealed array shapes](https://github.com/phpstan/phpstan/issues/8438) — sealed feature request.
- [#14722 Compose sealed array shapes](https://github.com/phpstan/phpstan/issues/14722) — active follow-up pain with sealed composition.

### D3 — flip keyless meaning (no deprecation) as the headline; deprecation is a stricter *alternative*

Following the sealed precedent ("flip default meaning, do not deprecate
syntax"), the **headline proposal (a)** is to fix the *meaning* of keyless
`array{T1,T2}` to order-agnostic / `isList = Maybe`, keeping the syntax valid
and Psalm-interoperable, with `describe()` now rendering list-shapes distinctly
so authors who wanted a list can switch to `list{...}`. A **stricter
alternative (b)** — deprecating keyless `array{}` in 3.0.x to force
`list{...}` or `array{0:,1:}` — is offered as an option for maximal
disambiguation but is not the default (it would break Psalm interop and be far
noisier than sealed's rollout). Soft nudges (💡 tips suggesting `list{...}`) sit
between the two and are compatible with (a).

---

## Proposal — what #3872 becomes

The clean model (all resolved): **`array{...}` is uniformly an order-agnostic
structural key→value set** (accepts key permutations; `isList` is Maybe for
all-int-sequential keys, No otherwise, never Yes on its own). **`list{...}` /
`&list` is uniformly positional** (`isList` Yes; acceptance rejects
permutations). Keyless `array{T1,T2}` is just shorthand for `array{0:T1,1:T2}`.

Rolled out in three separable pieces:

### PR-A (stable, now) — functionMap precision migration
Rewrite functionMap / functionMap_php80delta entries whose returns are genuinely
lists from `array{0:X,1:Y}` (and `array{0: null}`) to `list{X,Y}` (and
`list{null}`): `imageaffinematrixconcat`, `imageaffinematrixget`,
`Imagick::compareImages`, `Imagick::compareImageChannels`, `Redis::time`,
`Redis::geopos`, `Redis::getTransferredBytes`, `token_get_all`, `fgetcsv`,
`SplFileObject::fgetcsv`, …. This is the salvage of #3872's functionMap diff.
No engine change; pure precision gain; prerequisite for the strict world.
*Commit title style:* "Use list-shapes for genuinely-list functionMap returns."

### PR-B (bleeding edge → default in 3.0) — the semantic bundle
Gated on `BleedingEdgeToggle::isBleedingEdge()`, all together for coherence:
1. `array{0:T,1:T}` **and** keyless `array{T1,T2}` → `isList = Maybe`
   (re-derive #3872's `createEmptyIndeterminate` idea on top of the current
   sealed-array `resolveArrayShapeNode`).
2. `list{...}` / `&list` acceptance **enforces** list-ness — reject a proven
   `array{1:x,0:y}` — matching general `list<T>`, with an
   `IsSuperTypeOfResult` 💡 reason.
3. `describe()` renders list-shapes as `list{...}` so the distinction is visible.
Fixes #12725: `$a` no longer "always list"; `$b` reversed literal now rejected.

### Announcement (mirrors the 2.2 sealed post)
Headline **(a)**: keyless meaning flips to order-agnostic; `list{...}` is the
list spelling; opt-in via bleeding edge now, only behaviour in 3.0; expect new
errors, they're valuable. Stricter **alternative (b)** floated for discussion:
deprecate keyless `array{}`.

### Test homes
- `tests/PHPStan/Analyser/nsrt/list-shapes.php` (the file #3872 already touched)
  + new `bug-12725.php` with `assertType` for isList and the accept/reject cases.
- Rule-level expectations for the new `list{}` acceptance errors.
- Verify each fails before the fix (stash/pop), per CLAUDE.md.

### Open threads to raise upstream
- Should the `array_values`/`array_keys` declared-order unsoundness
  (`list{1,2}` vs sound `list{1|2,1|2}`) be addressed in the same bleeding-edge
  bundle, or tracked separately? It shares the exact root. Current lean:
  **separate issue**, note the shared root, don't expand PR-B's blast radius.
