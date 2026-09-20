# Effect envelope 2.3.x 対応・敵対的レビュー

作成日: 2026-09-15

## 結論

`worktree-effect-envelope` は最新の `phpstan/2.3.x`（`77841303f2b8174c6d22309d8e89f2436b746f86`）を基点に rebase 済みであり、2.3.x の `processArgs()` / `ArgsResult` アーキテクチャに合わせた追加修正後、マージを妨げる問題は見つからなかった。

ただし、当初の「引数処理後の scope を dynamic effect extension に渡す」だけの実装には、PHP の引数評価順を壊して effect を過小評価する false negative があった。敵対的レビューで再現し、各引数の評価時点の `ExpressionResult` を一時 storage から厳密に参照するよう修正した。

最終候補:

- branch: `worktree-effect-envelope`
- HEAD: `f303c63986107fd567c959029a2d1e40aa53db52`
- base: `phpstan/2.3.x` at `77841303f2b8174c6d22309d8e89f2436b746f86`
- divergence: upstream 0 / branch 12 commits
- uncommitted patch SHA-256: `84c99c3240377f4a579bd1303e166404f941f49f1eb6096982ecc1b1bde79963`

## 2.3.x への適合内容

2.3.x では call の引数が左から右へ一度だけ処理され、その結果と評価後 scope が `ArgsResult` に保存される。effect label の call-site 解決は `processArgs()` より後でなければならないが、最終 scope から引数式を再評価してはならない。

今回の修正は function、instance method、static method の三経路で同じ規則を適用する。

1. 引数処理後の `NodeCallbackScope` を作る。
2. `ArgsResult::getArgResults()` の各結果を transient storage に積む。
3. effect 解決に限り、それらを「実際の評価位置の型」として扱う。
4. `finally` で必ず storage を pop する。

通常の dynamic return type 処理は既定値 `false` のままであり、従来の counterfactual な scope 再評価を維持する。effect call-site の三経路だけが評価位置固定を opt-in する。

## 敵対的レビューで見つかった問題

### 修正済み: 後続の named argument が先行引数を遡及的に書き換える

次の呼び出しでは PHP は source order で評価するため、実行時の `mode` は `'w'` である。

```php
$mode = 'w';
fopen(mode: $mode, filename: $mode = 'r');
```

最終 scope だけで `$mode` を問い合わせる実装では `'r'` と再評価され、write を read と誤分類して `@phpstan-impure io.fs.read` を通してしまう。function、instance method、static method の全てで再現可能だった。

`ArgsResult` が保持する引数ごとの `ExpressionResult` を transient storage に積み、評価位置固定のコピーだけは `NodeCallbackScope` で再価格付けしないようにして解消した。

### 修正済み: entry scope より型が広がると事前状態へ戻る

最初の修正案は callback scope の walk seed を引数処理前へ戻していた。この方法は constant narrowing には通るが、次の widening では失敗した。

```php
$mode = 'r';
fopen($mode = $newMode, $mode); // $newMode is string
```

第 2 引数の評価時点型は `string` なので effect は保守的な `io` でなければならない。ところが entry scope の `'r'` を採用すると `io.fs.read` に過小評価される。scope の種を操作する方式をやめ、引数 result 自体へ「評価位置固定」を付けることで解消した。

### 修正済み: PHP 8.0 fixture 宣言不足

named argument を追加した fixture に `<?php // lint >= 8.0` が必要だった。全テストで `RequiredPhpVersionCommentTest` が検出したため、規約どおり先頭へ追加した。

## 回帰テスト

`DynamicEffectExtensionTest` に次を追加した。

- 前の引数による assignment を後の引数が読む function/static call
- 後の named argument が変数を書き換えても、先行 mode が `'w'` のままになる function/method/static call
- `'r'` から任意の `string` へ widening した後続引数を `io` と判定する case
- static extension は `Scope::getNativeType()` を使用し、PHPDoc の `'r'` と native の `string` が異なる case で native-type 経路を検証する

期待 diagnostics は完全一致で検証しているため、write を read と誤分類した場合、および unknown を read と誤分類した場合はテストが落ちる。

## 検証結果

- `make tests`: 21,983 tests、97,575 assertions、65 skipped、成功
- `make phpstan`: 2,728 files、エラーなし
- focused purity/analyser tests: 68 tests、71 assertions、成功
- `NodeScopeResolverTest --filter bug-13735b`: 成功
- changed-file parallel lint: 9 files、syntax error なし
- `git diff --check`: 成功

## レビュー結果

- 目標・仕様レビュー: production 最終候補を含む patch SHA-256 `8d62a15...` に対して PASS、blocking finding なし。その後の差分は PHPDoc/native の相違を明示する回帰 case の追加だけで、最終 `84c99c3...` は主担当が差分監査した
- コード品質レビュー: transient clone、stack の nested/reentrant pop、closure 除外、PHPDoc/native 分離、API BC を確認し PASS。PHPDoc/native の差を区別するテスト不足という非ブロッキング指摘は、static extension を `getNativeType()` に変更し、PHPDoc `'r'` / native `string` の回帰 case を追加して反映した
- 初回 security/soundness レビュー: named argument の遡及的再評価を Medium finding として検出し、今回の production 修正と三経路の回帰テストにつながった
- 最終 security/QA の独立再実行: 担当 agent の workspace credit 枯渇で完了できなかった。代わりに最終 patch に対する全 PHPUnit、PHPStan 自己解析、lint、targeted runtime test を主担当で再実行した

実装の初稿と回帰 fixture は指定どおり Luna max に移譲した。Luna は検証途中で workspace credit が尽きたため、主担当が widening の追加 false negative を発見し、修正・最終検証を引き取った。

## 残存リスクと非ゴール

### Low: extension/metadata の未知 label が fail-open になり得る

dynamic extension または metadata が vocabulary にない label を返すと、現在の purity check はその effect point を診断対象から外し得る。これは trusted extension/configuration 境界にある既存の v1 方針で、2.3.x 移植による回帰ではない。ただし、将来は attributed label を vocabulary で検証し、不正なら callee 宣言へ fallback するか設定診断にする方が sound である。

### Constructor の call-site attribution は v1 非ゴール

`new` は constructor 宣言の effect label を伝播するが、`effectMetadata` と dynamic call-site extension の対象にはしていない。設計資料で明示された非ゴールであり、今回の 2.3.x 対応では拡張していない。

## 出典・関連資料

- PHPStan maintainer の提案: [`@phpstan-impure io`](https://x.com/OndrejMirtes/status/2086491960089444580)
- `20260915-phpstan-2.2x-vs-2.3x-side-effects-delta.md`
- `20260812-effect-envelope-phpstan-port-design.md`
- `20260812-effect-label-docs-adversarial-review.md`
