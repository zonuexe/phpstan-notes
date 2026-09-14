# 草稿: phpstan/phpstan#6963 へのコメント（未投稿・日本語訳）

状態: 草稿、2026-08-22。投稿は承認後。原文は `20260822-issue-draft-float-range-proposal.md`。ブランチ `float-nan` と `float-range-type` はローカルのみ。`<#PR_NAN>` `<#PR_RANGE>` は PR 番号のプレースホルダ。

---

提案: float の区間は「NaN を含まない、開閉境界付きの区間」とし、`float` は `float<-inf, inf>|NAN` とする。プロトタイプは PR 2 本。<#PR_NAN> は型と `is_nan()`/`is_finite()`/`is_infinite()` の絞り込みを PHPDoc 構文なしで入れる。<#PR_RANGE>（Draft）はその上に構文と比較絞り込みを足す。#15094、#13859、#9250、#13504 の土台になるが、どれもここで完成はしない。

## 動機

PHP 8.5 は NAN から string・bool・array への変換すべて（`"{$f}"`、`echo $f`、`(bool) $f`、`(array) $f`）と、NAN・±INF・範囲外 float の `(int)` キャストに警告を出す（[RFC](https://wiki.php.net/rfc/warnings-php-8-5)）。これをすべての `echo $float` に出さずに報告するには、PHPStan が「この float は NaN ではない」と言える必要がある。今は言えない。`is_nan($f)` は絞り込まず、`float ~ NAN` は表現できず（`NonRemoveableTypeTrait`）、`if ($f > 0.0)` の中でも `$f` は `float` のまま。コードには置き場所だけある。`ConstantNumericComparisonTypeTrait` に `// subtract range when we support float-ranges` が 4 箇所、`FilterFunctionReturnTypeHelper` に `// PHPStan does not yet support FloatRangeType`。

## モデル

PHP の float は、全順序集合 O（`-INF` … `INF`、`-0.0 === 0.0` なので 1 点）と、何と比べても小さくも大きくも等しくもない NAN に分かれる。

- 区間は O の凸部分集合。NAN は区間の要素にならない。
- `float<-inf, inf>` は NAN 以外のすべての float。`int<min, max>` と違い `float` に正規化しない。`float` は `float<-inf, inf>|NAN` で、`TypeCombinator::union()` はこの union を `float` に戻す。
- `is_nan($f)` は `NAN` / `float<-inf, inf>` に、`is_finite($f)` は `float<(-inf, inf)>` / `-INF|INF|NAN` に、`is_infinite($f)` は `-INF|INF` / `float<(-inf, inf)>|NAN` に絞り込む。
- `$f > c` の真側に NAN は入らない。偽側には入る。`!($f > c)` は「`$f <= c` または `$f` が NAN」だから。これが `else { echo $f; }` を報告できる根拠になる。

## `IntegerRangeType` のコピーではない

| | `IntegerRangeType` | `FloatRangeType` |
|---|---|---|
| 非有界側 | `null` | `-INF` / `INF` は float の値 |
| 境界の包含 | 閉のみ。`n±1` で足りる | 境界ごとにフラグ。float に使える後続値はない |
| 全域 | `int` に正規化 | `float<-inf, inf>` のまま。NAN を除く |
| `tryRemove(点)` | `[a, p-1] ∪ [p+1, b]` | `[a, p) ∪ (p, b]` |
| 隣接する区間 | `max + 1 == min` | 共有端点をどちらかが含む。`[0,1) ∪ (1,2]` には穴がある |
| `getFiniteTypes()` | 小さい区間は列挙 | 常に `[]` |
| 比較の偽側 | 補集合 | 補集合 + NAN |
| `accepts(int)` | なし | `float` と同じく coerce。`float<0.0, 1.0>` は `int<0, 1>` を受理 |

開境界が要る場所は 3 つ。点の除去（`float ~ 0.0`）、`$f > 0.0` の真側（`(0.0, inf]`）、`is_finite()`。±INF は値なので「`-inf` で開」は有限を意味する: `float<(-inf, inf)>`。3 つ目に整数の対応物はない。

mvorisek の `(0.1, 0.2)` への問い: この型は実数ではなく double の集合。PHPDoc の `0.1` は PHP の `0.1` と同じ double で、`float<(0.1, 0.2)>` はその 2 つの間にある double の集合。実行時の `$f > 0.1` も同じ double を比較する。

double は有限集合で後続値がある（`nextUp(0.0)` は `5.0E-324`、`nextUp(PHP_FLOAT_MAX)` は `INF`）。だからどの開境界も隣の double で閉じて書ける。`float<[5.0E-324, 1.0]>` は誰も読みたくないので、プロトタイプは境界を書かれたとおりに表示し、空判定・包含・同値・結合・比較絞り込み・`(int)` の切り捨ては正準化した閉境界で判定する（隣接 double は 16-bit 語で計算するので 32-bit ビルドでも動く）。`float<(0.0, 5.0E-324)>` は `never`、`(0.0, 1.0]` は `[5.0E-324, 1.0]` と等しく、`(0.0, 1.0)` の `(int)` は `0`。

## 構文

閉区間は今の phpdoc-parser で解析できる:

```
float<0.0, 1.0>      // [0.0, 1.0]
float<0, 10>         // int リテラルは coerce
float<0.0, inf>      // 0.0 ≤ x ≤ INF
float<min, max>      // NAN 以外のすべての float
```

phpdoc-parser は `-inf` を字句解析できない（`-` で始められるのは数値トークンだけ。#13504 も同じ壁）。そこでプロトタイプは `min`/`max` を受理し `-inf`/`inf` を印字する。float に `min` を残したくはない。`PHP_FLOAT_MIN` は最小の正の正規化数で、subnormal はさらに小さい。`-inf` の lexer 変更が最初のフォローアップ。

開境界は PHP 8.3 の `Random\IntervalBoundary` の語彙を使う:

```
float<0.0, 1.0, closed-open>   // [0.0, 1.0)
float<0.0, max, open-closed>   // (0.0, INF]
float<min, max, open-open>     // (-INF, INF)、有限
```

`toPhpDocNode()` はこの形を出力し、往復する（`TypeToPhpDocNodeTest` で再解析後の `equals()` を検証）。`describe()` は読みやすさのため括弧記法 `float<(0.0, inf]>` を印字する。`mixed~(...)` が今そうであるように表示専用。既定は両端閉。`int<a, b>`、`range()`、`random_int()`、`filter_var(FILTER_VALIDATE_FLOAT, min_range/max_range)` と同じ。

## プロトタイプ

両 PR ともフルスイートと `make phpstan` が通る。先行する 2 コミットは、新しい絞り込みが到達する既存コードの修正:

- `ConstantFloatType::toInteger()/toString()/toBoolean()/toArrayKey()/toBitwiseNotType()` が PHPStan 内部で `(int) NAN`、`(string) NAN`、`(bool) NAN` を実行していたため、`(int) (0 * INF)` を畳み込むと PHPStan 自身が PHP 8.5 の警告を出していた。NAN と無限大は PHP の定義どおり 0 に。解析ホストの int 範囲外の値には定義された結果がないので素の `int` にする。64-bit ホストは前提にしない。
- `IntegerRangeType::createAllSmallerThan()/createAllGreaterThanOrEqualTo()` は float を `PHP_INT_MAX` と比較していた。64-bit ビルドでは PHP がこれを `2^63` に変換するため、`2^63` に等しい float が `(int) ceil(2^63)` に到達していた。どの int 幅でも正確な `PHP_INT_MAX + 1.0` と比較するよう変更。

<#PR_NAN> はその上に、集合代数と正準形を持つ `FloatRangeType`、NAN・無限大・区間に対する `FloatType::tryRemove()`、`TypeCombinator`/`UnionType`/`UnionTypeHelper` のマージ、述語拡張を足す。`is_nan()` は任意の引数を絞り込む。`is_finite()`/`is_infinite()` は `int|float` と分かっている引数だけ絞り込む。`is_finite("1")` と `is_infinite("1e500")` が実行時に真になるから。`toPhpDocNode()` は構文が入るまで `float` に広げる。既存テストの期待値は変わらない。

<#PR_RANGE> は PHPDoc 構文、比較絞り込み（4 箇所の placeholder、偽側に NAN を残す、相手が NAN でありうるなら絞り込まない、右辺が区間なら正準境界）、`float ~ 0.0`、`negate()`、union のメンバごとの単項マイナスを足す。

```php
function (float $f) {
    if ($f > 0.0)  { /* float<(0.0, inf]> */ }  else { /* NAN|float<-inf, 0.0> */ }
    if ($f === 0.0) { /* 0.0 */ } else { /* NAN|float<[-inf, 0.0)>|float<(0.0, inf]> */ }
    /* float */
    if (is_nan($f)) { /* NAN */ } else { /* float<-inf, inf> */ }
    if ($f < NAN) { /* never */ }   // else 側は絞り込まない
};
/** @param float<0.0, 1.0> $u  @param float<-3.0, 1.0> $s */ function ($u, $s) {
    abs($u);  // float<0.0, 1.0>        (int) $u;  // int<0, 1>        -$s;  // float<-1.0, 3.0>
    if ($u !== 0.5) { /* float<[0.0, 0.5)>|float<(0.5, 1.0]> */ }
};
```

Draft は既存の期待値を 15 件変える。いずれも旧コメントが予告した方向。`bug-5309.php` には `// could be '0.0' when we support float-ranges`、`dependent-variables-type-guard-same-as-type.php` には `// could be Yes, but float type is not subtractable` とあった。`comparison-operators.php` は `float` から `float<(10.0, inf]>` になる。`TypeSpecifierTest` がコストを示す。`mixed` に対する `if ($n < 3)` は `mixed~(int<3, max>|true)` ではなく `mixed~(NAN|float<3.0, inf>|int<3, max>|true)` になる。

`FloatRangeType` は `CompoundType` を直接実装する `final` クラスで、1.9 の型リファクタリング以降の `ConstantArrayType` と `ArrayType` の関係と同じく `FloatType` を継承しない。`FloatType::isSuperTypeOf()`/`accepts()` は `CompoundType` の分岐経由でこのクラスに到達し、float 族は `isFloat()` で問う。レビューで見てほしい点が 2 つ残る。新しい型は `TypeCombinator`、`UnionType`、`UnionTypeHelper`、`FloatType`、`InitializerExprTypeResolver` で `instanceof FloatRangeType` により分岐している。`IntegerRangeType` が分岐している箇所と同じ（final クラスに対する表現の判定だが、具象型分岐ではある）。また `isSubTypeOf()` の `instanceof IntersectionType` に `phpstanApi.instanceofType` の baseline を 1 件足した。`IntegerRangeType` と同じ免除で、`CompoundType` 一般に委譲すると `IntegerType::isSuperTypeOf()` 経由で再帰する。両方の区間型を `Type` のメソッド経由にすべきなら、先にそれをやる。

含まないもの: 算術伝播、`positive-float`/`finite-float` の別名、`abs`/`sqrt`/`sin`/`Randomizer::getFloat`/`FILTER_VALIDATE_FLOAT` の戻り値型、PHP 8.5 のルールそのもの、定数としての `PHP_FLOAT_MAX`/`MIN`/`EPSILON`、`$x !== $x` の誤検知、#14394（`float === NAN`。配列内の NAN があるので別の変更）。

## 質問

1. `-inf` のために phpdoc-parser の lexer を変えてよいか。
2. `closed-open` キーワードを解析可能な形、括弧記法を表示専用とする。これでよいか、括弧記法も解析可能にするか。
3. エラーメッセージで `float<-inf, inf>` を `float` と区別してよいか。
4. 長くなった `mixed~(...)` の出力。
5. 範囲外の float→int キャストを、ホストの wrap 結果の定数ではなく素の `int` としてモデル化する。これでよいか（#14948 は int 全般のホスト／ターゲット区別の話）。
6. 新クラスの `@api` は今か後か。今は `@api` なしの `final`。どちらも後から開く分には後方互換。
7. `IntegerRangeType` と同じ `instanceof FloatRangeType` 分岐でよいか、先に `Type` のメソッドにするか。

<#PR_NAN> を先に入れたい。Draft はその先を示すためのもの。
