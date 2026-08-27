# NodeScopeResolver 分割を跨いだ rebase の実施記録(PR #6018)

341 コミット遅れの feature ブランチ(PR #6018 `@pure-unless-parameter-passed`)を最新 `phpstan/2.2.x` に
rebase した際、upstream の大規模リファクタで依存 API が消滅していたため「衝突解決」ではなく
**移植作業**になった。その判断と手順の記録。

関連: [20260815-callback-tags-semantics-and-effects-feedback.md](20260815-callback-tags-semantics-and-effects-feedback.md),
[20260709-pseudo-constant-settings-purity.md](20260709-pseudo-constant-settings-purity.md)

---

## 1. 状況

- ブランチ: `feature/pure-unless-parameter-passed`(16 コミット、57 ファイル)
- 旧ベース `94f5e2303` → 新ベース `9680cc45c`(**341 コミット差**)
- リモートは staabm が force-push 済み(私の 15 コミット + doc 修正 1 コミット `cc28625dd`)。
  **staabm の追加分を捨てないよう、ローカルではなくリモートをベースに rebase する**必要があった。
- `gh pr view` で `mergeable=CONFLICTING` を事前確認。

## 2. 何が起きていたか

upstream が `NodeScopeResolver`(巨大クラス)を分割していた:

```
aa4fcf767 Introduce StmtHandler
783adece3 Extract PhpDocsResolver from NodeScopeResolver
9e6cf8c0e Extract VarAnnotationProcessor from NodeScopeResolver
48054f5db Extract CalledMethodProcessor from NodeScopeResolver
46884498d Extract PropertyHooksProcessor from NodeScopeResolver
```

私のブランチが変更していた API は**すべて消滅**していた(grep で 0 件を確認):

| 私の変更対象 | upstream での移動先 |
|---|---|
| `NodeScopeResolver::getPhpDocs()` | `src/Analyser/PhpDocsResolver.php` |
| 関数経路の `enterFunction()` 呼び出し | `src/Analyser/StmtHandler/FunctionHandler.php` |
| メソッド経路の `enterClassMethod()` 呼び出し | `src/Analyser/StmtHandler/ClassMethodHandler.php` |

結果、3 コミット目で **約 1000 行 × 2 ブロック**の巨大コンフリクトが発生。git は
「HEAD 側で大量削除 / 私の側で変更」を 1 つの塊として提示してくる。

## 3. 判断: 一度 abort して方針を決める

巨大コンフリクトをその場で機械的に解決するのは危険と判断し、`git rebase --abort` で
いったん元の状態(`cc28625dd`)に戻した。そのうえで選択肢を整理:

1. **段階的に移植**(採用): コミット単位で新しい移動先へ手作業で配線し直す。履歴を保てる。
2. squash してから移植: コンフリクト解決は 1 回で済むがレビュー履歴が失われる。**レビュー中の PR には不利**。
3. maintainer に相談。

レビュー継続中(staabm が見ている)なので履歴を保つ **1** を採用した。

## 4. 手順(採用した方法)

### 4.1 先に新構造を把握してから再開する

abort した状態で、`git grep -n 'getPhpDocs(' phpstan/2.2.x -- 'src/**/*.php'` などで
**移動先と、新 API の呼び出し側(=自分が配線を足すべき箇所)を全部洗い出してから** rebase を再開した。
これをやらずに再開すると、コンフリクト画面の中で構造を調べることになり見通しが悪い。

判明した配線ポイントは 4 箇所だけだった(巨大コンフリクトの見た目に反して実作業は小さい)。

### 4.2 「移動した側」は upstream を採用し、変更は移動先へ手で入れ直す

コンフリクトの本質は「コードが別ファイルへ移った」ことなので、
**コンフリクトファイルは `git checkout --ours` で upstream 版を丸ごと採用**し、
自分の変更は移動先ファイルへ別途書く、という分離が有効だった。

```bash
git checkout --ours src/Analyser/NodeScopeResolver.php   # 移動元は upstream のまま
# → PhpDocsResolver / FunctionHandler / ClassMethodHandler に手で配線
git add <解決した全ファイル>
git rebase --continue
```

`--ours` / `--theirs` は rebase 中は意味が反転する(`--ours` = 適用先 = upstream 側)点に注意。
今回はそれが欲しい側だった。

### 4.3 重複コミットは NSR 側だけ upstream を採る

後続コミット(`Report ... on a non-optional parameter`)も同じ配線変更を含んでいたが、
その配線は前コミットで既に `FunctionHandler` へ移植済みだった。
NSR 部分は `--ours`、他ファイル(実質的な変更: `FunctionPurityCheck` + テスト)はそのまま適用、で通った。

### 4.4 結果

16 コミット全て保持したまま完了。差分の形は変わった:

```
src/Analyser/NodeScopeResolver.php                → 差分ゼロ(upstream に追従)
src/Analyser/PhpDocsResolver.php                  | 6 ++++--
src/Analyser/StmtHandler/ClassMethodHandler.php   | 3 ++-
src/Analyser/StmtHandler/FunctionHandler.php      | 3 ++-
```

巨大コンフリクトの正体は「**8 行の配線が引っ越しただけ**」だった。

## 5. 検証(移植が正しいかの確認手段)

配線ミスは型エラーとして出るので、`make phpstan`(自己解析)が最も効く検出器だった。

- Pure スイート **30/30**
- PhpDoc rules + FunctionMetadata + AnalyserIntegration + RequiredPhpVersionComment: **5138 tests**
- `make phpstan`: **[OK] No errors** ← タプル要素数のズレ等はここで落ちる
- CS: クリーン

## 6. 落とし穴と対処

### 6.1 worktree が消えている

scratch 領域(`/private/tmp/...`)に作った worktree はセッションを跨ぐと消える。
ブランチ自体は残るので `git worktree prune` → `git worktree add <path> <branch>` で作り直せばよい。

### 6.2 メイン作業ディレクトリの vendor 不整合

別ブランチで作業した後にブランチを切り替えると `vendor/` が古いままになり、
`bin/phpstan` が **`Unknown named parameter $autoTag` で起動失敗**する。
このとき **エラー 0 件は「解析結果」ではなく「起動失敗」**なので、実測値として信用してはいけない。
`composer install` で復旧する。今回これで一度誤った結論を出しかけた。

判定のコツ: `--error-format=raw` で何も出ないときは、まず「本当に解析されたか」を疑う
(既知のエラーが出るファイルを流して sanity check する)。

### 6.3 phpcs が worktree で動かない

リポジトリの `phpcs.xml` が相対パス `./build-cs/phpcs.xml` を参照するため、
worktree には `build-cs/` が無く実行できない。メイン作業ディレクトリのものを
**シンボリックリンク**すれば通る(検証後に削除する)。

```bash
ln -sfn /path/to/main/build-cs build-cs
XDEBUG_MODE=off php build-cs/vendor/bin/phpcs <files>
rm -f build-cs
```

`--standard` で build-cs の設定を直接指定すると**リポジトリ固有の除外設定が効かず**、
無関係な違反(trailing comma 等)が大量に出る。指定せずリポジトリの設定に任せること。

### 6.4 レビュアーの force-push を捨てない

ローカルの `feature/...` ではなく `origin/feature/...` をベースに worktree を作った。
`git log origin/<branch>` で相手の追加コミットを**内容確認してから**取り込むこと。
今回は doc の 1 語修正(`(by-ref out) parameters` → `(by-ref out) optional parameters`)だった。

## 7. 教訓

1. **巨大コンフリクト = 巨大作業とは限らない。** 「コードが移動した」だけなら実作業は数行のことがある。
   まず移動先を特定して規模を測る。
2. **一度 abort する勇気。** コンフリクト画面の中で構造調査をしない。戻してから調べ、方針を決めて再開する。
3. **移動を伴う衝突は `--ours` で分離する。** 移動元は upstream を採用し、変更は移動先へ手で書く。
4. **`make phpstan` が移植の正しさを検証する。** タプル要素数・引数順のズレは自己解析で落ちる。
5. **「エラーが出ない」を信用する前に、ツールが動いているかを確認する。**(6.2)
6. レビュー中の PR では履歴を潰さない(squash より段階移植)。

## 8. 未対応(要判断)

staabm の doc 修正は `bin/functionMetadata_original.php` のみで、
生成物 `resources/functionMetadata.php` 側は古い文言のまま(不整合)。
生成スクリプト `bin/generate-function-metadata.php` はこのローカル環境では動かない
(`toString() on null`、私の変更とは無関係な既存問題)ため、直すなら手動で 1 語追加になる。
