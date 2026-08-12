# Effect envelope ブランチ敵対的レビュー

- レビュー日: 2026-08-12
- 対象 worktree: `/Users/megurine/repo/php/phpstan-src/.claude/worktrees/effect-envelope`
- 比較基準: `f69c88168cb6ddb076c43f6cfd73df90592055f4` (`2.2.x`)
- 対象 HEAD: `ca2577677d5fb37f21a348e071aa973d10a46545`
- 差分: 8 commits、95 files、`+5131/-156`
- 仕様正本: `phpstan-notes` の index に stage 済みの
  `20260812-effect-envelope-phpstan-port-design.md`、
  `20260812-effect-extension-api-design.md`、`effect-envelope-demo/*`

## 結論

**FAIL — 現状のままのマージは非推奨。**

stage 1–8 の設計要求、feature toggle、extension precedence、BC 方針は実装されており、
全テスト・自己解析・デモも通る。しかし、新規 builtin effect catalog が複数の PHP API の
effect を実際より狭く宣言している。effect envelope は上界なので、この under-approximation は
単なる精度不足ではなく、宣言違反を無警告で通す soundness failure になる。

## Findings

### [P1] 汎用 stream / URL-wrapper API を `io.fs.*` と断定し、network/process I/O を隠す

該当箇所:

- `bin/functionMetadata_original.php:116,123,125,127,132-137,272,318-319`
- 生成物 `resources/functionMetadata.php:854,953,955-958,983,988-1001,1680`

`fopen()` は local file だけでなく URL と登録済み stream wrapper を開く。
`fgets()` / `fread()` / `fputs()` / `fwrite()` は resource の provenance を問わず、
`fsockopen()` の socket や `popen()` の process pipe にも使える。`file()`、
`file_get_contents()`、`file_put_contents()`、`copy()`、`rename()` も wrapper-aware である。
にもかかわらず catalog はこれらを `io.fs` / `io.fs.read` / `io.fs.write` に固定している。

仕様 S1 の segment-prefix subsumption では `io.fs.*` は `io.net` や `io.process` を含まない。
そのため、次のような filesystem-only 宣言が arbitrary stream や HTTPS を無警告で許容する。

```php
/**
 * @param resource $stream
 * @phpstan-impure io.fs.read
 */
function readFromArbitraryStream($stream): string|false
{
	return fread($stream, 1024);
}

/** @phpstan-impure io.fs.read */
function fetchRemoteUrl(): string|false
{
	return file_get_contents('https://example.com/resource');
}
```

公開 CLI でこの再現を解析すると exit 0、finding 0 だった。さらに
`stream_socket_pair()` で作った socket resource に `fwrite()` し、別端を `fread()` すると
実際に `"ping"` が読めることも確認している。これは「語彙が粗い」問題ではなく、
`@phpstan-impure io.fs.*` が別 effect を隠す false negative である。

修正案:

- provenance を知らない stream / URL API は保守的に `io` とする。
- literal scheme、mode、resource provenance を証明できる call site だけ
  `DynamicFunctionEffectExtension` で `io.fs.*` / `io.net` 等へ狭化する。
- `@param resource` の `fread()` / `fwrite()`、literal HTTPS の
  `fopen()` / `file_get_contents()`、proven local path の陽性対照を回帰テストにする。

### [P1] `readfile()` から `output` effect が欠落している

該当箇所:

- `bin/functionMetadata_original.php:271`
- `resources/functionMetadata.php:1675`

`readfile()` は file/URL を読むだけでなく、内容を出力する API である。現在は
`['io.fs.read']` しか付かないため、`@phpstan-impure io.fs.read` の関数で `readfile()` を呼んでも
`output` 超過が報告されない。remote wrapper の場合は前項の `io.net` も同時に欠落する。

公開 CLI で `@phpstan-impure io.fs.read` +
`readfile('https://example.com/resource')` を解析し、exit 0、finding 0 を確認した。

修正案: 少なくとも `output` を含める。wrapper provenance を静的に決められない既定値は
`['io', 'output']` のような保守的上界にし、狭化は call-site extension に任せる。

### [P1] `system()` / `passthru()` / cURL の direct output effect が欠落している

該当箇所:

- `bin/functionMetadata_original.php:320-321,327,330`
- `resources/functionMetadata.php:876,880,1600,1761`
- 誤った沈黙を固定している fixture:
  `tests/PHPStan/Rules/Pure/data/impure-effect-labels-calls.php:435-440`

`system()` と `passthru()` は subprocess output を直接表示するが、catalog は
`io.process` だけを付けている。したがって `@phpstan-impure io.process` が `output` を隠す。
同様に `curl_exec()` は `CURLOPT_RETURNTRANSFER=true` でない限り response body を直接出力し得るが、
`io.net` だけである。`curl_multi_exec()` も構成 handle に同じ条件を含み得る。

