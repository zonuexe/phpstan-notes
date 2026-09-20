> これは日本語のレビュー用翻訳です。内容の正本は英語原文 [20260915-phpstan-15224-effects-proposal-research.md](20260915-phpstan-15224-effects-proposal-research.md) です。

# 調査メモ：phpstan/phpstan#15224 と effects proposal

日付: 2026-09-15

このメモでは、一次情報の事実と推奨事項を分けて記載する。GitHub issue、pull request、commit、PHP manual、playground、現在の実装／テストへの link は一次情報である。既存の proposal と adaptation review は、検証が必要な箇所を特定する目的だけで参照した。

## 一次情報の事実

### Issue #15224 と関連作業

- [`phpstan/phpstan#15224`](https://github.com/phpstan/phpstan/issues/15224) は “Purity of mbstring (string processing) functions” という題名で、open のまま `bug` とラベル付けされている。報告者は、encoding を明示した mbstring の string function を pure として扱い、encoding の省略は `mb_internal_encoding()` に依存するため impure として扱うよう求めている。
- リンク先の [PHPStan playground reproduction](https://phpstan.org/r/53c621bb-6177-46e2-a885-8fe830318335) では、encoding を省略した call と明示的な `'UTF-8'` の call を、`mb_trim`、`mb_strcut`、`mb_ucfirst`、`mb_str_pad`、`mb_strpos`、`mb_str_split` について `@phpstan-pure` function の中に置いている。
- PHP の manual は、`mb_strlen($string, $encoding)` と `mb_str_pad(..., $encoding)` が、`encoding` を省略した **場合または `null` の場合** に内部の character encoding を使うと規定している。`mb_internal_encoding()` は引数なしならその state を取得し、encoding が渡されれば設定する（[`mb_strlen`](https://www.php.net/manual/en/function.mb-strlen.php)、[`mb_str_pad`](https://www.php.net/manual/en/function.mb-str-pad.php)、[`mb_internal_encoding`](https://www.php.net/manual/en/function.mb-internal-encoding.php)）。
- そのため Vincent Langlet は、省略が技術的には internal encoding を読むことに同意し、`@pure-unless-parameter-passed` と反対の annotation が必要かを尋ねた。[Issue comment](https://github.com/phpstan/phpstan/issues/15224#issuecomment-5655248189)
- zonuexe はケースを区別した。`@pure-unless-parameter-passed` は argument の presence を条件とする by-reference write をモデル化するのに対し、encoding の read は argument の value を条件とする。explicit `null` でも fallback し、`?string` の value は不確実である。[Issue comment](https://github.com/phpstan/phpstan/issues/15224#issuecomment-5658653555)
- 同じ comment は二つ目の衝突も指摘する。ambient reader を impure と扱えば repeated-result reasoning は保護できるが、捨てられた結果に対する no-effect diagnostic を失う。引用された PHPStan commit は `file_get_contents()` を明確に impure とし、call 間でその結果を記憶しないようにして、対応する discarded-result finding を削除した（[Issue comment](https://github.com/phpstan/phpstan/issues/15224#issuecomment-5658653555)、[`2b5b3174`](https://github.com/phpstan/phpstan-src/commit/2b5b3174c7f00e56b7692b4c26552b2ad13c73d5)）。
- issue の bot comment には、2.3.x の push 後に playground diagnostic が変わったことが記録されている。その後、最後の contributor comment が PR #6441 に link し、一般的な purity issue は残っていると述べている（[bot comment](https://github.com/phpstan/phpstan/issues/15224#issuecomment-5660642897)、[final comment](https://github.com/phpstan/phpstan/issues/15224#issuecomment-5664293314)）。
- [`phpstan/phpstan-src#6441`](https://github.com/phpstan/phpstan-src/pull/6441) は open で review ready である。単一の commit で `mb_str_pad()` を同等の mbstring function と同じく side-effect-free にするが、PR はこれを pragmatic consistency override と明記し、encoding-sensitive purity は対象外としている（[PR](https://github.com/phpstan/phpstan-src/pull/6441)、[`04832150`](https://github.com/phpstan/phpstan-src/commit/04832150e6f3c7b55c67c527e7330b54db8ff236)）。
- [`phpstan/phpstan-src#6018`](https://github.com/phpstan/phpstan-src/pull/6018) も open で review ready であり、`@pure-unless-parameter-passed` を実装する。その作成者は、#15224 が merge の判断を阻むべきではないと述べている。[PR comment](https://github.com/phpstan/phpstan-src/pull/6018#issuecomment-5675306421)

### 現在の effect-envelope 2.3.x candidate

- dynamic function effect API は明示的に call-site-sensitive である。call と `Scope` を受け取り、non-null の answer は callee の declared label に追加されるのではなく置き換えるため、call を narrow できる。[DynamicFunctionEffectExtension.php](/Users/megurine/repo/php/phpstan-src-worktree-effect-envelope/src/Analyser/EffectLabel/DynamicFunctionEffectExtension.php:10)
- PHP は call の前に function argument を左から右へ評価する。[PHP manual](https://www.php.net/manual/en/functions.arguments.php)
- 現在の function、instance-method、static-method handler は `processArgs()` の後で effect resolution を実行し、captured `ArgsResult` entry を transient scope に公開し、`finally` でその storage を pop する（[FuncCallHandler.php](/Users/megurine/repo/php/phpstan-src-worktree-effect-envelope/src/Analyser/ExprHandler/FuncCallHandler.php:320)、[MethodCallHandler.php](/Users/megurine/repo/php/phpstan-src-worktree-effect-envelope/src/Analyser/ExprHandler/MethodCallHandler.php:159)、[StaticCallHandler.php](/Users/megurine/repo/php/phpstan-src-worktree-effect-envelope/src/Analyser/ExprHandler/StaticCallHandler.php:253)）。
- transient copy には、expression の実際の evaluation position における type を保持していると印付けできる。これによって、後続 argument の mutation が別の scope 上で先行 argument の type を再計算することを防ぐ（[DynamicReturnTypeStoragePrimer.php](/Users/megurine/repo/php/phpstan-src-worktree-effect-envelope/src/Analyser/ExprHandler/Helper/DynamicReturnTypeStoragePrimer.php:25)、[ExpressionResult.php](/Users/megurine/repo/php/phpstan-src-worktree-effect-envelope/src/Analyser/ExpressionResult.php:171)、[NodeCallbackScope.php](/Users/megurine/repo/php/phpstan-src-worktree-effect-envelope/src/Analyser/NodeCallbackScope.php:147)）。
- regression fixture は、後続の named argument が先行 argument で使う variable を mutation する場合の三つの call form すべてをカバーする。さらに、先行する literal から任意の `string` への widening と、PHPDoc の literal と native の `string` の差もカバーする。expected diagnostic は、sound でない read-only narrowing ではなく、write、保守的な `io`、または保守的な `io.fs` を要求する（[fixture](/Users/megurine/repo/php/phpstan-src-worktree-effect-envelope/tests/PHPStan/Rules/Pure/data/effect-extension.php:164)、[expected diagnostics](/Users/megurine/repo/php/phpstan-src-worktree-effect-envelope/tests/PHPStan/Rules/Pure/DynamicEffectExtensionTest.php:68)、[native-type resolver fixture](/Users/megurine/repo/php/phpstan-src-worktree-effect-envelope/tests/PHPStan/Rules/Pure/data/effect-dynamic-extensions.php:99)）。
- structured effect source は現在、その境界で vocabulary validation されていない。resolver は extension または `effectMetadata` の label を受け入れるが、envelope checking は返された label に未知のものが一つでもあると、後で impure point 全体を落とす（[CallSiteEffectLabelResolver.php](/Users/megurine/repo/php/phpstan-src-worktree-effect-envelope/src/Analyser/EffectLabel/CallSiteEffectLabelResolver.php:64)、[FunctionPurityCheck.php](/Users/megurine/repo/php/phpstan-src-worktree-effect-envelope/src/Rules/Pure/FunctionPurityCheck.php:324)）。
- constructor declaration は label を `new` へ伝播するが、現在の candidate では `new` は dynamic effect extension や `effectMetadata` に意図的に提供されない。[NewHandler.php](/Users/megurine/repo/php/phpstan-src-worktree-effect-envelope/src/Analyser/ExprHandler/NewHandler.php:350)

## 推論と具体的な提案事項

1. **consumer の分離を規範にする。** 次を追加する。「read effect は、possibly matching write をまたぐ無条件の result reuse を防ぐが、それだけで discarded call を effectful にするわけではない。no-effect-statement analysis が問うのは、return value を破棄した後も observable write が残るかどうかである。」これは `file_get_contents()` の履歴と #15224 が示す区別であり、単一の `hasSideEffects` bit では維持できない（[Issue comment](https://github.com/phpstan/phpstan/issues/15224#issuecomment-5658653555)、[`2b5b3174`](https://github.com/phpstan/phpstan-src/commit/2b5b3174c7f00e56b7692b4c26552b2ad13c73d5)）。

2. **#15224 を value-gated の実例にする。** PHP が文書化する fallback からの proposal の推論として明示し、次の table を含める。

   | Call | Effect |
   |---|---|
   | `mb_strlen($s, 'UTF-8')` | none |
   | `mb_strlen($s)` | `global.read` |
   | `mb_strlen($s, null)` | `global.read` |
   | `mb_strlen($s, $encoding)` for `?string` | possibly `global.read` |
   | `mb_internal_encoding()` | `global.read` |
   | `mb_internal_encoding('UTF-8')` | `global.write` |

   省略と null の同値性は PHP が文書化しており、`?string` の帰結は issue comment が示している（[`mb_strlen`](https://www.php.net/manual/en/function.mb-strlen.php)、[`mb_internal_encoding`](https://www.php.net/manual/en/function.mb-internal-encoding.php)、[issue comment](https://github.com/phpstan/phpstan/issues/15224#issuecomment-5658653555)）。

3. **これを inverse presence gating として表現しない。** argument-conditional effect には少なくとも三つの形が必要だと明記する。presence-gated fixed effect、callback-polymorphic effect、value/type-gated effect である。#15224 は三つ目に当たる。省略と explicit `null` は一致する一方、known non-null encoding は異なるからである。`@pure-unless-parameter-passed` は独立した狭い feature として残る（[Issue comment](https://github.com/phpstan/phpstan/issues/15224#issuecomment-5658653555)、[PR #6018](https://github.com/phpstan/phpstan-src/pull/6018)、[callback-condition PR #3482](https://github.com/phpstan/phpstan-src/pull/3482)）。

4. **単なる「post-argument scope」ではなく evaluation-position semantics を指定する。** 次を追加する。「call-site effect condition は、各 argument の source-order evaluation position における captured result から評価する。final scope で先行 argument を再評価してはならず、argument 前の scope に戻してもならない。captured type から narrowing を証明できない場合は、declared または catalogued の保守的な effect を保持する。」次の両方の regression を含める。

   ```php
   $encoding = null;
   mb_strlen(encoding: $encoding, string: $encoding = 'payload');
   // encoding was null at its evaluation position: global.read

   $mode = 'r';
   fopen($mode = $newMode, $mode); // $newMode is string
   // mode is not proven read-only: keep broad io
   ```

   PHP は左から右への評価を規定しており、candidate の fixture は後続 mutation と widening の両ケースを表している（[PHP manual](https://www.php.net/manual/en/functions.arguments.php)、[fixture](/Users/megurine/repo/php/phpstan-src-worktree-effect-envelope/tests/PHPStan/Rules/Pure/data/effect-extension.php:164)、[expected diagnostics](/Users/megurine/repo/php/phpstan-src-worktree-effect-envelope/tests/PHPStan/Rules/Pure/DynamicEffectExtensionTest.php:68)）。

5. **type-trust rule を明示する。** effect resolver は PHPDoc type と native type のどちらを使うかを明記しなければならず、trusted type から narrowing を証明できないときは保守的な fallback を要求する。PHPDoc が `'r'` と言う一方で native parameter が `string` である regression を追加する。native type を使う runtime-effect resolver は read-only と推論してはならない（[fixture](/Users/megurine/repo/php/phpstan-src-worktree-effect-envelope/tests/PHPStan/Rules/Pure/data/effect-extension.php:219)、[resolver](/Users/megurine/repo/php/phpstan-src-worktree-effect-envelope/tests/PHPStan/Rules/Pure/data/effect-dynamic-extensions.php:99)、[expected diagnostic](/Users/megurine/repo/php/phpstan-src-worktree-effect-envelope/tests/PHPStan/Rules/Pure/DynamicEffectExtensionTest.php:84)）。

6. **legacy docblock の fail-open と structured-source validation を分離する。** 既存の `@phpstan-impure` docblock にある未知の suffix は、その declaration を unbounded にするという backward-compatible rule を維持する。一方、`effectMetadata` と dynamic extension では、未知の label が configuration/extension diagnostic を生むようにし、override を破棄して callee の declared または conservative catalogued label を残す。impure point を黙って取り除いてはならない。現在の resolver/checker の組み合わせが、この境界に明示的な rule が必要なことを示している（[CallSiteEffectLabelResolver.php](/Users/megurine/repo/php/phpstan-src-worktree-effect-envelope/src/Analyser/EffectLabel/CallSiteEffectLabelResolver.php:64)、[FunctionPurityCheck.php](/Users/megurine/repo/php/phpstan-src-worktree-effect-envelope/src/Rules/Pure/FunctionPurityCheck.php:324)、[EffectEnvelope.php](/Users/megurine/repo/php/phpstan-src-worktree-effect-envelope/src/Analyser/EffectLabel/EffectEnvelope.php:41)）。

7. **constructor boundary を v1 の non-goal として明記する。** 次のように書く。「Constructor declaration は effect label を `new` に伝播する。metadata または dynamic extension による `new` の call-site attribution は v1 の対象外である。」これは現在の実装と一致し、function/method/static の resolver surface がすでに instantiation をカバーしていると読者が誤解するのを防ぐ。[NewHandler.php](/Users/megurine/repo/php/phpstan-src-worktree-effect-envelope/src/Analyser/ExprHandler/NewHandler.php:350)

## 使用するステータス表現

- 「Issue #15224 は open のままであり、PR #6441 は mbstring の consistency だけを扱い、encoding-sensitive purity には対応しない。」（[issue](https://github.com/phpstan/phpstan/issues/15224)、[PR #6441](https://github.com/phpstan/phpstan-src/pull/6441)）
- 「`@pure-unless-parameter-passed` は open な PR #6018 で提案されており、general effect model を待つ必要はない。」（[PR #6018](https://github.com/phpstan/phpstan-src/pull/6018)、[author comment](https://github.com/phpstan/phpstan-src/pull/6018#issuecomment-5675306421)）
