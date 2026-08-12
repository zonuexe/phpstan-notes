# Issue draft: Effect labels on the purity tags — a concise spec with a working implementation

<!-- Draft for a phpstan/phpstan issue (or a comment on #14220 if that reads better). GitHub formatting: no hard wraps. Posting is the owner's call (steins#314). -->

**Suggested title**: `RFC: effect labels as parameters of @phpstan-impure — spec, prior art, working implementation`

---

This picks up the idea sketched in #14220 — *"The effect could just be a parameter after @phpstan-impure PHPDoc tag. Like `@phpstan-impure io`"* — and turns it into a small spec with a working implementation to poke at. The full self-contained spec lives in [phpdoc-effects-interop.md](https://github.com/rigortype/steins/blob/master/docs/type-specification/phpdoc-effects-interop.md); this issue is the condensed version.

## Proposal

A label list after the existing purity tags turns the boolean impurity flag into a **declared upper bound** on what kind of impurity a function may perform:

```php
/** @phpstan-impure io.db, nondet.time (reads the clock for cache TTL) */
function refreshCache(string $key): CacheEntry { … }

/** @phpstan-all-methods-impure io.net */
class RedisClient { … }
```

## Grammar

```ebnf
impure-tag       = "@phpstan-impure" [ label-list [ comment ] ] ;
class-impure-tag = "@phpstan-all-methods-impure" [ label-list [ comment ] ] ;
label-list       = label { "," label } ;
label            = segment { "." segment } ;          (* segment = [a-z][a-z0-9]* *)
comment          = "(" text-without-close-paren ")" ;
```

`@phpstan-ignore`'s shape, reused verbatim — its identifiers are already dot-paths. One constraint: the comment is only legal **after at least one label**, because a `(` directly after the tag name goes down phpdoc-parser's Doctrine-annotation path and can produce `phpDoc.parseError` (verified against phpdoc-parser 2.3.3).

## Semantics, in eight sentences

1. **A label list is an upper bound, checked by segment-aware prefix subsumption**: a declared `io` admits an inferred `io.net.http` and rejects `iota`. That prefix test is the entire minimal adoption surface.
2. **Bare tags keep today's meaning.** Bare `@phpstan-impure` stays ⊤ ("impure, nothing said about how"); labels only ever *narrow* a tag, so no existing docblock changes meaning.
3. **An unknown label makes the whole tag read as unspecified** (⊤), never as the recognized subset. PHPStan today discards everything after the tag, so `/** @phpstan-impure database */` (a one-word human note) is legal in the wild and must never start failing a run; and checking the body against only the recognized members would hold it to a narrower claim than its author wrote. Typo reporting is a separable, opt-in concern.
4. **The class-level pair keeps its shipped semantics verbatim** (2.1.39): a method-level tag overrides the class tag; `all-methods-pure` covers the constructor but not void-returning methods; no interface→implementation propagation. The override rule doubles as the exception mechanism, so no `-except` syntax is needed in v1.
5. **`@phpstan-pure` = the empty bound, with caller-frame by-ref writes admissible** (`preg_match`'s `$matches`, `sort($rows)`): nothing escapes the frame, so no caller can observe them — a worked answer to the `hasSideEffects` by-ref question, spelled `mutate.local` below.
6. **`@phpstan-pure` does not claim termination** — there is deliberately no divergence label — so deduplicating or memoizing repeated pure calls stays licensed.
7. **Vocabulary evolution is asymmetric**: adding a leaf never breaks an existing bound (a prefix is a predicate, not a closed enumeration), while moving or removing a node is breaking and degrades safely (docblock spelling → unspecified; a checker's own native annotations → a vocabulary diagnostic).
8. **A checker that understands none of this loses nothing**: the tags keep their current boolean meaning, and the labels ride along as ignored text.

## Proposed v1 vocabulary (25 labels)

```
exit  ffi
global.read  global.write
io  io.db  io.fs  io.fs.read  io.fs.write  io.input  io.ipc  io.net  io.net.http
io.output  io.output.buffer  io.output.header  io.output.stdout  io.output.stderr
io.process  io.signal
mutate  mutate.local
nondet  nondet.random  nondet.time
```

Output is an ambient channel under `io`, split on one question: can `ob_start()` capture it? `io.output.buffer` (echo, print, inline HTML) is the capturable side — which makes a future masking rule ("this helper runs its callback under `ob_start` and swallows the output") a single prefix test — while `.stdout`/`.stderr` (direct fd writes) and `.header` (`header()`, `setcookie()`) are mechanically outside it. A bare `io` bound deliberately admits output; `io.db` keeps its edge against an `echo`.

## Backward compatibility (verified against phpdoc-parser 2.3.3 / phpstan-src 2.2.x)

- `@phpstan-impure io` parses today as the `@phpstan-impure` tag + a `GenericTagValueNode("io")` that PHPStan ignores: no parse error, no behavioral change, and `InvalidPHPStanDocTagRule` matches tag names exactly, so nothing new fires.
- The only syntactic hazard is the Doctrine path noted under Grammar, and the grammar excludes the one spelling that triggers it.
- Nothing widens, nothing is redefined, no new tag name is introduced (the class-level pair already exists).

## Prior art

None of the design is novel for novelty's sake — the load-bearing choices each have a precedent in shipped effect systems:

- **Hierarchical effects with a coarse `io` that includes output** match Koka literally: its `io` is an alias whose expansion *includes* `console` alongside `net`, `fsys`, `div`, `exn` ([Koka book](https://koka-lang.github.io/koka/doc/book.html); [Leijen, MSFP 2014](https://arxiv.org/abs/1406.2061)). One deliberate difference: Koka's alias is a closed enumeration, while a dot-path prefix is an open predicate — that is what makes rule 7's evolution property possible. Effect systems as a discipline trace to [Lucassen & Gifford, POPL 1988](https://dl.acm.org/doi/10.1145/73560.73564).
- **`mutate.local`** is the first-order cousin of the `runST` encapsulation argument ([Launchbury & Peyton Jones, PLDI 1994](https://dl.acm.org/doi/10.1145/178243.178246)): state nobody outside the scope can observe may be discharged. Koka's isolated `st<h>` heaps and [Flix's region-scoped mutation](https://doc.flix.dev/) make the same move.
- **`@pure-unless-callable-is-impure`** (already merged in phpdoc-parser) is a first-order encoding of effect polymorphism — Flix's `map: (a -> b \ ef, List[a]) -> List[b] \ ef` ([Madsen & van de Pol, OOPSLA 2020](https://dl.acm.org/doi/10.1145/3428222); the newest form is [Associated Effects, PLDI 2024](https://dl.acm.org/doi/10.1145/3656393)) restricted to what a PHPDoc tag can carry. Effect-labeled callables would be the natural next step, not a rework.
- **The `io.output.buffer` boundary** exists so masking is [Koka's `mask`](https://dl.acm.org/doi/10.1145/3093333.3009872) restricted to one label — and the hierarchy itself encodes what masking can never touch.
- **The one cautionary precedent**: Java's checked exceptions ([Hejlsberg, Artima 2003](https://www.artima.com/articles/the-trouble-with-checked-exceptions)) failed by making effect annotations mandatory at every layer. Everything here is opt-in: inference does the work, a tag only adds a checkable bound, and absence of a tag means what it means today.

## Working implementation

[Steins](https://github.com/rigortype/steins) implements the spec in all three directions, with the test suite to match ([#303](https://github.com/rigortype/steins/issues/303)): **reading** (a tag on an interface method bounds calls typed against the interface, reported as "≤ io.db, possibly more" — a docblock bounds but never proves), **checking** (a function is verified against its own tag, and the finding quotes the tag's spelling):

```console
$ steins check --profile contracts demo/lie.php
demo/lie.php:6:12: error[effect.envelope-exceeded]: time() has effect nondet.time, but refreshCache() is declared @phpstan-impure io.db — nondet.time exceeds the envelope
```

and **writing** (`steins transform effects-envelope` emits the tags from inference — only where every call resolved, never a bare tag, and it refuses to touch an existing tag it cannot read rather than overwrite a human's note).

## Open questions where upstream's call decides the spec

1. Fixed vocabulary, or open identifiers with a typo-distance diagnostic against a known set (the way `@phpstan-ignore` identifiers work)? Prior art leans open-with-registration: no successful effect system ships a closed vocabulary.
2. Should class-level purity tags ever propagate interface → implementation? (`reportMethodPurityOverride` already gestures this way; today they don't, and the implementation mirrors that.)
3. Does `all-methods-pure`'s void-method exclusion survive into a labeled world, where a void method could still usefully declare `@phpstan-impure io.output`?
