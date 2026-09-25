# 2.2.x の既存問題: `makeList()` のキー順・必須化、非空 shape の isList 二重表現

RFC #14939 の 2.3.x 実装（[20260923-rfc14939-list-shapes-2.3.x-impl.md](20260923-rfc14939-list-shapes-2.3.x-impl.md) §10）の途中で見つかった問題のうち、**RFC の意味論変更を待たずに今の 2.2.x で直せる**2件を切り出したメモ。2.2.x を対象に個別の bug fix として出すための下準備。

- 計測対象: `phpstan/2.2.x` @ `31470ab9e`（一時 worktree）と `phpstan/2.3.x` @ `ef16d6ead`。stable・bleeding edge とも、**2.3.x の結果は 2.2.x と完全に一致**。
- プローブ: 旧セッションの scratchpad にある `probe/stable.php`（再現コードは下に全文を載せる）。レベル 9。
- 2.2.x の `ConstantArrayType` は turbo でシャドウされていない（2.2.x でシャドウされているのは 14 クラスだけ）。**PHP だけの修正で済む**。2.3.x に持っていく場合は C++ ミラーへの移植と `make bump-turbo` が必要。

## 問題 A: `makeList()` が宣言順のキーを並べ直さず、必須キーより下のキーを必須にしない

### 症状（stable でも bleeding edge でも発生）

```php
/**
 * @param array{1?: int, 0?: string} $desc
 * @param array{1: int, 0?: string} $reqAbove
 * @param list{0: string, 1?: string, 2?: string, 3: string} $c
 * @param 1|2 $i
 */
function p4(array $desc, array $reqAbove, array $c, int $i): void {
	if (array_is_list($desc)) { dumpType($desc); dumpType(array_values($desc)); } // P4a
	if (array_is_list($reqAbove)) { dumpType($reqAbove); }                       // P4b
	dumpType($c);                                                                // P4c
	unset($c[$i]);
	dumpType(array_is_list($c));
}
```

| 位置 | 2.2.x / 2.3.x の結果 | 正しい結果 | 性質 |
| --- | --- | --- | --- |
| P4a `$desc` | `list{1?: int, 0?: string}` | `list{0?: string, 1?: int}` | キーが降順のまま「リスト」と主張している |
| P4a `array_values($desc)` | `list{0?: int, 1?: string}` ＋「already a list, call has no effect」 | `list{0?: string, 1?: int}` | **unsound**: 値の型が入れ替わる。実行時 `[0 => 's', 1 => 5]` は `['s', 5]` なのに `list{int, string}` と推論する |
| P4c `$c` | `list{0: string, 1?: string, 2?: string, 3: string}` | `list{string, string, string, string}` | 精度: キー 3 が必須なら、リストでは 1 と 2 も必須 |
| P4c `unset` 後の `array_is_list()` | `bool` | `false` | 精度: 必須の 1 か 2 を消すと、もうリストではあり得ない |
| P4b `$reqAbove` | 「always false」、true 側 `*NEVER*` | （RFC の範囲） | 下記「スコープ外」を参照 |

### 原因

`ConstantArrayType::makeList()`（2.2.x L3239–3282）。

```php
if ($this->isUnsealed()->no()) {
	// ... $positionByIndex[値] = 位置, 0..m の連続プレフィックスを $keptPositions に
	if (count($keptPositions) < count($this->keyTypes)) {
		// 到達不能キーがあるときだけ builder で組み直す（#6025 で追加）
	}
}
return $this->recreate($this->keyTypes, ..., $this->optionalKeys, TrinaryLogic::createYes(), ...);
```

1. **キー順**: キーを1つも捨てない場合（`count($keptPositions) === count($this->keyTypes)`）は、宣言順のまま isList を Yes にしているだけ。`array{1?: int, 0?: string}` は `[1, 0]` の並びで「リスト」になり、`array_values()` や `array_keys()` など、キー順を信じる位置ベースの操作が誤った型を返す。
   - さらに stable（`unsealed === null` なので `isUnsealed()` は Maybe）では `isUnsealed()->no()` の分岐に入らない。そのため、到達不能キーがあってもこの経路になる。
