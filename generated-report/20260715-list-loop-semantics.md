# list 意味論の現状調査 — ループ・逐次書き込みにおける list 性の喪失と再構築

Status: **調査完了・4件の修正実装済み**(2026-07-15)。phpstan-src 2.2.x HEAD (`4eee31c99`) にて全再現を確認。

## 0. 成果物(ローカルブランチ、未 push・PR 未作成)

| ブランチ | 対象 | コミット | 状態 |
|---|---|---|---|
| `foreach-non-empty-narrowing` | #13312 (G4) | `11820b54b` | 全スイート+self-analysis グリーン |
| `by-ref-constant-array-foreach` | #1311 by-ref 定数配列 (G1) | `3668e7246` | 同上(certainty 処理をレビューで修正済み) |
| `counted-for-loop-unroll` | #1311 for ループ (G2+G5有界) | `232c3a62e` + `3a74a4138` | 同上(strict-rules 契約の保持をレビューで追加) |
| `value-of-offset-access-resolution` | #10231 | `c2cc17d6e` | 同上 |

#13789 (G3) は upstream PR #5506 に委任(修正実在をローカル検証済み)。G5 無界は upstream 議論案件。
対象: phpstan/phpstan #1311(コア)、#10231、#13789、#13312 とその周辺クラスタ。