`@phpstan-impure io.process` 内の `system()` / `passthru()` を公開 CLI で解析し、
exit 0、finding 0 を確認した。既存 cURL fixture は `io.net` envelope 内の
`curl_exec()` が沈黙することを成功ケースとして置いており、実際の API 契約ではなく現在の
metadata 定数を固定する false-confidence test になっている。

修正案:

- `system()` / `passthru()` に `output` を追加する。
- `curl_exec()` / `curl_multi_exec()` の既定値にも `output` を追加し、
  `CURLOPT_RETURNTRANSFER=true` を追跡できる場合だけ dynamic extension で除く。
- inside-envelope fixture は `io.net, output` を要求する契約テストへ直す。

### [P2] effect-envelope policy が `FunctionPurityCheck` に集中しすぎている

`src/Rules/Pure/FunctionPurityCheck.php:35` は 422 physical LOC（約410 pure LOC）となり、
従来の purity / void / callable 条件に加えて、envelope 解決、effect mapping、
certainty 別メッセージ、pure-local-mutation policy まで所有している。

機能上の誤りは再現していないが、effect label の変更が中心 purity evaluator を毎回触る構造で、
今後の回帰リスクが高い。envelope 判定と diagnostic rendering を非公開 collaborator に抽出し、
`FunctionPurityCheck` は既存 orchestration に戻すのが望ましい。

### [P2] `InvalidEffectLabelsRule` が traversal と全 diagnostic policy を抱えている

`src/Rules/PhpDoc/InvalidEffectLabelsRule.php:46` は 374 physical LOC（約321 pure LOC）で、
AST/docblock discovery、pure-tag policy、unknown/suggestion/covering 判定、redundancy 判定を一体で持つ。
documented repository standard の違反ではないが、規則追加時の変更理由が分散しやすい。
Rule を traversal adapter とし、label diagnostic calculation を小さな内部 component に分ける余地がある。

## 採用しなかった指摘

### 冗長ラベルの二重ループを HIGH の source-controlled DoS とする主張

`InvalidEffectLabelsRule::reportRedundantLabels()` は構造上 nested loop だが、繰り返し `io` の場合は
各 outer iteration が最初の同一ラベルで `break` するため、その入力で Θ(L²) にはならない。
1,000件の `io` では999件の診断が生成されたが、実測で異常な CPU/RSS 増幅は確認できなかった。
default vocabulary は21 entry に限定され、多数の distinct known labels には project config/provider の
制御も必要である。従って、今回提示された PoC と攻撃モデルから HIGH blocker とは認定しない。

ただし、診断数の上限と distinct-label 時の計算量を改善する performance hardening は有益である。
重複 count と ancestor prefix index を一度構築すれば、pairwise scan を避けられる。

## Spec axis

**PASS。** staged design と差分を照合し、S1–S8、stage 1–8、Liskov、
`mutate.local` tolerance、diagnostic toggles、extension → metadata → declaration の置換優先順位、
D-U1 の possibly-grade finding、unknown-label whole-envelope fallback に欠落は見つからなかった。
上の P1 は設計実装の欠落ではなく、stage 5 builtin catalog の事実データが不正確な問題である。

## Standards axis

documented repository standard の hard violation は見つからなかった。`FunctionReflection` と
`ExtendedMethodReflection` は `@api-do-not-implement` のため accessor 追加は repo の BC 方針に適合する。
Fowler/maintainability judgement として P2 の2件を残す。

## 実行検証

- targeted PHPUnit: 157 tests、182 assertions、exit 0
- `make tests`: 21,404 tests、96,988 assertions、65 skipped、exit 0
- `make phpstan`: 2,441 files、no errors、exit 0
- staged demo default / bleedingEdge: 期待 finding と toggle 差を確認
- clean CLI fixture: exit 0、output なし
- adversarial stream/wrapper/output fixture: 構文 valid、PHPStan exit 0、finding 0
- `git diff --check f69c88168...HEAD`: clean

テストが緑であることは「実装済みの期待値と一致する」証拠にはなるが、builtin catalog の
effect claim 自体が正しいことの証拠にはならない。今回の P1 はその境界を実 API semantics と
公開 CLI 再現で突いた結果である。

## 推奨修正順

1. builtin catalog の全新規 `impureEffectLabels` を「上界として sound か」で再監査する。
2. P1 3系統を保守的ラベルへ直し、resource provenance / URL scheme / direct output の回帰テストを追加する。
3. catalog を直した同一 HEAD で targeted suite、full suite、self-analysis、デモを再実行する。
4. P2 の責務分割はその後の独立リファクタとして扱う。