2. **必須化**: どちらの経路でもオプショナルフラグをそのまま引き継ぐ。リストはキーが 0..n-1 なので、必須キー k があれば k 未満のキーは全部必須のはずだが、それが反映されない。

### 履歴

- **#5067**（`951d9e499`, 2026-03-11 "Improve intersection of ConstantArray and AccessoryIsList"）で `makeList()` が導入された。当初は `new self(..., TrinaryLogic::createYes())` とフラグを立てるだけで、キー構造には触れていなかった。キー順の問題はここから存在する。
- **#6025**（`59e3fc2bf`, 2026-08-09、#14938 の修正）で、sealed な Maybe shape について「到達不能キーを削る」射影が追加された（A/B メモ `20260709-pr6025-vs-6026-ab-tradeoff.md` の案 B）。ただし削るキーがない場合と stable の場合は旧来の経路のまま。
- A/B メモの原則「`list` と交差した後のキー構造も、値の集合どおりであるべき」を、**削除だけでなく並び順と必須化にも当てはめたもの**、と位置づけられる。新しい issue にするより #5067/#6025 の後続 bug fix として説明するのが自然。

### 修正方針（2.3.x ブランチ `a4ef7b405` で実装・検証済みの内容）

- 0..m の連続プレフィックスを**値の昇順**に集め、最大の必須インデックス+1 を `requiredLength` とする。
- 位置が宣言順と違うとき、またはオプショナルキーの数が変わるとき、builder で昇順に組み直す。`index < requiredLength` のキーはオプショナルにしない。
- builder だけだと、オプショナルな末尾（`[0, 1?, 2?]`）で Maybe と答えることがある。組み直した CAT に対して `makeList()` を再帰させ、recreate 経路で Yes にする（再帰は1段で止まる）。
- stable でも並べ直しを行うかどうかは要検討: stable は `isUnsealed()->no()` の条件で分岐全体をスキップしている。stable では「到達不能キーを削る」処理はしないまま、**並べ直しと必須化だけを行う**形に分けるのが安全。2.3.x ブランチでは BE の sealed 経路でしか確認していないので、2.2.x では stable 経路の設計を改めて決める必要がある。

### スコープ外（RFC 側）

P4b `array{1: int, 0?: string}` の「always false」は、宣言順 `[1, 0?]` を信じる旧意味論のもとでは一貫した答え。実行時には `[0 => 's', 1 => 5]` も受理されるので誤検出だが、これを直すにはキー集合の意味論（RFC #14939）が必要。この修正では変わらない（isList が No なので `makeList()` は Never を返す）。

### テスト計画

- `tests/PHPStan/Analyser/nsrt/` に、P4a（`array_values` を含む）と P4c の `assertType` を追加する。ファイル名は issue 番号がないので機能名にする（例: `make-list-key-order.php`）。
- `tests/PHPStan/Type/Constant/ConstantArrayTypeTest` に `makeList()` の単体テストを追加する（キー順、必須化、再帰で Yes になること。stable と BE の両方）。
- 修正前に失敗することを `git checkout HEAD~1 -- src/` で確認する（stash は使わない）。
- 期待値の変化: 2.3.x ブランチでは `bug-14177.php` の 187 行目が `bool` から `false` に変わった。2.2.x の nsrt にも同じテストがあれば同様に変わる見込み。

## 問題 B: 非空 shape の isList に表現が2つある（当初 BE のみと見ていたが、実装時に stable の PHPDoc でも再現。「実装結果」参照）

### 症状

```php
/**
 * @param non-empty-list<'a'|'b'|'c'> $cols
 * @param 'a'|'b'|'c' $col
 */
function p1(array $cols, string $col): void {
	$loop = [];
	foreach ($cols as $c) {
		$loop[$c] = '0';
	}
	dumpType($loop);                  // non-empty-array{a?: '0', b?: '0', c?: '0'}
	dumpType(array_is_list($loop));   // stable: false / BE: bool  ← 問題
	$direct = [];
	$direct[$col] = '0';
	dumpType($direct);                // non-empty-array{a?: '0', b?: '0', c?: '0'}
	dumpType(array_is_list($direct)); // stable: false / BE: false
}
```

