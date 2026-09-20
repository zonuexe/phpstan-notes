# Research memo: phpstan/phpstan#15224 and the effects proposal

Date: 2026-09-15

This memo separates source facts from recommendations. GitHub issue, pull-request, commit, PHP manual, playground, and current implementation/test links are primary sources; the existing proposal and the adaptation review were used only to identify what needed verification.

## Source facts

### Issue #15224 and linked work

- [`phpstan/phpstan#15224`](https://github.com/phpstan/phpstan/issues/15224), titled “Purity of mbstring (string processing) functions”, is open and labelled `bug`. The reporter asks for mbstring string functions with an explicit encoding to be treated as pure and calls omitting the encoding to be treated as impure because they depend on `mb_internal_encoding()`.
- The linked [PHPStan playground reproduction](https://phpstan.org/r/53c621bb-6177-46e2-a885-8fe830318335) places omitted-encoding and explicit-`'UTF-8'` calls to `mb_trim`, `mb_strcut`, `mb_ucfirst`, `mb_str_pad`, `mb_strpos`, and `mb_str_split` inside `@phpstan-pure` functions.
- PHP’s manual specifies that `mb_strlen($string, $encoding)` and `mb_str_pad(..., $encoding)` use the internal character encoding when `encoding` is omitted **or `null`**; `mb_internal_encoding()` gets that state when called without an argument and sets it when an encoding is passed. ([`mb_strlen`](https://www.php.net/manual/en/function.mb-strlen.php), [`mb_str_pad`](https://www.php.net/manual/en/function.mb-str-pad.php), [`mb_internal_encoding`](https://www.php.net/manual/en/function.mb-internal-encoding.php))
- Vincent Langlet therefore agreed that omission technically reads internal encoding and asked whether an annotation opposite to `@pure-unless-parameter-passed` was needed. [Issue comment](https://github.com/phpstan/phpstan/issues/15224#issuecomment-5655248189)
- zonuexe distinguished the cases: `@pure-unless-parameter-passed` models a by-reference write gated by argument presence, whereas the encoding read is gated by the argument’s value; an explicit `null` still falls back, and a `?string` value is uncertain. [Issue comment](https://github.com/phpstan/phpstan/issues/15224#issuecomment-5658653555)
- The same comment identifies a second conflict: treating an ambient reader as impure protects repeated-result reasoning but loses the no-effect diagnostic for a discarded result. The cited PHPStan commit made `file_get_contents()` definitely impure, stopped remembering its result across calls, and removed the corresponding discarded-result findings. ([Issue comment](https://github.com/phpstan/phpstan/issues/15224#issuecomment-5658653555), [`2b5b3174`](https://github.com/phpstan/phpstan-src/commit/2b5b3174c7f00e56b7692b4c26552b2ad13c73d5))
- The issue’s bot comment records changed playground diagnostics after a 2.3.x push; the final contributor comment then links PR #6441 and says the general purity issue remains. ([bot comment](https://github.com/phpstan/phpstan/issues/15224#issuecomment-5660642897), [final comment](https://github.com/phpstan/phpstan/issues/15224#issuecomment-5664293314))
- [`phpstan/phpstan-src#6441`](https://github.com/phpstan/phpstan-src/pull/6441) is open and ready for review. Its single commit makes `mb_str_pad()` side-effect-free like comparable mbstring functions, but the PR explicitly calls this a pragmatic consistency override and leaves encoding-sensitive purity out of scope. ([PR](https://github.com/phpstan/phpstan-src/pull/6441), [`04832150`](https://github.com/phpstan/phpstan-src/commit/04832150e6f3c7b55c67c527e7330b54db8ff236))
- [`phpstan/phpstan-src#6018`](https://github.com/phpstan/phpstan-src/pull/6018), which implements `@pure-unless-parameter-passed`, is also open and ready for review; its author says #15224 should not block its merge decision. [PR comment](https://github.com/phpstan/phpstan-src/pull/6018#issuecomment-5675306421)

### Current effect-envelope 2.3.x candidate

- The dynamic function effect API is explicitly call-site-sensitive: it receives the call and a `Scope`; a non-null answer replaces, rather than adds to, the callee’s declared labels so a call may be narrowed. [DynamicFunctionEffectExtension.php](/Users/megurine/repo/php/phpstan-src-worktree-effect-envelope/src/Analyser/EffectLabel/DynamicFunctionEffectExtension.php:10)
- PHP evaluates function arguments from left to right before the call. [PHP manual](https://www.php.net/manual/en/functions.arguments.php)
- The current function, instance-method, and static-method handlers run effect resolution after `processArgs()`, expose the captured `ArgsResult` entries through a transient scope, and pop that storage in `finally`. ([FuncCallHandler.php](/Users/megurine/repo/php/phpstan-src-worktree-effect-envelope/src/Analyser/ExprHandler/FuncCallHandler.php:320), [MethodCallHandler.php](/Users/megurine/repo/php/phpstan-src-worktree-effect-envelope/src/Analyser/ExprHandler/MethodCallHandler.php:159), [StaticCallHandler.php](/Users/megurine/repo/php/phpstan-src-worktree-effect-envelope/src/Analyser/ExprHandler/StaticCallHandler.php:253))
- The transient copies can be marked as carrying the type from the expression’s actual evaluation position, preventing a later argument mutation from re-pricing an earlier argument on another scope. ([DynamicReturnTypeStoragePrimer.php](/Users/megurine/repo/php/phpstan-src-worktree-effect-envelope/src/Analyser/ExprHandler/Helper/DynamicReturnTypeStoragePrimer.php:25), [ExpressionResult.php](/Users/megurine/repo/php/phpstan-src-worktree-effect-envelope/src/Analyser/ExpressionResult.php:171), [NodeCallbackScope.php](/Users/megurine/repo/php/phpstan-src-worktree-effect-envelope/src/Analyser/NodeCallbackScope.php:147))
- Regression fixtures cover all three call forms when a later named argument mutates a variable used by an earlier argument, plus widening from a prior literal to arbitrary `string` and a PHPDoc-literal/native-`string` difference. The expected diagnostics require write, conservative `io`, or conservative `io.fs` rather than an unsound read-only narrowing. ([fixture](/Users/megurine/repo/php/phpstan-src-worktree-effect-envelope/tests/PHPStan/Rules/Pure/data/effect-extension.php:164), [expected diagnostics](/Users/megurine/repo/php/phpstan-src-worktree-effect-envelope/tests/PHPStan/Rules/Pure/DynamicEffectExtensionTest.php:68), [native-type resolver fixture](/Users/megurine/repo/php/phpstan-src-worktree-effect-envelope/tests/PHPStan/Rules/Pure/data/effect-dynamic-extensions.php:99))
- Structured effect sources are not currently vocabulary-validated at their boundary: the resolver accepts extension or `effectMetadata` labels, while envelope checking later drops the whole impure point if any returned label is unknown. ([CallSiteEffectLabelResolver.php](/Users/megurine/repo/php/phpstan-src-worktree-effect-envelope/src/Analyser/EffectLabel/CallSiteEffectLabelResolver.php:64), [FunctionPurityCheck.php](/Users/megurine/repo/php/phpstan-src-worktree-effect-envelope/src/Rules/Pure/FunctionPurityCheck.php:324))
- Constructor declarations propagate their labels to `new`, but `new` is deliberately not offered to dynamic effect extensions or `effectMetadata` in the current candidate. [NewHandler.php](/Users/megurine/repo/php/phpstan-src-worktree-effect-envelope/src/Analyser/ExprHandler/NewHandler.php:350)

## Inferences and exact proposal recommendations

1. **Make the consumer split normative.** Add: “A read effect prevents unconditional result reuse across a possibly matching write, but does not by itself make a discarded call effectful; no-effect-statement analysis asks whether an observable write remains after discarding the return value.” This is the distinction the `file_get_contents()` history and #15224 show a single `hasSideEffects` bit cannot preserve. ([Issue comment](https://github.com/phpstan/phpstan/issues/15224#issuecomment-5658653555), [`2b5b3174`](https://github.com/phpstan/phpstan-src/commit/2b5b3174c7f00e56b7692b4c26552b2ad13c73d5))

2. **Use #15224 as the value-gated worked example.** Include this table, explicitly presented as the proposal’s inference from PHP’s documented fallback:

   | Call | Effect |
   |---|---|
   | `mb_strlen($s, 'UTF-8')` | none |
   | `mb_strlen($s)` | `global.read` |
   | `mb_strlen($s, null)` | `global.read` |
   | `mb_strlen($s, $encoding)` for `?string` | possibly `global.read` |
   | `mb_internal_encoding()` | `global.read` |
   | `mb_internal_encoding('UTF-8')` | `global.write` |

   The omission/null equivalence is documented by PHP, and the issue comment supplies the `?string` consequence. ([`mb_strlen`](https://www.php.net/manual/en/function.mb-strlen.php), [`mb_internal_encoding`](https://www.php.net/manual/en/function.mb-internal-encoding.php), [issue comment](https://github.com/phpstan/phpstan/issues/15224#issuecomment-5658653555))

3. **Do not encode this as inverse presence gating.** State that argument-conditional effects need at least three forms: presence-gated fixed effects, callback-polymorphic effects, and value/type-gated effects. #15224 is the third form because omission and explicit `null` agree while a known non-null encoding differs; `@pure-unless-parameter-passed` remains an independent narrow feature. ([Issue comment](https://github.com/phpstan/phpstan/issues/15224#issuecomment-5658653555), [PR #6018](https://github.com/phpstan/phpstan-src/pull/6018), [callback-condition PR #3482](https://github.com/phpstan/phpstan-src/pull/3482))

4. **Specify evaluation-position semantics, not merely “post-argument scope”.** Add: “A call-site effect condition is evaluated from each argument’s captured result at that argument’s source-order evaluation position. It must not re-evaluate an earlier argument on the final scope or restore the pre-argument scope. If the captured type does not prove a narrowing, keep the conservative declared/catalogued effect.” Include both regressions:

   ```php
   $encoding = null;
   mb_strlen(encoding: $encoding, string: $encoding = 'payload');
   // encoding was null at its evaluation position: global.read

   $mode = 'r';
   fopen($mode = $newMode, $mode); // $newMode is string
   // mode is not proven read-only: keep broad io
   ```

   PHP specifies left-to-right evaluation, and the candidate’s fixtures encode both later-mutation and widening cases. ([PHP manual](https://www.php.net/manual/en/functions.arguments.php), [fixture](/Users/megurine/repo/php/phpstan-src-worktree-effect-envelope/tests/PHPStan/Rules/Pure/data/effect-extension.php:164), [expected diagnostics](/Users/megurine/repo/php/phpstan-src-worktree-effect-envelope/tests/PHPStan/Rules/Pure/DynamicEffectExtensionTest.php:68))

5. **Make the type-trust rule explicit.** Require effect resolvers to state whether they use PHPDoc or native types, and require conservative fallback when the trusted type cannot prove the narrowing. Add the regression where PHPDoc says `'r'` but the native parameter is `string`; a runtime-effect resolver using native types must not infer read-only. ([fixture](/Users/megurine/repo/php/phpstan-src-worktree-effect-envelope/tests/PHPStan/Rules/Pure/data/effect-extension.php:219), [resolver](/Users/megurine/repo/php/phpstan-src-worktree-effect-envelope/tests/PHPStan/Rules/Pure/data/effect-dynamic-extensions.php:99), [expected diagnostic](/Users/megurine/repo/php/phpstan-src-worktree-effect-envelope/tests/PHPStan/Rules/Pure/DynamicEffectExtensionTest.php:84))

6. **Separate legacy docblock fail-open from structured-source validation.** Preserve the backward-compatible rule that an unknown suffix in an existing `@phpstan-impure` docblock makes that declaration unbounded. For `effectMetadata` and dynamic extensions, however, require unknown labels to produce a configuration/extension diagnostic and discard the override so the callee’s declared or conservative catalogued labels remain; they must not silently remove the impure point. The current resolver/checker combination demonstrates that this boundary needs an explicit rule. ([CallSiteEffectLabelResolver.php](/Users/megurine/repo/php/phpstan-src-worktree-effect-envelope/src/Analyser/EffectLabel/CallSiteEffectLabelResolver.php:64), [FunctionPurityCheck.php](/Users/megurine/repo/php/phpstan-src-worktree-effect-envelope/src/Rules/Pure/FunctionPurityCheck.php:324), [EffectEnvelope.php](/Users/megurine/repo/php/phpstan-src-worktree-effect-envelope/src/Analyser/EffectLabel/EffectEnvelope.php:41))

7. **State the constructor boundary as a v1 non-goal.** Say: “Constructor declarations propagate effect labels through `new`; call-site attribution of `new` through metadata or dynamic extensions is not part of v1.” That matches the current implementation and prevents readers from assuming the function/method/static resolver surface already covers instantiation. [NewHandler.php](/Users/megurine/repo/php/phpstan-src-worktree-effect-envelope/src/Analyser/ExprHandler/NewHandler.php:350)

## Status wording to use

- “Issue #15224 remains open; PR #6441 addresses only mbstring consistency, not encoding-sensitive purity.” ([issue](https://github.com/phpstan/phpstan/issues/15224), [PR #6441](https://github.com/phpstan/phpstan-src/pull/6441))
- “`@pure-unless-parameter-passed` is proposed in open PR #6018 and need not wait for the general effect model.” ([PR #6018](https://github.com/phpstan/phpstan-src/pull/6018), [author comment](https://github.com/phpstan/phpstan-src/pull/6018#issuecomment-5675306421))
