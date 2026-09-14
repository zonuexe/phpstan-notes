# 草稿: ブランチ `float-nan` の PR 本文（未提出・日本語訳）

対象: `phpstan/phpstan-src`、base `2.2.x`。原文は `20260822-pr-draft-float-nan.md`。タイトル:

> Separate NAN and the infinities from float: `is_nan()`/`is_finite()`/`is_infinite()` narrowing

---

https://github.com/phpstan/phpstan/issues/6963 の一部で、https://github.com/phpstan/phpstan/issues/15094 の土台。設計は <#6963 のコメントへのリンク>。完全版のプロトタイプは <#PR_RANGE> で、その先頭 3 コミットがこの PR。

NAN は何と比べても小さくも大きくも等しくもないので、どの順序集合にも含まれない。PHP 8.5 は NAN から string・bool・array への変換すべてと、表現不能な `(int)` キャストに警告を出す（[RFC](https://wiki.php.net/rfc/warnings-php-8-5)）。これをすべての `echo $float` に出さずに報告するには、PHPStan が「この float は NaN ではない」と言える必要がある。今は `is_nan($f)` は絞り込まず、`float ~ NAN` は表現できない（`NonRemoveableTypeTrait`）。

この PR は、NaN を含まない開閉境界付きの凸集合 `FloatRangeType` を追加し、`float` を `float<-inf, inf>|NAN` と読めるようにする。用途は一つ、NAN と無限大を残りから分離すること。

```php
function (float $f) {
    if (is_nan($f)) { /* NAN */ } else { /* float<-inf, inf> */ }
    if (is_finite($f)) { /* float<(-inf, inf)> */ } else { /* -INF|INF|NAN */ }
    if (is_infinite($f)) { /* -INF|INF */ } else { /* NAN|float<(-inf, inf)> */ }
    if ($f === INF) { /* INF */ } else { /* NAN|float<[-inf, inf)> */ }
    /* float */
};
function (int $i) { if (is_nan($i)) { /* never */ } }
```

コミット:

1. `ConstantFloatType` の NAN・INF・範囲外値のキャスト。`toInteger()`/`toString()`/`toBoolean()`/`toArrayKey()`/`toBitwiseNotType()` が PHPStan 内部で `(int) NAN` などを実行していたため、`(int) (0 * INF)` を畳み込むと PHPStan 自身が PHP 8.5 の警告を出していた。NAN と無限大は PHP の定義どおり 0 に。解析ホストの int 範囲外の値には定義された結果がない（PHP は wrap して警告する）ので素の `int` にする。64-bit ホストは前提にしない。
2. `IntegerRangeType::createAllSmallerThan()`/`createAllGreaterThanOrEqualTo()` は float を `PHP_INT_MAX` と比較していた。64-bit ビルドでは PHP がこれを `2^63` に変換するため、`2^63` に等しい float が `(int) ceil(2^63)` に到達していた。どの int 幅でも `PHP_INT_MAX` より大きい最初の float である `PHP_INT_MAX + 1.0` と比較するよう変更。
3. NaN の分離。`FloatType::tryRemove()` が `NonRemoveableTypeTrait` を置き換える。`FloatRangeType` は `CompoundType` を直接実装する `final` クラス（`FloatType` のサブクラスではない。`ConstantArrayType` と `ArrayType` の関係と同じ）で、集合代数と正準形を持つ（`(0.0, 1.0]` は `[5.0E-324, 1.0]` と同じ集合、`(0.0, 5.0E-324)` は空。隣接 double は 16-bit 語で計算するので 32-bit ビルドでも動く）。`TypeCombinator`/`UnionType`/`UnionTypeHelper` でのマージ（`float<-inf, inf>|NAN` は `float` に戻る）。`is_nan`/`is_finite`/`is_infinite` の拡張。

この PR に含まないもの: PHPDoc 構文（`toPhpDocNode()` は `float` に広げる）、比較絞り込み、素の `float` からの有限点の除去（`float ~ 0.0` は比較絞り込みがあって初めて役に立つ。単独だと既存の `0.0` 減算を通じて `$f > 10.0` が `NAN|float<[-inf, 0.0)>|float<(0.0, inf]>` と表示される）、PHP 8.5 のルール。

レビュー向けの補足:

- `is_finite()`/`is_infinite()` は `int|float` と分かっている引数だけ絞り込む。`is_finite("1")` と `is_infinite("1e500")` は実行時に真なので、`numeric-string|float` の引数はそのまま。`is_nan()` は任意の型を絞り込む。真になるのは NAN だけ。
- `instanceof FloatRangeType` は `TypeCombinator`、`UnionType`、`UnionTypeHelper`、`FloatType` に現れる。`IntegerRangeType` で分岐している箇所と同じ。クラスは `final` なので表現の判定であり、意味の問いは `isFloat()` を通す。`isSubTypeOf()` の `instanceof IntersectionType` に `phpstanApi.instanceofType` の baseline を 1 件追加。`IntegerRangeType` と同じ免除で、`CompoundType` 一般に委譲すると `IntegerType::isSuperTypeOf()` 経由で再帰する。両方の区間型を `Type` のメソッド経由にすべきなら、先にそれをやる。
- 既存の期待値は変わらない。新規テスト: `FloatRangeTypeTest`、`ConstantFloatTypeTest`、`IntegerRangeTypeTest`、nsrt `float-nan.php`、`TypeToPhpDocNodeTest`。
