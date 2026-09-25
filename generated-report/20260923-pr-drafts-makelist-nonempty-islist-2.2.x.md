# PR 下書き: `makeList()` のキー順・必須化 / 非空 shape の isList（2.2.x）

背景と検証記録: [20260923-stable-makelist-and-nonempty-islist-2.2.x.md](20260923-stable-makelist-and-nonempty-islist-2.2.x.md)

- **状態: 2026-09-23 にユーザーの承認を得て投稿済み。** PR A = https://github.com/phpstan/phpstan-src/pull/6544 （`f89573d8f`）、PR B = https://github.com/phpstan/phpstan-src/pull/6545 （`c750ef82d`）。どちらも Co-authored-by トレーラー付き（ユーザーが amend）。本文は下の英語本文そのまま。
- 2本は独立した PR（どちらも base は `2.2.x`）。マージ順はどちらが先でもよい（一時マージで衝突なし、全テスト通過を確認済み）。
- 英語本文は GitHub 投稿用なので、1段落1行・箇条書き1項目1行で書いている（ローカルでの折り返しなし）。
- `#5067` や `#6025` は phpstan-src 内の PR なので、本文中では短い記法で自動リンクされる。phpstan/phpstan 側は `phpstan/phpstan#…` と書く。

---

## PR A

- ブランチ: `make-list-ascending-keys`（`f89573d8f`）
- base: `phpstan/phpstan-src:2.2.x`
- タイトル（コミットの件名と同じ）:

```
Put the keys of a list in ascending order and require the ones below a required key
```

### 本文（英語・投稿用）

