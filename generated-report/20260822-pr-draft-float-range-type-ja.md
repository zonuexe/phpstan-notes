# 草稿: ブランチ `float-range-type` の PR 本文（Draft PR、未提出・日本語訳）

対象: `phpstan/phpstan-src`、base `2.2.x`、Draft として作成。原文は `20260822-pr-draft-float-range-type.md`。タイトル:

> [WIP] Float ranges: `float<a, b>`, comparison narrowing, finite-point removal

---

https://github.com/phpstan/phpstan/issues/6963 のプロトタイプ（設計は <#6963 のコメントへのリンク>）。先頭 3 コミットは <#PR_NAN> なので、レビューはそちらで。この PR は 4 番目のコミットが対象で、<#PR_NAN> がマージされたら rebase する。

4 番目のコミットが足すもの:

- PHPDoc: `float<a, b>`（閉）、非有界側の `min`/`max`/`inf`（phpdoc-parser は `-inf` を字句解析できない。#13504 も同じ壁）、PHP 8.3 の `Random\IntervalBoundary` の語彙による省略可能な境界キーワード: `float<0.0, 1.0, closed-open>`、`float<min, max, open-open>`（有限）。`toPhpDocNode()` はこの形を出力し往復する。`describe()` は `float<[0.0, 1.0)>` を印字する。
- 比較絞り込み: `ConstantNumericComparisonTypeTrait` の 4 箇所の `// subtract range when we support float-ranges`。`$f > 0.0` は `float<(0.0, inf]>`、偽側は `NAN|float<-inf, 0.0>`。偽側は NAN を保持し、相手のオペランドが NAN でありうるときは絞り込まない（`!($f < NAN)` はすべての `$f` で成り立つ）。右辺が区間なら正準境界で絞り込む。
- `float ~ 0.0` は `NAN|float<[-inf, 0.0)>|float<(0.0, inf]>`。`$f !== 1.0` でガードした変数が確定するようになる。
- `negate()`、正準境界での `toInteger()`（`(0.0, 1.0)` の `(int)` は `0`、`(1.0, 2.0)` は `1`）、union のメンバごとの単項マイナス。

期待値の変更: 15 テスト。いずれも旧コメントが予告した方向。`bug-5309.php` には `// could be '0.0' when we support float-ranges` とあった。`comparison-operators.php` は `float` から `float<(10.0, inf]>` に、`TypeSpecifierTest` は `mixed~(int<3, max>|true)` から `mixed~(NAN|float<3.0, inf>|int<3, max>|true)` になる。長くなった `mixed~(...)` への意見がほしい。

含まないもの: 算術伝播、`positive-float`/`finite-float` の別名、`abs`/`sqrt`/`sin`/`Randomizer::getFloat`/`FILTER_VALIDATE_FLOAT` の戻り値型、PHP 8.5 のルール、定数としての `PHP_FLOAT_MAX`/`MIN`/`EPSILON`、`$x !== $x`、#14394。

未解決の論点は #6963 のコメントにある。
