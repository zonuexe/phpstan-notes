# Effect envelope demo

`@phpstan-impure <labels>` / `@phpstan-all-methods-impure <labels>` を
**宣言的な effect 上界(envelope)** として検査する試作
(phpstan-src worktree `effect-envelope`、5 コミット)の動作デモ。

- [src/demo.php](src/demo.php) — 注釈付きの単一ファイル。§1–§7 が各機能に対応
- [output-phpstan-2.2.8-released.txt](output-phpstan-2.2.8-released.txt) — **リリース版 PHPStan 2.2.8** の出力
- [output-default.txt](output-default.txt) — 試作ブランチ、デフォルト設定
- [output-bleedingedge.txt](output-bleedingedge.txt) — 試作ブランチ + bleedingEdge

## 3つの出力が語ること

### 1. リリース版 2.2.8(BC 実証)

パースエラーもタグ警告も**ゼロ**。全ラベル付きタグは無害に無視され、出るのは
従来からある 4 件だけ — `preg_match`/`sort` の by-ref 偽陽性 3 件と、正しい
`impure.propertyAssign` 1 件。**既存の PHPStan に対してこのタグは今日から書ける。**

### 2. 試作・デフォルト設定(stage 1–3、opt-in なしで安全)

リリース版の 4 件に加えて envelope 検査 3 件:

| 行 | finding | 意味 |
|---|---|---|
| 80 | `io.output.buffer exceeds the envelope` | `@phpstan-impure io.db` と主張したメソッドが echo している(宣言サイト検査)。D-V2 後の語彙 |
| 251 | `io.output.stdout … declared @phpstan-impure io.output.buffer` | **D-V2 の4子分割の実演**: echo/`php://output` は buffer envelope 内、`fwrite(STDOUT)` は ob_start が捕獲できない別チャネルなので超過 |
| 101 | `io.fs.write (call to function file_put_contents()) exceeds …io.net` | クラスレベル envelope + builtin カタログ経由の call 伝播 |
| 203 | `nondet.time (call to function time()) exceeds …io.fs` | 提案の旗艦例そのもの: 時計の読み取りが検査可能な事実になった |
| 235 | `may have effect io (call to function mail()) … may exceed the envelope` | possibly 級(D-U1)。`mail` の上界は `io`(transport が platform/php.ini 依存 — stage 9 soundness 修正) |
| 101 | `io.fs.write (call to function file_put_contents())` | **狭化 extension の実演**: literal ローカルパスだから `io.fs.write`。非定数パスなら保守的な `io` になる |

沈黙も同じだけ重要: `show()`(全 effect が bound 内)、`migrate()`
(未知ラベル `legacy-database-stuff` → タグ全体が ⊤、finding を発明しない)、
`refreshCache()`(`io, nondet.time` 宣言どおり)はすべて無警告。

### 3. 試作 + bleedingEdge(stage 4 の許容 + Liskov + stage 6 診断)

- `slug()` / `sortedCopy()` の **preg_match/sort 偽陽性が消えた**
  (phpstan/phpstan#11884 の解)。`MatchCache::extract()` の
  `$this->matches` への by-ref 書き込みは**代入機構が独立に捕捉し続け**、
  メッセージは effect 語彙で語られる(162 行:
  `has effect mutate, but is declared @phpstan-pure`、identifier は従来の
  `impure.propertyAssign` のまま)。
- 123 行: `method.envelopeWidened` — 実装がインターフェースの `io.net` を
  `io.fs.write` で widening(effect 版 Liskov)。
- 214/220 行: 語彙診断 — `io.netw` には `did you mean "io.net"?`、
  `io, io.db` には「io.db は io に覆われている」。**bound の意味は変えない**
  (typo タグは依然 ⊤)し、`legacy-database-stuff` のような人間のメモ(§6)は
  診断ルールも沈黙する。

## 再現方法

```bash
cd effect-envelope-demo
# 試作ブランチ(phpstan-src の worktree effect-envelope)
php <worktree>/bin/phpstan analyse --no-progress --error-format=raw -c default.neon src/demo.php
php <worktree>/bin/phpstan analyse --no-progress --error-format=raw -c bleeding.neon src/demo.php
# リリース版
composer require --dev phpstan/phpstan && vendor/bin/phpstan analyse --no-progress -c default.neon src/demo.php
```

設計と実装の全記録: [../generated-report/20260812-effect-envelope-phpstan-port-design.md](../generated-report/20260812-effect-envelope-phpstan-port-design.md)