関連ノート: [20260709-rfc-draft-array-list-shapes.md](20260709-rfc-draft-array-list-shapes.md)(shape 側=宣言的な list 意味論の RFC #14939)。
本ノートはその**フロー解析側**(ループ・書き込みでの list 維持)の対をなす。

---

## 1. 要約

- list は第一級の型ではなく `ArrayType(int, T) & AccessoryArrayListType` という **intersection 内の accessory** として表現される。
- **非定数オフセット(`int<0, max>`)への書き込みは accessory を必ず破壊する**。`AccessoryArrayListType::setOffsetValueType()` はオフセットが `null`(append)か定数 `0` 以外では `ErrorType` を返し、intersection から脱落する(= trinary としては maybe への正しい degrade だが、二度と回復できない)。
- それを救済しているのは**構文的ヒューリスティックのパッチワーク**(§4)であり、「`$i` が `[0, count($arr)]` に留まる」という**関係をスコープが表現する仕組みは存在しない**。
- ループ不動点(3回反復、2回目以降 `generalizeWith`)では両辺が `isList()->yes()` のときだけ accessory を再構成するため、**一度でも非 list な中間状態が挟まると喪失が確定**する。
- 2025〜2026 年にかけて `foreach` 系は大きく改善済み(#4933、#5542)。**ユーザーコードで `foreach ($list as $k => $v) { $list[$k] = f($v); }` は一般の `list<T>` に対しては既に正しく動く**。残る未解決は §3 の4類型。
- ondrejmirtes 本人による大型アーキテクチャ PR(phpstan-src #5506「Keep unions of general array types separate」、#5857「Single-pass expression analysis groundwork」)が**進行中**であり、大規模な再設計を今こちらで行うと衝突リスクが高い。段階的な計画を §7 に示す。

---

## 2. 対象 issue の分析(全て 2.2.x HEAD で再現確認済み)

### 2.1 #1311 — Unable to infer type when modifying an array(2018-07-30、コア issue)

OP のオリジナル playground は旧形式 ID で死んでいるが、ondrejmirtes が bot 追跡用に現代版を再投稿しており、それが正典:

**Variant A — foreach by-ref**(`d6ad83be-5c1a-4854-941a-f0e937275846`):

```php
$temp = [1, 2, 3];
foreach ($temp as &$item) {
    $item = (string) $item;
}
$this->list = $temp; // @var array<int, string>
```

現在の結果: `$temp` は **`array{1, 2, 3}` のまま完全に無変化** →
`Property HelloWorld::$list (array<int, string>) does not accept array<int, int>.`
by-ref 変異が**定数配列**に対して一切追跡されない。

**Variant B — for + count()**(`29982739-327a-494d-bc97-06021030576b`):

```php
$temp = [1, 2, 3];
for ($i = 0; $i < count($temp); $i++) {
    $temp[$i] = (string) $temp[$i];
}
$this->list = $temp;
```

現在の結果: スロットごとに新旧 union(`1|"1"` 相当)→
`does not accept array<int, int|string>.`
書き込み自体は追跡されるが「ループが全要素を書き換えた」ことを証明できず union 化する。

ondrejmirtes の初期コメントで「by-ref 変異」と「for ループでの全書き換え認識」の**2つの別問題**に分類済み。「I don't care about the by-ref btw.」という発言もあり、by-ref 側の優先度は低いと見られている。

**修正の軌跡(重要)**:

| PR (phpstan-src) | 状態 | 内容 |
|---|---|---|
| #3709 + #3898 | Merged (2025-03) | `count()` narrowing 改善(Variant B の部分改善) |
| #4933 | Merged (2026-02) | `enterForeach()` を `SetOffsetValueTypeExpr` → `SetExistingOffsetValueTypeExpr` に変更。キー変数なし by-ref foreach での **list 性維持**を修正(#13809, #13851, #14083, #14084, #9669 をクローズ) |
| #5542 | Merged (2026-04) | ループ後の配列型書き換え機構(従来 `$stmt->keyVar !== null` が前提)をキー無し by-ref foreach に拡張、**値型の更新**まで対応 |
| #5549, #5556 | **Closed 未マージ** | 同機構の**定数配列**への拡張の2度の試み(いずれも phpstan-bot 製の実験で同日クローズ)。#5556 で VincentLanglet が挙げた `Offset 'two' might not exist` スニペットは PR 起因のリグレッションではなく **HEAD に既存する #1311 の下流症状**(= 修正が満たすべき受け入れテスト)。彼の本質的指摘は「`enterForeach`/`tryProcessUnrolledConstantArrayForeach` 経由で統一せよ」という設計指針 |

つまり **Variant A(定数配列 by-ref)は2回試みられて2回失敗している**未解決領域。

### 2.2 #10231 — value-of<TArray[K]> が `list<*ERROR*>` になる(2023-11-30)【修正実装済み 2026-07-15】

```php
/**
 * @template TArray of array
 * @param array<TArray> $input
 * @return array<value-of<TArray[TGroupColumnName]>, list<value-of<TArray[TValueColumnName]>>>
 */
```

`$output[$result[$groupByColumn]][] = ...` というループ内 list 構築の形をしているが、**欠陥はジェネリクス解決層**(`TypeNodeResolver` の `value-of<OffsetAccessType>` 処理)にあり、ループ意味論とは別系統。修正試行 phpstan-src #5358(`toArrayKey()` 変換のスキップ)は Closed 未マージ(bot 製・レビューなしで放棄。ただし bare defer 方式には `return.phpDocType` 偽陽性を残す実欠陥があることを確認)。

**実装済み修正**(branch `value-of-offset-access-resolution`, commit `c2cc17d6e`): 根因は2つ —
(i) `array<K,V>` の K を PHPDoc パース時に `toArrayKey()` で正規化する際、未解決の LateResolvableType がテンプレート境界に対して強制解決され `int|string` に**凍結**される → 代替: `array-key`(benevolent int|string)との **intersect** で valid-key 性を保ちつつ遅延解決を維持。
(ii) `ValueOfType::getResult()` が置換後にスカラーへ解決された offset-access に `getIterableValueType()` = ErrorType を返す → LateResolvableType 経由に限り解決型へフォールバック(直接書かれた `value-of<int>` は従来どおりエラー維持)。
結果: OP 再現が `array<string, list<int>>` に。全スイート+`make phpstan` グリーン。
**upstream 論点**: (ii) は「late-resolvable 経由の `value-of<スカラー>` を透過扱いする」寛容意味論であり、厳格なエラー派の反対がありうる。PR 議論で明示すべき。

### 2.3 #13789 — foreach-by-ref 後に `list<non-empty-array>` が `list<array>` に広がる(2025-11-11、2.1.32 リグレッション)

```php
foreach ($foo as &$row) {       // $foo: list<non-empty-array<mixed>>
    sanitize($row);              // ここで $row: array<mixed> (可空に)
    $row[random_bytes(2)] = random_bytes(2); // ここで $row: non-empty-array に復帰
}
// ループ後: list<array<mixed>> — 要素の non-empty が失われる
```

ループ**内**の各行の推論は正しい。VincentLanglet の分析: `$row` が一瞬でも possibly-empty になった時点で `$foo` が `non-empty-list<array<mixed>>` に広げられ、その後 `$row` が non-empty に復帰しても**書き戻されない**。ループ後書き換え機構が**本体末尾時点の narrowed 型ではなく、中間の広がった型**を拾っている。キー書き戻しのワークアラウンドでも要素の `non-empty` は戻らない(部分的回避のみ)。関連: #13802(同じ 2.1.32 期の by-ref 系リグレッション)。

### 2.4 #13312 — foreach 内で list → non-empty-list に narrow されない(2025-07-26)

```php
foreach ($arr as $v) { bar($arr); }               // エラー: list<mixed> might be empty
for ($i = 0; $i < count($arr); ++$i) { bar($arr); } // OK: non-empty-list に narrow 済み
```

**for では narrow され foreach ではされない非対称**。しかも `strictRules: false` だと発生しない —
foreach 内の non-empty 化は現状 `polluteScopeWithAlwaysIterableForeach: true`(既定)の**副作用**として起きているだけで、strict-rules がこれを `false` にすると消える。phpstan-src #4162 で一度「修正」されたがOP再現は直らず reopen。ondrejmirtes 自身が「`polluteScopeWithAlwaysIterableForeach` かその周辺のせい」と診断。
→ **設定非依存の、意図的な narrowing ルールとして実装し直すべき**(foreach 本体内では被反復配列は非空、という自明な不変条件)。

---

## 3. 未解決ギャップの類型(= 抜本対応の対象)

| # | 類型 | 代表 issue | 状態 |
|---|---|---|---|
| G1 | **定数配列**への by-ref foreach 変異が無追跡 | #1311-A | 2回の修正試行が未マージ |
| G2 | **for ループでの全書き換え**認識(union でなく置換) | #1311-B | count() narrowing で部分改善どまり |
| G3 | ループ本体内の**複合変換**の書き戻し(末尾時点の型を使う) | #13789 | 未着手 |
| G4 | foreach 本体内の **non-empty narrowing** が設定の副作用 | #13312 | 誤修正→reopen |
| G5 | **fresh build**: `for ($i=0;$i<N;$i++) $out[$i]=...` が list にならない | (#1311 派生) | `array<int<0,9>, int<0,9>>` 止まり |
| — | value-of<TArray[K]> 解決失敗 | #10231 | 別系統(ジェネリクス) |

補足: ユーザーコードで頻出の `foreach ($list as $k => $v) { $list[$k] = f($v); }`(一般 list)と
`$out = []; foreach (...) { $out[] = f($v); }` は**既に正しく動く**(2.2.x で確認)。

---

## 4. 実装マップ — list 性はどこで生まれ、どこで死ぬか

### 4.1 表現

- `src/Type/Accessory/AccessoryArrayListType.php` — `CompoundType & AccessoryType`。`isList()` は常に Yes(:369)、`getIterableKeyType()`/`getArraySize()` は `int<0, max>`(:319–327)。`NonGeneralizableTypeTrait` 使用(:41)= **generalize では運ばれず、都度再構成される**。
- `ArrayType::isList()`(ArrayType.php:278–293)は最良でも Maybe。memoized。
- `ConstantArrayType` は `TrinaryLogic $isList` を明示保持(:113)。sealed 非 optional shape のみ `getArraySize()` が正確(:2065–2079)— **サイズ精度が存在する唯一の場所**。
- `list{...}` shape は TypeNodeResolver.php:1158–1216 で CAT + accessory intersect として構築。

### 4.2 喪失の中心 — 書き込み経路

- **`AccessoryArrayListType::setOffsetValueType()`(:155–162)**: offset が `null` か定数 `0` のみ `$this`、**それ以外は `ErrorType`** → intersection から脱落。
- **`IntersectionType::setOffsetValueType()`(IntersectionType.php:1094–1159)** の救済ブロック:
  - Case A(:1128): 非空配列 かつ offset ⊆ `{0,1}` のみ。
  - Case B(:1131–1145): `HasOffsetValueType(c)` メンバーがあり offset ⊆ `[0, c+1]` のとき(既知スロットの直後への書き込み)。
  - **:1149–1156**: 値型が配列である list への整数 offset 書き込みは**無条件で list 維持** — 既存の健全性妥協(多次元 list 構築用のハック)。前例として重要。
- **`setExistingOffsetValueType()`** は list を無条件維持(:164–167)。Type.php:236 に「既存キーの変更、widening しない」と明記。**「既存キーへの書き込みは list を壊さない」という正しい意味論は既に API として存在する**。問題は、これを使えるのは PHPStan がキーの存在を証明できるときだけ、という点。
- 選択ロジック: `AssignHandler.php:544–560`(`$x[$dim]` が追跡済み式なら existing 経路)、実計算は `produceArrayDimFetchAssignValueToWrite`(:1419–1553)。lossy 経路後の再付与は `shouldKeepList()`(:1556–1605)= **完全な構文マッチ**: `$list[$i+1]`、`$list[count($list)-n]`、`$list[array_key_last($list)]`、`$list[array_search(...)]` の4形のみ。

### 4.3 ループ解析(NodeScopeResolver.php)

- `LOOP_SCOPE_ITERATIONS = 3`(:192)、`GENERALIZE_AFTER_ITERATION = 1`(:193)。2回目以降 `generalizeWith` で widening。
- **generalize 時の list 再構成条件**(MutatingScope.php:3973–3975, 4023–4024): `$a->isList()->yes() && $b->isList()->yes()` — **片側でも Maybe なら永久喪失**。
- **foreach value-rewrite**(:1561–1653): `foreach ($x as $k => $v) { $x[$k] = f($v); }` 用の狙い撃ち機構。全 iteration scope から `$x[$k]` の最終型を集めて `mapValueType`/`mapKeyType` で書き換える。`AccessoryArrayListType::mapValueType` は `$this`(:280–289)なので list 生存。**ただし `isConstantArray()->no()` でゲート**されており(:1563–1571)、定数配列は対象外 — これが G1 の直接原因。break があると無効。
- 定数配列の foreach は**アンロール**(:4280–4379)され、この機構を通らない。
- **`inferForLoopExpressions`**(:5173–5241): `for ($i=0; $i<count($v); $i++)` を**構文で**マッチし、`$arrayType->isList()->yes()` のときだけ `$v[$i]` を追跡式に登録(読み側+existing 書き込み経路の有効化)。**最終パスのみ**で不動点反復中は無効(:1970)。

### 4.4 count() 系

- スコープに `$i` ↔ `count($arr)` の関係は**ない**。全て構文マッチ+オンデマンド導出。
- `count($x) === N` → `truncateListToSize()`(TypeSpecifier.php:112–236、Type.php:301)で list shape 化。
- `CountFunctionTypeSpecifyingExtension` は truthy 時に `NonEmptyArrayType` を intersect するだけ。

### 4.5 union/縮退

- `TypeCombinator::optimizeConstantArrays`(TypeCombinator.php:1092–1249): `ARRAY_COUNT_LIMIT = 256` 超過時。連番キー検査で list を**再導出**(:1162–1175, 1215–1217)。
- `ConstantArrayTypeBuilder`: 非定数 offset で `isList = No` + `degradeToGeneralArray`(:358, :399–410)。

---

## 5. 関連 issue クラスタ(open)

| issue | 概要 |
|---|---|
| #14170 | iterable 版の「iteration 後に list 喪失」(#14124 の類似) |
| #13802 | by-ref foreach で `string` → `string\|null` 化(#13789 と同期のリグレッション) |
| #13655 | 2.1.30 リグレッション: 必須キーが `wins?:` と spurious に optional 化 |
| #12725 | shape 由来 `array{string,string}` が `array_is_list()` で list と認識されない(shape 側、RFC #14939 が包含) |
| #14938 | optional キーで isList が無条件 No(自分が起票済み、RFC の一部) |
| #11600 | ConstantArrayType がキー順序を保証しない問題(基盤的) |
| #12434 | shape を list<> で包むと assert-if-true narrowing が壊れる |
| #11507 | by-key 書き込みで同一に見える signature と不一致 |
| #7423 | array-key 推論の一般的な不正確さ(fuzzing 由来) |

closed 側の軌跡: #2404, #9669, #13809, #13851, #14083, #14084(#4933/#5542 系で解決)、#13270(#4162)、#14124。

**進行中の大型 PR(衝突注意)**:
- phpstan-src **#5506** "Keep unions of general array types separate"(ondrejmirtes、open)— `array<int>|array<string>` を `array<int|string>` に潰さない TypeCombinator 基盤変更。
- phpstan-src **#5857** "Single-pass expression analysis groundwork"(ondrejmirtes、open)— 式解析の一回走査化。ループ書き戻し機構の土台が変わる可能性が高い。

---

## 6. 設計論 — 根本原因と選択肢

### 6.1 意味論の核

list とは「キー集合が `{0..n-1}`(昇順)である array」。書き込みの正確な意味論は:

| 書き込み | list 性 |
|---|---|
| `$a[] = v`(append) | 維持 ✓ 実装済み |
| `$a[$k] = v`, `0 <= $k < n`(既存キー) | 維持 ✓ API はある(`setExistingOffsetValueType`)が、キー存在の証明が必要 |
| `$a[$k] = v`, `$k == n`(次の空き) | 維持 — **サイズとの関係が証明できないと使えない** |
| `$a[$k] = v`, `$k > n` | 破壊(gap) |

`$i: int<0,max>` の書き込みに対する trinary の正解は *maybe* であり、accessory 脱落はそれ自体は正しい。**真の問題は (1) maybe に落ちた後に yes へ回復する手段が関係追跡なしには無いこと、(2) 現実のコードは「ループが全体として list を list に写す」ことを保証しているのに、それをステートメント単位の型操作では表現できないこと**。

### 6.2 選択肢

**Option R — 関係追跡(`$i ≤ count($arr)` をスコープで保持)**
正攻法だが SMT 的で、MutatingScope の設計を大きく変える。upstream の受容可能性が不明で、#5857 と正面衝突する。→ 長期。RFC #14939 と同様に Discussion で合意を先に取るべきテーマ。

**Option L — ループ・スキーマ推論の一般化(推奨の中核)**
「ループは配列に対する map/filter/build である」という**ループ単位の意味論**を認識する。foreach value-rewrite(§4.3)は既にこの思想の実装であり、G1〜G3, G5 は全て「この機構の適用範囲が狭い」ことに帰着する:
- G1: **設計調査の結論(2026-07-15)**: generalize-then-rewrite(broadcast)は不均質 shape(`[['x'=>1],['y'=>2]]` に `$el['z']=3`)で全スロットに union を撒いて新たな偽陽性を生むことが実証されたため**不採用**。採用案は `tryProcessUnrolledConstantArrayForeach()` の by-ref bail(NodeScopeResolver.php:4185–4187)を外し、スロットごとに `$iterEndScope` から値変数の型を読んで `setExistingOffsetValueType($keyType, ...)` で**スロット単位に書き戻す**(#5549 のコア部分)。`enterForeach` の2つのゲート(MutatingScope.php:2515, :2529)と post-loop ゲート(:1570)は**触らない** — broadcast 機構を定数配列経路に一切入れないのが #5549 との決定的な違い。shape 精度は完全維持(`array{'1','2','3'}`)。>16 要素は既存のアンロール制限どおり未追跡のまま(健全性優先)。
- G2: **設計調査の結論(2026-07-15、グレード A)**: 2本立て。
  (a) **counted for ループのアンロール** `tryProcessUnrolledCountedFor()` を新設(foreach の `tryProcessUnrolledConstantArrayForeach` :4178–4380 の鏡像)。`$i=0; $i<N; $i++`(N はリテラル or 定数配列の count、`FOR_UNROLL_LIMIT = 16` 以下)で本体に `$x[$i]` 書き込みがあり `$i` が本体で変異されないとき、`$i` を定数に束縛して展開 → 全書き込みが定数オフセット化し union でなく置換になる。定数ケース `array{'1','2','3'}`、条件付き書き込みの既存精度 `array{1, 2|ns, 3}` も維持、**G5 の有界ケース(`$out[$i]=$i`, N=10)も `array{0,...,9}` として同時解決**。
  (b) 一般 list(count 不明)には foreach :1561–1653 と同型の **post-loop `mapValueType` 置換**を For_ に追加(`inferForLoopExpressions` がマッチし break が無いとき、追跡済み `$v[$i]` の clean な型で要素型を置換)。
   鍵となる実証: 書き込み後の追跡式 `$temp[$i]` は clean な新型を保持しており、union は配列コンテナ側にだけ残る。また条件付き書き込み(`if ($i===1)`)は offset が定数に narrowing されるため現状でも per-slot 精度がある=「定数オフセットは置換、範囲オフセットは union」という一貫した機構が根っこ。
  いずれも statement 層(NodeScopeResolver)のみで、#5857(expression 層の書き換え、AssignHandler +485/−300)と非衝突。#5506 とは `for-loop-expr.php` の期待値行の軽微な churn のみ。
- G3: **設計調査の結論(2026-07-15)**: 原因は2つの合わせ技 —
  (i) by-ref 書き込みごとに `$foo` の要素型を union する**貪欲な write-through**(`IntertwinedVariableByReferenceWithExpr` → `ArrayType::setExistingOffsetValueType` の union、ArrayType.php:453–456)。2.1.32 の `8f9490ecc` で導入。union は単調なので一度広がると回復不能。
  (ii) 本体末尾の `$row` で**置換**する事後修正機構(NodeScopeResolver.php:1523–1653)は既に正しく存在するが、`$row` の再代入が `OriginalForeachValueExpr` を無効化してゲート(:1563–1571)で**スキップ**される。このガードは by-value では正しいが by-ref では誤り(参照こそ再代入が要素に反映される)。
  修正案(Option A): by-ref かつキー無しのとき tracking-expr 生存要件をバイパスして事後修正を走らせる(:1532–1567 のみの外科的変更)。副次的に `overwritten-arrays.php` / `bug-13809.php` の既存 `// could be` コメント付き期待値が精密化される。
  **ただし upstream 事情(ローカル検証済み 2026-07-15)**: ondrejmirtes の進行中 PR #5506 が **#13789 を実際に修正することを PR ブランチのチェックアウトで確認**(再現コードが `list<non-empty-array<mixed>>` を出力、同梱の `bug-13789.php` テストは HEAD では正しく fail する本物の回帰テスト)。機構はコミット `a0e520f8c`/`08b744463`(general array の union を分離保持)で、union 崩壊時に個別メンバーの non-empty accessory が捨てられていたのが根因だった。ただし #5506 は CI 79 件失敗・CONFLICTING・最終更新 2026-06-09 と停滞気味。**判断: メンテナが claim 済みのバグなので実装は upstream に委ねる**。こちらの Option A(NodeScopeResolver ゲートのバイパス)設計は完成済みで、#5506 が長期停滞した場合の代替として温存。
- G5: 有界(N ≤ 16)は G2 のアンロールで解決(グレード B)。**無界の一般形はグレード C** — `$i == count($out)` という変数間関係を Scope が表現できない限り、「本体唯一の変異が無条件 `$out[$i] = v`」を append に読み替える脆いヒューリスティックしかなく、しかもその実装先(`shouldKeepList` 族)は #5857 の書き換え圏内。**無理に実装せず upstream 設計議論(関係追跡 RFC)に回す**。
  なお `ConstantArrayTypeBuilder::setOffsetValueType` の単発書き込み意味論(:300 の per-slot union、:345 の isList=No)自体は単発書き込みとしては正しく、変えるべきでない — 問題は常にループ集約レベルにある。
ループは構文要素なので、ループ・スキーマ認識が構文的であることは恥ずべきことではない(statement 単位のヒューリスティックとは筋が違う)。

**Option U — 不健全な pragmatic 維持の拡大**
IntersectionType.php:1149–1156 の前例(値型が配列なら無条件維持)を「`int<0,max>` offset 書き込みは list 維持」まで広げる。#1311 系は一発で消えるが、`$list[10] = x`(gap 生成)を見逃す。upstream が受けるとは考えにくい。→ 不採用。ただし :1149–1156 自体の存在は、upstream が実用のために健全性を妥協する用意があることの証拠として設計議論に使える。

**Option N — #13312 の独立修正**
foreach 本体内で被反復式を `NonEmptyArrayType` と intersect する**設定非依存の意図的 narrowing** を `enterForeach` 側に実装。`polluteScopeWithAlwaysIterableForeach` はループ**後**のスコープ汚染の設定であり、本体**内**の narrowing がそれに寄生している現状が誤り。独立して着手可能、最小リスク。

**Option G — #10231 の独立修正**
`TypeNodeResolver` の `value-of<OffsetAccessType>` 解決(#5358 の再検討)。list 意味論とは独立に進められる。

### 6.3 推奨: 段階計画

1. **Phase 1(いま、独立修正)**: G4/#13312(Option N)。最小・最明快・失敗した先行修正(#4162)の轍を避けて OP 再現をテストに含める。
2. **Phase 2(rewrite 機構の一般化)**: G1 → G3 → G2 の順で Option L を実装。G1 は #5556 の VincentLanglet レビュー(enterForeach/tryProcessUnrolledConstantArrayForeach 経由の統一)に沿わせる。各修正は独立 PR、failing-first テスト必須。#5857 の動向を監視し、大きく被る場合は upstream に相談。
3. **Phase 3(意味論の明文化)**: G5 とループ・スキーマ推論の一般形、および関係追跡(Option R)の要否を Discussion として提起。RFC #14939(shape 側)と対にする「フロー側 list 意味論 RFC」の候補。
4. **並行**: #10231(Option G)は別トラック。

### 6.4 いじらないと決めたこと

- `AccessoryArrayListType::setOffsetValueType` の trinary 意味論(maybe への degrade)自体は正しいので変えない。
- `shouldKeepList()` の構文ヒューリスティック群は、Option L が入れば縮小できるが、先に消すとリグレッションするので温存。
- 表現の変更(accessory 方式をやめて list を第一級型にする等)は、#5506/#5857 と全面衝突するため今は追わない。

---

## 7. 再現ファイル(セッション scratchpad、揮発)

`bug-1311-foreach-ref.php` / `bug-1311-for-loop.php` / `bug-10231.php` / `bug-13789.php` / `bug-13312.php` / `patterns.php` — 各 issue の再現+典型パターン6種の dumpType 採取済み。恒久化が必要なら nsrt テストとして phpstan-src 側に移す。

パターン実測(2.2.x HEAD、要点):

| パターン | 結果 |
|---|---|
| `$out=[]; foreach($list as $v){ $out[]=f($v); }` | `list<...>` 維持 ✓ |
| `$out=[]; for(...){ $out[]=$list[$i]; }` | `list<int>` 維持 ✓ |
| `foreach($list as $k=>$v){ $list[$k]=(string)$v; }`(一般 list) | `list<string系>` 維持・更新 ✓ |
| `for($i=0;$i<10;$i++){ $out[$i]=$i; }` | `array<int<0,9>, int<0,9>>` — list にならない ✗ (G5) |
| `$arr=[1,2,3]; $arr[3]=4;` | `array{1,2,3,4}` ✓(連続 append) |
| `$arr=[1,2,3]; $arr[10]=4;` | `array{0:1,1:2,2:3,10:4}` ✓(gap も shape 維持) |
