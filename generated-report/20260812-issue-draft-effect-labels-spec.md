# Issue draft: Effect labels on the purity tags — v2 (post-review)

<!-- v2, rewritten after 20260812-effect-label-docs-adversarial-review.md (FAIL → all findings addressed).
     Draft for a phpstan/phpstan issue (or a comment on #14220). GitHub formatting: no hard wraps.
     Posting is the owner's call. Former blockers, both resolved 2026-08-12:
     Steins catalog soundness landed upstream (steins 4f0efa3, PR #319);
     PHPStan branch stage 11 (checkEffectEnvelopes toggle) landed at d1064a7da. -->

**Suggested title**: `RFC: opt-in effect labels as parameters of @phpstan-impure`

---

## The ask, first

Should PHPStan interpret the suffix of `@phpstan-impure` — today parsed and discarded — as an **opt-in** effect-envelope parameter?

```php
/** @phpstan-impure io.db, nondet.time (reads the clock for cache TTL) */
function refreshCache(string $key): int
{
	$expiry = time() + 3600;
	file_put_contents('/tmp/cache/' . $key, (string) $expiry);
	return $expiry;
}

/** @phpstan-impure io.fs */
function touchCache(): int
{
	$expiry = time() + 3600;                          // ← the body reads the clock
	file_put_contents('/tmp/cache/last', (string) $expiry);
	return $expiry;
}
```

- **Default config (feature off)**: both functions produce no errors — byte-identical to today's output. The suffix stays ignored text.
- **With `featureToggles.checkEffectEnvelopes: true`** (proposed: default `false`, `true` in bleeding edge):

```
Function touchCache() has effect nondet.time (call to function time()), but is declared @phpstan-impure io.fs, so nondet.time exceeds the envelope.
```

`refreshCache()` stays silent: `io.fs.write ⊑ io` and `nondet.time` is declared. That is the whole feature: the label list is a **declared upper bound** on the kinds of impurity, checked against the body.

The parameter position is the one sketched by @ondrejmirtes ([X, 2026-08-09](https://x.com/OndrejMirtes/status/2086491960089444580)): *"The effect could just be a parameter after @phpstan-impure PHPDoc tag 😊 Like @phpstan-impure io"*. #14220 is the background discussion; this issue tries to be the "specific proposal with code samples and expected output" requested there.

## What v1 answers from #14220's user stories

| #14220 wish | v1 |
|---|---|
| "no I/O" purity | `@phpstan-impure` with labels excluding `io` (or `@phpstan-pure`) |
| "no output" | any envelope not covering `io.output` — and `io.output.buffer` vs `.stdout` distinguishes ob_start-capturable output from direct fd writes |
| configurable exception purity | out of scope — exceptions stay `@throws` territory |
| fibers/generators | out of scope in v1 (`yield` contributes no label and is never reported against an envelope) |

## A second payoff inside the engine: scoped forgetting

Envelope checking is not the only consumer of effect *kinds*. PHPStan's own [remembering-and-forgetting machinery](https://phpstan.org/blog/remembering-and-forgetting-returned-values) must forget remembered values when an intervening call could have changed them — and with a boolean `hasSideEffects`, the trigger is all-or-nothing: an intervening `rand()` forces forgetting `is_dir($x)` even though it provably cannot have touched the stat cache. Labels scope the invalidation: stat-derived memory is invalidated by calls whose labels reach `io.fs` or `global.write` — `clearstatcache()` is *precisely* a `global.write` — while `nondet.random` keeps it. So the vocabulary pays for itself inside the engine before any user writes an envelope, and the functions the blog post names get exact classifications instead of a shared boolean.

The same decomposition sorts the no-effect statement rules and PHP 8.5's `#[\NoDiscard]`. Read-shaped labels (`global.read`, `nondet.*`, `io.fs.read`) make a discarded call a **dead statement** — derivable, no annotation — which closes the loop on the `hasSideEffects` strain visible in [#8440](https://github.com/phpstan/phpstan/issues/8440)/[phpstan-src#2037](https://github.com/phpstan/phpstan-src/pull/2037) (the parameter-flipped boolean becomes "unprovable target ⇒ wide default" under argument narrowing) and [#12738](https://github.com/phpstan/phpstan/issues/12738) (`ob_get_contents();` is `global.read` — derivable, no must_use needed). `#[\NoDiscard]` then shrinks to the quadrant that genuinely needs declaring: effectful calls whose result is still the point (`fopen()`). And the boolean's hardest case becomes sayable: `rand()` may not be collapsed into one call (`nondet.random`), yet a bare `rand();` statement is dead — one label, both answers.

## Grammar

```ebnf
impure-tag       = "@phpstan-impure" [ label-list [ comment ] ] ;
class-impure-tag = "@phpstan-all-methods-impure" [ label-list [ comment ] ] ;
label-list       = label { "," label } ;
label            = segment { "." segment } ;          (* segment = [a-z][a-z0-9]* *)
comment          = "(" text-without-close-paren ")" ;
```

`@phpstan-ignore`'s shape, reused verbatim. One constraint: the comment is only legal **after at least one label** — a `(` directly after the tag name goes down phpdoc-parser's Doctrine-annotation path (verified against phpdoc-parser 2.3.3).

## Semantics

1. **Checking is segment-aware prefix subsumption**: a declared `io` admits an inferred `io.net.http` and rejects `iota`. That prefix test is the core subsumption relation — the full MVP surface is larger: the list grammar, a three-state reading (absent / unbounded / bounded), the vocabulary, effect attribution for language constructs and catalogued builtins, call propagation, precedence, and diagnostics. A working branch (below) has all of it.
2. **Bare tags keep today's meaning** (⊤ — "impure, nothing said about how").
3. **An unknown label makes the whole tag read as unspecified** (⊤), never as the recognized subset: `/** @phpstan-impure database */` is a legal human note in the wild today and must not start failing a run. This is deliberately fail-open — it creates no new false positives, at the cost of silently losing the bound. Enforcement mode therefore **pairs with a vocabulary diagnostic** (unknown label + typo suggestion) so the degradation is at least visible at the declaration.
4. **Class-level tags keep their shipped semantics verbatim** (2.1.39): method tag overrides class tag (even an unbounded one — no fallback), `all-methods-pure` covers the constructor but not void methods, no interface→implementation propagation of the *class* tag.
5. **`@phpstan-pure` = the empty bound, tolerating `mutate.local`**: mutation of a local binding that the enclosing function owns, that is not aliased, and that does not escape the frame — `preg_match(..., $matches)` into a local, `sort($rows)` on a local copy. Writes through by-ref into properties, statics, superglobals, `global` aliases, by-ref formal parameters, or escaping captures are **not** `mutate.local` and stay reported (they are caught independently by the assignment machinery). `sort()` itself is not pure — the *enclosing function's* envelope discharges the local mutation; only that enclosing function, after discharge, is a candidate for memoization or CSE.
6. **Vocabulary evolution**: adding a leaf never changes any *recognized* ancestor or sibling bound (a prefix is a predicate, not a closed enumeration). It **can** change the meaning of a docblock that already carried the same spelling as an unknown label (⊤ → bounded) — vocabulary additions are semantic events for such docblocks and belong in release notes.
7. **A checker that understands none of this loses nothing**: the tags keep their boolean meaning; the labels ride along as ignored text.

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

Output is an ambient channel under `io`, split on one question: can `ob_start()` capture it? `io.output.buffer` (echo, print, inline HTML, `php://output`) is the capturable side; `.stdout`/`.stderr` (direct fd writes — `fwrite(STDOUT, …)`, `php://stdout`) and `.header` are mechanically outside it, which makes a future masking rule a single prefix test. A bare `io` deliberately admits output; `io.db` keeps its edge against an `echo`. Projects can extend the vocabulary (own roots such as `email.send`) and attribute labels to third-party symbols via configuration or extensions.

## Backward compatibility — two separate claims

**Parser compatibility (unconditional).** `@phpstan-impure io` parses today as the tag + a `GenericTagValueNode("io")` that PHPStan ignores: no parse error, no behavioral change, `InvalidPHPStanDocTagRule` matches tag names exactly. Verified against phpdoc-parser 2.3.3 / phpstan-src 2.2.x. Anyone can start writing labels now.

**Semantic migration (opt-in).** Enabling `checkEffectEnvelopes` *reinterprets* an existing recognized suffix: `@phpstan-impure io` goes from ⊤ to the bounded claim `io`, and a body performing `nondet.time` gets a new finding. That is the feature working as intended, but it is a semantic change for any pre-existing recognized suffix — hence default `false`, bleeding edge `true`, and the vocabulary diagnostic to audit existing suffixes before enabling. The same applies to future vocabulary additions (semantics rule 6).

## Trust model — the decision this proposal makes

A docblock envelope on an interface method is trusted as an upper bound at call sites typed against the interface. That trust is only coherent if implementations may not widen it, so enforcement includes a **Liskov inclusion check**: an overriding method's envelope must be subsumed by the overridden one (under `reportMethodPurityOverride`, where the impure-overriding-pure check already lives; `pure = ∅`, bare impure = ⊤). This differs from Steins, which reads docblock envelopes as an *unchecked stratum* — bounding but never proving, exempt from its Liskov rule — because it has native attributes above them. PHPStan has only the docblock, and already trusts `@phpstan-pure`/`@phpstan-impure` declarations in its purity checks; extending both the trust and the substitutability obligation to labels is the consistent PHPStan-native choice.

## Argument-conditional effects: the two `pure-unless` tags

Two tags declare a purity that flips on an argument, not on the body — one merged, one in review:

- `@pure-unless-callable-is-impure $cb` (phpstan-src#3482, merged) — the callee's effects are exactly the callback's, so purity flips on *what `$cb` does*.
- `@pure-unless-parameter-passed $count` (phpstan-src#6018, proposed) — a by-ref out-parameter is written only when the caller supplies a binding for it, so purity flips on *whether `$count` is passed*.

Both are one shape: an effect conditioned on a call-site argument, sitting at two ends of a single axis. The callback tag is effect-polymorphic — the envelope contains the argument's *own* effect, a variable resolved per call site. The parameter tag is presence-gated — the envelope contains a fixed effect (the by-ref write) enabled by an argument's presence. The first-order callback slice already appears under Prior art as a fragment of Flix-style effect polymorphism; the parameter case is the strictly easier end of the same generalization.

Under labels the parameter case stops being a tag at all. A by-ref out-parameter write is a per-parameter `mutate` effect; evaluating the envelope per call site by which arguments are passed makes `@pure-unless-parameter-passed` a *derived* fact of the signature, not an annotation. The branch diagnostic that rejects the tag on a non-optional parameter (always passed ⇒ never pure ⇒ misuse) dissolves for the same reason: a non-optional by-ref out-parameter simply carries an unconditional `mutate`, no special case.

This is the argument for landing these tags on their own timeline and folding them later, not the reverse. Each is a correct, narrow fix (one merged, one in review); each is also one more bespoke boolean-adjacent spelling of "pure except for X," the fragmentation labels exist to remove. Named as sugar over argument-conditional effects, both keep working while the general mechanism absorbs the next `pure-unless-Y` before it needs its own tag. Neither should gate on this proposal, and this proposal should not grow to swallow them — it should cite them as the two worked examples that motivate argument conditioning as a first-class part of the model.

## Prior art (what each precedent supports)

- **Koka and Flix ship user-extensible effect mechanisms**, which favors open-with-registration over a closed vocabulary. Koka's coarse `io` alias also includes console output, the same call this proposal makes for `io.output ⊑ io` ([Koka book](https://koka-lang.github.io/koka/doc/book.html); [Leijen, MSFP 2014](https://arxiv.org/abs/1406.2061)). Unlike Koka's closed alias, a dot-path prefix is an open predicate — that is what makes vocabulary evolution (semantics rule 6) possible.
- **`mutate.local`** follows the `runST` encapsulation argument ([Launchbury & Peyton Jones, PLDI 1994](https://dl.acm.org/doi/10.1145/178243.178246)): state nobody outside the scope can observe may be discharged. Koka's `st<h>` and [Flix regions](https://doc.flix.dev/) make the same move.
- **`@pure-unless-callable-is-impure`** (merged in phpdoc-parser) is a first-order slice of Flix-style effect polymorphism ([Madsen & van de Pol, OOPSLA 2020](https://dl.acm.org/doi/10.1145/3428222)); effect-labeled callables would be its natural extension, not a rework. See *Argument-conditional effects* above for how it and `@pure-unless-parameter-passed` share one shape.
- **Masking**: the `io.output.buffer` boundary restricts [Koka's `mask`](https://dl.acm.org/doi/10.1145/3093333.3009872) to one label.
- **Cautionary**: mandatory checked propagation has documented ergonomics objections ([Hejlsberg on Java's checked exceptions, Artima 2003](https://www.artima.com/articles/the-trouble-with-checked-exceptions)). Everything here is opt-in; inference does the work; absence of a tag means what it means today.

## Working implementations (evidence, not proof)

**PHPStan branch** — `zonuexe/phpstan-src` branch `worktree-effect-envelope` @ `1c6b2318f`, 13 commits on top of 2.2.x (`f69c88168`). Everything above is implemented: parsing, envelope checks (behind `checkEffectEnvelopes`), class-level tags, call propagation, constructor propagation, `mutate.local` tolerance for pure (behind `pureEnvelopeToleratesLocalMutation`), Liskov inclusion, vocabulary diagnostics with typo suggestions, a builtin catalog audited as *sound upper bounds* with per-call-site narrowing (constant paths/schemes: `file_get_contents('/etc/x')` → `io.fs.read`, `('https://…')` → `io.net.http`, `('php://stdout')` → `io.output.stdout`), and project-level vocabulary/attribution extension points. Full test suite green (21k+ tests); a demo file with captured outputs under three configs (released 2.2.8 / branch default / branch + bleeding edge) shows the BC claims empirically — the branch's default-config output is byte-for-byte identical to stock 2.2.x on the demo file (`diff` is empty).

**Steins** ([rigortype/steins](https://github.com/rigortype/steins) @ `735d350`, needs rustc ≥ 1.97) implements the same spec as an external analyzer in all three directions — reading (interface-typed call sites report `≤ io.db, possibly more`), checking (`effect.envelope-exceeded`, quoting the author's spelling), and writing (`steins transform effects-envelope` emits tags **only from exhaustive inference; non-exhaustive functions get no tag**, and it refuses to touch an existing tag it cannot read). Its builtin catalog carries the same soundness audit as the PHPStan branch: wrapper-capable stream APIs default to `io` and narrow only on provable literal targets (`file_get_contents('https://…')` → `io.net.http`, `fwrite(STDOUT, …)` → `io.output.stdout`, per-role `copy`), so envelope checking judges a sound upper bound in both implementations.

## Open questions for upstream

1. Fixed v1 vocabulary with project-level registration (the branch's choice), or fully open identifiers with only a typo-distance diagnostic, the way `@phpstan-ignore` works?
2. Should class-level purity tags ever propagate interface → implementation? (Today they don't; the branch mirrors that. The Liskov check above is about method-level *envelope* substitutability, a separate question.)
3. Does `all-methods-pure`'s void-method exclusion survive into a labeled world, where a void method could still usefully declare `@phpstan-impure io.output`?
4. Should argument-conditional effects — the `pure-unless-*` family — become a first-class envelope feature (per-parameter effects, resolved per call site), so those tags reduce to derived sugar rather than independent concepts?