表示上はまったく同じ型なのに、bleeding edge ではループ経由だと `array_is_list()` が `bool`、直接代入だと `false`（「always evaluate to false」も報告される）になる。空でなく、文字列キーだけの配列はリストになり得ないので、正しいのは `false`。**精度の問題（取りこぼし）で、誤検出ではない**。

### 原因（2.3.x BE で計装して確認。2.2.x での経路は実装時に再確認する）

- builder 経由（直接代入）: `ConstantArrayTypeBuilder` は配列が非空だと知っているので、CAT の isList を **No** にし、外側に `non-empty-array` を付ける。
- merge 経由（ループの反復をまとめるとき）: `mergeWith()`（2.2.x L3098–3102）が `$naiveIsList->or(self::inferIsListFromShape(...))` で isList を推論し直す。**裸の** `array{a?, b?, c?}` は `[]` を含むので `inferIsListFromShape()` は Maybe を返し、No が Maybe に引き上げられる。`non-empty-array` アクセサリは CAT の外側にあるので、merge からは見えない。
- 結果として `CAT(No) & non-empty-array` と `CAT(Maybe) & non-empty-array` の2種類ができ、`describe()` では区別できない。
- 2.3.x で計装した結果: `EQDBG array{a?: '0', b?: '0', c?: '0'} Maybe vs No`。
- stable で症状が出ないのは、legacyMergeWith の経路か、ループの合流のしかたの違いによると思われる（未確認。実装時に 2.2.x で `inferIsListFromShape` の呼び出しを計装して確かめる）。

### なぜ今は目立たないか、なぜ重要か

- 今の `equals()` は isList を無視するので、2つの表現は「等しい」と判定され、ループの不動点判定にも影響しない。
- RFC 実装（2.3.x ブランチ）で `equals()` が isList を比較するようになった途端、この二重表現が原因でループが収束しなくなり、bug-13786 のテストで `non-empty-array<'a'|'b'|'c'|'d', '0'>` に一般化される退行が起きた。**この問題を先に 2.2.x で直しておけば、RFC ブランチはその分小さくなる**。

### #6025 との関係

#6025 で入った「merge したらキー集合から isList を推論し直す」処理の副作用。レビューで SanderMuller さんが「`$naiveIsList->or(...)` の `or` は load-bearing か」と指摘した箇所（`mergeWith()` / `legacyMergeWith()` の同じ行、メモ `pr6025-review-resume`）の近くなので、#6025 の追補として文脈を説明しやすい。

### 修正方針（2.3.x ブランチで実装・検証済みの内容）

`TypeCombinator::intersect()` の「CAT と `NonEmptyArrayType` の isSuperTypeOf が maybe」の分岐で正規化する。

- 条件: bleeding edge ∧ CAT の isList が Maybe ∧ `hasOffsetValueType(new ConstantIntegerType(0))->no()`。
- 根拠: **空でないリストは必ずキー 0 を持つ**。キー 0 を持ち得ない Maybe の CAT が non-empty と交差したら、その CAT の isList は No で正しい。
- 実装: `$types[$i]->tryRemove(new AccessoryArrayListType())` で No にする。ただし 2.3.x ブランチの `tryRemove` の list 分岐は RFC 側の変更なので、2.2.x では **No にする専用の手段が別途必要**（例: `recreate` を呼ぶ CAT 側のメソッドを追加する。`makeListMaybe()` の逆方向）。
- **既存の「オプショナルキーが1つ + non-empty なら必須にする」分岐より後に置く**こと。前に置くと `array<1, string>` + non-empty → `array{1: string}` の変換が失われる（2.3.x で `array-shape-from-general-array-with-single-finite-key` が一度壊れた）。
- 代わりの案: `mergeWith()` 側でアクセサリを考慮する。ただし merge は CAT しか見ないので、呼び出し元（`processArrayTypes`）からアクセサリの情報を渡す必要があり、変更が大きくなる。
- bleeding edge 限定にするか: stable では症状が出ていないので BE 限定で足りる。ただし stable の legacyMergeWith でも持ち上げ自体は起きているので、実装時に stable の経路も確認する。

### テスト計画

