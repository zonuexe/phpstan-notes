# `@phpstan-impure <labels>` effect envelope の PHPStan 移植設計

Steins (rigortype/steins) で実装済みの「interop effect envelope」
([PR #304](https://github.com/rigortype/steins/pull/304) = 仕様/ADR、issue #303 = 実装スタック slice 0–5、全マージ済み)
を PHPStan 本体に移植するための設計メモと懸念点。

- 仕様原典: [phpdoc-effects-interop.md](https://github.com/rigortype/steins/blob/master/docs/type-specification/phpdoc-effects-interop.md)(upstream 提出用に自己完結で書かれている)
- 動機: [why-effects.md](https://github.com/rigortype/steins/blob/master/docs/why-effects.md)、[20260703-effect-system-design.md](20260703-effect-system-design.md)(案B「死んだ色の昇格」の系譜)
- 発端: ondrejmirtes 自身の提案 (2026-08-09) — *"The effect could just be a parameter after @phpstan-impure PHPDoc tag. Like @phpstan-impure io."*

---

## 1. 提案の一行要約

既存の `@phpstan-impure` タグにカンマ区切りの **effect ラベルリスト** を載せ
(`@phpstan-impure io.db, nondet.time (コメント)`)、boolean の不純フラグを
「この関数が行ってよい不純性の種類の**上界 (envelope)**」に拡張する。

これは 20260703 メモの**案B**(死んだ `ImpurePointIdentifier` の色を判定に効く effect kind へ昇格)の
宣言側の器にあたる。`@pure-unless-callable-is-impure` (PR #3482) が担う多相(案C の最小スライス)とは
直交し、共存できる。

## 2. 仕様サマリ(Steins 実装で検証済みの意味論)

### 2.1 文法

```ebnf
impure-tag      = "@phpstan-impure" [ label-list [ comment ] ] ;
pure-tag        = "@phpstan-pure" ;                              (* ラベル不可 *)
class-pure-tag  = "@phpstan-all-methods-pure" ;                  (* ラベル不可 *)
class-impure-tag= "@phpstan-all-methods-impure" [ label-list [ comment ] ] ;
label-list      = label { "," label } ;
label           = segment { "." segment } ;
segment         = lowercase-letter { lowercase-letter | digit } ;
comment         = "(" text-without-close-paren ")" ;
```

- `@phpstan-ignore` の識別子リストと同型(カンマ区切り dot-path + 末尾括弧コメント1個)。
- **タグ名直後の `(` は禁止**: `@phpstan-impure (why)` は phpdoc-parser の Doctrine パスに入り
  `phpDoc.parseError` を出しうる。ラベルが1つ以上あるときのみコメント合法。
- 現行 phpdoc-parser 2.3.3 では `@phpstan-impure io` は `GenericTagValueNode("io")` として
  無害にパースされる(検証済み・BC 無風)。

### 2.2 v1 ラベル語彙(20個)

```text
exit
ffi
global.read   global.write
io   io.db   io.fs   io.fs.read   io.fs.write   io.ipc
     io.net  io.net.http   io.process   io.signal
mutate   mutate.local
nondet   nondet.random   nondet.time
output   output.header
```

(Steins builtin registry から `failure.*` を除いた **21 ラベル**。`failure.*` は値の provenance であって effect ではない。)

### 2.3 意味論の要点(不変条件)

| # | 規則 | 根拠 |
|---|---|---|
| S1 | ラベルは segment 単位の prefix subsumption: `io` ⊒ `io.net.http`、`io` ⋣ `iota` | 実装は4行の文字列判定。leaf 語彙を知らないチェッカーでも有用 |
| S2 | 裸 `@phpstan-impure` = ⊤(現状どおり)。ラベル追加は常に narrowing、既存 docblock の意味は不変 | BC の要 |
| S3 | `@phpstan-pure` = 空 envelope、**ただし frame-local な by-ref out-param 書き込み(`mutate.local` 相当)は全 envelope が許容** | `preg_match($p,$s,$m)` / `sort($rows)` 問題への回答。Pure が最狭なので、そこで許容し広い envelope で拒否すると検査が非単調になる |
| S4 | `@phpstan-pure` はラベルを取らない(「pure だが effect を行う」は矛盾) | 部分的 effect は impure bound で綴る |
| S5 | **未知ラベルはタグ全体を ⊤ (unspecified) にする**。認識できた部分集合で検査しない | 現行 PHPStan はタグの後ろを捨てるので `@phpstan-impure database` という人間のメモが既に世に存在しうる。部分解釈は「著者が書いていない狭い bound」で検査する偽陽性製造機。⊤ 化は finding を失うことはあっても発明しない |
| S6 | ⊤ 化したタグも **precedence には勝つ**(メソッドの inert タグはクラスタグにフォールバックしない) | `Absent / Unbounded / Bound` の3値が必須。「書かれていない」と「書かれているが何も言っていない」は別 |
| S7 | クラスレベルは nearest-wins(メソッドタグがクラスタグを**置換**、conjunction ではない)。constructor は `all-methods-pure` の対象、void 返却メソッドは対象外、interface→実装の伝播なし | PHPStan 2.1.39 の実装済み意味論をそのまま採用 |
| S8 | 空ラベルリストの二義性: メソッド側の空 = pure(最狭)、裸 `all-methods-impure` の空 = ⊤(最広)。同じ型で表現すると事故る | Steins は family を見て特別扱い |
| S9 | typo 診断(語彙適合)は bound 検査とは別の関心事。v1 では bound 側は S5 のみ、typo 報告は将来の opt-in ルール | |
| S10 | `-except`(補集合 bound)は予約のみ、v1 に入れない | クラス/メソッド override で動機ケースは足りる |

### 2.4 Steins 実装からの移植上の教訓

Steins のチェック側 (`effect.envelope-exceeded`) の構造で PHPStan にそのまま効くもの:

1. **3値 `InteropTag { Absent, Unbounded, Bound(family, labels) }`** を早期に潰す:
   パーサはラベルを生文字列で保持し(妥当性検証はしない)、レジストリ照合で未知ラベルを含むタグを
   `Unbounded` に落とすのは消費側の一箇所。
2. **subsumption 判定は1関数**:
   `subsumes(env, eff) = eff == env || (eff が env + "." で始まる)`。
3. **`mutate.local` の許容は envelope 側でなく判定関数側**(`tolerated_by_every_envelope`)に置く。
4. **エラーメッセージは著者が書いた綴りで引用し返す**:
   `declared @phpstan-impure io.net — io.fs.read exceeds the envelope`。
5. **診断は proven(確実に起きる effect)のみから生成**。宣言由来の情報(呼び先のタグ)は
   bound 検査の入力にしない — 「他人の body についての契約」はこの body の違反ではない。

## 3. PHPStan 側の現状機構(2.2.x @ 369bfdfaa 調査結果)

### 3.1 タグ解析の継ぎ目

- `PhpDocNodeResolver::resolveIsPure()/resolveIsImpure()` ([PhpDocNodeResolver.php:670-700](https://github.com/phpstan/phpstan-src/blob/2.2.x/src/PhpDoc/PhpDocNodeResolver.php)) は
  `$phpDocTagNode->name` しか見ておらず、**タグ値は今日完全に捨てられている**。ここがラベルリストを読む継ぎ目。
- phpdoc-parser は **2.3.3 に完全固定ピン**。`@phpstan-impure io.db, nondet.time (…)` は
  `GenericTagValueNode` にタグ行の残余がそのまま入る — **phpdoc-parser 側の変更は不要**で、
  文法の所有権を phpstan-src 側に保てる(専用 value node 化は将来の任意工程)。
- `@phpstan-ignore` の識別子文法は `IgnoreLexer`(`#[AutowiredService]`、dot-path は既に合法:
  `Error::PATTERN_IDENTIFIER`)+ `RichParser::parseIdentifiers()`(private)に実装済み。
  ただし **`PATTERN_IDENTIFIER` は大文字を許す**のに対し envelope ラベル文法は小文字+数字のみで、
  strict-list-or-bare 判定(全体が適合しなければ bare 扱い)も必要なため、
  再利用より **~40行の専用パーサ**のほうが単純(§4.1)。

### 3.2 Reflection 配管

- `ResolvedPhpDocBlock`(`@api` final)が `isPure: ?bool` を `'notLoaded'` センチネル付きで保持。
  `merge()`(PHPDoc 継承)では isPure は own-wins、`areAllMethodsPure/Impure` は**継承されない**。
- `?bool $isPure` が factory 経由で `PhpFunctionReflection` / `PhpMethodReflection` へ流れ、
  境界で `TrinaryLogic` 化。`PhpMethodReflection::hasSideEffects()` には
  void 短絡(void 非コンストラクタ → Yes)と fluent-setter ヒューリスティックあり。
- **all-methods タグの実装は2箇所に重複**: [NodeScopeResolver.php:5425-5440](https://github.com/phpstan/phpstan-src/blob/2.2.x/src/Analyser/NodeScopeResolver.php)(解析中コンテキスト)と
  [PhpClassReflectionExtension.php:957-972](https://github.com/phpstan/phpstan-src/blob/2.2.x/src/Reflection/Php/PhpClassReflectionExtension.php)(reflection 経路)+ native メソッド用の簡易版(:760-767)。
  precedence(method-wins、constructor 包含、void 除外、継承なし)は仕様 S7 と一致(当然 — 仕様が
  この実装を写した)。
- 導入コミット `ba8fae802` は functionMetadata から **162 の per-method エントリを削除して
  stub 上のクラスレベルタグに置換**した。「per-function カタログよりも宣言的アノテーション」が
  このコードベースの進行方向。

### 3.3 ImpurePoint

- identifier は 20 種の PHPDoc union alias。**判定ロジックで identifier を読む場所は
  エラー ID 接尾辞(`FunctionPurityCheck.php:213`)のみ** — 表示専用(20260703 メモの「死んだ色」の確認)。
  よって直交する effect ラベルの追加は既存消費者を壊さない。
- 構文由来の 14 identifier は effect が identifier 自体に載っている(echo→output 等)。
  `functionCall`/`methodCall`/`new` の3種が実世界の impure point の大半を占めるが
  **effect 情報ゼロ**(callee の `isPure()` の boolean だけ)。
- `SimpleImpurePoint::createFromVariant()` が callee の `hasSideEffects()`/`isPure()` を
  impure point に変換する唯一の場所(ラベル伝播 stage (b) のフック)。
- `ClosureType::isPure()` が point リストを TrinaryLogic に畳む — ラベル設計での
  「envelope join」の自然な置き場所。

### 3.4 チェックと Liskov

- `FunctionPurityCheck::check()` の分岐: `$isPure->yes()` → impure point 報告 /
  **`$isPure->no()`** → 過剰宣言検出(`impure*.pure`「impure と印されているが副作用なし」)のみ。
  この `no()` 分岐が envelope bound 検査の挿入点。
- purity の override 検査は `MethodSignatureRule`(`src/Rules/Methods/`)内、
  `reportMethodPurityOverride` feature toggle(bleedingEdge で true)配下:
  `no()` vs `yes()` の2値比較。envelope Liskov(child ⊆ parent、pure=∅、bare impure=⊤)は
  この一般化としてきれいに収まり、既存 `method.impure` は `⊤ ⊄ ∅` の特殊例になる。

### 3.5 BC 面の整理

| 対象 | 判定 |
|---|---|
| `ImpurePoint`(`@api` final) | v1 では**触らない**(ラベル化は check 内の写像関数で行う)。identifier union の拡張は import している拡張を壊しうるので不可 |
| `FunctionReflection` / `ExtendedMethodReflection` / `CallableParametersAcceptor`(いずれも `@api` + do-not-implement) | メソッド追加可。ただし内部実装 ~15 クラスへの横展開が必要(stage (b) まで遅延可) |
| `MethodReflection` / `ParametersAcceptor`(`@api`、do-not-implement なし) | **メソッド追加不可** |
| `ResolvedPhpDocBlock`(`@api` final)/ `PhpDocNodeResolver`(非 api)/ `FunctionPurityCheck`(非 api) | 追加自由 |
| Turbo mirror | 関係クラスに `#[ShadowedByTurboExtension]` なし(着地前に再 grep) |

## 4. 設計: PHPStan での実装案

### 4.1 新規部品(すべて非 `@api` で開始)

```
src/Analyser/EffectLabel/EffectLabelVocabulary.php   … v1 固定語彙 21 ラベル + isKnown()
src/Analyser/EffectLabel/EffectEnvelope.php          … 値オブジェクト
src/Analyser/EffectLabel/EffectLabelListParser.php   … GenericTagValueNode 文字列 → ?list<string>
```

- `EffectLabelListParser::parse(string $value): ?list<string>`:
  正規表現ベース(`[a-z][a-z0-9]*(\.[a-z][a-z0-9]*)*` のカンマ区切り + 任意の末尾 `(コメント)` 1個)。
  **全体が適合しなければ `null`(= bare 扱い)**。部分リストは返さない(Steins の strict-list-or-bare)。
- `EffectEnvelope`:
  - 3値を表現: `Absent`(タグなし)は「envelope が null」で、`Unbounded`(⊤)と
    `Bound(labels)` はオブジェクトで表現。**未知ラベルを1つでも含めば `Unbounded`**(S5)。
    `Unbounded` は `Absent` と区別可能でなければならない(S6: precedence に勝つ)。
  - `allows(string $effectLabel): bool` = `mutate.local` は常に true(S3)、
    それ以外は `∃ declared label L: subsumes(L, effect)`。
  - `subsumes(env, eff) = eff === env || str_starts_with(eff, env . '.')`(S1、4行)。
  - pure 用に `EffectEnvelope::pure()`(空 bound)ファクトリ。
- 空リストの二義性(S8)は **パーサ層では起きない**設計にする: bare `@phpstan-impure` は
  `parse()` が呼ばれる前にタグ値が空文字で分かるので `null`(ラベル情報なし)とし、
  `Bound([])` は `@phpstan-pure` 由来でのみ作る。

### 4.2 タグ → Reflection への配管

1. `PhpDocNodeResolver::resolveIsImpure()` を拡張し、`ResolvedPhpDocBlock` に
   `getImpureEffectLabels(): ?list<string>`(bare/散文/未知混在の生リスト; 解釈は消費側)と
   `getAllMethodsImpureEffectLabels(): ?list<string>` を追加(`'notLoaded'` センチネル方式を踏襲)。
   `merge()` の方針: isPure と同じ own-wins(ラベルは isPure に随伴する属性なので独立に継承させない)。
2. 宣言側チェックの v1 は **reflection インターフェースを触らずに済む経路**を使う:
   purity ルールに渡ってくる in-analysis reflection
   (`PhpFunctionFromParserNodeReflection` 系、内部クラス)へ labels を載せ、
   `FunctionPurityCheck` で読む。`FunctionReflection::getEffectEnvelope()` の公開
   (@api-do-not-implement への追加 + 内部 ~15 実装の横展開)は stage (b) で行う。
3. all-methods 側: NodeScopeResolver:5425 / PhpClassReflectionExtension:957 の重複2箇所に
   ラベルのフォールバックを追加。**メソッド自身のタグが bare/散文/未知ラベルで inert(⊤)でも
   クラスタグへフォールバックしない**(S6)— 現行コードは `$isPure === null` でフォールバック判定
   しているので、「メソッドに impure タグがあるが労働不能」の場合も `$isPure = false` は立ち、
   labels だけ ⊤ になる。この整合を明示的にテストで固定する。

### 4.3 bound 検査(宣言側 — v1 の本体)

`FunctionPurityCheck::check()` の `$isPure->no()` 分岐に追加:

```
envelope = 宣言された EffectEnvelope (Bound のときのみ)
foreach (impurePoints as p):
    labels = mapImpurePointToEffectLabels(p)   // §4.4
    if labels === null: continue               // 効果不明の点は検査しない(proven-only)
    foreach labels as eff:
        if (!envelope->allows(eff)):
            error "…has effect <eff>, but is declared @phpstan-impure <labels> — <eff> exceeds the envelope"
            identifier: impureFunction.effectOutsideEnvelope / impureMethod.effectOutsideEnvelope (仮)
```

`@phpstan-pure` 側(`$isPure->yes()` 分岐)は v1 では**現状維持**。
S3(mutate.local 許容)を pure に適用する = `preg_match`/`sort` の by-ref 誤警告解消は
大きな行動変更なので、**別スライス**(bleedingEdge + feature toggle)に切り出す(§6 stage 4)。

エラーメッセージは Steins 同様、著者の綴りで引用し返す(§2.4-4)。

### 4.4 ImpurePoint → effect ラベル写像(v1)

check 内の純関数 `mapImpurePointToEffectLabels(ImpurePoint): ?list<string>` として実装
(ImpurePoint 本体は不変・BC 無風):

| identifier | ラベル | 備考 |
|---|---|---|
| `echo` `print` `betweenPhpTags` | `output` | |
| `die` `exit` | `exit` | |
| `global` `static` `superglobal` `staticPropertyAccess` | `global` | read/write の区別は identifier に無いので親ラベル。宣言側が `global.read` だけ書いた場合は超過扱い(保守的に正しい) |
| `propertyAssign` `propertyAssignByRef` `propertyUnset` | `mutate` | |
| `include` `require` `eval` | `null`(⊤) | 任意コード実行 = 効果不明。検査対象外(finding を発明しない) |
| `yield` `yieldFrom` | `null`(⊤) | 語彙に対応なし。v1 は検査対象外 |
| `functionCall` `methodCall` `new` | `null`(⊤) | v1 は検査対象外。stage (b) で callee の envelope から導出 |

**⊤(null)の点は「bound を超過」ではなく「検査しない」**。Steins の
「診断は proven のみ、非網羅は finding を生まない」不変条件の PHPStan 版。これにより
カタログ未整備でも偽陽性ゼロで導入できる(検出力は段階的に上がる)。

### 4.5 呼び出し伝播(stage (b)、v1 スコープ外)

- `SimpleImpurePoint::createFromVariant()`(非 api)で callee の envelope labels を
  point に随伴させ、caller の bound 検査に使う。
- **Steins からの意図的乖離**: Steins は「declared lane は診断を生まない」が、PHPStan には
  taint/exhaustiveness 機構がなく、既存の purity 検査が callee の宣言(`@impure`)を
  caller 内の certain impure point として扱う前例がある。PHPStan 版では
  callee の宣言 envelope を call の effect として caller の bound 検査に用いるのが
  自己一貫的。この乖離はレポート/PR 本文に明記する。
- builtin へのラベル付与は functionMetadata の第3バリアント(`effects` キー)より
  **stub への `@phpstan-impure <labels>` 記載**が本家の進行方向に沿う(`ba8fae802` の教訓)。

### 4.6 Liskov(stage 3)

`MethodSignatureRule` の purity override 分岐を envelope 包含に一般化。
同じ `reportMethodPurityOverride` toggle 配下に置けば non-bleeding-edge では何も変わらない。

- 判定は両側に**使える宣言があるときのみ**: 親子とも `Maybe`(タグなし)は従来どおりスキップ。
- child Bound vs parent Bound → 全 child ラベルがいずれかの parent ラベルに subsume されるか。
- child が**裸タグ由来の ⊤** vs parent Bound → widening として報告(著者は「不純・種類未指定」
  という実在の主張を書いた。impure-overriding-pure の既存前例と同型)。
- child が**未知ラベル由来の ⊤** → 検査ごとスキップ(typo かもしれない主張を widening と
  断じるのは S5 の「finding を発明しない」に反する)。裸 ⊤ と未知 ⊤ の区別が必要
  (raw labels は取得済みなので語彙照合を check 側で行えば区別できる)。
- 既存 `method.impure`(impure が pure を override)が発火するケースでは envelope 検査は
  行わない(二重報告回避)。
- **D-L1(Steins からの意図的乖離)**: Steins は「interop envelope は Liskov に参加しない」
  (owner ruling)だが、PHPStan には docblock しかなく、`reportMethodPurityOverride` が
  既に docblock purity の Liskov 検査を行っている。PHPStan-native な一般化としてタグ間の
  envelope 包含検査を行う。

### 4.7 `@phpstan-pure` の mutate.local 許容(stage 4)— preg_match 問題の解

当初の見立て(呼び出しサイトごとの by-ref 引数 lvalue 分類が必要)より**大幅に簡単**に
できることが判明した。鍵は 20260703 メモの検証事実:
`preg_match($p, $s, $this->matches)` のようなプロパティ/静的/スーパーグローバルへの
by-ref 汚染は、**代入機構が独立に** `impure.propertyAssign` 等の certain な impure point を
立てて捕捉済み。つまり関数呼び出し側の impure point は「フレーム内ローカルへの書き込み」
だけを代表すればよく、lvalue の分類は不要。

実装は2手:
1. `preg_match` / `sort` 系(by-ref out-param への書き込みが唯一の副作用である builtin)に
   宣言ラベル `mutate.local` を与える(stubs または functionMetadata の第3バリアント)。
   stage 2 の call 伝播により、これらの呼び出しの impure point は `[mutate.local]` を運ぶ。
2. `FunctionPurityCheck` の `$isPure->yes()` 分岐(bleedingEdge toggle 配下)で、
   **effect ラベルがすべて tolerated(mutate.local)である impure point をスキップ**。

これで `@phpstan-pure` 関数内の `preg_match('/…/', $s, $matches)` の誤警告
(possiblyImpure.functionCall)が消え、`preg_match($p, $s, $this->matches)` は
引き続き `impure.propertyAssign` で正しく報告される。issue #11884
(by-ref out 引数の条件付き純度、シグネチャ非依存の一般解が欲しい)への直接回答になる。

stage 2 実装後の精密化:
- **ラベル添付ゲートの緩和**: stage 2 の `createFromVariant()` は「宣言 impure
  (`isPure()->no()`)の callee のみラベルを信頼」するが、`preg_match` は
  `hasSideEffects=Maybe` のまま。ラベルを持つ callee は Maybe でも「言うことがある」ので、
  ゲートを「`getImpureEffectLabels() !== null` なら添付」に緩和する(ユーザーランドでは
  labels 非 null ⇒ isPure=false なので挙動不変、builtin メタデータ由来のみ効く)。
- **builtin のラベル源**: `functionMetadata.php` の第3バリアント
  `['impureEffectLabels' => list<string>]`(hasSideEffects と併記可)。
  `NativeFunctionReflectionProvider` → `NativeFunctionReflection` に配管。
  初期セットは最小: `preg_match` / `preg_match_all` / sort 族
  (sort/rsort/usort/uasort/uksort/ksort/krsort/asort/arsort)= `mutate.local`、
  `shuffle` = `mutate.local, nondet.random`(多ラベルのテストケースを兼ねる)。
  usort の impure コールバックは既存の即時起動伝播が独立に捕捉するので嘘にならない。
- **pure 分岐の許容は feature toggle**(bleedingEdge で true)配下:
  「effect ラベルがすべて tolerated(mutate.local)の impure point をスキップ」。
  labels が null の点は従来どおり報告(不明は許容しない)。
- 副次効果: ユーザーが自作の out-param ヘルパに `@phpstan-impure mutate.local` を
  書けば pure 文脈から呼べるようになる(アノテーションだけで解決する経路が開く)。
`@pure-unless-parameter-passed`(phpdoc-parser #259、パース済み・未消費)との関係:
ラベル方式は「第3引数を渡したときだけ impure」の条件性は表現しないが、
「渡しても呼び出し元フレームを汚さない」というより強い主張で同じ誤警告群を解消する。
両者は排他ではない(条件付き純度はシグネチャの精密化、ラベルは効果の分類)。

### 4.8 診断ルール(stage 6)

S9 で「bound とは別の関心事」として予約していた語彙診断と、pure 分岐の effect 語彙化。
どちらも opt-in(新 feature toggle、bleedingEdge で有効)。

**Rule A: `InvalidEffectLabelsRule`**(toggle `reportInvalidEffectLabels`)

envelope 意味論には一切影響しない純粋な診断。核心は散文と typo の区別 —
`@phpstan-impure database`(人間のメモ、S5 が保護)は沈黙し、`io.netw`(typo)は報告する。
未知ラベルは以下の**いずれかの「ラベルを書こうとした」シグナル**があるときのみ報告:
1. 既知ラベルとの Levenshtein 距離 ≤ 2(Steins の `nearest_label` と同じ閾値)→ did-you-mean 付き
2. 同一リストに既知ラベルが混在(`io.db, io.netw`)
3. 既知ラベルの dot-拡張(`io.net.http.get` → 「語彙より細かい。covering label は io.net.http」)

加えて: 冗長ラベル(`io, io.db` の io.db — 同一リスト内の別ラベルに subsume される)、
pure 系タグへのラベル(S4 違反。**全ラベルが既知のときのみ**報告 —
`@phpstan-pure because reasons` の散文ガード)。
identifier: `effectLabel.unknown` / `effectLabel.redundant` / `effectLabel.onPureTag`。

**Rule B: pure 分岐の effect 語彙化**(toggle `pureFunctionEffectMessages`)

`@phpstan-pure` = 空 envelope なので、ラベルを持つ impure point は Steins 形式
(`Function f() has effect io.db (call to function …), but is declared @phpstan-pure.`)で
語るのが一貫。二重報告を避けるため既存メッセージの**置換**とし、identifier は
既存の `impure.*` / `possiblyImpure.*` を維持(presentational な変更に留める)。
stage 4 の許容スキップが先、メッセージ選択が後。toggle off では一切不変(baseline 保護)。

## 5. 懸念点

- **C1: 検出力の初期値が低い。** v1(構文由来 effect のみ)で bound 検査が火を吹くのは
  echo/exit/global/mutate 系のみ。実用価値の中心は stage (b)(call 伝播)以降。
  ただし v1 の時点で「タグを書いても既存動作が一切変わらない」「偽陽性ゼロ」を保証できるのが
  導入戦略として重要(仕様の BC 節と同じ論理)。
- **C2: `@api` 横展開の重さ。** `getEffectEnvelope()` を CallableParametersAcceptor 系に
  公開する段になると内部実装 ~15 クラスへの機械的追加が必要(PR #3482 のコンフリクト主因と
  同じ構造)。v1 で回避し、stage (b) の PR を機械的変更として分離する。
- **C3: 語彙の所有権**(仕様 open question 1)。v1 は 20 ラベル固定を提案。開集合にする場合も
  S5(未知→⊤)により安全だが、typo 診断(将来の opt-in ルール)は固定語彙前提のほうが単純。
  vendor 名前空間ルート(Steins ADR-0068)は PHPStan 側では将来の拡張ポイント
  (extension が語彙を登録する DI タグ)として予約だけしておく。
- **C4: result cache / exported nodes。** docblock 変更はファイルハッシュで自然に無効化される
  はず(タグ解釈はファイル内容由来)だが、`ExportedNode` の比較に docblock 全文が含まれるか
  試作時に確認(含まれなければ依存ファイルの再解析が漏れる)。
- **C5: all-methods ロジックの3重複。** NodeScopeResolver / PhpClassReflectionExtension /
  native 簡易版の3箇所に同じ precedence を足すことになる。試作段階で共通ヘルパへの抽出を検討
  (ただし upstream が重複を選んだ経緯があるかは PR で要確認)。
- **C6: 「inert タグでも precedence に勝つ」の現行コードとの整合。** 現行の class-fallback 条件は
  `$isPure === null`。bare `@phpstan-impure` はこれを `false` にするので isPure 面は既に S6 と
  整合しているが、**labels 面のフォールバック条件を isPure と別に管理しない**よう注意
  (labels は常に isPure に随伴、独立フォールバック禁止)。
- **C7: `impure*.pure`(過剰宣言)との相互作用。** `@phpstan-impure io.db` + 副作用ゼロの body は
  引き続き `impure*.pure` を出すべき(labels は bound の狭窄であって宣言の弱化ではない)。
  ラベル付きタグで既存検査が退行しないことをテストで固定。
- **C8: void 短絡との相互作用。** `PhpMethodReflection::hasSideEffects()` の void→Yes 短絡と
  fluent-setter ヒューリスティックは isPure ではなく hasSideEffects 側なので bound 検査には
  影響しないはずだが、`@phpstan-impure output` を書いた void メソッド(仕様 open question 3)の
  挙動をテストで明示。
- **C9: upstream 提案の段取り。** 実装 PR をいきなり投げるより、仕様文書
  (phpdoc-effects-interop.md は upstream 提出用に書かれている)を issue 化して
  ondrejmirtes の合意を先に取るのが筋。本試作は「議論のための動く証拠」の位置づけ。
  (投稿は必ずユーザー承認後 — 下書きのみ用意する。)

## 6. 段階的実装プラン

| stage | 内容 | BC 面 | 状態 |
|---|---|---|---|
| 1 (v1 試作) | パーサ + EffectEnvelope + ResolvedPhpDocBlock 配管 + FunctionPurityCheck の bound 検査(構文由来 effect のみ)+ all-methods labels + テスト | 非 api のみ | **コミット済 `24013d987`** |
| 2 | 宣言 envelope の call 伝播(impure point にラベルを随伴、§4.5)、reflection accessor 公開 + 実装横展開 | @api メソッド追加(do-not-implement のみ)+ ImpurePoint ctor 末尾 defaulted 引数(ctor 非 api) | **コミット済 `332d7aa70`** |
| 3 | Liskov: MethodSignatureRule の envelope 包含一般化(§4.6) | toggle 配下 | **コミット済 `01fb739ca`** |
| 4 | `@phpstan-pure` の mutate.local 許容(§4.7、preg_match/sort 問題の解消) | 挙動変更 → 新 toggle `pureEnvelopeToleratesLocalMutation`(bleedingEdge) | **コミット済 `c6314e1a8`** |
| 6 | 診断ルール: `InvalidEffectLabelsRule`(unknown/redundant/onPureTag)+ pure 分岐の effect 語彙化(§4.8) | 2 新 toggle(bleedingEdge) | **コミット済 `457d5a493`** |
| 7 | 拡張 API: 語彙合成(effectLabels param + EffectLabelsProvider)、effectMetadata、Dynamic*EffectExtension、SendGrid/fopen fixture | 新 param 2 + `@api` interface 4 | **コミット済 `fe77a923a`** |
| 8 | **D-U1**: ラベル付き uncertain point の envelope 検査(possibly 級)。stage 5 の「uncertain は検査しない」契約の改訂 | 検査対象の拡大(ただしラベル保持点のみ) | **コミット済 `dedef5872`** |
| 9 | **敵対的レビュー対応**: builtin カタログの soundness 修正 + 同梱狭化 extension | カタログ値変更 + 新 extension 1 | **コミット済 `c3d89f0fd`** |
| 10 | **D-V2 語彙移行**: `output` → `io.output.{buffer,stdout,stderr,header}` + `io.input`、`fwrite(STDOUT/STDERR)` ConstFetch 狭化 | 語彙のクリーン変更(エイリアスなし) | **コミット済 `d3aa74c8a`** |
| 11 | `-except`、Memcached.stub、effect-parametric 宣言(opaque callable)、遅延起動 extension 同梱判断、P2 責務分割リファクタ、Steins 側語彙同期([提案書](20260812-steins-vocab-sync-proposal.md)) | — | 予約のみ |

### 6.8 stage 10 実装記録(2026-08-12)

**stage 10 (`d3aa74c8a`)** — D-V2 語彙移行(25 ラベル):
- 逆転の列挙(D-V2 の意味論コスト、全て意図的にテストで固定):
  (1) `@phpstan-impure io` + echo は**沈黙に反転**(`io.output.buffer ⊑ io`)—
  同原理の検査は `io.db` + echo の非包含ペアで置換。
  (2) **第2の逆転を発掘**: `php://input` の旧マッピング `global.read` → `io.input` により
  `global.read` envelope 下の `php://input` 読みが finding に反転(`global.read` の意味の
  純化 — パース済みメモリ読み専用に)。
  (3) fwrite のカタログ collapse(`io, output` → `io`)で期待エラー1件が消滅
  (ノイズ減として現れた collapse)。
- 旧綴り `@phpstan-impure output` は **silent に bare-tag 意味へ退化**
  (`levenshtein('output','io.output') = 3` > 提案閾値 2 — 1 edit 近ければ全 legacy
  docblock に did-you-mean が出ていた偶然も記録)。混在リスト(`io.db, output`)では
  mixed-list シグナルで unknown 報告が出ることをテストで対照。
- `fwrite(STDOUT/STDERR)` の ConstFetch 狭化を extension に追加(provenance 不要の構文判定)。
  `io.output.buffer` envelope 下の `fwrite(STDOUT)` が `io.output.stdout` で超過 —
  マスキング境界が階層で検査可能になった実演をデモ §10 に追加。
- system/passthru/curl_exec の出力成分は親 `io.output`(捕獲可能性の資料が割れるため
  unmaskable 側へ over-approximate)、readfile は文書化された ob パターンがあるため
  `.buffer`。
- full `make tests` **21,416 グリーン**、red チェック(語彙リストのみ復元で 23 失敗)、
  `src/`〜`stubs/` 全域 grep で旧ラベルのコンテキスト残存ゼロ。
- デモ移行 + §10 追加 + 3系統再キャプチャ(released 2.2.8 は 4 findings のまま = BC 維持)。
- Steins 側同期は[提案書](20260812-steins-vocab-sync-proposal.md)を作成済み(適用は未実施)。

### 敵対的レビュー対応記録(2026-08-12、対象 `ca2577677`)

[レビュー](20260812-effect-envelope-adversarial-review-ca2577677.md)の判定は FAIL。応答:

- **P1 ×3(受理・全再現済み)**: stage 5 カタログが wrapper 対応 API
  (fopen/file_get_contents/file_put_contents/file/readfile/copy/rename/fread/fwrite 等)を
  `io.fs.*` と断定し、readfile/system/passthru/curl_exec の直接出力を欠落させていた。
  envelope は上界なので under-approximation は false negative を製造する — 5ケースの
  再現フィクスチャで finding ゼロを本セッションでも確認。**指摘どおりの soundness 欠陥**。
  修正 = stage 9: 静的カタログを sound な上界(`io` / `io, output` / `io.process, output` /
  `io.net, output`)へ引き上げ、literal ローカルパスの呼び出しサイトだけ同梱
  `DynamicFunctionEffectExtension` で `io.fs.*` へ狭化(stage 7 API の初の本体ドッグフード)。
  chmod/chown/symlink は PHP ドキュメント上リモート不可のため据え置き、
  `copy` の局所狭化は read+write ゆえ `io.fs`(write 単独は read を隠す)。
- **P2 ×2(受理・後送)**: FunctionPurityCheck / InvalidEffectLabelsRule の責務集中。
  機能欠陥なしのためレビューの推奨どおり独立リファクタとして stage 10 に積む。
- **不採用1件(レビュー側の判断に同意)**: 冗長ラベル二重ループの DoS 主張。
  performance hardening は将来課題として記録のみ。
- 教訓(レビュー §実行検証の指摘を採用): **テストが緑であることはカタログの effect 主張が
  正しいことの証拠にならない**。カタログ追加時は「上界として sound か」の監査を
  必須手順にする(stage 9 で全エントリ再監査)。

**D-U1 の記録**(2026-08-12): stage 7 で「effectMetadata / extension で付与したラベルは
non-void・未注釈メソッド(isPure=Maybe → uncertain point)では読まれない」制約が発覚。
役割 A(SendGrid シナリオの核)を殺すため、**ラベルを持つ uncertain point は検査対象にする**と
決定。文言は `may have effect …, so … may exceed the envelope`、identifier は
`possiblyImpure{Function,Method}.effectOutsideEnvelope`(既存の certain 用
`impure*.effectOutsideEnvelope` と対)。proven-only 規律は「**ラベルなし** uncertain の
スキップ」として存続 — 規律の目的は ⊤ から finding を製造しないことであり、
宣言/付与されたラベルは ⊤ ではない。`mutate.local` 系(preg_match/sort)は
allows() が常に許容するため新ノイズなし。

### 6.0 ブランチの状態

`worktree-effect-envelope`(worktree `.claude/worktrees/effect-envelope`)、未 push。
2026-08-12 に upstream `phpstan/2.2.x`(`f69c88168`)へ**競合なくリベース済み**
(旧 base `81e06a583` から 15 コミット分前進。stage 2 が触れた `ClosureTypeResolver` は
上流で大規模リストラクチャされたがラベル転送は保持されている)。
リベース後の検証: full `make tests` **21,404 件グリーン** / `make phpstan` エラーなし /
デモ 3 系統キャプチャ再取得済み(出力不変)。

各コミットには実作業モデルを明記する規約:
`Co-authored-by: Claude Opus 5 <noreply@anthropic.com>`(stage 1–8 は全て Opus 5 実装)。
リベース後の SHA: `fbeb3e924`(1) `b1f0fa2e0`(2) `e4ef2c55b`(3) `837ee9a2a`(4)
`3a3d5676a`(5) `a6d46a576`(6) `1730790be`(7) `ca2577677`(8)。
リベース前のバックアップブランチ: `backup/effect-envelope-prerebase`(`dedef5872`)。

### 6.1 stage 1 試作の状況(2026-08-12)

worktree `.claude/worktrees/effect-envelope`(branch `worktree-effect-envelope`)で実装済み・未コミット。
Opus サブエージェント実装 + 本セッションでの diff 監査済み。

- 新規: `src/Analyser/EffectLabel/{EffectLabelVocabulary,EffectLabelListParser,EffectEnvelope}.php`
  (+ 単体テスト 54 件)、rule テストフィクスチャ2本。
- 配管: `PhpDocNodeResolver`(sibling リゾルバ追加、`resolveIsImpure()` は不変)→
  `ResolvedPhpDocBlock`(`'notLoaded'` センチネル、merge は isPure 随伴の own-wins)→
  `NodeScopeResolver::getPhpDocs()` 22 番目のタプル要素 → `enterFunction/enterClassMethod`
  末尾 optional 引数 → `Php{Function,Method}FromParserNodeReflection` → `FunctionPurityCheck`。
- 検査: `$isPure->no()` 分岐内、certain な impure point のみ、identifier→ラベル写像は
  check 内の const map(`ImpurePoint` 本体は不変)。
- 検証: 対象 phpunit(Pure/EffectLabel/PhpDoc/Reflection/Analyser 統合)グリーン、
  `make lint` / `make cs` / `make phpstan` グリーン。既存の失敗は
  `ReflectionProviderGoldenTest`(PHP 8.5 golden 欠落、クリーンツリーでも同一)のみ。

実装で判明した本レポートの訂正:
- 語彙は 21 ラベル(§2.2 の旧記述「20」は誤り)。
- `ResolvedPhpDocBlock::withNakedNameScope()` は存在しない — raw フィールドを写すのは
  `changeParameterNamesByMapping()`。
- `Php{Function,Method}FromParserNodeReflection` は `@api`(「internal」は誤り)。
  ただしコンストラクタは `@api` ではないので末尾 defaulted 引数の追加は BC 上許容。
- `@phpstan-impure (why)` は `DoctrineTagValueNode` になる(parseError ではなく)。
  リゾルバは GenericTagValueNode 以外を labels なしとして安全側に読む。

監査での指摘(いずれも対応済み):
- **クラスタグ由来 envelope のメッセージ誤引用**: `@phpstan-all-methods-impure io.net` 由来なのに
  `declared @phpstan-impure io.net` と報告していた。著者の綴りで引用する原則(§2.4-4)違反 →
  labels に provenance(タグ名)を随伴させる `DeclaredEffectLabels` 値オブジェクト
  (`fromImpureTag()` / `fromAllMethodsImpureTag()`)で修正済み。provenance の決定は
  method-own vs class-fallback の分岐点(`NodeScopeResolver::getPhpDocs()`)で行う。
- `@impure` エイリアスも `@phpstan-impure` と表示される件は Steins 自体が canonical 綴りへ
  正規化する仕様(`@psalm-pure` → `@phpstan-pure` 表示)なので現状維持が正しい。
- reflection 経路(`PhpMethodReflection`)への labels 配管は stage 1 では消費者ゼロのため
  意図的に省略(コード内コメントで明示)。stage 2 で interface 公開と同時に配管。

エンドツーエンド smoke(`bin/phpstan analyse -l 9`)確認済み:
```
Function refreshCache() has effect output, but is declared @phpstan-impure io.db, nondet.time, so output exceeds the envelope. [impureFunction.effectOutsideEnvelope]
Method RedisClient::ping() has effect output, but is declared @phpstan-all-methods-impure io.net, so output exceeds the envelope. [impureMethod.effectOutsideEnvelope]
```
(`@phpstan-impure output` + echo は沈黙、未知ラベル `database` は ⊤ で沈黙、括弧コメントは剥がされる。)

### 6.2 stage 2/3 実装記録(2026-08-12)

**stage 2 (`332d7aa70`)** — call 伝播:
- ラベルは `ImpurePoint` / `SimpleImpurePoint` の末尾 optional フィールドとして随伴
  (`ImpurePoint` は `@api` だが ctor は非 api → 末尾 defaulted 引数は BC 許容)。
  `createFromVariant()` が `isPure()->no()` の callee のラベルを添付、伝播6箇所は verbatim 転送。
- `FunctionReflection` / `ExtendedMethodReflection`(共に do-not-implement)に
  `getImpureEffectLabels(): ?list<string>` を追加、内部実装 17 クラスへ機械的横展開
  (`InaccessibleMethod` は対象外 — 事前調査リストの誤り)。
- 検証で確定した事実: **builtin クラス stub の phpdoc は流れる**
  (`stubs/ArrayObject.stub` に `@phpstan-all-methods-impure io.net` で伝播を確認)。
  **builtin 関数 stub は流れない**(`NativeFunctionReflection::isPure()` は
  functionMetadata 由来で stub の ResolvedPhpDocBlock を見ない)→ stage 4 の metadata 配管で解消。
- `Union/IntersectionTypeMethodReflection` は null(join 意味論未定義のため ⊤ が安全)。
  `new`(コンストラクタ)の point は `createFromVariant` を通らないためラベルなし(既知ギャップ)。

**stage 3 (`01fb739ca`)** — Liskov:
- `MethodSignatureRule` の第3分岐(両側 `isPure()->no()`)、`reportMethodPurityOverride`
  toggle 配下、identifier `method.envelopeWidened`。
- bare ⊤(実在の主張 → widening 報告)と inert ⊤(未知ラベル → 検査スキップ)を
  `fromLabels()` 前の null チェックで区別。`mutate.local` 許容は `allows()` の再利用で自動成立。
- red チェック実施済み(検査を無効化 → envelope 系 6 件だけが落ち、既存 `method.impure` は
  単独で発火 = 二重報告なしの陽性証明)。
- メッセージはタグ名を引用しない(reflection 経路に provenance がないため
  「effect envelope io.db」表記で誤引用を回避 — §4.6 の妥協を記録)。

### 6.3 stage 4 実装記録(2026-08-12)

**stage 4 (`c6314e1a8`)** — pure の mutate.local 許容:
- `createFromVariant()` のラベル添付ゲートを「labels 非 null なら添付」に緩和
  (ユーザーランドは labels ⇒ isPure=false なので挙動不変、metadata 由来の Maybe builtin にのみ効く)。
- functionMetadata 第3バリアント `impureEffectLabels` を追加(純追加 12 エントリ、既存置換なし):
  `preg_match` / `preg_match_all` / sort 族 9 種 = `mutate.local`、
  `shuffle` = `mutate.local, nondet.random`。`hasSideEffects` は全エントリで Maybe のまま
  (`expr.resultUnused` 等の他消費者に影響なし)。
- 新 toggle `pureEnvelopeToleratesLocalMutation`(config false / bleedingEdge true)配下で、
  `reportImpurePoints()` が「全ラベル既知かつ空 envelope が許容する point」をスキップ。
- **中心仮説の実証**: `preg_match($p,$s,$this->matches)` / `sort($this->x)` /
  `self::$static` / `$GLOBALS` への by-ref 汚染はすべて代入機構の独立 point
  (labels なし)で引き続き報告される — lvalue 分類なしで健全。
- red チェック実施(スキップ無効化で新期待 12 件が正しく失敗、対照 3 件は両方で存在)。
  実 DI 検証: default config では従来どおり報告、`-c conf/bleedingEdge.neon` で沈黙。
- 既知の残作業: `bin/generate-function-metadata.php` は phpstorm-stubs の匿名クラスで
  既存クラッシュ(本変更と無関係)。生成物はエミッタと同一形式で手編集し、
  パッチ版生成器との差分が既存ドリフト 6 行のみであることを確認済み。

### 6.4 stage 5 実装記録 + デモ(2026-08-12)

**stage 5 (`31d2ade0c`)** — 残ギャップ + builtin カタログ:
- `new` 式の impure point にコンストラクタの宣言ラベルを随伴(`NewHandler` の解決済み2箇所。
  `FunctionPurityCheck` は無変更で自然に流れることをテストで確認)。
- builtin カタログ **55 追加(計 67 ラベル付きエントリ)**。既存の `hasSideEffects` は一切不変、
  metadata-pure な関数(`is_file` 等4件)はラベル無意味のためスキップ。
  重要な訂正: `time`/`rand` 族は metadata-pure ではなく `hasSideEffects=true` だったため
  **nondet.time / nondet.random は v1 で到達可能** — 仕様の旗艦例
  `@phpstan-impure io.db, nondet.time` + `time()` がエンドツーエンドで動く。
- `stubs/Redis.stub`: 3 クラスの `@phpstan-all-methods-impure` に ` io.net` を追加
  (1語で全メソッドに envelope。`ba8fae802` の stub 方向性に沿う)。
  `Memcached.stub` は同型のフォローアップ候補として未着手。
- クラス側のラベル付与は metadata ではなく **stub タグで行う**方針を確定
  (`NativeMethodReflection` への metadata 配管は不要と判断)。
- full `make tests` **21,358 件グリーン**、既存期待値の変更ゼロ。red チェック実施
  (NewHandler の添付を外して該当 4 期待値のみ失敗 → 復元)。
- 既知の残ギャップ: Maybe な callee のラベルは envelope 検査で使われない
  (uncertain point はスキップ — proven-only の帰結。header/curl_exec 等は
  pure 許容と将来の判断待ち)。

**デモ**(`phpstan-notes/effect-envelope-demo/`、§7 として独立):
単一ファイル [demo.php](../effect-envelope-demo/src/demo.php) を3系統で解析しキャプチャ:
1. **リリース版 PHPStan 2.2.8**: パースエラー・タグ警告ゼロ(BC 実証)。
   出るのは従来の preg_match/sort 偽陽性 + 正しい propertyAssign のみ。
2. **試作・デフォルト**: + envelope 検査 3 件(宣言サイト / クラスタグ+builtin call 伝播 /
   nondet.time 旗艦例)。未知ラベル `legacy-database-stuff` は finding を発明しない。
3. **試作 + bleedingEdge**: preg_match/sort 偽陽性が消え(#11884 解)、
   プロパティ escape は残り、`method.envelopeWidened`(Liskov)が加わる。

### 6.5 stage 6 実装記録(2026-08-12)

**stage 6 (`457d5a493`)** — 診断ルール:
- `InvalidEffectLabelsRule`(level 2 + toggle `reportInvalidEffectLabels`)。
  実装中に §4.8 のヒューリスティックを1点改善: typo 提案と「語彙より細かい」の判別で、
  当初の距離2閾値だと `io.db.write` が `io.fs.write`(距離2)に誤提案される実例を発見 →
  **距離 ≤1 の near-miss は covering label に勝ち、それ以外は covering label が距離2提案に勝つ**
  2 段階に修正(`_LIMIT` 定数化)。covering label の探索は `isKnown()` の祖先規則を
  dot-prefix walk で再利用(語彙の再実装なし)。完全重複は専用メッセージ。
- pure 分岐の effect 語彙化(toggle `pureFunctionEffectMessages`): certain →
  `has effect …`、uncertain → `may have effect …` の区別を維持。
  `@pure-unless-callable-is-impure` 分岐は `@phpstan-pure` と宣言されていないので
  従来文言のまま(偽の envelope 引用をしない)。identifier は既存の
  `impure.*`/`possiblyImpure.*` を維持。
- red チェック両項目で実施、full `make tests` 21,368 件グリーン、既存期待値の変更ゼロ
  (削除行は FunctionPurityCheck の構築2箇所のみ)。
- デモ更新: §8(typo 提案・冗長ラベル)追加、bleedingEdge キャプチャに
  effect 語彙化されたメッセージ(`has effect mutate, but is declared @phpstan-pure`)と
  診断2件が入った。released 2.2.8 キャプチャは §8 追加後も**警告ゼロのまま**(BC 維持)。

### 6.6 stage 7/8 実装記録(2026-08-12)

**stage 7 (`fe77a923a`)** — 拡張 API(詳細は
[20260812-effect-extension-api-design.md](20260812-effect-extension-api-design.md) §5.9):
語彙合成 / effectMetadata / Dynamic*EffectExtension 3種 + CallSiteEffectLabelResolver。
fopen 狭化 PoC・SendGrid シナリオ fixture・red チェック3系統。full suite 21,386。

**stage 8 (`dedef5872`)** — D-U1:
- ゲートを「certain のみ」→「ラベル解決済みのみ」に変更。certain は従来文言・identifier
  不変(red チェックで byte-for-byte 確認)、uncertain は `may have effect … may exceed` /
  `possiblyImpure*.effectOutsideEnvelope`。
- **既存 fixture への波及ゼロ**(full suite で labelled Maybe call が bound 外にある
  既存箇所は皆無だった)。full `make tests` 21,388 グリーン。
- 過去 stage のコメント誤りを1件訂正: `header()` は void 返却なので point は
  certain(沈黙の真の理由は `output.header ⊑ output` の包含)。
- 実用上の新検出: 未注釈・非 void の 3rd-party メソッドへの effectMetadata/extension
  付与が void ハックなしで効く(D-U1 の本題)+ curl_exec/mail 等の Maybe builtin
  ラベルが活きる。デモに §9(mail の possibly 級)を追加、3系統再キャプチャ済み
  (released 2.2.8 は引き続き警告ゼロ = BC 維持)。

### 6.7 stage 9 実装記録(2026-08-12、レビュー対応)

**stage 9 (`c3d89f0fd`)** — カタログ soundness + `StreamWrapperFunctionEffectExtension`:
- カタログ 22 エントリを sound な上界へ(wrapper 対応 FS 関数 → `io`、`readfile`/`fwrite`/
  `fputs` → `+output`、`system`/`passthru`/`curl_exec`/`curl_multi_exec` → `+output`、
  `fsockopen`/`mail` → `io`)。`hasSideEffects` は全箇所不変。
- 同梱狭化 extension(stage 7 API の初の本体消費者): 定数パスのスキームテーブル
  (local → `io.fs.*`、http(s) → `io.net.http`、`php://output` → `output`、
  `php://memory`/`data://` → `mutate.local`、`php://filter/...resource=` は再帰、
  `expect://` → `io.process`、fsockopen `unix://` → `io.ipc`、未知スキーム → null)。
  `copy` は per-argument union(read+write)で計画の `io.fs` より精密に。
- 追加裁定: **D-K1**(kind ラベル `io.db` は transport を包含 — Redis stub は `io.db` へ)、
  **D-P1**(`output` = PHP 層の emit。子プロセスの継承 fd 書き込みは `io.process` の意味内)、
  D-W1(登録 wrapper は `io` 内という近似)。エージェントが発掘した追加 suspects
  4 件はこの3裁定で決着(popen/proc_open は変更なし + 根拠記録)。
- 検証: レビューの再現ケース **7 件すべてが finding 化**(certain/possibly の grade も
  設計どおり)、狭化4系統は沈黙、未知スキームのみ finding — 本セッションでも独立に確認。
  red チェック2系統、full `make tests` 21,406 グリーン、既存期待値の変更は
  カタログ修正が強制する 5 箇所のみ(cURL の false-confidence fixture 含む)。
- デモ更新: `HttpMailer` は `fsockopen(定数ホスト)`(狭化の実演)に、`touchCache` は
  定数パスに変更。§9 の `mail` は `may have effect io` に。3系統再キャプチャ済み、
  released 2.2.8 は警告ゼロ維持。
- コミットからこの stage 分は新規約どおり実作業モデルを明記
  (`Co-authored-by: Claude Fable 5`(設計裁定・監査)+ `Claude Opus 5`(実装))。
- **P2(責務分割)と D-V2 語彙移行(`io.output.buffer` 族 + `io.input`)は stage 10**。

試作の受け入れ基準(stage 1):
1. `@phpstan-impure io` を書いた既存コードで**エラー出力が一切変わらない**(ラベルが認識されても
   bound 内なら沈黙、bare/散文/未知は現状どおり)。
2. `@phpstan-impure io.db` + `echo` → `effectOutsideEnvelope` 系エラー1件(著者の綴りで引用)。
3. `@phpstan-impure database`(散文/未知)+ 任意の body → 追加エラーゼロ。
4. inert なメソッドタグがクラスタグにフォールバックしないことのテスト。
5. `make tests` / `make phpstan` グリーン。

