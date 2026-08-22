# Steins レジストリ同期変更案: D-V2 語彙移行(output → io.output 族 + io.input)

> **STATUS: 適用済み(2026-08-12)** — steins #316/#317(`2973666` 時点で master に反映、
> ADR-0083 として記録)。さらにレビュー対応の仕様 hardening(`a7d3f02`)、
> scoped-invalidation 注記(`c968d5b`)、hasSideEffects 分解 narrative(`94b4b7a`)が続いた。

PHPStan 側 stage 10 と対になる Steins 側の変更案。適用先は rigortype/steins。
背景と根拠は [20260812-effect-extension-api-design.md](20260812-effect-extension-api-design.md) §5.11(D-V2)。
**まだ何も適用していない — このドキュメントは提案であり、着手はユーザー判断。**

## 1. 変更の一行要約

`output` / `output.header` ルートを廃止し、ambient チャネルとして `io` 配下へ移設。
`io.output` の内部は **ob_start で捕獲可能か(= 将来のマスキング境界)** で分割し、
`io.input` を対称に新設する。

## 2. `BUILTIN_LABELS` の diff(crates/steins-catalog/src/lib.rs:840-895)

```diff
 io
 io.db
 io.fs
 io.fs.read
 io.fs.write
+io.input
 io.ipc
 io.net
 io.net.http
+io.output
+io.output.buffer
+io.output.header
+io.output.stdout
+io.output.stderr
 io.process
 io.signal
 mutate
 mutate.local
 nondet
 nondet.random
 nondet.time
-output
-output.header
```

(interop v1 語彙 = 21 → **25 ラベル**。`failure.*` の除外は不変。
`subsumes()` / `is_known_label()` / `nearest_label` は無変更で動く — prefix 意味論のまま。)

## 3. 各ラベルの意味(仕様書の語彙節に載せる定義)

| ラベル | 意味 | 由来の例 |
|---|---|---|
| `io.output` | スクリプトの ambient 出力チャネル全般(傘) | — |
| `io.output.buffer` | **OB 層経由 = ob_start() で捕獲可能**な出力 | `echo` `print` `printf` inline HTML `php://output`(マニュアル: print/echo と同一機構)`flush` `ob_flush` |
| `io.output.stdout` | プロセス fd への直接書き込み(OB の影響を受けない) | `php://stdout` `fwrite(STDOUT, …)` |
| `io.output.stderr` | 同上 | `php://stderr` `STDERR` |
| `io.output.header` | 応答メタデータ(OB 対象外) | `header()` `setcookie()`(旧 `output.header`) |
| `io.input` | ambient 入力チャネル | `php://input` `php://stdin`(注: `$_GET` 等のパース済みメモリ読みは従来どおり `global.read`) |

組織原理: `io` の子は「自分で開いた資源」(fs/net/db/…)と「ambient チャネル」
(output/input)の両方を含む。`io.output.buffer` を独立 leaf にする狙いは、
将来の効果マスキング(`ob_start` ガード / `@phpstan-masks`)の規則を
「**`io.output.buffer` に subsume されるラベルだけ差し引ける**」という prefix 1 個の
判定にすること。`fwrite(STDOUT, …)` が ob_start で捕獲できない事実を階層自体が運ぶ。

## 4. 意味論上の帰結(divergence-registry / ADR 補遺に書くべきこと)

1. **bare `io` envelope が出力を許容するようになる**(`io.output ⊑ io`)。
   これは意図的: PHPStan 側 stage 9 のカタログ監査で `fwrite = io, output` 型の
   ぎこちないペアが量産されたのは、ストリームの行き先に stdout が含まれる現実と
   旧階層が喧嘩していた症状(Koka の console 込み `io` 傘と同型)。
   「io するが出力しない」は子の列挙、または予約済みの `-except` 形
   (`io -except io.output`)で綴る — 移設が `-except` に初の具体的動機を与える。
2. 細ラベル envelope の切れ味は無傷(`io.db ⋣ io.output.buffer`)。
3. system/passthru/curl_exec の出力成分は **親 `io.output`**(`.buffer` ではなく):
   ob による捕獲可能性の資料が割れているため、マスキングが差し引け**ない**側に
   倒す(over-approximation = マスキングに対して sound)。readfile は
   ドキュメント化された ob パターンがあるため `.buffer` でよい。

## 5. Steins 側の変更チェックリスト

- [ ] `BUILTIN_LABELS`(§2 の diff)
- [ ] effect origin 表(effects.md): `echo`/`print`/`<?=` → `io.output.buffer`、
      `exit`/`die` は不変
- [ ] builtin カタログの関数→ラベル行(header 族 → `io.output.header` 等。
      PHPStan 側 stage 9/10 のカタログ表が参照実装)
- [ ] `phpdoc-effects-interop.md` の語彙節(v1 vocabulary リスト)を 25 ラベルへ改訂
- [ ] ADR-0082 への amendment(または新 ADR): D-V2 の記録
      (組織原理・マスキング境界・bare io の意味変化)
- [ ] fixtures / conformance 資産の `output` → `io.output.*` 機械的移行。
      **逆転ケースに注意**: 「`io` envelope が echo を検出する」ことを前提にした
      fixture は移設で沈黙に反転する — 同じ原理を `io.db` + echo 等の
      非包含ペアで検査し直す(PHPStan 側 stage 10 で列挙済みの逆転リストを参照)
- [ ] `steins transform effects-envelope` の emission(書く側)が新語彙を出すこと、
      旧語彙を含む既存タグは `existing-tag-unreadable`(未知ラベル)として
      byte-untouched になること(既存の refuse 規律で自然に成立するはず — 要テスト)
- [ ] `effect.unknown-label` の typo 提案候補に新 leaf が入ること

## 6. 互換メモ

- 旧綴り `@phpstan-impure output` は両実装で「未知ラベル → タグ全体 ⊤」に退化する
  (S5)。**finding を発明しない方向の劣化**なので、移行期の混在は安全。
  Levenshtein 距離(output ↔ io.output = 3)の関係で typo 提案は出ない —
  移行案内はドキュメントの仕事。
- interop 仕様の「Backward compatibility」節の主張(現行 PHPStan では
  GenericTagValueNode として無害)は語彙の中身に依存しないため不変。