- `tests/PHPStan/Analyser/nsrt/` に上の P1 を追加する（nsrt は BE で走るので、ループ経由でも `false` になることを確認する）。
- `ImpossibleCheckTypeFunctionCallRuleTest` に、ループ経由でも「always evaluate to false」が報告されることを追加する。→ 実装では追加しなかった（このルールは推論された型を読むだけなので、nsrt で `array_is_list()` が `false` になることを確かめれば足りる）。
- `TypeCombinatorTest` に、`intersect(CAT(Maybe, キー0なし), NonEmptyArrayType)` の isList が No になる単体テストを追加する。

## 2.2.x で個別に出せるほかの候補（参考）

RFC ノート §10 の表のとおり。優先度はこの2件より低い。

- type-only 表示で `list<int, X>` と出る（`list<X>` にすべき）。数行の修正。
- リストでない shape の `count()` 絞り込み（オプショナルキーが全部ない／全部あるの両端だけ）。2.2.x では `TypeSpecifier` 側にある。

## 次のアクション（未実施）

1. 2.2.x から作業ブランチを切る（この worktree は RFC ブランチの HEAD にいるので、新しいブランチを `phpstan/2.2.x` から作る）。`composer install` が必要。
2. 問題 A → 問題 B の順に、それぞれ1コミット（1 PR）で実装する。A は stable でも unsound なので優先度が最も高い。
3. PR 本文を下書きし、**投稿前に承認をもらう**。

## 実装結果（2026-09-23）

worktree `.claude/worktrees/silly-cori-2d0a91`。2本とも `phpstan/2.2.x` @ `31470ab9e` から独立に切った（upstream の追跡は解除済み、**未 push**）。

| ブランチ | コミット | 内容 |
| --- | --- | --- |
| `make-list-ascending-keys` | `f89573d8f` | 問題 A |
| `nonempty-shape-islist` | `c750ef82d` | 問題 B |

検証: どちらも全テスト（21728件）が通過。phpcs は clean、`make phpstan` はエラー 0。追加したテストは修正前に失敗することを `git checkout HEAD~1 -- src/` で確認済み。A と B を一時ブランチでマージしても衝突はなく、合わせた全テスト（21737件）も通過。mutation testing（CI の infection）はローカル未実行。PHPUnit 12 への更新などが必要で重いため。

### A の実装で決めたこと

- **stable 経路の扱い（事前メモの懸念点）:** 並べ直しと必須化は、sealed かどうかに関係なく**全経路で**行う。到達不能キーの削除だけは従来どおり `isUnsealed()->no()` のときに限る。unsealed（stable の `null` を含む）では全キーを残し、整数キーを昇順に並べ、非整数キーはその後ろに置く。
- **必須化の条件は `0 <= k < requiredLength`:** 負の整数キーはリストに入り得ないので、オプショナルのまま残す（テストの行を用意した）。
- **組み直しの手順:** builder（`disableArrayDegradation`）で組み直したあと、`$this->recreate(..., Yes, $this->unsealed)` を呼ぶ。unsealed のペア（stable の `null`、実際の extras）は元の CAT のものを引き継ぐ。builder は `[0, 1?, 2?]` で Maybe と答えることがあるが、これはリストのキー構造なので Yes と明示する。
- **自己解析:** `instanceof self` は baseline の件数を超えるので、`getConstantArrays()` を使い、1件でなければ `ShouldNotHappenException` を投げる形にした。
- **既存テストの変化:** 期待値が変わったのは `bug-14177.php` の 188 行目（`bool` → `false`）だけ。stable でも BE でも、P4a と P4c が直ることをプローブで確認した。P4b は変わらない（スコープ外）。
- **テスト:**
  - `nsrt/make-list-key-order.php`（P4a、P4c、`array{0?: string, 1: int}`）
  - `ConstantArrayTypeTest::testMakeListPutsKeysInAscendingOrderAndRequiresTheOnesBelowARequiredKey`（7行: 降順と必須化を stable と BE の両方、文字列キー、負のキー、実際の extras）
  - 2.2.x の builder で宣言順だと isList が No になる入力（先頭に必須キー 2 など）は、`makeList()` の Maybe 分岐に来ないのでテストしない。

### B の実装で分かったこと・決めたこと