````markdown
`ConstantArrayType::makeList()` (introduced in #5067, extended in #6025) turns a maybe-list shape into a list by flipping its `isList` flag, but it keeps the keys in the order they were written and their optionality as it was. A list has its keys in ascending order, and a list that has the key `k` has every key below `k` — and positional operations such as `array_values()` trust both.

```php
/** @param array{1?: int, 0?: string} $a */
function foo(array $a): void
{
	if (array_is_list($a)) {
		\PHPStan\dumpType($a);               // list{1?: int, 0?: string}
		\PHPStan\dumpType(array_values($a)); // list{0?: int, 1?: string}
	}
}

foo([0 => 'x', 1 => 5]); // array_values() returns ['x', 5]
```

The key `0` holds a `string`, yet `array_values()` is inferred to put an `int` there — the value types are swapped. This happens on 2.2.x both with and without bleeding edge.

A required key also implies the keys below it: `list{0: string, 1?: string, 2?: string, 3: string}` can only be `[string, string, string, string]`, but the optional flags stayed, so after `unset($list[$i])` with `$i` of type `1|2`, `array_is_list($list)` was `bool` instead of `false`.

With this PR, `makeList()`:

- puts the integer keys in ascending order (followed by the other keys where unsealed extras keep every key),
- makes the keys `0 <= k < (highest required key + 1)` required,
- rebuilds the shape only when one of these changes something, and marks the rebuilt shape as a list explicitly, since the builder on its own can answer maybe for an optional tail like `[0, 1?, 2?]`.

Dropping the keys a list can never have stays limited to sealed shapes, as in #6025.

One existing expectation changes accordingly: in `bug-14177.php`, `array_is_list()` after that `unset()` is now `false` instead of `bool`.

Not changed here: a shape whose written order already rules out being a list, e.g. `array{1: int, 0?: string}`, is still reported as never a list. Whether an `array{...}` shape promises its key order at all is a separate question; this PR only fixes what happens once a shape is known to be a list.

I found this while prototyping the proposal in https://github.com/phpstan/phpstan/discussions/14939, but the fix stands on its own: it does not depend on that proposal, and it can be reviewed and merged independently.
````

### 確認用の日本語訳

> `ConstantArrayType::makeList()`（#5067 で導入、#6025 で拡張）は、「リストかもしれない」shape の `isList` フラグを立ててリストにするが、キーは書かれた順のまま、オプショナルかどうかもそのまま残している。リストはキーが昇順に並び、キー `k` を持つリストは `k` 未満のキーも全部持つ。そして `array_values()` のような位置ベースの操作は、この両方を前提にしている。
>
> （コード例）
>
> キー `0` には `string` が入っているのに、`array_values()` の結果ではそこに `int` が入ると推論される。値の型が入れ替わっている。これは 2.2.x で、bleeding edge の有無にかかわらず起きる。
>
> 必須キーは、それより下のキーの存在も意味する。`list{0: string, 1?: string, 2?: string, 3: string}` は `[string, string, string, string]` しかあり得ないのに、オプショナルのフラグが残っていた。そのため `$i` が `1|2` 型のときに `unset($list[$i])` すると、`array_is_list($list)` は `false` ではなく `bool` になっていた。
>
> この PR で `makeList()` は次のようにする。
>
> - 整数キーを昇順に並べる（unsealed な extras があって全キーを残す場合は、それ以外のキーをその後ろに置く）。
> - `0 <= k < (最大の必須キー + 1)` のキーを必須にする。
> - これらで何かが変わるときだけ shape を組み直し、組み直した shape を明示的にリストとする。builder だけに任せると、`[0, 1?, 2?]` のようなオプショナルな末尾に対して maybe と答えることがあるため。
>
> リストが決して持ち得ないキーを削るのは、#6025 と同じく sealed な shape に限ったまま。
>
> これに伴い、既存の期待値が1箇所変わる。`bug-14177.php` で、上の `unset()` の後の `array_is_list()` が `bool` ではなく `false` になる。
>
> ここで変えていないこと: 書かれた順序の時点でリストになり得ない shape（例: `array{1: int, 0?: string}`）は、引き続き「リストにはならない」と報告される。そもそも `array{...}` shape がキー順を約束するかどうかは別の問題であり、この PR が直すのは「shape がリストだと分かった後」の扱いだけ。
>
> この問題は https://github.com/phpstan/phpstan/discussions/14939 の提案をプロトタイピングする中で見つけたが、修正はそれ単体で成り立つ。その提案には依存しておらず、独立してレビュー・マージできる。

---

## PR B

- ブランチ: `nonempty-shape-islist`（`c750ef82d`）
- base: `phpstan/phpstan-src:2.2.x`
- タイトル（コミットの件名と同じ）:

```
A non-empty constant array that cannot have the key 0 is not a list
```

### 本文（英語・投稿用）

````markdown
Every list except the empty one has the key `0`. So a shape whose only list is the empty array — its keys are strings, or integers past `0` — is never a list once it is known to be non-empty. PHPStan did not always see that:

```php
/** @param non-empty-array{a?: string, b?: int} $a */
function foo(array $a): void
{
	\PHPStan\dumpType(array_is_list($a)); // bool, but no value of $a is a list
}
```

With a single optional key, the existing rule in `TypeCombinator::intersect()` makes that key required (`non-empty-array{a?: string}` becomes `array{a: string}`), so that case already worked; with two or more optional keys the shape's `isList` stayed maybe. This happens on 2.2.x both with and without bleeding edge.

On bleeding edge the same kind of shape also comes out of merging. Both variables below end up as `non-empty-array{a?: '0', b?: '0', c?: '0'}`, but only the second one is known not to be a list:

```php
/**
 * @param non-empty-list<'a'|'b'|'c'> $cols
 * @param 'a'|'b'|'c' $col
 */
function bar(array $cols, string $col): void
{
	$loop = [];
	foreach ($cols as $c) {
		$loop[$c] = '0';
	}
	\PHPStan\dumpType(array_is_list($loop));   // bool

	$direct = [];
	$direct[$col] = '0';
	\PHPStan\dumpType(array_is_list($direct)); // false
}
```

`ConstantArrayTypeBuilder` knows the array is non-empty and marks the shape as not a list. Since #6025, `mergeWith()` recomputes list-ness from the merged shape, and the merged shape on its own admits `[]`, so it lifts no to maybe. The `non-empty-array` accessory that rules `[]` out sits next to the shape, where the merge cannot see it. The result is two representations of the same type that differ only in a flag `describe()` does not show and `equals()` does not compare.

This PR fixes it where the shape and the accessory meet: when `TypeCombinator::intersect()` combines a constant array whose `isList` is maybe and which cannot have the key `0` with `non-empty-array`, the shape becomes not a list, through a new `@internal` `ConstantArrayType::makeListNo()` (the counterpart of `makeListMaybe()`). It runs after the existing rule that requires a single optional key, so that rule still gets to make the key required first. A shape that can have the key `0` — an optional key `0`, or unsealed extras with integer keys — stays maybe.

No existing expectation changes. The baseline count of `instanceof ConstantArrayType` in `TypeCombinator` goes from 22 to 24; the intersect loop around the new branch is written that way throughout.

I found this while prototyping the proposal in https://github.com/phpstan/phpstan/discussions/14939, but the fix stands on its own: it does not depend on that proposal, and it can be reviewed and merged independently. (In that prototype `equals()` starts comparing list-ness, and these two representations then keep the loop from phpstan/phpstan#13786 from converging, so the array gets generalized — which is how this surfaced.)
````

### 確認用の日本語訳

> 空のものを除くすべてのリストは、キー `0` を持つ。したがって、唯一のリストが空配列であるような shape（キーが文字列か、`0` より大きい整数だけ）は、空でないと分かった時点でリストにはなり得ない。PHPStan はこれを常には見抜けていなかった。
>
> （コード例1: `bool` になるが、`$a` のどの値もリストではない）
>
> オプショナルキーが1つだけなら、`TypeCombinator::intersect()` の既存の規則がそのキーを必須にする（`non-empty-array{a?: string}` は `array{a: string}` になる）ので、そのケースはすでに正しかった。オプショナルキーが2つ以上あると、shape の `isList` は maybe のままだった。これは 2.2.x で、bleeding edge の有無にかかわらず起きる。
>
> bleeding edge では、同じ種類の shape が merge からも生じる。下の2つの変数はどちらも `non-empty-array{a?: '0', b?: '0', c?: '0'}` になるが、リストではないと分かっているのは2つ目だけ。
>
> （コード例2: ループ経由は `bool`、直接代入は `false`）
>
> `ConstantArrayTypeBuilder` は配列が空でないことを知っているので、shape をリストではないと印を付ける。#6025 以降、`mergeWith()` は merge 後の shape からリスト性を計算し直す。merge 後の shape は、それ単体では `[]` を含み得るので、no を maybe に引き上げる。`[]` を除外する `non-empty-array` アクセサリは shape の外側にあり、merge からは見えない。その結果、同じ型に2つの表現ができる。両者の違いは、`describe()` が表示せず `equals()` も比較しないフラグだけ。
>
> この PR は、shape とアクセサリが出会う場所でこれを直す。`TypeCombinator::intersect()` が「`isList` が maybe で、キー `0` を持ち得ない定数配列」と `non-empty-array` を組み合わせるとき、その shape をリストではないものにする。これには新しく追加した `@internal` の `ConstantArrayType::makeListNo()`（`makeListMaybe()` と対になるもの）を使う。この処理は「オプショナルキーが1つなら必須にする」既存の規則の後に実行するので、その規則が先にキーを必須にできる。キー `0` を持ち得る shape（オプショナルなキー `0` がある、または整数キーの unsealed な extras がある）は maybe のまま。
>
> 既存テストの期待値は変わらない。`TypeCombinator` にある `instanceof ConstantArrayType` のベースライン件数は 22 から 24 になる。新しい分岐の周りの intersect ループは、全体がこの書き方になっている。
>
> この問題は https://github.com/phpstan/phpstan/discussions/14939 の提案をプロトタイピングする中で見つけたが、修正はそれ単体で成り立つ。その提案には依存しておらず、独立してレビュー・マージできる。（そのプロトタイプでは `equals()` がリスト性を比較するようになり、この2つの表現のせいで phpstan/phpstan#13786 のループが収束せず、配列が一般化されてしまった。それがこの問題に気づいたきっかけ。）

---

## 投稿手順（承認後に実行。push も含むので、それぞれ実行前に確認する）

```bash
git push origin make-list-ascending-keys
gh pr create --repo phpstan/phpstan-src --base 2.2.x --head zonuexe:make-list-ascending-keys --title "Put the keys of a list in ascending order and require the ones below a required key" --body-file <PR A の英語本文を保存したファイル>

git push origin nonempty-shape-islist
gh pr create --repo phpstan/phpstan-src --base 2.2.x --head zonuexe:nonempty-shape-islist --title "A non-empty constant array that cannot have the key 0 is not a list" --body-file <PR B の英語本文を保存したファイル>
```

## 検討メモ（下書きに入れていないもの）

- Attribution: このセッションはシステム指示で Co-authored-by の付与が禁止されている。付ける場合は、ユーザーが各ブランチで `git commit --amend --no-edit --trailer "Co-authored-by: Claude Opus 5.5 <noreply@anthropic.com>"` を実行する（どちらも1コミットのブランチなので、amend だけで済む）。
- playground のリンクは作っていない（作成すると外部への公開になるため）。付けるなら、コード例を phpstan.org に貼る作業はユーザーが行う。
- #6025 のスレッドへの追補コメントは、PR B の本文で文脈を説明しているので不要と判断した。付けたい場合は別途下書きする。
- mutation testing（infection）はローカルで未実行。CI で失敗したら対応する。

---

## PR A 本文 改訂版 v2（敵対的レビュー対応、2026-09-23、ユーザー承認のうえ #6544 に反映済み。`a32938e47` も fast-forward で push 済み）

追加コミット `a32938e47`（Reach makeList() from list<mixed> intersections and drop the keys a list cannot have）に合わせた版。投稿済みの本文との差分は、「With this PR」の箇条書き、その直後の段落、`array_is_list()` の経路を説明する段落の3箇所だけ。

### 本文（英語・投稿用、全文）

````markdown
`ConstantArrayType::makeList()` (introduced in #5067, extended in #6025) turns a maybe-list shape into a list by flipping its `isList` flag, but it keeps the keys in the order they were written and their optionality as it was. A list has its keys in ascending order, and a list that has the key `k` has every key below `k` — and positional operations such as `array_values()` trust both.

```php
/** @param array{1?: int, 0?: string} $a */
function foo(array $a): void
{
	if (array_is_list($a)) {
		\PHPStan\dumpType($a);               // list{1?: int, 0?: string}
		\PHPStan\dumpType(array_values($a)); // list{0?: int, 1?: string}
	}
}

foo([0 => 'x', 1 => 5]); // array_values() returns ['x', 5]
```

The key `0` holds a `string`, yet `array_values()` is inferred to put an `int` there — the value types are swapped. This happens on 2.2.x both with and without bleeding edge.

A required key also implies the keys below it: `list{0: string, 1?: string, 2?: string, 3: string}` can only be `[string, string, string, string]`, but the optional flags stayed, so after `unset($list[$i])` with `$i` of type `1|2`, `array_is_list($list)` was `bool` instead of `false`.

With this PR, `makeList()`:

- puts the integer keys in ascending order,
- drops the negative and non-integer keys, which a list never has,
- makes the keys below the highest required key required,
- rebuilds the shape only when one of these changes something, and marks the rebuilt shape as a list explicitly, since the builder on its own can answer maybe for an optional tail like `[0, 1?, 2?]`.

Dropping the keys past a gap in `0..n` stays limited to sealed shapes, as in #6025, since unsealed extras may fill the gap. A shape whose dropped keys include a required one is never a list.

`array_is_list()` narrows by intersecting with `list<mixed>`. A shape that is not a subtype of `array<int<0, max>, mixed>` — one with a string or a negative key, or with extras — is first rebuilt against that array in `TypeCombinator::intersect()`, and the rebuilt shape used to move on without meeting the list accessory, so `makeList()` never ran for it: `array{a?: bool, 1?: int, 0?: string}` still became `list{1?: int, 0?: string}`. The rebuilt shape now meets the remaining members again.

One existing expectation changes accordingly: in `bug-14177.php`, `array_is_list()` after that `unset()` is now `false` instead of `bool`.

Not changed here: a shape whose written order already rules out being a list, e.g. `array{1: int, 0?: string}`, is still reported as never a list. Whether an `array{...}` shape promises its key order at all is a separate question; this PR only fixes what happens once a shape is known to be a list.

I found this while prototyping the proposal in https://github.com/phpstan/phpstan/discussions/14939, but the fix stands on its own: it does not depend on that proposal, and it can be reviewed and merged independently.

````

### 変更箇所の日本語訳

> この PR で `makeList()` は次のようにする。
>
> - 整数キーを昇順に並べる。
> - リストが決して持たない負のキーと非整数キーを削る。
> - 最大の必須キーより下のキーを必須にする。
> - これらで何かが変わるときだけ shape を組み直し、組み直した shape を明示的にリストとする。builder だけに任せると、`[0, 1?, 2?]` のようなオプショナルな末尾に対して maybe と答えることがあるため。
>
> `0..n` の隙間より先のキーを削るのは、#6025 と同じく sealed な shape に限ったまま。unsealed な extras がその隙間を埋め得るため。削ったキーの中に必須キーがある shape は、決してリストにならない。
>
> `array_is_list()` は `list<mixed>` との交差で絞り込む。`array<int<0, max>, mixed>` の部分型でない shape（文字列キーや負のキー、extras を持つもの）は、まず `TypeCombinator::intersect()` の中でその配列に合わせて組み直される。ところが組み直した shape は、list アクセサリと組み合わされないまま次に進んでいたので、`makeList()` が呼ばれなかった。`array{a?: bool, 1?: int, 0?: string}` は `list{1?: int, 0?: string}` のままだった。今は、組み直した shape が残りのメンバーともう一度組み合わされる。

---

## PR B 再設計版（v2、2026-09-23、未反映）

- ブランチ `nonempty-shape-islist` を1コミット `e4e81e898` に作り直した。旧コミット `c750ef82d` はローカルのブランチ `nonempty-shape-islist-backup-c750ef82d` に退避してある。
- 反映には **force-push が必要**（PR は Draft 中）。
- 新しいタイトル（コミットの件名と同じ）:

```
Derive that a non-empty array without the key 0 is not a list instead of storing it on the shape
```

### 本文（英語・投稿用）

````markdown
Every list except the empty one has the key `0`, so a non-empty array that cannot have that key is never a list. `ConstantArrayTypeBuilder` stored that conclusion on the shape itself: a write to a union of keys set the shape's `isList` to no outright. But the new keys are optional, so the shape still admits the array without them — and the conclusion is not even true for integer keys. On 2.2.x, with and without bleeding edge:

```php
/**
 * @param 0|1 $int
 * @param 'a'|'b' $string
 */
function foo(int $int, string $string): void
{
	$a = [];
	$a[$int] = 'x';
	array_is_list($a); // reported as always false, but [0 => 'x'] is a list

	$b = [];
	$b[$string] = 'x';
	array_pop($b);
	array_is_list($b); // reported as always false, but [] is a list
}
```

The flag also gave one type two representations. On bleeding edge, merging such a shape (e.g. across the iterations of a loop) recomputes list-ness from the keys since #6025 and gets maybe, so `array_is_list()` was `false` for `$b` before the `array_pop()` when the array was built directly, but `bool` when it was built in a loop.

This PR keeps the flag true to the shape and derives the rest where both facts meet:

- After a write to a union of keys, `ConstantArrayTypeBuilder` makes the array a list only if the array without the new keys and each single write are (by the same rules as for a single key).
- `IntersectionType::isList()` is no when the intersection is non-empty and none of its members can have the key `0`.
- `AccessoryArrayListType::isSuperTypeOf()` takes a no from `isList()` of a compound type before asking its members, so the `($value is list ? true : false)` return type of `array_is_list()` sees it.

So `array_is_list()` stays `false` for a non-empty array like `$b` before the `array_pop()` — now also when it was built in a loop, and for PHPDoc shapes like `non-empty-array{a?: string, b?: int}`, where it was `bool`.

What the shape can no longer express is that one of the keys is always written: `['x']` with `5|6` written to it is now maybe a list instead of not a list, since the shape `array{0: 'x', 5?: ..., 6?: ...}` also describes `['x']`.

No existing expectation changes. Self-analysis takes about the same time (user CPU +1.2% over two interleaved runs, within noise).

I found this while prototyping the proposal in https://github.com/phpstan/phpstan/discussions/14939, but the fix stands on its own: it does not depend on that proposal, and it can be reviewed and merged independently.
````

### 確認用の日本語訳

> 空のものを除くすべてのリストはキー `0` を持つので、そのキーを持ち得ない空でない配列は決してリストではない。`ConstantArrayTypeBuilder` はこの結論を shape 自身に保存していた。union のキーに書き込むと、shape の `isList` を無条件に no にしていた。しかし新しいキーはオプショナルなので、shape はそれらのキーがない配列も表している。しかも、この結論は整数キーではそもそも正しくない。2.2.x では、bleeding edge の有無にかかわらず次のようになる。
>
> （コード例: `$a[0|1] = 'x'` は「常に false」と報告されるが `[0 => 'x']` はリスト。`$b['a'|'b'] = 'x'; array_pop($b);` も「常に false」と報告されるが `[]` はリスト）
>
> このフラグは、1つの型に2つの表現も生んでいた。bleeding edge では、こうした shape を merge すると（例: ループの反復をまとめるとき）、#6025 以降キーからリスト性を計算し直して maybe になる。そのため `array_pop()` の前の `$b` について、`array_is_list()` は直接作った配列なら `false`、ループで作った配列なら `bool` だった。
>
> この PR は、フラグを shape に対して正しい値に保ち、それ以外は両方の事実が出会う場所で導く。
>
> - union のキーに書き込んだ後、`ConstantArrayTypeBuilder` は「新しいキーがない配列」と「1つのキーを書いた各結果」のすべてがリストのときだけ、その配列をリストとする（単一のキーの場合と同じ規則）。
> - `IntersectionType::isList()` は、交差が空でなく、どのメンバーもキー `0` を持ち得ないときに no を返す。
> - `AccessoryArrayListType::isSuperTypeOf()` は、compound type の `isList()` が no なら、メンバーに問い合わせる前に no と答える。これで `array_is_list()` の戻り値型 `($value is list ? true : false)` にも反映される。
>
> その結果、`array_pop()` の前の `$b` のような空でない配列に対して `array_is_list()` は引き続き `false` になる。ループで作った場合も、`non-empty-array{a?: string, b?: int}` のような PHPDoc の shape の場合（これまでは `bool` だった）も、同じく `false` になる。
>
> shape で表現できなくなったのは、「どれか1つのキーは必ず書かれている」という情報。`['x']` に `5|6` を書いた配列は、リストではないのではなく「リストかもしれない」になる。shape `array{0: 'x', 5?: ..., 6?: ...}` は `['x']` も表しているため。
>
> 既存テストの期待値は変わらない。自己解析の時間もほぼ同じ（交互に2回ずつ計測して user CPU +1.2%、ノイズの範囲内）。
>
> この問題は https://github.com/phpstan/phpstan/discussions/14939 の提案をプロトタイピングする中で見つけたが、修正はそれ単体で成り立つ。その提案には依存しておらず、独立してレビュー・マージできる。

### force-push に添えるコメント（英語・投稿用）

```markdown
I reworked this after an adversarial review and force-pushed. The first version stored "not a list" on the shape (a new `makeListNo()` called from `TypeCombinator::intersect()`), which outlived the `non-empty-array` accessory that justified it — after `unset()`, `array_pop()` or `$a[] =` the array can be a list again, and it produced new "always false" reports. The builder turned out to do the same thing already, which is where the false positives in the description come from. This version keeps the shape's flag true to the shape and derives "not a list" from the intersection instead.
```

> 訳: 敵対的レビューを受けて作り直し、force-push した。最初の版は「リストではない」を shape に保存していた（`TypeCombinator::intersect()` から呼ぶ新しい `makeListNo()` を使っていた）。この情報は、それを正当化していた `non-empty-array` アクセサリより長く残ってしまう。`unset()`、`array_pop()`、`$a[] =` の後は配列が再びリストになり得るので、新たな「常に false」の報告を生んでいた。builder もすでに同じことをしていて、それが本文の誤検出の原因だった。この版は、shape のフラグを shape に対して正しい値に保ち、「リストではない」は交差の側から導く。
