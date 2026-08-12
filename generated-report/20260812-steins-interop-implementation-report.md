# Steins 側実装完了レポート: interop envelope + D-V2 語彙同期

PHPStan 側(effect extension の port 作業)へ返す情報のまとめ。2026-08-11〜12 の
一連の作業で Steins に着地したもの、確定した共有意味論、書かれた文書、未決事項を
一望する。参照実装・検証結果として PHPStan 側 stage の続きから使えるように書く。

## 0. 一行要約

Ondřej Mirtes の提案構文 `@phpstan-impure io`(2026-08-09)を Steins で
**読み・検査・書き**の三方向で完全実装し(6 PR スタック、master `7b3ecab`)、
続けて D-V2 語彙移行(`output` → `io.output` 族、PR #317、master `721b077`)で
両実装の語彙を 25 ラベルに同期した。仕様書は upstream にそのまま提出可能な形で
master にある。

## 1. 着地したもの(時系列)

| # | 内容 | 状態 |
|---|---|---|
| [#303](https://github.com/rigortype/steins/issues/303) | umbrella: interop envelope 全体 | closed |
| [#304](https://github.com/rigortype/steins/pull/304) | 仕様書 + ADR-0082 | merged |
| [#305](https://github.com/rigortype/steins/pull/305) | パーサ(TagKind::InteropEnvelope、strict-list-or-bare 文法) | merged |
| [#307](https://github.com/rigortype/steins/pull/307) | declared レーン接続(unchecked 層、taint 非放電) | merged |
| [#308](https://github.com/rigortype/steins/pull/308) | contract 検査(作者の綴りで finding を描画) | merged |
| [#309](https://github.com/rigortype/steins/pull/309) | `steins transform effects-envelope`(書き出し) | merged |
| [#310](https://github.com/rigortype/steins/pull/310) | ドキュメント整合 + CHANGELOG | merged |
| [#316](https://github.com/rigortype/steins/issues/316) / [#317](https://github.com/rigortype/steins/pull/317) | D-V2: `output` → `io.output` 族 + `io.input`(ADR-0083) | merged |
| master 直コミット | ja 翻訳追随(`f0e63c5`)、Vocabulary evolution + pure 非停止性の規範化(`2973666`) | done |

全 PR で CI green(test linux/macos・rustdoc -D warnings・fp-gate・licenses・
wasm)。fp-gate は公開 corpus に対して全段階で無影響だった。

## 2. 共有意味論として確定したもの(PHPStan 側と共有する決定)

正本は steins の
[`docs/type-specification/phpdoc-effects-interop.md`](https://github.com/rigortype/steins/blob/master/docs/type-specification/phpdoc-effects-interop.md)
(upstream 提出可能な自己完結文書)。要点:

1. **文法** = `@phpstan-ignore` と同形。カンマ区切りドットパス + 任意の末尾
   括弧コメント。**コメントはラベル 1 個以上の後のみ**(タグ名直後の `(` は
   phpdoc-parser の Doctrine 経路で `phpDoc.parseError` になり得る — 実測済み)。
2. **裸タグ**: 裸 `@phpstan-impure` は ⊤ = 従来意味のまま(Steins は読まない)。
   裸 `@phpstan-pure` は空 envelope。ラベルは既存タグの意味を**狭める**方向にしか
   使わない。
3. **未知ラベル**(オーナー裁定 2026-08-12): 1 個でも未知なら**タグ全体が無指定**
   (⊤)。認識できた部分集合で検査すると作者の主張より狭い上界で判定してしまう
   ため(⊤ 合成)。無指定のタグも優先争い(メソッド > クラス)には勝ったまま。
   typo 検出は bound 読解とは別の関心事(仕様は position を取らない)。
4. **クラスレベルタグ** = PHPStan 実装済み意味論の逐語採用(2.1.39, phpstan-src
   #4422 準拠を phpstan-src 2.2.x で検証): メソッドタグが常に勝つ /
   `all-methods-pure` はコンストラクタを含み void 返しメソッドを含まない /
   interface→実装への伝播なし / エイリアスなし。
   `@phpstan-all-methods-impure` はラベルリストを取れる(全被覆メソッドの一括
   bound)。
5. **pure の意味** = 空 bound + `mutate.local` 許容(呼び出し元フレームへの
   by-ref 書き込みは観測不能 — runST 被包化の一階版)。**pure は停止性を含意
   しない**(重複呼び出しの同一視・記憶は許可される)— 2973666 で規範化。
6. **語彙の進化原則**(2973666 で規範化): 葉の追加は非破壊(prefix は述語で
   あって Koka 型の閉じた別名ではない)/ 節点の移動・削除は破壊で、二層の劣化
   パス(docblock → 無指定、属性 → 語彙適合診断)を通す。正の bound は語彙成長
   に安定、補集合 bound(`-except`)は不安定 — 除外は検査時点の語彙で解釈される。
7. **D-V2 後の v1 語彙(25 ラベル)**: `exit ffi global.read global.write io
   io.db io.fs io.fs.read io.fs.write io.input io.ipc io.net io.net.http
   io.output io.output.buffer io.output.header io.output.stderr
   io.output.stdout io.process io.signal mutate mutate.local nondet
   nondet.random nondet.time`(`failure.*` は value provenance のため除外の
   まま)。`io.output.buffer` = ob_start 捕獲可能境界。bare `io` は出力を許容
   (Koka の io 別名が console を含むのと同型)。

## 3. Steins 固有の実装事項(共有仕様ではないが参照実装として有用)

- **二層構造**: `#[\Steins\Effect]` = checked 層(Liskov 連言・taint 放電)、
  docblock タグ = unchecked 層(declared レーン行き・放電なし・nearest-wins)。
  PHPStan 側は単層でよいが、「docblock は bound であって証明ではない」の扱いの
  一例として。
- **finding は作者の綴りで描画**: `declared @phpstan-impure io.db` と出る。
  属性を書いていないユーザーに属性構文を見せない。メッセージ文言は contract
  ではない(id が contract)ので PHPStan 側は自由。
- **旧語彙(D-V2)の劣化実測**: 属性 `'output'` は `effect.unknown-label`
  (全プロファイル・提案なし — 距離 3 > 上限 2)**かつ** contracts 面では
  `effect.envelope-exceeded` も出る(未知ラベルは何も subsume しないため)。
  docblock の `output` はタグ全体が無指定に退化し finding を発明しない。
  PHPStan 側 stage 10 の逆転リスト 3 件のうち「io+echo 沈黙化」は Steins でも
  両面テストで釘打ち済み(`io.db`+echo が代替の検査ペア)。
- **transform の規律**「読みは忠実・書きは保守的」: 網羅的推論のみ書く、
  クラス pure は全メソッド条件、裸タグ・メソッド単位 pure は書かない、
  未知ラベル入り既存タグは作者の散文として `existing-tag-unreadable` で
  byte-untouched(上書きも二重挿入もしない)、checked 属性由来の未知ラベルが
  bound に混入したら `bound-label-unknown` で書かない。
- **インライン HTML** が effect origin になった(ADR-0008 の文書と実装の乖離を
  D-V2 で精算)。空白のみのテキストは除外(実用的近似と明記)。
- **新カタログ行**(stage 9 と同型の false-negative を Steins 側でも解消):
  readfile/fpassthru = `io.fs.read, io.output.buffer` / system・passthru =
  `io.process, io.output`(捕獲可能性の資料が割れる行は親 = unmaskable 側に
  倒す)/ curl_exec = `io.net, io.output` / flush・ob_flush =
  `io.output.buffer`。fwrite は `io.fs.write` のまま(行き先狭化は defer)。

## 4. 書いた文書の一覧

| 文書 | 場所 | 用途 |
|---|---|---|
| interop 仕様書 | steins `docs/type-specification/phpdoc-effects-interop.md` | **upstream 提出の正本**。文法 EBNF・意味論・後方互換・進化原則・open questions |
| ADR-0082(+ 未知ラベル追補) | steins `docs/adr/0082-interop-envelopes.md` | 設計判断と却下案の記録(ADR-0006 を改正、ADR-0067/0068 を消費) |
| ADR-0083 | steins `docs/adr/0083-io-output-ambient-channel.md` | D-V2 の記録(ADR-0008/0018 を改正) |
| Ondřej 向け write-up | ローカル draft(scratchpad、オーナーに納品済み) | phpstan#14220 への投稿文。実バイナリ出力・25 ラベル語彙・Prior art 節(出典リンク付き)入り。**未投稿** |
| 前例文献フィードバック | 本 repo `20260812-effect-vocab-precedent-feedback.md` | Koka/Flix ほか 10 項・出典リンク 18 本。§9/§10 は upstream 議論の応答弾 |
| D-V2 提案書(適用済み) | 本 repo `20260812-steins-vocab-sync-proposal.md` | チェックリストは全消化。§5 の逆転注意も対処済み |

## 5. 未決・deferred(issue 起票済み)

- [#311](https://github.com/rigortype/steins/issues/311) docblock ラベルの typo
  検出 — 散文を bound と読まない制約下で。`effect.unknown-label` は再利用不可
  (抑制チャネル無し)、専用 id + opt-in floor が要る。
- [#312](https://github.com/rigortype/steins/issues/312) `-except` 補集合上界 —
  予約のみ。開放語彙下の不安定性(§2-6)をコメントで記録済み。D-V2 が初の具体的
  動機(`io -except io.output`)を与えた。
- [#313](https://github.com/rigortype/steins/issues/313) コンストラクタ
  own-property carve-out — ADR-0055 slice E2(mutate.self)と**同時に**入れないと
  プロパティ初期化 pure コンストラクタが軒並み誤検知化する時限結合。
- [#314](https://github.com/rigortype/steins/issues/314) upstream 持ち込み —
  投稿判断はオーナー。write-up は投稿可能状態。
- マスキング本体(`@phpstan-masks io.output.buffer` 系)/ fwrite 行き先狭化 /
  ob_start 族カタログ行 — #316 コメントに理由付きで記録。

## 6. PHPStan 側実装への具体的インプット

1. **未知ラベル規則(§2-3)は共有意味論**。PHPStan 側実装も「1 個でも未知なら
   タグ全体を bound として扱わない」に揃えること。認識部分集合での検査は
   仕様違反(finding の捏造)。
2. **stage 10 の逆転 3 件のうち Steins で観測できた分は釘打ち済み**。
   `php://input` の `global.read`→`io.input` 逆転は Steins には該当なし
   (ストリームターゲット着色が元々無い)— PHPStan 側のみの作業。
3. **旧語彙属性の double-finding**(unknown-label + envelope-exceeded)は
   PHPStan 側の移行ガイドにも書くべき挙動(向こうに属性は無いが、拡張が独自
   注釈を持つなら同じ問題が出る)。
4. 検証済み後方互換事実(再掲): `@phpstan-impure io` は phpdoc-parser 2.3.3 で
   `GenericTagValueNode` として無害 / `InvalidPHPStanDocTagRule` はタグ名完全
   一致なので素通り / 唯一の構文的罠はタグ名直後の `(`。
5. 仕様の open questions 3 件(語彙の open/closed、interface 伝播、void 除外の
   将来)は Ondřej の裁定待ちとして仕様書末尾に維持。前例からの推奨回答は
   `20260812-effect-vocab-precedent-feedback.md` §9 参照。