- **stable で症状が出ない理由（計装で確認）:** stable ではこのループで `mergeWith` も `legacyMergeWith` も呼ばれない。BE では `mergeWith` が2回呼ばれ、2回目で `naive=No` が `result=Maybe` に引き上げられていた。
- **ただし問題自体は BE 限定ではなかった:** PHPDoc の `non-empty-array{a?: string, b?: int}` は、**stable でも BE でも** `array_is_list()` が `bool` になる（オプショナルキーが1つなら既存規則で必須化されるので `false`）。そのため修正は **BE で限定せず**に入れた。「空でないリストはキー 0 を持つ」はどちらの意味論でも成り立つ。
- **実装:**
  - `TypeCombinator::intersect()` の「オプショナルキーが1つ + non-empty なら必須にする」分岐の直後に、正規化の分岐を両方向で追加。条件は `isListOnlyWhenEmpty()`（isList が Maybe ∧ `hasOffsetValueType(0)` が No）。
  - `ConstantArrayType::makeListNo()`（`@internal`）を追加。`makeListMaybe()` と対になる。
  - RFC ブランチの `tryRemove(list)` は 2.2.x にはないので、これを使うことにした。
- **自己解析:** baseline の `TypeCombinator.php` の `instanceof ConstantArrayType` の件数を 22 → 24 に上げた（ループ全体がこの書き方）。
- **テスト:**
  - `nsrt/non-empty-shape-without-key-zero-is-not-a-list.php`（PHPDoc の3パターン、ループ経由と直接代入）
  - `TypeCombinatorTest::testIntersectWithNonEmptyArrayRulesOutTheEmptyList`（7行、両方向の intersect、stable と BE）。修正前は No になるべき4行が失敗し、Maybe のままであるべき3行（オプショナルなキー 0、int の extras）はガードとして修正前後とも通る。
- **既存テストの期待値変更はなし。** mutation については、`hasOffsetValueType(0)->no()` を `!->yes()` にするミュータントは、オプショナルなキー 0 の行が倒す見込み（未実行）。

### RFC ブランチへの影響

2件がマージされたら、RFC ブランチ（2.3.x、`a4ef7b405`）はリベース時に次のように整理できる。

- `makeList()` の変更は2.2.x の実装（全経路で並べ直し）で置き換える。
- `isListOnlyWhenEmpty` から BE の条件を外し、No にする手段を `tryRemove` から `makeListNo()` に置き換える。

どちらも C++ ミラーが関わるので、2.3.x へのマージ（アップマージ）時に turbo 側の対応が別途必要になる（2.3.x では `ConstantArrayType` と `TypeCombinator` がシャドウされている）。

## 投稿（2026-09-23）

- A: https://github.com/phpstan/phpstan-src/pull/6544 — `make-list-ascending-keys` @ `f89573d8f`
- B: https://github.com/phpstan/phpstan-src/pull/6545 — `nonempty-shape-islist` @ `c750ef82d`
- base はどちらも `2.2.x`、head は `zonuexe:` の fork。本文は `20260923-pr-drafts-makelist-nonempty-islist-2.2.x.md` の英語本文そのまま。

## 敵対的レビュー（Opus 5.5 サブエージェント、2026-09-23）と対応

### #6544（A）への指摘と対応

1. **重大:** 文字列キー、負のキー、extras を含む形は、`array_is_list()` の経路で `makeList()` を通らず、値の型の取り違えが残っていた。
   - 原因: `TypeCombinator::intersect()` の「CAT と ArrayType」の分岐で形を組み直したあと、`continue 2` で次の `$i` に進んでしまい、`AccessoryArrayListType` と組み合わされない。
   - 対応: `$types[$i--] = $newArrayType` として、組み直した形をもう一度残りのメンバーと組み合わせる。
2. **退行（stable のみ）:** `non-empty-list{0?: string, -1?: bool}` で、負のキーが先頭に来て「先頭のオプショナルキーを必須にする」規則がキー -1 に効いていた。
   - 対応: 負のキーと非整数キーは、sealed かどうかに関係なく常に削る。
3. **修正前からの問題:** sealed で隙間の先に必須キーがある形（`array{0?: string, 2?: int, 3: bool}`）。
   - 対応: 削ったキーの中に必須キーがあれば `NeverType` を返す。
   - stable の legacy な unsealed で count が厳密になる件は、stable の表現の問題なので範囲外とした。
4. **速度:** `in_array` をやめて `array_flip` と `isset` に置き換えた。
   - 計測すると、時間の大半は builder 自体の二乗コスト（256キーで 0.004 秒、4000キーで 0.72 秒）。前回のコミットと同等なので、これ以上は手を入れない。
5. **2.3.x の C++ 移植:** アップマージ時に対応する（PR 本文には書かない）。
6. **テスト:** 実際の `intersect(shape, ArrayType<int<0,max>>, list[, non-empty])` を通るテストを stable と BE の両方で追加（`testIntersectWithListPutsKeysInAscendingOrder`、6行）。NSRT にも3つのメソッドを追加。

追加コミット `a32938e47` の検証:
- 全テスト 21734 件が通過し、既存テストの期待値変更は増えていない。
- 新しいテストは前回のコミット `f89573d8f` の実装では失敗する（NSRT 5箇所、単体8行）。
- phpcs は clean、`make phpstan` はエラー 0。
- 作業中のミス: コミットせずに `git checkout <sha> -- src/` を実行し、未コミットの修正を消してしまった。この会話の中の編集内容から再適用して、全テストで元どおりになったことを確認した。**A/B 比較の前には必ず WIP コミットを作ること。**

### #6545（B）への指摘

- **重大:** `makeListNo()` で CAT 自身に No を書き込むため、non-empty でなくなった後（`unset`、`array_pop`、`$a[] =`）も No が残る。修正前は `bool` だったものが、stable でも誤った「常に false」になる。こちらでも再現済み。
  - builder が作る No も同じ理由で健全ではない（修正前からある問題）。つまり PR 本文の「builder の No が正しい」という前提が逆。
- **stable での過剰発火:** stable の形は `unsealed === null` で extras があり得る扱いなのに、規則が発火する。
- **その他:**
  - 結果がメンバーの順序に依存する場合が残る。
  - 逆方向の分岐は、前段のソートのせいで一度も実行されない。
  - `unset` や `pop` の後のテストがない。
- 対応: ユーザーが Draft に戻した（2026-09-23）。設計からやり直す。
  - 方向性の案: 結論を CAT ではなく交差の側（`IntersectionType::isList()` など）で導く。あるいは、配列を空にし得る操作で No を Maybe に戻す。

## #6545 の再設計（2026-09-23）

**原則:** CAT の isList フラグは、CAT 自身が構造上表す値の集合に対して常に正しい値にする。「空でない」ことから導かれる「リストではない」はアクセサリとの組み合わせでしか成り立たないので、交差の側で導く。

1. **`ConstantArrayTypeBuilder`**（union キーへの書き込み、旧 L372 の無条件 `createNo()`）:
   - `extremeIdentity([書き込み前の isList] + 各キーの単独書き込み結果)` に変更。単独書き込みの結果は `isListAfterAddingKey()` で計算し、単一キー書き込みの分岐と同じ規則（`nextAutoIndexes` の min/max、負のキー、文字列キー）に従う。
   - これで、修正前の 2.2.x（stable・BE とも）にあった**2つの誤検出**が直る。`$a = []; $a[0|1] = 'x'` と、`$b[$s] = 'x'; array_pop($b)` のどちらも「常に false」になっていた。
   - `['x']` に `0|1` を書いた場合（No → Yes）も直る。
2. **`IntersectionType::isList()`**: Maybe のとき、交差が空でなく、メンバーのどれもキー 0 を持ち得なければ No にする。
   - 最初は交差自身の `hasOffsetValueType()` を使ったが、その中で `isList()` を呼んでいるので**相互再帰で SIGSEGV** になった。そこで各メンバーに直接 `intersectResults` で問い合わせる形にした。
3. **`AccessoryArrayListType::isSuperTypeOf()`**: CompoundType に処理を委ねる前に、相手の `isList()` が No なら No を返す。`array_is_list()` の戻り値型（条件付き型の `isSuperTypeOf`）はこの経路を通る。
4. `makeListNo()`、`TypeCombinator` の分岐、ベースラインの変更（22 → 24）は撤去した。

**トレードオフ:** `['x']` に `5|6` を書いた場合は No から Maybe になる。どれか1つのキーは必ず書かれる、という相関を shape では表せないため。

**検証:**
- 全テスト 21736 件が通過し、既存テストの期待値変更はなし。phpcs と自己解析も clean。
- 自己解析の user CPU は 209.1 → 211.6 秒（+1.2%、交互に2回ずつ計測、ノイズの範囲内）。
- 新しいテストはすべて、修正前の `31470ab9e` で正しい理由で失敗する:
  - NSRT 7箇所
  - ルールテスト（修正前は16・25行目で誤検出、35行目を見逃し）
  - builder の単体テスト5行
  - `TypeCombinatorTest` 4行
- 最初の版のレビューで挙がった反例（`unset` 後に追記するケース）は `bool` になり、誤検出は出ない。

**テスト:**
- `nsrt/non-empty-shape-without-key-zero-is-not-a-list.php` に `unionKeyWrite()` と `nonEmptyIsNotKeptOnTheShape()` を追加。
- `ImpossibleCheckTypeFunctionCallRuleTest::testArrayIsListAfterUnionKeyWrite` と `data/array-is-list-union-key-write.php`。
- `ConstantArrayTypeBuilderTest::testIsListAfterUnionOffset`（6行）。

**コミット:** 1つにまとめた `e4e81e898`（旧コミットは `nonempty-shape-islist-backup-c750ef82d` に退避）。PR に反映するには force-push が必要。

**残る既存の問題（範囲外）:**
- stable で `array_is_list()` の到達不能な分岐の型が `non-empty-list&list{a: string, b?: int}` になる（修正前も同じ）。
- 全キーを `unset` した `array{}` の isList が Maybe のまま残る（健全だが不正確）。

## 反映と CI（2026-09-23）

- #6545: `e4e81e898` を `--force-with-lease` で push。タイトルと本文を v2 に更新して、Draft を解除した（いずれもユーザーの承認済み）。force-push を説明するコメントは、指示に含まれていなかったので投稿していない（下書きのみ）。
- **CI の結果**（どちらの PR も、今回の変更による失敗はない）:
  - #6544: 失敗14件。無関係な PR #6547（phpstan-bot のスタブ更新）とまったく同じ14件。
    - Mutation Testing ×2: infection が最初に走らせる PHPStan が、`ImpossibleCheckTypeFunctionCallRuleTest.php:588` の `#[RequiresPhp('>= 8.0')]` で「Version requirement is incomplete」を出して止まる。2.2.x 本体でも Static Analysis が失敗している。
    - PHPStan (8.1, windows)、extension-tests ×4、integration-tests ×7。
  - #6545: 失敗15件。14件は上と共通。
    - 残る1件は「Tests with old PHPUnit (8.1, windows-latest)」の `IntersectionTypeTest::testIsAcceptedBy` データセット #2/#3/#7。無関係な PR（`int-range-float-2-pow-63`、`int-range-float-bound-32bit`、`fix/bind-class-scope`、`10022/range-php83`）でも同じデータセットで失敗している、既存の順序依存の不安定なテスト。
    - 原因は、古い PHPUnit ではデータプロバイダが、前のテストが bleeding edge を有効にした後に評価されることがあるため（修正前のコードでも、BE で作ったオブジェクトでは #7 が No になるのを確認した）。

## #6544 へのレビュー（staabm、2026-09-23）と対応

- **指摘**（review 5287831460、コメント 4079827089、`bug-14177.php` の私が追加したコメント行に対して）: `list{0: string, 1?: string, 2?: string, 3: string}` は誤った PHPDoc では？ `array{...}` と書くべきでは？
- **返信**（ユーザー承認のうえ投稿）: https://github.com/phpstan/phpstan-src/pull/6544#discussion_r4080948486
  - どの値も表さないという意味では無効ではなく、1 と 2 の `?` が冗長なだけで、`list{string, string, string, string}` と同じ値を表す。
  - `array{...}` にすると非リストの `[0 => 'a', 3 => 'd']` も許して意味が変わる。
  - 冗長なキーや到達不能なキーの報告は PHPDoc ルールの役目で、この PR の範囲外（後続で出してもよい）。
- **対応コミット:** テストのコメントを「in a list, the required key 3 implies the keys 1 and 2, so unsetting either leaves no list」に言い換えた。`--fixup=f89573d8f` の `b459b9555` として fast-forward で push 済み。マージ前に autosquash が必要かどうかは、メンテナの運用に従う。
