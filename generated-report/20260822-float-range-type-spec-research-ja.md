# PHPStan float range 型 — 仕様提案のための調査ノート

- 日付: 2026-08-22
- 対象 issue:
  - [phpstan/phpstan#6963 Add range for float](https://github.com/phpstan/phpstan/issues/6963)（2022-04, feature-request, open）
  - [phpstan/phpstan#15094 Report potential implicit `NAN` string casting in PHP 8.5+](https://github.com/phpstan/phpstan/issues/15094)（2026-08-21, open）
- 前提資料: `20260822-range-syntax-survey-ja.md`（英訳: `20260822-range-syntax-survey.md`）（同ディレクトリ、以下「予備調査」）
- 検証環境: PHP 8.5.9 (homebrew) / phpstan-src `2.2.x` @ `79a31bb78` / phpdoc-parser 2.3.3
- 本ノートの位置づけ: issue / PR を書く前の「事実の確認」と「設計空間の整理」。仕様は **ドラフト** であり、最終判断は Ondřej に委ねる論点を §9 に分離した。

---

## 0. TL;DR

1. **float の区間型は「NaN を含まない」型として定義するのが筋がよい。** PHP の float 値集合は「全順序集合 O（−INF … +INF、−0.0 と 0.0 は同一点）」と「NaN」の直和であり、区間は O の部分集合としてしか定義できない。したがって `float<-inf, inf>` は「NaN 以外のすべての float」を意味し、`float` ≡ `float<-inf, inf> | NAN` となる。#15094 が求める「`is_nan()` で `float~NAN` に絞る」はこの帰結として自然に得られる。
2. **開区間・閉区間の区別は必須。** 理由は 3 つ: (a) 後続値が存在しないので点の除去（`float ~ 0.0`）が半開区間でしか表現できない、(b) `$f > 0.0` の絞り込み結果が開区間、(c) IEEE の ±INF は順序上の実在値なので「`-inf` で開」＝「有限」という意味が生じ、`finite-float` が区間として表現できる。`int<1, 5>` は `n±1` で閉区間に正規化できたが float にはそれがない（Ondřej の「I would not start by copying IntegerRangeType」の形式的な中身）。 ただし double は有限集合で後続値 `next_up` が存在するため、開区間は集合としては閉区間に正準化できる（§6.6）。開閉フラグは表記のために保持し、代数は正準閉区間で行うのが筋。
3. **境界キーワードは `min`/`max` ではなく `-inf`/`inf` にする。** `PHP_FLOAT_MIN` は「最小の正の正規化数 (2.2e-308)」であり、しかも subnormal（5e-324 など）はそれより小さい。「min」は二重に誤解を招く。ただし現行 phpdoc-parser は `-inf` を字句解析できない（§7.1）。
4. **mvorisek の「0.1 は正確に表現できないのに開区間に意味はあるのか」は、型を「double の集合」として定義すれば解消する。** PHPDoc の `0.1` は PHP の `0.1` リテラルと同じ double（最近接値）に解決され、区間は「その double との比較で決まる double の集合」。実数は一切登場しない。
5. **算術伝播は IEEE 754 の単調性により端点計算だけで健全**（外向き丸め不要）。ただし開境界は算術を通すと閉じる（吸収）、±INF 同士の演算は NaN を生む、という 2 点の規則が要る。
6. 現行 PHPStan には `if ($f !== $f)` を「常に false」と報告し分岐内を `*NEVER*` にする NaN 起因の誤検知がある（§3.3）。区間型導入時に同時に直すべき。
7. 推奨する導入順序: **Phase 1** = 型クラス + 閉区間 PHPDoc 構文 + `is_nan`/比較絞り込み + #15094 のルール、**Phase 2** = 開区間構文（phpdoc-parser 変更を伴う）+ 算術伝播 + 組み込み関数の戻り値、**Phase 3** = `finite-float` 等の名前付き別名と PHP 8.5 の `(int)` キャスト警告ルール（§8）。

---

## 1. 動機となっている issue 群

| issue | 要旨 | 区間型との関係 |
|---|---|---|
| [#6963](https://github.com/phpstan/phpstan/issues/6963) | `ceil()` が範囲を失う。float にも range が欲しい | 本体。Ondřej: 「IntegerRangeType をコピーするな。`float<1, 5>` は無限個」「開閉区間の違いも検討せよ」 |
| [#15094](https://github.com/phpstan/phpstan/issues/15094) | PHP 8.5 で NaN→string 強制変換が Warning。`is_nan()` ガード無しの `"{$f}"` を報告したい。提案者自身が `FloatRangeType` で `float~NAN` を表すのが筋と述べている | 「NaN を含まない float」を型で表せることが前提 |
| [#13859](https://github.com/phpstan/phpstan/issues/13859) | `positive-float` / `non-negative-float` が欲しい。mvorisek: 非有限値での定義を明確に | `float<(0.0, inf]` 等の別名。INF を含むかの議論 |
| [#9250](https://github.com/phpstan/phpstan/issues/9250) | 科学計算向けに NaN / Inf / finite を型で表したい | `finite-float` = `float<(-inf, inf)>` |
| [#13504](https://github.com/phpstan/phpstan/issues/13504) | `@phpstan-assert-if-false NAN\|INF\|-INF` が `-INF` で構文エラー。`float<-INF, INF>` も `!NAN` も書けない。`infinite` / `finite` 型が欲しい | 字句解析の `-` 問題（§7.1）、finite の表現 |
| [#14394](https://github.com/phpstan/phpstan/issues/14394) | `float === NAN` は常に false と報告すべき。VincentLanglet: 同一変数の `NAN === NAN` は always true と誤報告 | NaN の同一性の扱い。PR [#5332](https://github.com/phpstan/phpstan-src/pull/5332) はクローズ |
| [#11465](https://github.com/phpstan/phpstan/issues/11465) | float 条件のループが最低 1 回回ることを検出したい | 比較による絞り込みの結果型 |
| [#12865](https://github.com/phpstan/phpstan/issues/12865) | `float / float` も `DivisionByZeroError` を投げうるのに `catch.neverThrown` | 0 を含まない区間なら投げないと証明できる |
| [#9182](https://github.com/phpstan/phpstan/issues/9182) | 整数値であることが保証された float | 区間とは直交（accessory 型の話）。対象外と明記する |

Psalm 側: [vimeo/psalm#5533 positive-float](https://github.com/vimeo/psalm/issues/5533) は orklah が「positive-int は count()/offset 等で自然に現れるが float は違う。float は丸めの問題があり SA で有意義な検査ができない。範囲を float に実装する気はない」としてクローズ。Psalm の型構文ドキュメントにも float の細分型は存在しない。**PHPStan が導入すれば PHP エコシステム初**になる。

---

## 2. PHP の float 意味論 — 実機で確認した事実（PHP 8.5.9）

仕様を書く前に「PHP がどう振る舞うか」を固定する。以下はすべて本ノート作成時に実行して確認した。

### 2.1 NaN の比較

| 式 | 結果 |
|---|---|
| `NAN == NAN`, `NAN === NAN` | `false` |
| `NAN != NAN` | `true` |
| `NAN < 1.0`, `NAN > 1.0`, `NAN <= NAN`, `NAN >= NAN` | すべて `false` |
| `NAN <=> 1.0`, `1.0 <=> NAN`, `NAN <=> NAN` | すべて `1` |
| `NAN == 0`, `NAN == null`, `NAN == false`, `NAN == "abc"` | `false` |
| `NAN == true` | **`true`**（bool 比較は `(bool)NAN === true` で行われる） |
| `in_array(NAN, [NAN])`, `in_array(NAN, [NAN], true)`, `array_search(NAN, [NAN])` | `false` |
| `min(NAN, 1.0)` / `min(1.0, NAN)` | `1.0` / **`NAN`**（引数順依存） |
| `max(NAN, 1.0)` / `max(1.0, NAN)` | `1.0` / `NAN` |
| `sort([3.0, NAN, 1.0])` | `[NAN, 1.0, 3.0]`（順序は非決定的に見える） |

→ **比較演算子の truthy 側には NaN は決して入らない。falsy 側には NaN が入りうる。** これが絞り込み規則（§7.6）の根拠。

### 2.2 ±INF、−0.0、境界定数

| 式 | 結果 |
|---|---|
| `INF > PHP_FLOAT_MAX`, `-INF < -PHP_FLOAT_MAX`, `PHP_FLOAT_MAX < INF` | `true` — **±INF は順序上の実在値** |
| `INF == INF`, `INF === INF`, `INF <=> INF` | `true`, `true`, `0` |
| `PHP_FLOAT_MAX + PHP_FLOAT_MAX`, `PHP_FLOAT_MAX * 2`, `1e309`, `"1e500" + 0` | `INF`（有限同士の演算でも INF は生まれる） |
| `INF - INF`, `0.0 * INF`, `array_sum([INF, -INF])` | `NAN`（INF を含む区間の算術は NaN を生みうる） |
| `PHP_FLOAT_MIN` | `2.2250738585072014E-308`（**最小の正の正規化数**） |
| `PHP_FLOAT_MIN / 2` | `1.1125369292536007E-308`、`> 0` は `true`（subnormal は PHP_FLOAT_MIN より小さい） |
| `5e-324`, `5e-324 / 2` | `5.0E-324`, `0.0` |
| `-0.0 === 0.0`, `-0.0 == 0.0`, `-0.0 <=> 0.0`, `-0.0 < 0.0` | `true`, `true`, `0`, `false` — **順序上は同一点** |
| `(string) -0.0` / `(string) 0.0` | `'-0'` / `'0'`（文字列化では区別される。PHPStan は既に `0.0->toString()` を `'0'\|'-0'` にしている） |
| `fdiv(1, -0.0)` / `fdiv(1, 0.0)` | `-INF` / `INF` |
| `round(-0.4)`, `ceil(-0.5)`, `floor(-0.0)`, `sqrt(-0.0)`, `0.0 * -1` | いずれも `-0.0` |
| `is_nan(INF)`, `is_finite(NAN)`, `is_infinite(NAN)`, `is_finite(INF)` | `false`, `false`, `false`, `false` |
| `sqrt(-1.0)`, `log(-1.0)`, `asin(2.0)`, `sin(INF)`, `fmod(1, 0)`, `fdiv(0, 0)` | `NAN` |
| `log(0.0)` | `-INF` |
| `atan(INF)` | `1.5707963267948966`（= `fl(π/2)`、有限値） |

### 2.3 int と float の比較

| 式 | 結果 |
|---|---|
| `1.0 === 1` / `1.0 == 1` | `false` / `true` |
| `(float) PHP_INT_MAX` | `9.223372036854776E+18`（= 2^63、PHP_INT_MAX より大きい double） |
| `PHP_INT_MAX == (float) PHP_INT_MAX`, `(float) PHP_INT_MAX > PHP_INT_MAX` | `true`, `false` |
| `9007199254740993 == 9007199254740992.0`, `<`, `>` | `true`, `false`, `false` |

→ **PHP の int–float 比較は int を double に変換してから行う。** 区間の境界に int を使うときは `(float)` した値が PHP の比較結果と一致するので、外向き丸めは不要（§7.8）。

### 2.4 PHP 8.5 の新しい警告（[RFC: Warnings for PHP 8.5](https://wiki.php.net/rfc/warnings-php-8-5)）

RFC の投票結果: NaN 強制変換の Warning は 20–0 で採択、非表現可能 float→int は 17–0 で採択。PHP 9 以降で Error への昇格予定。実機での観測:

| 操作 | 結果 | 診断 |
|---|---|---|
| `(string) NAN`, `"{$nan}"`, `echo $nan`, `strval(NAN)`, `settype($nan, 'string')` | `'NAN'` | **Warning: unexpected NAN value was coerced to string** |
| `NAN . ""`, `sprintf('%s', NAN)`, `var_export(NAN)` | `'NAN'` | 警告なし（8.5.9 で観測。連結は警告されない） |
| `(string) INF`, `(string) -INF` | `'INF'`, `'-INF'` | 警告なし |
| `(bool) NAN`, `if (NAN)`, `!NAN` | `true` / truthy / `false` | **Warning: unexpected NAN value was coerced to bool** |
| `NAN && true` | `true` | 警告なし（8.5.9 で観測） |
| `(array) NAN` | `[NAN]` | Warning: unexpected NAN value was coerced to array |
| `(int) NAN`, `(int) INF`, `(int) 1e19`, `$a[NAN]`, `NAN % 2`, `sprintf('%d', NAN)` | `0`, `0`, `-8446744073709551616`, … | **Warning: The float NAN is not representable as an int, cast occurred**（INF / 範囲外も同文面） |
| `$a[1.5]`, `5.5 % 2` | | Deprecated: Implicit conversion from float 1.5 to int loses precision（8.1 から） |
| `json_encode(NAN)` | `false` | `Inf and NaN cannot be JSON encoded` |

→ #15094 の直接対象は 1 行目だが、**bool / array / int への強制変換、INF や範囲外 float の `(int)` キャストも同じ構造のルールになる。** 「NaN を含まない」「±INF を含まない」「`[PHP_INT_MIN, PHP_INT_MAX]` に収まる」を型で言えれば全部同じ仕組みで報告できる。

### 2.5 PHP 本体にある「境界の開閉」の前例

- **`Random\IntervalBoundary`（PHP 8.3）**: `ClosedOpen`（既定）, `ClosedClosed`, `OpenClosed`, `OpenOpen` の 4 ケース。`Random\Randomizer::getFloat(float $min, float $max, IntervalBoundary $boundary = ClosedOpen): float`。`getFloat(1.0, 1.0, OpenOpen)` は `ValueError: $max must be greater than $min`、`getFloat(NAN, 1.0)` / `getFloat(0.0, INF)` は `ValueError: must be finite`。`nextFloat()` は `[0.0, 1.0)`。
- **`lcg_value()`**（8.4 で deprecated）: ドキュメント上 `(0, 1)`。
- **`filter_var($s, FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => 0.0, 'max_range' => 1.0]])`**（7.4〜）: 閉区間。`"1.0"` は通り `"1.5"` は `false`。`"NAN"`, `"INF"`, `"1e400"` は `false`。
- `mt_rand() / mt_getrandmax()` という古典的イディオム → `[0.0, 1.0]`。

**PHP 自身が float 区間の 4 種の境界を語彙として持っている**ことは、PHPDoc 構文の語彙選定（§7.1）で強い根拠になる。

---

## 3. PHPStan の現状

### 3.1 `dumpType()` プローブ結果（level 9、`2.2.x`）

```php
function f(float $f, int $i, mixed $m): void {
    dumpType(NAN);               // NAN
    dumpType(INF);               // INF
    dumpType(-INF);              // -INF
    dumpType(PHP_FLOAT_MAX);     // float   ← 定数型にならない（ConstantResolver は PHP_FLOAT_DIG のみ特別扱い）
    dumpType(PHP_FLOAT_MIN);     // float
    dumpType(PHP_FLOAT_EPSILON); // float
    dumpType(-0.0);              // -0.0
    dumpType(1e308 * 10);        // INF     ← 定数畳み込みは PHP と同じ double 演算
    dumpType(INF - INF);         // NAN
    dumpType(PHP_INT_MAX + 1);   // 2147483648|9.223372036854776E+18（32/64bit 両対応の union）
    dumpType(abs($f));           // float   ← 非負にならない
    dumpType($f * $f);           // float
    dumpType($i / 2);            // (float|int)
    dumpType(range(0.0, 1.0, 0.25)); // array{0.0, 0.25, 0.5, 0.75, 1.0}

    if (is_nan($f))      { dumpType($f); } else { dumpType($f); }  // float / float  ← 絞り込みなし
    if (is_finite($f))   { dumpType($f); } else { dumpType($f); }  // float / float
    if (is_infinite($f)) { dumpType($f); } else { dumpType($f); }  // float / float
    if ($f > 0.0)        { dumpType($f); } else { dumpType($f); }  // float / float
    if ($f === 0.0)      { dumpType($f); } else { dumpType($f); }  // 0.0 / float   ← 点の除去ができない
    if ($f === INF)      { dumpType($f); } else { dumpType($f); }  // INF / float
    if ($f === NAN)      { dumpType($f); }                         // NAN（ただし identical.alwaysFalse が出る）
    if ($m > 0.5)        { dumpType($m); }   // mixed~(0.0|bool|int<min, 0>|null)
    if ($m < 0.5)        { dumpType($m); }   // mixed~(int<1, max>|true)
    if ($f)              { dumpType($f); } else { dumpType($f); }  // float / 0.0
}
```

`NAN === NAN` → `identical.alwaysFalse`、`NAN == NAN` → `equal.alwaysFalse`、`NAN < 1.0` → `smaller.alwaysFalse`、`-0.0 === 0.0` → `identical.alwaysTrue`、`0.1 + 0.2 == 0.3` → `equal.alwaysFalse` は既に正しく報告される（定数同士なら PHP と同じ double 演算で評価しているため）。

### 3.2 コード内に残る「float range 待ち」の TODO

- `src/Type/Traits/ConstantNumericComparisonTypeTrait.php`: `getSmallerType()` 等で `new ConstantFloatType(0.0), // subtract range when we support float-ranges` が 3 箇所、`// subtract range when we support float-ranges` が 1 箇所。`$m > 0.5` の結果 `mixed~(0.0|bool|int<min, 0>|null)` に `0.0` だけ混ざっているのはこのため。
- `src/Type/Php/FilterFunctionReturnTypeHelper.php:292-294`: `// PHPStan does not yet support FloatRangeType` `// 'FILTER_VALIDATE_FLOAT' => ['min_range', 'max_range'],`
- `src/Type/FloatType.php`: `NonRemoveableTypeTrait` を use → `float ~ 0.0` / `float ~ NAN` は表現不能。`UndecidedComparisonTypeTrait` → `isSmallerThan()` 系は常に maybe。
- `src/Type/Constant/ConstantFloatType.php`: `equals()` は `$this->value === $type->value || (is_nan both)` — **`-0.0` と `0.0` は equals、NaN 同士も equals**（型としての同一性は値の同一性と違う、という判断が既にある）。`getFiniteTypes()` は NaN なら `[]`。
- `src/Type/FiniteTypeSet.php:95`: 「float は `equals()` が値の同一性と一致しない（`-0.0 === 0.0`, `NAN !== NAN`）ので有限集合キーから除外」。
- `resources/functionMap.php`: `abs` → `float|0|positive-int`、`sin`/`cos`/`atan`/`sqrt`/`exp` 等はすべて素の `float`。`Random\Randomizer::getFloat` のエントリなし（リフレクションの `float` のまま）。

### 3.3 NaN 起因の既存誤検知（区間型と同時に直すべきもの）

```php
function g(float $f): void {
    if ($f !== $f) {       // notIdentical.alwaysFalse: "Strict comparison using !== between float and float will always evaluate to false."
        dumpType($f);      // *NEVER*
    }
    if ($f == $f) {        // equal.alwaysTrue
    }
}
```

`$x !== $x` は NaN 検出の古典イディオムで、`float` には NaN が含まれうるので always false ではない。`float<-inf, inf>`（NaN を含まない）に対してなら always false が正しい。つまり **「同一式の `===` は常に true」という規則は『型が NaN を含みうるか』で条件付けする必要がある。** これは #14394 での VincentLanglet の指摘（同一変数の `NAN === NAN` が always true と出る）と同根。

### 3.4 `IntegerRangeType` の構造（比較対象として）

- `private function __construct(private ?int $min, private ?int $max)` — `null` が非有界。`fromInterval()` で `min > max` → `never`、`min === max` → `ConstantIntegerType`、両方 `null` → `IntegerType`、`PHP_INT_MIN`/`PHP_INT_MAX` に達した片側非有界 → 定数に潰す。
- `isDisjoint($touchingIsDisjoint)` のオフセット `+1`、`tryRemove()` の `removeMin - 1` / `removeMax + 1`、`shift()`、`getFiniteTypes()`（`CALCULATE_SCALARS_LIMIT` 以下なら列挙）、`isSubTypeOfUnionWithReason()`（union 内の定数が区間を埋め尽くすか数える）— **いずれも「後続値」と「有限個」に依存**。float には移植できない。
- `src/` 内 79 ファイル・約 300 箇所が `IntegerRangeType` を参照（`InitializerExprTypeResolver` 34、`BinaryOpHandler` 23、`MutatingScope` 18、`TypeCombinator` 7 …）。float range も同程度の波及を覚悟する必要がある。`TypeCombinator::union()` には `IntegerRangeType` 専用の並び替え・結合ロジック（L340–373, L558–572, L1861）がある。
- `#[ShadowedByTurboExtension]` が付いているのは `TypeCombinatorCache` のみ。`IntegerRangeType` / `FloatType` / `TypeCombinator` 本体に C++ ミラーはない（確認済み）。

---

## 4. 先行議論の整理

| 発言者 | 主張 | 本ノートの対応 |
|---|---|---|
| VincentLanglet (#6963) | `IntegerRangeType` のコピーで簡単に見えるが、`float<0.0, 10.0>` から `0.0` を除外する構文がない。`[a,b]`, `]a,b]`, `[a,b[`, `]a,b[` の 4 種が必要 | §6.2, §7.1 |
| ondrejmirtes (#6963) | `IntegerRangeType` のコピーから始めるな。float は無限個。開閉区間も検討せよ | §6.1 で形式化 |
| mvorisek (#6963) | `0.1` は正確に表せない。`(0.1, 0.2)` は「最近接の 0.1」を除外するのか、それが 0.1 より大きくても？ | §6.4: 型は double の集合として定義、実数は登場しない |
| VincentLanglet (#6963) | 主用途は #13859 の `positive-float` = `]0, max[` | §7.11 |
| mvorisek (#13859) | `non-negative-float` は非有限値に対して well-defined であるべき、さもなくば導入すべきでない | §7.11 で INF の包含を明示 |
| mind-bending-forks (#13859) | `non-negative-float` は `[0, ∞)` に INF を含めるべき。`PHP_FLOAT_MAX + PHP_FLOAT_MAX = INF` なので閉じていてほしい | 同上。閉区間 `float<0.0, inf>` は INF を含む |
| bea4dev (#15094) | ルール専用の ad-hoc ヘルパより `FloatRangeType` で `float~NAN` を表す方が根本的 | 本ノートの出発点 |
| orklah (Psalm #5533) | float の範囲は SA で有意義でない、実装しない | §5.3 で反論材料を整理 |
| ondrejmirtes (#10297) | NaN の `accepts`/`isSuperTypeOf` は `ConstantFloatType` で直す → PR #3036 | NaN の型としての同一性は既に「equals」側に倒れている |

既にマージ済みの NaN 関連 PR: [#3036 Fix NAN not accepting NAN](https://github.com/phpstan/phpstan-src/pull/3036)、[#4040 Improve NAN inferences](https://github.com/phpstan/phpstan-src/pull/4040)（`0 * INF` → `NAN`）、[#4368 Fix PHP8.5 Warning "unexpected NAN value was coerced to string"](https://github.com/phpstan/phpstan-src/pull/4368)（PHPStan 自身のコード修正）、[#5321 `[NAN] === mixed`](https://github.com/phpstan/phpstan-src/pull/5321)。[#5332](https://github.com/phpstan/phpstan-src/pull/5332)（`float === NAN` を always false に）はレビュー途中でクローズ。

---

## 5. 他言語・他システムの前例（予備調査の要約 + 追加調査）

### 5.1 予備調査からの要点

- **Dijkstra EWD831** の半開区間原則は「離散かつ 0 起点のインデックス」の文脈。連続値には「上限 −1」が定義できないので、Kotlin は `until`（`a..(b-1)` への変換）の限界から `..<`（`OpenEndRange`、1.9）へ移行し、Swift は最初から `...` / `..<` を任意の `Comparable` に適用、Rust は `..` / `..=`。
- **Boost.ICL** は `discrete_interval` と `continuous_interval` を型レベルで分け、連続型には `right_open_interval` を既定とする。
- **PostgreSQL** は `int4range` は `[1,10]` → `[1,11)` に正規化（canonical function）するが、`numrange` は正規化しない（後続値がないため）。本提案の「int は閉区間で正規化、float は開閉を保持」と完全に同型。
- **Julia IntervalSets.jl** は `iv"[1, 5)"` 等の数学記法を文字列マクロで提供。**IEEE 1788** は外向き丸めと decoration（COM/DAC/DEF/TRV/ILL）。
- **Zod / ArkType / Valibot** は `gt`/`gte`/`lt`/`lte` で開閉を表現。
- **Rust `f32`/`f64`** は `Ord`/`Eq` を実装しない（NaN）。

### 5.2 追加で押さえた前例

| 系 | 仕組み | 開閉 | NaN / ∞ の扱い | 本提案への示唆 |
|---|---|---|---|---|
| **PHP `Random\IntervalBoundary`** (8.3) | `ClosedOpen`/`ClosedClosed`/`OpenClosed`/`OpenOpen` | 4 種 | `$min`/`$max` は有限必須、`min < max` 必須（`OpenOpen` で `min == max` は ValueError） | PHP 開発者が既に知っている語彙。`float<0.0, 1.0, closed-open>` 案の根拠 |
| **JSON Schema** (draft-06+) / OpenAPI 3.1 | `minimum`/`maximum`/`exclusiveMinimum`/`exclusiveMaximum` | 4 種 | JSON に NaN/Inf はない | 「閉が既定、開は明示」 |
| **Symfony Validator** | `Range(min, max)` は閉、`Positive` (>0) / `PositiveOrZero` (≥0) / `Negative` / `NegativeOrZero` / `GreaterThan(OrEqual)` / `LessThan(OrEqual)` | 4 種 | — | `positive` = 0 を含まない、`-or-zero` = 含む、という PHP 界の命名慣習 |
| **Laravel validation** | `between:a,b`（閉）、`gt`/`gte`/`lt`/`lte`/`min`/`max` | 4 種 | — | 同上 |
| **Kotlin** | `0.0..1.0` → `ClosedFloatingPointRange<Double>`、`0.0..<1.0` → `OpenEndRange<Double>` | 閉 / 右半開 | `contains(NaN)` は比較が false なので `false` | 区間は NaN を含まない |
| **Swift** | `Range<Double>` (`..<`)、`ClosedRange<Double>` (`...`)、`PartialRangeFrom` (`0.0...`)、`PartialRangeUpTo` (`..<1.0`) | 閉 / 右半開 + 片側無限 | `contains(.nan)` は `false` | 片側非有界は「開/閉 at ∞」で統一できる |
| **Rust** | range pattern `0.0..=1.0` は float 可。定数パターンに NaN を使うとコンパイルエラー（`cannot use NaN in patterns`） | `..` / `..=` | NaN はパターンとして拒否 | 「NaN は区間の外」を言語が明示した例 |
| **Ada** | `type T is digits 8 range -1.0e10 .. 1.0e10;` — float 型に閉区間制約を付け `Constraint_Error` で検査 | 閉のみ | IEEE の NaN/∞ は型の値域外（`'Valid` で検査） | 静的型に float 閉区間を持つ数少ない前例 |
| **IEEE 754-2008 `totalOrder`** | NaN を含む全順序（−NaN < −∞ < … < +∞ < +NaN） | — | NaN に順序を与える | PHP の比較演算子はこれを使わない。採用しない理由として言及 |

### 5.3 「float の範囲は SA で有意義でない」（orklah）への反論材料

1. 用途は「数値計算の証明」ではなく **「NaN / INF / 0 / 範囲外を除外したことの追跡」**。PHP 8.5 の警告群（§2.4）は正にそれを要求している。
2. 区間の端点は PHP と同じ double 演算で計算されるので、丸め誤差は「型が嘘をつく」方向には働かない（§7.8 の単調性）。
3. `sin`/`cos` ∈ `[-1, 1]`、`abs`/`sqrt`/`exp` ≥ 0、`atan` ∈ `[-π/2, π/2]`、`nextFloat()` ∈ `[0, 1)`、`filter_var(FLOAT, min_range/max_range)` 等、**PHP コアに区間を返す関数は十分ある**（§7.12）。
4. 既に `ConstantFloatType` は定数 float の算術・比較を PHP と同じ精度で畳み込んでいる。区間型はそれの「集合版」でしかない。

---

## 6. 設計の核心

### 6.1 値集合のモデル

PHP の `float` 値の集合 F を次のように分解する:

- **O** = IEEE binary64 のうち NaN 以外の値。PHP の `<`, `<=`, `==`, `<=>` によって**全順序**をなす。ただし `-0.0` と `0.0` は `===` / `<=>` で同一（§2.2）なので **順序上は 1 点として扱う**。両端は `-INF` と `INF` で、これらは O の要素（最小元・最大元）である。
- **NaN** = O のどの要素とも比較不能な 1 つの値（PHP からはペイロードの区別は見えない。`ConstantFloatType::equals()` も NaN 同士を equals としている）。

F = O ⊔ {NaN}。**区間型は O の凸部分集合**として定義する。したがって:

- `float<-inf, inf>`（両端閉）= O = 「NaN 以外のすべての float」
- `float` = `float<-inf, inf> | NAN`
- どの区間型も NaN を含まない。`is_nan()` の偽側は「`float` から NaN を引く」= `float<-inf, inf>`。

`int` と決定的に違うのは、**`int<min, max>` は `int` に正規化されるが、`float<-inf, inf>` は `float` に正規化されない**こと。ここで「IntegerRangeType をコピーするな」が効いてくる。

### 6.2 なぜ開閉区間が必要か（3 つの独立した理由）

1. **点の除去**: `float<0.0, 10.0>` から `0.0` を除くと `float<(0.0, 10.0]`。int なら `int<1, 10>` と書けたのは後続値 `0 + 1` があるから。float には後続値がない（正確には `nextafter` で次の double はあるが、`float<4.9e-324, 10.0>` は人間にもルールにも扱えない）。`tryRemove()` を実装するには開境界が内部表現として必須。
2. **比較による絞り込み**: `if ($f > 0.0)` の真側は `float<(0.0, inf]`。閉区間しかないと `float<0.0, inf>` に丸めるしかなく、`0.0` を除外した事実（ゼロ除算が起きない、`log($f)` が `-INF` にならない）が失われる。
3. **無限大の除外 = 有限性**: `-INF`/`INF` は O の要素なので、`-inf` で開 ＝ `-INF` を含まない。よって `float<(-inf, inf)>` = 有限 float = `is_finite()` の真側。int には「PHP_INT_MAX の外」が存在しないのでこの概念自体がなかった。

結論: **IEEE float にとって「∞ で開」は数学の「∞ は常に開」とは違い、実質的な意味を持つ**。`float<-inf, inf>`（閉）と `float<(-inf, inf)>`（開）は異なる型であり、前者が「非 NaN」、後者が「有限」。

### 6.3 `min`/`max` ではなく `-inf`/`inf`

- `PHP_FLOAT_MIN` = 2.2e-308（最小の正の正規化数）。`float<min, max>` を `int<min, max>` の類推で読んだ人は `[-PHP_FLOAT_MAX, PHP_FLOAT_MAX]` か `[PHP_FLOAT_MIN, PHP_FLOAT_MAX]` のどちらを想像するか分からない。
- さらに subnormal（`5e-324`）は `PHP_FLOAT_MIN` より小さい正の値なので、`PHP_FLOAT_MIN` は「正の float の下限」ですらない。
- `INF`/`-INF` は PHP の定数として実在し、`dumpType(INF)` も `INF` と表示する。`-inf`/`inf` は値の名前であり、記号の選択に迷いがない。
- 他方、`int<min, max>` の `min`/`max` は「型が取りうる最小/最大」という意味では正しく、float でも「`-INF` / `INF` が取りうる最小/最大」なので、意味論上は `min` ≡ `-inf` と定義することも可能。**採用しない理由は専ら混乱回避**。`float<min, max>` を受理するなら `-inf`/`inf` のエイリアスとしてであり、`describe()` は常に `-inf`/`inf` を出す。

### 6.4 mvorisek の懸念への回答 — 型は「double の集合」

- PHPDoc の `0.1` は phpdoc-parser で `ConstExprFloatNode('0.1')` になり、PHPStan は `(float) '0.1'` で double に変換する。これは PHP のリテラル `0.1` と同じ `zend_strtod` による最近接 double（`0.1000000000000000055511151231257827…`）。
- `float<(0.1, 0.2)>` は「`fl(0.1) < d < fl(0.2)` を満たす double d の集合」。境界点は double そのものであり、「真の 0.1」は登場しない。
- ランタイムの `$f > 0.1` も同じ double 同士の比較なので、型と実行が一致する。**実数の近似という問題は、型を「実数の区間」だと思うことから生じる。「double の区間」だと定義した瞬間に消える。**
- 表示は `ConstantFloatType::castFloatToString()` と同じ `precision=-1`（最短往復表現）を使えば `0.1` のまま往復する。

### 6.5 IntegerRangeType との差分一覧（「コピーしない」の具体）

| 観点 | IntegerRangeType | FloatRangeType（提案） |
|---|---|---|
| 非有界の表現 | `null` | `-INF` / `INF` という実値（null 不要） |
| 境界の開閉 | なし（閉のみ） | 各端に inclusive フラグ |
| `fromInterval(全域)` | `IntegerType` に正規化 | `float<-inf, inf>` のまま（NaN を含まないので `FloatType` ではない） |
| 単点 | `min === max` → 定数 | `min == max` かつ両端閉 → `ConstantFloatType`; 片方でも開 → `never` |
| 空 | `min > max` | `min > max`、または `min == max` で片方が開 |
| 隣接の union 結合 | `max + 1 == otherMin` で結合 | 端点が一致し、**少なくとも一方が閉**なら結合（`[a,b) ∪ (b,c]` は b に穴） |
| `tryRemove(点)` | `n-1` / `n+1` で 2 分割 | 開境界で 2 分割 `[a,p) ∪ (p,b]` |
| `getFiniteTypes()` | 列挙可能 | 常に `[]` |
| `shift()` | あり | なし（算術は §7.8） |
| `isSubTypeOfUnionWithReason` | union 内の定数を数える | 不要（区間を定数で埋め尽くせない） |
| 比較の falsy 側 | 補集合 | 補集合 **∪ NaN**（対象型が NaN を含みうる場合） |
| 算術の境界 | 整数演算、overflow は float へ | double 演算、開境界は閉じる、±INF 混在で NaN |
| `accepts(int)` | — | `FloatType` と同様 int を受理（`IntegerRangeType` は `(float)` 変換して判定） |
| `toInteger()` | 自身 | 0 方向切り捨てで `IntegerRangeType`、`[PHP_INT_MIN, PHP_INT_MAX]` 外は警告対象 |
| `toBoolean()` | 0 を含むか | 0.0 を含むか（NaN は区間外なので考慮不要） |

---

### 6.6 追記: double は有限集合である — 開区間の「正準化」

PHP 8.5 で `pack('d')`/`unpack('q')` により隣接 double を求めて確認した:

| 事実 | 値 |
|---|---|
| `next_up(0.0)` | `5.0E-324`（最小 subnormal） |
| `next_up(PHP_FLOAT_MAX)` | `INF`（間に値はない） |
| `next_up(1.0)` | `1.0000000000000002` |
| `[0.0, 1.0]` に含まれる double の個数 | 4,607,182,418,800,017,409（≈ 2^62） |
| `[0.0, INF]` に含まれる double の個数 | 9,218,868,437,227,405,313 |

つまり **double の区間は集合としては離散かつ有限**で、後続値関数 `next_up`/`next_down` が存在する。したがって開区間は必ず閉区間に書き換えられる:

- `float<(0.0, inf]>` ＝ `float<[5e-324, inf]>`
- `float<[0.0, 1.0)>` ＝ `float<[0.0, 0.9999999999999999]>`
- `float<(-inf, inf)>` ＝ `float<[-1.7976931348623157E+308, 1.7976931348623157E+308]>` ＝ `[-PHP_FLOAT_MAX, PHP_FLOAT_MAX]`

Ondřej の「`float<1, 5>` は無限個」は文字どおりには正しくないが、本質は変わらない: **後続値は人間が読み書きできる数ではなく、算術を通すと保存されない**（`[5e-324, 1.0] + 1.0` は `[1.0, 2.0]` になり 1.0 を「含む」）。開閉フラグは集合論的な必要性ではなく **表記上の必要性**（ユーザーが書いた境界をそのまま保持する）である。

設計への帰結:

1. **`equals()` / `isSuperTypeOf()` は `(a, b]` と `[next_up(a), b]` を同一視しなければならない**。PostgreSQL が離散型（`int4range`）で `[1,10]` → `[1,11)` に正準化するのと同じ問題が、double にも起きる。
2. 推奨する解決: **表現は「ユーザーが書いた境界 + 開閉フラグ」、代数は「正準閉区間」で行う**。`canonicalMin() = minInclusive ? min : next_up(min)`、`canonicalMax() = maxInclusive ? max : next_down(max)` を定義し、包含・union・intersection・差集合・`equals()` はすべて正準値で計算する。`describe()` だけが保存した境界を印字する。これで `IntegerRangeType` の `±1` を `next_up`/`next_down` に置き換えた形で代数が書け、§7.4–7.5 の「端点一致時の開閉優先規則」は不要になる（正準値の比較に吸収される）。
3. `next_up` の実装は符号・`-0.0`・NaN・±INF を正しく扱う必要がある（整数ビット `+1` は正の double にしか使えない）。PHP には `nextafter()` がないので自前実装（`pack`/`unpack` または `PHP_FLOAT_EPSILON` ベースではなくビット操作）になる。
4. 算術伝播（§7.8）の「開境界は閉じる」規則は、正準化後の閉区間で計算すれば自動的に満たされる。
5. `describe()` の正準化: `[5e-324, 1.0]` を `(0.0, 1.0]` と印字するか否か。ユーザーが書いた境界を保持していれば往復は保たれる。PHPStan 自身が生成した区間（`$f > 0.0` の絞り込み）は `(0.0, inf]` として生成すればよい。

## 7. 提案仕様（ドラフト）

### 7.1 PHPDoc 構文

#### 7.1.1 現行 phpdoc-parser 2.3.3 での構文解析可否（実測）

| 候補 | 結果 | 備考 |
|---|---|---|
| `float<0.0, 1.0>` | **OK** | `GenericTypeNode(float, [Const(0.0), Const(1.0)])`。PHPStan 側は現状 `ErrorType`（`parameter.unresolvableType`） |
| `float<-1.5, 1.5>`, `float<1e-3, 1e3>`, `float<1_000.5, 2_000.5>`, `float<.5, 1.>`, `float<-0.0, 0.0>` | OK | 負数・指数・アンダースコア・省略形すべて字句解析可 |
| `float<0, 1>` | OK | `ConstExprIntegerNode` — int 境界の受理は PHPStan 側の判断 |
| `float<min, max>`, `float<inf, inf>`, `float<0.0, inf>` | OK | 識別子 |
| **`float<-inf, inf>`**, `float<-INF, INF>`, `float<-PHP_FLOAT_MAX, PHP_FLOAT_MAX>` | **FAIL** `Unexpected token "-inf,"` | `[+-]?` を許すのは `TOKEN_FLOAT`/`TOKEN_INTEGER` だけ。`-inf` は `TOKEN_OTHER`。**#13504 の `-INF` エラーと同根** |
| `float<0.0, 1.0)`, `float<(0.0, 1.0]>`, `float<]0.0, 1.0]>`, `float<[0.0, 1.0)>` | FAIL | 括弧は型のグルーピングとして解釈される |
| `float<0.0<, 1.0>`, `float<0.0, <1.0>`, `float<0.0, ~1.0>`, `float<0.0!, 1.0>` | FAIL | |
| `float<0.0..1.0>`, `float<0.0..<1.0>`, `float<0.0...1.0>` | FAIL | `...` は variadic トークン |
| `float<gt 0.0, lte 1.0>`, `float<open 0.0, 1.0>`, `float<0.0 open, 1.0>` | FAIL | 識別子の後に型は続けられない |
| **`float<0.0, 1.0, open>`**, **`float<0.0, 1.0, closed-open>`**, `float<0.0, 1.0, ClosedOpen>` | **OK** | 第 3 引数が識別子 |
| `float<0.0, 1.0, '[)'>`, `float<0.0, 1.0, "[)">` | OK | 第 3 引数が文字列定数 |
| `float<(0.0), 1.0>` | OK だが `float<0.0, 1.0>` に潰れる | 括弧はグルーピング |
| `float<0.0, 1.0>~0.0`, `float~NAN`, `float~0.0` | 先頭だけ解析、`~` 以降は未消費 | `~` は型文法に存在しない（VincentLanglet の指摘どおり） |
| `positive-float`, `non-negative-float`, `finite-float` | OK | 識別子。PHPStan 側で解決すれば使える |

参考: `TypeParser::parseGenericTypeArgument()` は各引数の前置修飾子として `covariant` / `contravariant` / `*` を受理する（`GenericTypeNode::$variances`）。**引数ごとの前置キーワードの機構は既にある**ので、境界修飾子もその延長で実装できる。

#### 7.1.2 提案する表記

**基本形（閉区間、Phase 1）**

```
float<a, b>          // [a, b]   a ≤ b、a・b は float/int リテラル、または -inf / inf
float<-inf, inf>     // NaN 以外のすべての float（= float ~ NAN）
float<0.0, inf>      // 0.0 以上、INF を含む
```

- 境界が int リテラル（`float<0, 1>`）なら `(float)` に変換して受理する。int を拒否する理由がない。
- `a > b` は `never` ではなく **PHPDoc エラー**（`int<5, 1>` と同じ扱い）。`a == b` は `ConstantFloatType(a)`。
- `NAN` は境界として拒否する（識別子 `NAN`/`nan` も受理しない）。

**開区間（Phase 2）— 2 案を併記し、推奨は A**

- **案 A: ISO 80000-2 の括弧記法を generic の内側に置く**
  ```
  float<[0.0, 1.0)>   // 0.0 ≤ x < 1.0
  float<(0.0, inf]>   // positive-float（INF を含む）
  float<(-inf, inf)>  // finite-float
  float<0.0, 1.0>     // = float<[0.0, 1.0]>（括弧省略は両端閉）
  ```
  - 利点: PostgreSQL `numrange`、Julia `iv"[1,5)"`、IEEE 1788、数学の標準記法と一致。`describe()` の可読性が最も高い。`(`/`)`/`[`/`]` は既存トークン。
  - 欠点: phpdoc-parser に `GenericTypeNode` の拡張（または新ノード）が必要。`(`がグルーピングと衝突するため `float<` 直後に限定した文脈依存パースになる。`]a, b]`（ISO 31-11 の仏式）は採用しない。
- **案 B: 第 3 引数に `Random\IntervalBoundary` と同じ語彙**
  ```
  float<0.0, 1.0, closed-open>
  float<0.0, inf, open-closed>   // positive-float
  float<-inf, inf, open-open>    // finite-float
  ```
  - 利点: **現行 phpdoc-parser で解析可能**、PHP 8.3 の語彙と一致。
  - 欠点: 冗長で `describe()` が長い。`float<-inf, inf, open-open>` は読みにくい。
- 案 C（前置修飾子、`float<open 0.0, 1.0>` 等）は variance 機構の流用で実装は容易だが、語彙が定着していないので推奨しない。

**`-inf` の字句解析**: 案 A/B どちらでも `float<-inf, inf>` を書くために phpdoc-parser の変更が要る。最小の変更は `Lexer` に `[+\-]?inf(?![a-z0-9_\-])` 相当のトークン（または `TOKEN_IDENTIFIER` の直前に `-` を許す特例）を追加すること。#13504 の `-INF` も同時に解決できるので、**phpdoc-parser 側の PR を先行させる**のが現実的。暫定として `float<min, max>` を `-inf`/`inf` の別名として受理し、`describe()` は `-inf`/`inf` を出す、という段階案もある（§9 の論点 1）。

#### 7.1.3 文字列 DSL 案を含めた表記の比較（追記）

追加で検証した候補（phpdoc-parser 2.3.3、すべて **解析可**）:

| 候補 | AST |
|---|---|
| `float<'[0.0, 1.0)'>`, `float<"[0.0, 1.0)">` | `ConstTypeNode/ConstExprStringNode` 1 引数 |
| `float<'[-inf, inf]'>`, `float<'(0.0, inf]'>` | 同上。**`-inf` の字句解析問題を回避できる** |
| `float<0.0, 1.0, 'closed-open'>`, `float<0.0, 1.0, '[)'>` | float, float, string |
| `float<0.0, 1.0, \Random\IntervalBoundary::ClosedOpen>` | float, float, `ConstFetchNode`（PHP 8.3 の enum case そのもの） |
| `array<float<'[0.0, 1.0)'>>`, `float<'[0.0, 1.0)'>\|NAN` | ネスト・union も問題なし |

また `mixed~int` / `mixed~(0.0\|bool\|null)` は **解析不能**（`~` 以降が未消費）だが PHPStan は `describe()` でこれを出力している。つまり **`describe()` の出力形式と PHPDoc の入力文法は既に分離している**。開区間の表記を「出力用」と「入力用」で別に決めてよい、という前提がここから得られる。

評価軸と各案:

| 案 | 今日解析可 | 外部ツール安全性 (PhpStorm/Psalm/phpDocumentor) | エラーメッセージでの可読性 | 検証・エラー報告 | `-inf` | 前例 |
|---|---|---|---|---|---|---|
| A `float<[0.0, 1.0)>` | ✗（文法拡張） | 旧 parser では構文エラー | ◎ 最短・数学記法 | lexer/parser が位置付きで報告 | lexer 変更要 | PostgreSQL, Julia, IEEE 1788, ISO 80000-2 |
| B `float<0.0, 1.0, closed-open>` | ○ | 未知の generic として無視される程度 | △ 冗長（`float<-inf, inf, open-open>`） | 識別子の typo は PHPStan が報告 | lexer 変更要 | PHP 8.3 `Random\IntervalBoundary` |
| B' `… , \Random\IntervalBoundary::ClosedOpen>` | ○ | ○ | ✗ 長すぎる | enum case として解決、IDE でジャンプ可 | lexer 変更要 | PHP 本体 |
| C `float<0.0, 1.0, '[)'>` | ○ | ○ | ✗ 括弧が数値から離れる | 文字列の中身は PHPStan が独自検証 | lexer 変更要 | なし |
| D `float<'[0.0, 1.0)'>` | ○ | ◎ 文字列定数なので何も壊さない | ○ だが引用符が残る | 文字列内のミニパーサを自前実装、エラー位置は文字列内オフセット | **不要** | PostgreSQL `'[1.0, 2.0)'::numrange`, Julia `iv"[1,5)"`, Java Bean Validation `@DecimalMin(value="0.0", inclusive=false)` |

**文字列 DSL（C/D）についての判断**

- BCMath の類推（`BcMath\Number` が `'0.1'` を受け取る）は **精度** のためのものだが、float 区間の境界は最終的に double になるので精度の利得はない。D の実利は「文法を触らない」「`-inf` を回避できる」の 2 点に尽きる。
- 逆に、将来 `numeric-string<'[0, 100]'>`（Psalm #9985 の要望）のように **double に落とせない順序型** に区間を拡張するなら、文字列 DSL は精度の面で本当の利点を持つ。float 単独では弱いが「順序型一般の区間記法」としてなら D には筋がある。
- PHPStan の型文法には文字列を DSL として再解釈する前例がない（`array{'key': T}` のキーは名前であり構文ではない）。phpdoc-parser 側のトークン化・位置情報・エラーメッセージの恩恵を捨てることになり、Ondřej の好み（文法はパーサに持たせる。`list{}`, `T of X`, `callable(): T`, `int<min, max>` はすべて文法拡張で入れた）とは反りが合いにくい。
- C は D の欠点（自前パーサ）を持ちつつ可読性も悪いので選ぶ理由がない。文字列を使うなら D 一択。

**推奨（改訂）**

1. **出力（`describe()`）は案 A** `float<[0.0, 1.0)>`、両端閉は `float<0.0, 1.0>` に省略。開区間は主に `$f > 0.0` の絞り込み結果として *PHPStan が印字する* ものであり、ユーザーが書く頻度は低い。エラーメッセージでの可読性を最優先する。`mixed~X` の前例があるので、入力文法が追いつく前に出力してよい。
2. **入力は Phase 1 では閉区間 `float<a, b>` と名前付き別名のみ**。`toPhpDocNode()`（fixer や `@return` 生成で使う）は閉区間で表せないときだけ開区間形式を出す。
3. **Phase 2 で phpdoc-parser に案 A の文法を追加**（`float<` 直後の `[`/`(` と末尾の `]`/`)` を文脈依存で受理。トークンは既存）。`-inf` の lexer 変更と同じ PR にまとめられる。
4. Ondřej が文法拡張を拒む場合のフォールバックは **案 B `closed-open`**（文法内・PHP の語彙）。文字列 DSL（D）は「順序型一般への拡張を同時に狙う」という明確な動機がある場合に限って提案する。

### 7.2 型クラス

```php
/** @api */
final class FloatRangeType extends FloatType implements CompoundType
{
    private function __construct(
        private float $min,          // -INF 許可、NaN 不可
        private float $max,          // INF 許可、NaN 不可
        private bool $minInclusive,
        private bool $maxInclusive,
    ) {}

    public static function fromInterval(float $min, float $max, bool $minInclusive = true, bool $maxInclusive = true): Type;
    public static function createAllGreaterThan(float $v): Type;           // (v, inf]
    public static function createAllGreaterThanOrEqualTo(float $v): Type;  // [v, inf]
    public static function createAllSmallerThan(float $v): Type;           // [-inf, v)
    public static function createAllSmallerThanOrEqualTo(float $v): Type;// [-inf, v]
    public static function createNonNan(): Type;                           // [-inf, inf]
    public static function createFinite(): Type;                           // (-inf, inf)
}
```

- `FloatType` は `#[InstanceofDeprecated(insteadUse: 'Type::isFloat()')]` なので、`FloatRangeType` の判定は `Type` インターフェースのメソッド経由にする。CLAUDE.md の方針（散在 `instanceof` より `Type` メソッド）に従い、候補:
  - `Type::isNan(): TrinaryLogic` — `ConstantFloatType(NAN)` → yes、区間 → no、`FloatType` → maybe、`UnionType` → 委譲。#15094 のルールはこれだけで書ける。
  - `Type::isFinite()` / `Type::isInfinite()` は `is_finite`/`is_infinite` の specifying と `(int)` キャストルールで使う。
  - `Type::getFloatRange(): ?FloatRangeBounds` のような境界取得 API は `IntegerRangeType::getMin()/getMax()` の前例に倣って具象クラスのメソッドでよい。
- `ConstantFloatType` は現状どおり `FloatType` の子。`FloatRangeType::isSuperTypeOf(ConstantFloatType)` は値が NaN なら `no`、区間内なら `yes`、外なら `no`。
- `FloatType::tryRemove()` を実装（`NonRemoveableTypeTrait` を外す）:
  - `float ~ NAN` → `float<-inf, inf>`
  - `float ~ float<-inf, inf>` → `NAN`
  - `float ~ c`（c は非 NaN 定数）→ `float<[-inf, c)> | float<(c, inf]> | NAN`
  - `float ~ float<[a, b]>` → `float<[-inf, a)> | float<(b, inf]> | NAN`
- `generalize()` → `FloatType`。`getFiniteTypes()` → `[]`。`equals()` は 4 要素の一致（`-0.0`/`0.0` は `==` で同一視）。

### 7.3 正規化規則（`fromInterval`）

| 入力 | 結果 |
|---|---|
| `min` または `max` が NaN | `InvalidArgumentException`（呼び出し側バグ。PHPDoc 経由では到達しない） |
| `min > max` | `never` |
| `min == max` かつ両端閉 | `ConstantFloatType(min)`（`-0.0` と `0.0` なら `0.0` を正規形にする） |
| `min == max` かつ片方でも開 | `never` |
| `min == -INF` かつ `minInclusive == false` かつ `max == -INF` | `never`（同上） |
| `min == -INF` かつ `max == INF` かつ両端閉 | `float<-inf, inf>`（**`FloatType` にはしない**） |
| それ以外 | `FloatRangeType` |

`-0.0` は境界として `0.0` に正規化する（順序上同一点。`describe()` が `-0.0` を出すと `float<[-0.0, 1.0]>` と `float<[0.0, 1.0]>` が別物に見える）。

### 7.4 包含関係

`A = [a₁, a₂]`（各端の開閉付き）、`B` も同様として、`A->isSuperTypeOf(B)`:

- `B` が `ConstantFloatType(v)`: `is_nan(v)` → no; `a₁ < v < a₂` → yes; `v == a₁` → `a₁` 閉なら yes、開なら no; `v == a₂` 同様; それ以外 no。
- `B` が `FloatRangeType`: 下端は `a₁ < b₁`、または `a₁ == b₁` かつ（`a₁` 閉 または `b₁` 開）なら包含。上端も対称。両方包含なら yes。交わらなければ no（交わらない条件: `a₂ < b₁`、または `a₂ == b₁` かつ少なくとも一方が開、および対称）。それ以外 maybe。
- `B` が `FloatType`（素の float）: maybe（NaN を含みうるため yes にはならない）。
- `B` が `IntegerType`/`IntegerRangeType`/`ConstantIntegerType`: `isSuperTypeOf` は no（int は float ではない）。`accepts()` は `FloatType::accepts()` と同じく int を受理するが、区間との整合は `(float)` 変換後の区間で判定する（`float<0.0, 1.0>->accepts(int<0, 1>)` → yes、`accepts(int<0, 2>)` → no）。`(float)` 変換は PHP の int–float 比較と同じ変換なので整合する（§2.3）。
- `CompoundType`（union/intersection/template）は `isSubTypeOf` へ委譲（`IntegerRangeType` と同じ）。

### 7.5 union / intersection / subtraction

| 演算 | 規則 |
|---|---|
| `tryUnion(A, B)` | 交わるか、端点が一致して少なくとも一方が閉 → `[min(a₁,b₁), max(a₂,b₂)]`、端の開閉は「より外側の端を持つ側」を引き継ぎ、端点一致時は閉を優先。それ以外 → `null`（`UnionType` のまま）。`float<[0,1)> \| 1.0` → `float<[0,1]>` のように定数との結合も行う |
| `tryUnion(A, FloatType)` | `FloatType` |
| `tryUnion(float<-inf, inf>, NAN)` | `FloatType`（**重要**: `describe()` が `float` に戻る） |
| `tryIntersect(A, B)` | `[max(a₁,b₁), min(a₂,b₂)]`、端点一致時は開を優先。空なら `never` |
| `tryIntersect(A, FloatType)` | `A` |
| `tryIntersect(A, NAN)` | `never` |
| `tryRemove(A, 点 p)` | `p` が内部 → `[a₁, p) \| (p, a₂]`; `p == a₁`（閉）→ `(a₁, a₂]`; 範囲外 → `A` |
| `tryRemove(A, B)` | 最大 2 片、B の端の開閉を反転して引き継ぐ |
| `tryRemove(A, NAN)` | `A`（元々含まない） |
| `tryRemove(A, FloatType)` | `never` |

`TypeCombinator::union()` の `IntegerRangeType` 専用経路（min でソートして隣接結合）に float 版を追加する。`UnionType` の `describe()` の並び順は既存の規則に合わせ、`float<[-inf, 0.0)> | float<(0.0, inf]> | NAN` のように NaN を末尾に置く。

### 7.6 比較演算子による絞り込み

対象 `$x` の型を T、定数 c（非 NaN）として:

| 条件 | truthy 側 | falsy 側 |
|---|---|---|
| `$x > c` | `T ∩ float<(c, inf]>` | `T ∩ (float<[-inf, c]> ∪ NAN)` |
| `$x >= c` | `T ∩ float<[c, inf]>` | `T ∩ (float<[-inf, c)> ∪ NAN)` |
| `$x < c` | `T ∩ float<[-inf, c)>` | `T ∩ (float<[c, inf]> ∪ NAN)` |
| `$x <= c` | `T ∩ float<[-inf, c]>` | `T ∩ (float<(c, inf]> ∪ NAN)` |
| `$x === c` | `c` | `T ~ c` |
| `$x == 0` / `!$x` | `0.0`（T が float のとき） | `T ~ 0.0`（NaN は truthy なので truthy 側に残る。PHP 8.5 では警告） |

- 実装は `ConstantNumericComparisonTypeTrait::getSmallerType()` 等の「`mixed` から引く型」に float 区間（と NaN）を加えるだけで BinaryOpHandler 側は変えずに済む。現状 `$m > 0.5` → `mixed~(0.0|bool|int<min, 0>|null)` が `mixed~(float<[-inf, 0.5]>|NAN|bool|int<min, 0>|null)` になる。
- **falsy 側に NaN が残る**ことが NaN 安全性の肝。`if ($f > 0) { ... } else { (string) $f }` は else 側で #15094 のルールに引っかかる（正しい）。
- T が `float<-inf, inf>`（NaN を含まない）なら falsy 側からも NaN は消える。
- `$x <=> c` は NaN で常に `1` を返すので、`<=>` の結果型からの逆絞り込みは行わない。
- 変数同士 `$a < $b` は `IntegerRangeType::isSmallerThan()` と同じ構造で区間端点の比較に還元できる（両方が区間のときのみ yes/no を出す）。

### 7.7 `is_nan` / `is_finite` / `is_infinite` の specifying

| 関数 | true 側 | false 側 |
|---|---|---|
| `is_nan($x)` | `T ∩ NAN` | `T ~ NAN`（`float` → `float<-inf, inf>`） |
| `is_finite($x)` | `T ∩ float<(-inf, inf)>` | `T ∩ (NAN \| -INF \| INF)` |
| `is_infinite($x)` | `T ∩ (-INF \| INF)` | `T ∩ (float<(-inf, inf)> \| NAN)` |

`FunctionTypeSpecifyingExtension` として実装。int 引数（非 strict）では `is_nan` は常に false（`ImpossibleCheckTypeHelper` が既存の仕組みで報告）。

### 7.8 算術伝播

**健全性の根拠**: IEEE 754 の `+ − × ÷` は correctly rounded であり、丸めは単調（x ≤ y ⇒ fl(x∘c) ≤ fl(y∘c)）。PHP ランタイムも PHPStan（PHP で動く）も同じ double 演算を使う。したがって **端点同士を double で計算した結果が、区間内の任意の値に対する演算結果を包含する**。IEEE 1788 の外向き丸めは「実数の区間」を扱うために必要なものであり、「double の区間」には不要。

規則:

1. `[a, b] + c` → `[fl(a+c), fl(b+c)]`。**開境界は閉じる**（`a` を含まなくても、`a` より僅かに大きい x で `fl(x+c) == fl(a+c)` となる吸収が起きうる）。例外: 端点が ±INF で結果も ±INF なら開のまま維持できる（`(−inf, b] + 1` の下端は依然 `−INF` に到達しない…が、`-PHP_FLOAT_MAX + -PHP_FLOAT_MAX = -INF` のように有限同士でも INF に到達しうるので、**安全側はすべて閉じる**）。
2. 単項マイナス: `[a, b]` → `[−b, −a]`、開閉は入れ替え。吸収がないので **開境界を保てる唯一の演算**。
3. `abs`: `[a, b]` が 0 を跨ぐなら `[0, max(−a, b)]`、全部負なら `[−b, −a]`、全部非負なら自身。開閉は端の由来に従う。NaN は `abs(NAN) = NAN` なので `FloatType` 入力では `float<[0, inf]> | NAN`。これで `functionMap` の `abs → float|0|positive-int` も精密化できる。
4. `×`: 4 端点積の min/max（`InitializerExprTypeResolver::integerRangeMath` の Mul と同型、`is_finite` チェック済みのコードが既にある）。**0 × ±INF = NaN** なので、一方が 0 を含み他方が ±INF を含むなら結果に `NAN` を union する。
5. `+`/`−`: 一方が `+INF` を含み他方が `−INF` を含む（加算）／同符号 INF 同士（減算）なら `NAN` を union。
6. `/`: 除数区間が 0 を含む → `DivisionByZeroError` の可能性（#12865 の修正と対応）。`fdiv` は `±INF`/`NAN` を返す。
7. `**`: `ExponentiateHelper` に委譲、Phase 2 以降。
8. int との混合: `IntegerRangeType` を `(float)` で float 区間に変換してから計算。`int<min, max>` → `float<[-9.2e18, 9.2e18]>`。

Phase 1 ではこれらを実装せず、算術結果は `FloatType` に落としてよい（現状と同じ）。§8 参照。

### 7.9 キャスト

| 変換 | 規則 |
|---|---|
| `toInteger()` / `(int)` | 0 方向切り捨て: `[a, b]` → `int<trunc(a), trunc(b)>`。`a < PHP_INT_MIN` または `b > PHP_INT_MAX` または ±INF を含む → PHP 8.5 Warning の対象（ルール化は Phase 3）。`toInteger()` 自体は `IntegerRangeType`（範囲外部分は `int` に広げる） |
| `toBoolean()` / `if ($f)` | 0.0 を含まない → `true`; 含む → `bool`。NaN を含みうる型では PHP 8.5 Warning |
| `toString()` | `numeric-string&uppercase-string`（現状と同じ）。NaN を含まないので警告なし。0.0 を含む区間は `'-0'` の可能性があるが `numeric-string` の範囲内 |
| `toArrayKey()` | `toInteger()` と同じ + 非整数値で Deprecated |
| `toFloat()` | 自身 |
| `int` → `float` 暗黙変換（`float` 引数に `int<1, 10>` を渡す等） | `float<[1.0, 10.0]>`。`(float)` は PHP の比較と同じ変換なので整合（§2.3） |

### 7.10 `describe()` と `toPhpDocNode()`

- 端点は `ConstantFloatType::castFloatToString()`（`precision=-1`、整数値なら `.0` 付与）で出す。`-INF`/`INF` は `-inf`/`inf`。
- 案 A なら `float<[0.0, 1.0)>`、両端閉は `float<0.0, 1.0>` に省略。案 B なら `float<0.0, 1.0, closed-open>`、`closed-closed` は省略。
- `VerbosityLevel::typeOnly()` では `float`。
- `toPhpDocNode()` は往復可能な構文を出す（ベースライン・`@return` 自動生成で使われる）。これが **PHPDoc 構文を先に決めなければならない理由**。

### 7.11 名前付き別名

| 名前 | 定義 | INF の包含 | 備考 |
|---|---|---|---|
| `positive-float` | `float<(0.0, inf]>` | 含む | #13859。`-0.0` は `0.0` と同一点なので含まない |
| `non-negative-float` | `float<[0.0, inf]>` | 含む | mind-bending-forks の要望どおり加算で閉じる |
| `negative-float` | `float<[-inf, 0.0)>` | 含む | |
| `non-positive-float` | `float<[-inf, 0.0]>` | 含む | |
| `non-zero-float` | `float<[-inf, 0.0)> \| float<(0.0, inf]>` | 含む | `non-zero-int` の類推。NaN は含まない（`NAN != 0` は true だが順序集合の外） |
| `finite-float` | `float<(-inf, inf)>` | **含まない** | #9250, #13504 |
| （非 NaN） | `float<-inf, inf>` | 含む | 専用名は付けない。`float~NAN` は構文上書けないので、これが正規の書き方 |

**#9250 の `float-NaN` / `float-finite` / `float-positive-inf` との対応**（プレイグラウンドは名前を並べただけで意味の定義はない）:

| #9250 の名前 | 読み方 | 本提案での表現 |
|---|---|---|
| `float-NaN` | 値 NaN（issue 本文 "when NaN type is expected to be returned" に沿う読み） | `NAN`（既存の定数型） |
| `float-NaN` | "float minus NaN" と読む場合 | `float<-inf, inf>`。ハイフンを減算と読むこの曖昧さは、PHPStan が否定を `non-` 接頭辞で表す（`non-empty-string`, `non-falsy-string`）慣習と衝突するので、名前を付けるなら `non-nan-float`。本提案では名前を付けず区間で書く |
| `float-finite` | 有限 | `finite-float` = `float<(-inf, inf)>` = `[-PHP_FLOAT_MAX, PHP_FLOAT_MAX]`。**`float<PHP_FLOAT_MIN, PHP_FLOAT_MAX>` ではない**（`PHP_FLOAT_MIN` ≈ 2.2e-308 は正の値なので、それは「正の正規化数」の区間になり 0・負数・subnormal を含まない） |
| `float-positive-inf` | 値 +INF | `INF`（既存。`-INF` は #13504 のとおり PHPDoc で書けない） |
| `float-positive-inf` | 「正で INF を含む」と読む場合 | `positive-float` = `float<(0.0, inf]>` |

`float<0.0, inf, '[)'>`（= `[0.0, PHP_FLOAT_MAX]`、非負**有限**）と `float<0.0, inf, '[]'>`（INF を含む）は **異なる型**で、差はちょうど `INF` 1 点。`PHP_FLOAT_MAX + PHP_FLOAT_MAX = INF` なので加算で閉じているのは `[]` の方であり、#13859 の `non-negative-float` の要望も `[]`。

**既定の開閉は closed-closed** とする。根拠: `int<1, 5>` は両端を含む、`range(1, 5)` = `[1,2,3,4,5]`、`range(0.0, 1.0, 0.5)` = `[0, 0.5, 1]`、`rand`/`random_int`/`Randomizer::getInt(1, 3)` は 3 を返す、`filter_var` の `min_range`/`max_range` は inclusive、Symfony `Range`・Laravel `between`・JSON Schema `minimum`/`maximum` も inclusive。PHP で closed-open が既定なのは `Randomizer::getFloat()`/`nextFloat()` だけで、これは一様サンプリングの都合（`Math.random()` と同じ `[0, 1)`）。したがって `getFloat($a, $b)` の戻り値型を `float<[a, b)>` にするのは個別対応であり、PHPDoc の既定を変える理由にはならない。open-open を既定にすると `float<1.0, 1.0>` が `never` になり、書いた端点が常に除外される、という直感に反する結果になる。

`positive-float` が INF を含むことは `positive-int` との非対称ではなく、`positive-int` に「int の上限を超えた値」が存在しないだけ。INF を除きたい場合は `float<(0.0, inf)>` と書く（案 A）。**名前付き別名は NaN を含まない**ことをドキュメントに明記する（mvorisek の要求）。

### 7.12 組み込み関数の戻り値（Phase 2 の候補）

| 関数 | 提案する戻り値 | 備考 |
|---|---|---|
| `abs(float)` | `float<[0.0, inf]> \| NAN` | 引数が区間なら NaN 抜き |
| `sqrt(x)` | x が `float<[0.0, inf]>` なら同じ区間（`sqrt` は単調）、それ以外 `… \| NAN` | `sqrt(-0.0) = -0.0` は 0 と同一点 |
| `sin`, `cos` | `float<-1.0, 1.0>`（x が有限なら）、`\| NAN`（±INF を含みうるなら） | |
| `tanh` | `float<-1.0, 1.0>` | |
| `atan` | `float<-1.5707963267948966, 1.5707963267948966>` | `atan(±INF)` で端点に到達するので閉 |
| `asin`, `acos` | `float<-π/2, π/2>`/`float<0, π>` `\| NAN` | |
| `exp` | `float<[0.0, inf]>` | `exp(-INF) = 0.0`, `exp(NAN) = NAN` |
| `log(x)`, `log10`, `log1p` | x が `(0, inf]` なら `float<-inf, inf>`、0 を含めば `\| -INF`、負を含めば `\| NAN` | |
| `hypot` | `float<[0.0, inf]>` | |
| `pi()`, `M_PI` 等 | 定数 | `M_PI` は既に定数解決されているか要確認 |
| `fmod` | `float<-inf, inf> \| NAN` | |
| `fdiv` | `float \| ±INF`（除数が 0 を含むとき） | |
| `floor`/`ceil`/`round` | 単調なので `[floor(a), floor(b)]` 等 | 既存の `RoundFunctionReturnTypeExtension` を拡張 |
| `Random\Randomizer::getFloat($a, $b, $boundary)` | 引数が定数なら `$boundary` どおりの区間 | PHP の語彙と 1:1 |
| `Random\Randomizer::nextFloat()` | `float<[0.0, 1.0)>` | |
| `lcg_value()` | `float<(0.0, 1.0)>` | deprecated だが |
| `mt_rand() / mt_getrandmax()` | `float<0.0, 1.0>` | 除算の伝播で自動的に得られる |
| `microtime(true)`, `hrtime(true)` | `float<(0.0, inf)>` | |
| `filter_var(…, FILTER_VALIDATE_FLOAT, min_range/max_range)` | `float<[min, max]> \| false` | TODO コメントの解消 |
| `array_sum(list<float<a,b>>)` | 伝播（要素数が不明なので `[min(0, n·a), max(0, n·b)]` は無理 → `float<-inf, inf>` 程度） | |
| `range(0.0, 1.0, 0.25)` | 既に定数配列 | |

### 7.13 有効になるルール

| ルール | 条件 | PHP バージョン |
|---|---|---|
| NaN→string 暗黙変換（#15094） | `(string)`, 補間, `echo`, `print`, `strval`, `settype`; 対象型が `float` を含み `isNan()->no()` でない | ≥ 8.5 |
| NaN→bool | `(bool)`, `if`, `!`, `?:`, `??` 以外の真偽文脈 | ≥ 8.5 |
| NaN→array | `(array)` | ≥ 8.5 |
| 非表現可能 float→int | `(int)`, 配列キー, `%`, `intdiv` 引数; 型が `[PHP_INT_MIN, PHP_INT_MAX]` に収まると証明できない | ≥ 8.5 |
| `Randomizer::getFloat` 引数 | `$min`/`$max` が有限でない、`$min >= $max`（OpenOpen では `>=`） | ≥ 8.3 |
| `catch (DivisionByZeroError)` の neverThrown（#12865） | 除数が 0 を含まない区間なら neverThrown、`float` なら maybe | |
| ループ最低 1 回（#11465） | 既存の int 機構を float 区間に拡張 | |
| `$x !== $x` / `$x === $x` の alwaysFalse/True（§3.3） | 型が NaN を含みうるなら報告しない | |
| `json_encode` の NaN/INF | 型が NaN/±INF を含みうるなら `false` を返しうる | |

「`float` を含み `isNan()->no()` でない」は **`float` 型の引数をそのまま文字列化するコードすべて** に当たる。#15094 の bea4dev も認めているように、これはデフォルトで有効にするには厳しすぎる可能性があり、level や bleeding edge への配置は Ondřej の判断（§9 論点 5）。

---

## 8. 段階的導入計画

| Phase | 内容 | 依存 |
|---|---|---|
| **0（先行）** | `$x !== $x` の NaN 誤検知修正、`PHP_FLOAT_MAX`/`PHP_FLOAT_MIN`/`PHP_FLOAT_EPSILON` の定数解決、`Type::isNan()` の追加（`FloatType` → maybe、`ConstantFloatType` → 値、union → 委譲） | なし。単独 PR 可 |
| **1** | `FloatRangeType`（内部表現は開閉付き）、閉区間 PHPDoc 構文 `float<a, b>` と `inf` 識別子、`FloatType::tryRemove()`、比較絞り込み（§7.6）、`is_nan`/`is_finite`/`is_infinite`、`ConstantNumericComparisonTypeTrait` の TODO 解消、`FILTER_VALIDATE_FLOAT` の range、#15094 の NaN→string ルール | phpdoc-parser の `-inf`（暫定: `min`/`max` 別名で回避可） |
| **2** | 開区間 PHPDoc 構文（案 A または B）、算術伝播（§7.8）、単項マイナス/`abs`/`sqrt`/三角関数等の戻り値（§7.12）、`Randomizer::getFloat` | phpdoc-parser PR（案 A のとき） |
| **3** | `positive-float` 等の別名、`finite-float`、`(int)` キャスト警告ルール、`DivisionByZeroError`、`json_encode` | Phase 2 |

Phase 1 の内部表現に最初から開閉フラグを持たせるのは、`$f > 0.0` の絞り込みと `tryRemove` が開境界を必要とするから。**Phase 1 時点では開境界は `describe()` に現れうる**（`float<(0.0, inf]>`）ので、表記は Phase 1 で決めておく必要がある（表記だけ先に決め、パーサ対応は Phase 2 でも可）。

### 8.1 代替設計との比較

| 設計 | 内容 | 長所 | 短所 |
|---|---|---|---|
| **A. 区間型（本提案）** | `FloatRangeType` + 開閉フラグ | #6963/#13859/#9250/#13504/#15094/#11465/#12865 を一つの機構で解決。PHP の比較意味論と 1:1 | 実装規模が大きい（`IntegerRangeType` 並みの波及） |
| B. `FloatType` を `SubtractableType` に | `float~NAN`, `float~0.0` を `MixedType` と同じ「引いた型」で表現 | #15094 だけなら最小。PHPDoc 構文不要（内部のみ） | 順序情報がないので `$f > 0` の絞り込みも `positive-float` も表せない。`float~NAN~INF~-INF` が finite の表現になり醜い。後で A に移行するとき捨てることになる |
| C. accessory 型 | `non-nan-float`, `finite-float`, `positive-float` を `AccessoryType` として `float&…` の intersection で表す（文字列の `non-empty-string` 方式） | 既存機構に乗る。名前付き別名だけなら十分 | 区間が境界値を持てない（`float<0.0, 1.0>` 不可）。accessory 同士の関係（positive ⊂ non-nan ⊂ …）を個別に書く必要があり、組合せ爆発 |

B は「#15094 を急ぐ」動機がある場合の暫定として検討に値するが、Phase 0 + Phase 1 の最小版（`FloatRangeType` の構文なし内部型 + `is_nan` specifying + `tryRemove(NAN)`）の方が同程度の規模で将来に繋がる。**推奨は A。**

---

## 8.2 プロトタイプの状況（2026-08-22 追記）

ブランチ `float-range-type`（worktree `phpstan-src-wt/float-range`、2.2.x 起点、未 push）に Phase 1 相当を実装した。提案コメント草稿は `20260822-issue-draft-float-range-proposal.md`。

実装したもの: `FloatRangeType`（開閉フラグ付き、`negate()` 含む）、`FloatType::tryRemove()`、`ConstantNumericComparisonTypeTrait` の TODO 解消、`BinaryOpHandler` の偽側 NaN 処理、`float<a, b>` の PHPDoc 解決（`min`/`max`/`inf`）、`TypeCombinator`/`UnionTypeHelper`/`UnionType` の range 対応、`is_nan`/`is_finite`/`is_infinite` の specifying 拡張、単項マイナスの union 対応。テスト: nsrt `float-range-types.php` + `FloatRangeTypeTest`（98 ケース）。

実装で判明した設計上の追加事項（ノート本文の規則に対する補正）:

- **偽側の規則は相手が NaN でないときだけ成立する。** `!($f < c)` = `$f >= c ∪ {NaN}` は c が非 NaN のとき。相手の型が NaN を含みうるなら偽側は何も絞り込めない（`!($f < NAN)` は全ての `$f` で真）。§7.6 の表はこの条件付き。
- **NaN 定数に対する `getSmallerType()` 系は `never`。** 既存コードは `IntegerRangeType::createAllGreaterThanOrEqualTo(NAN)` → `(int) ceil(NAN)` に到達していた（PHP 8.5 で警告）。
- **PHPStan 自身が PHP 8.5 の NaN 警告を踏む。** `ConstantFloatType::toString()/toInteger()/toArrayKey()/toBoolean()` が `(string) NAN` 等を実行するため、NAN 定数がキャストに届く経路（`$f === 0.0` の偽側など）で解析中に警告が出る。警告を出さない等価計算（`'NAN'`、0、2^64 剰余）に置き換えた。#15094 の裏返しとして提案の論拠になる。
- **`IntegerRangeType::createAll*` の 2^63 境界バグ。** `$value > PHP_INT_MAX` は PHP が PHP_INT_MAX を float 化して比較するため、`$value == 2^63` で偽になり `(int) ceil(2^63)` に到達する。`>=` に修正。
- **union の単項マイナス。** `getUnaryMinusTypeFromType()` は `NAN|float<…>` のような「定数 + 非定数」の union で非定数側を落としていた。メンバごとに再帰する形に変更。
- **`ConstantFloatType::isSuperTypeOf(FloatRangeType)`** は trait 経由だと `instanceof parent` で maybe になり `NAN&float<(0.5, inf]>` のような未簡約 intersection を生む。定数が区間に含まれなければ no を返すよう override。
- **`float<-inf, inf>|NAN` → `float` の畳み込み**は、union の定数マージが range 同士のマージより先に走るため、range をソート後に先に線形マージしてから定数を合流させる必要があった。
- **表示順**: 既存規則（定数が先）により `NAN|float<[-inf, 0.0)>|float<(0.0, inf]>`。`3|int<min, 1>|int<5, max>` と同じ流儀。

既存テストの期待値変更は 15 件で、いずれも旧コメントが予告していた方向（`bug-5309.php` の「could be '0.0' when we support float-ranges」、`dependent-variables-type-guard-same-as-type.php` の「could be Yes, but float type is not subtractable」等）。`mixed~(...)` の記述が `NAN|float<…>` を含んで長くなる点は Ondřej への確認事項。

### 8.3 adversarial review への対応（2026-08-22 追記）

`20260822-float-range-adversarial-review.md` の指摘を検証し、次を適用した:

- **過大申告の撤回**: 「5 件を同時に解決」→「#15094/#13859/#9250/#13504 の土台」。#14394（`float === NAN`）は配列内 NAN と zval 同一性の例外があるため独立した変更として切り離す。RFC の表現は "implicit or explicit coercion" に修正。
- **正準化の実装**（§6.6 の方針どおり）: `nextUp()`/`nextDown()`（符号・±INF・-0.0 対応、32-bit 半語 2 つでビット操作）を実装し、空判定・単点判定・`equals`・包含・結合・`toInteger()` を正準閉境界で行う。`float<(0.0, 5.0E-324)>` → `never`、`(0.0, 1.0]` ≡ `[5.0E-324, 1.0]`、`[-inf, -inf)` のような無限大で開いた端は明示的に空。
- **`toInteger()` の符号依存**: 正準化＋ゼロ方向切り捨てにより規則を別途書かずに正しくなる（`(0.0, 1.0)` → `0`、`(1.0, 2.0)` → `1`、`(-1.0, 0.0)` → `0`）。テストで固定。
- **往復の二重仕様の解消**: 案 B `float<a, b, closed-open>` を `TypeNodeResolver` で解析可能にし、`toPhpDocNode()` がそれを出力。`TypeToPhpDocNodeTest` で `equals()` 往復を検証。`describe()` は案 A 表示のまま。
- **32-bit**: #14948/#11711 に従い、`PHP_INT_SIZE` 由来（ホスト依存）ではなく **64-bit 意味論を決定的な方針** として `ConstantFloatType::INT_BOUND`/`INT_MODULUS` に集約し明記。
- **baseline**: `instanceof IntersectionType` の 1 件は「既存慣行（`IntegerRangeType`）の踏襲」として PR 本文に明記し、レビュー対象に残す。
- **コミット分割**: (1) `ConstantFloatType` の警告なしキャスト、(2) `IntegerRangeType` の 2^63 境界、(3) `FloatRangeType` 本体、の 3 コミット。

### 8.4 post-remediation re-review への対応（2026-08-22 追記）

- **P1 pure int の述語**: 拡張が `isFloat()->no()` で早期 return していたため `is_nan($int)` 等が絞り込まれなかった。「float でも int でもあり得ない型」だけをスキップする判定に修正（数値文字列は `"1e500"` → INF があるので対象外のまま）。nsrt に pure int の 3 述語 × 真偽側を追加。
- **P2 比較絞り込みの 1 ULP**: `getSmallerType()` 系が書かれた境界を使っていたため、RHS `[a, b)` に対する `x < RHS` の候補に `nextDown(b)` が残っていた（健全だが不正確）。4 メソッドとも正準境界（`canonicalMin()`/`canonicalMax()`）を使うように変更。これにより開閉で分岐していた実装が単純化された。
- **S1 `instanceof FloatRangeType` の散在**: `IntegerRangeType` と同じ分岐箇所（`TypeCombinator`/`UnionType`/`UnionTypeHelper`/`InitializerExprTypeResolver` ほか）であることを確認し、提案に「設計上のレビュー項目」として明記、未解決論点 7 に追加。
- **P3 baseline**: 解消ではなく「maintainer acceptance risk」として提案に残す（既に記載）。

- **（3 回目）numeric-string union の unsoundness**: `numeric-string|float` では `isFloat()` が maybe なので拡張が適用され、`is_finite("1")` が真なのに真側から numeric-string が消えていた。`is_finite`/`is_infinite` は引数型が `int|float` の部分型と**確定**する場合だけ適用し、`is_nan` は真になる値が NAN 定数しかないので型を問わず適用、と分離。`numeric-string|float` と `mixed` の nsrt を追加。

### 8.5 PR 構成（2026-08-22 追記）

- `float-nan`（最小 PR、3 コミット）: (1) `ConstantFloatType` の警告なしキャスト、(2) `IntegerRangeType` の 2^63 境界、(3) NaN・無限大の分離（`FloatType::tryRemove()` は NAN/±INF/区間のみ、有限点は対象外。`FloatRangeType` は比較メソッドを除き集合代数のみ。`toPhpDocNode()` は `float` に widening。述語拡張）。nsrt は `float-nan.php`。
- `float-range-type`（Draft PR）= `float-nan` の 3 コミット + 機能コミット（PHPDoc 構文、比較絞り込み、有限点の除去、`negate`/`looseCompare`/`getSmallerType` 系、単項マイナス）。nsrt は `float-range-types.php`（`float-nan.php` と重複する述語テストは削除）。
- 切り出し時の知見: **最小 PR に有限点の除去を入れると、既存の `ConstantNumericComparisonTypeTrait` が `$x > c` で `0.0` を引くため、`$f > 10.0` の真側が `NAN|float<[-inf, 0.0)>|float<(0.0, inf]>` と表示される**（健全だが比較絞り込みなしでは意味不明）。一方 `is_infinite()` の偽側は `±INF` の 2 点除去なので、無限大の除去は必要。よって「非有限値（NAN, ±INF）と区間は除去可、有限点は次の PR」という線引きにした。
- 2^63 修正を float-nan に前倒しした理由: float-nan 単体の `make phpstan`（PHP 8.5 ホスト）で `IntegerRangeType` 101/167 行の `(int) ceil(2^63)` 警告が出た。トレースは `ConstantFloatType(2^63)->getGreaterOrEqualType()`（既存 trait）→ `createAllSmallerThan(2^63)`。2.2.x 単体・再実験では再現せず原因は未特定だが、修正自体は独立に正しい。
- 運用ルール: `float-nan` のコミットは `float-nan` でしか直さない。`float-range-type` は `git rebase --onto float-nan <旧tip> float-range-type`、または `git checkout <旧機能tip> -- .` でツリー復元して積み直す。PR 本文草稿: `20260822-pr-draft-float-nan.md`、`20260822-pr-draft-float-range-type.md`。

### 8.6 分割後ブランチの確定情報（レビュー引き継ぎ用、§8.7・§8.8 反映後）

| ref | tip | 内容 |
|---|---|---|
| `backup/float-range-type-pre-split` | `96631c0af` | 4 回目レビューの凍結点（分割前） |
| `backup/float-nan-pre-32bit-fix` | `4055e3edc` | `float-nan` ブランチレビューの凍結点 |
| `float-nan` | `9b9d48e08` | 6d3a1d260（キャスト）→ 9c8783c8f（PHP_INT_MAX + 1.0）→ 9b9d48e08（NaN 分離、`FloatRangeType` は final・継承なし） |
| `float-range-type` | `bdd08e5fa` | `float-nan` + 機能コミット（cherry-pick 後に `looseCompare` の重複と `parent::` 呼び出しを解消） |

- `float-nan` の `FloatRangeType` は比較メソッド・`negate()`・`looseCompare()`・`createAll*OrEqualTo` を含まず、`toPhpDocNode()` は `float` に widening。`FloatType::tryRemove()` は NAN・±INF・区間のみ（有限点は機能コミット）。
- 次レビューで見てほしい点: (1) 32-bit ホスト（linux/386 PHP 8.5.9）で `ConstantFloatTypeTest`/`FloatRangeTypeTest`/`IntegerRangeTypeTest` が警告なしで通ること、特に `nextUp()` の `pack('n4')` と `castToInt()` の `null` 退化、(2) `float-nan` 単体で既存期待値が 1 件も変わらないこと、(3) 機能コミットが `float-nan` の差分を書き換えていないこと。

### 8.7 `float-nan` ブランチレビュー（32-bit ホスト）への対応（2026-08-22 追記）

`20260822-float-nan-branch-adversarial-review.md`（linux/386 PHP 8.5.9 で実測）の指摘に対し、**32-bit 解析ホストでも健全な実装**に直した。`php-64bit` 要件の追加は方針変更なので採らず、64-bit 意味論の主張を撤回した。

- `ConstantFloatType::castToInt()`: `INT_BOUND`/`INT_MODULUS`（64-bit 定数）を削除。NAN/±INF → 0。ホストの int 範囲（`(float) PHP_INT_MIN` 以上 `PHP_INT_MAX + 1.0` 未満、どちらの幅でも正確）外は `null` を返し、`toInteger()`/`toArrayKey()`/`toBitwiseNotType()` は `IntegerType` に退化。64-bit ホストでも `(int) 1e19` は wrap 定数ではなく `int` になる（PHP の仕様上「未定義」なので妥当。提案の論点 5 を差し替え）。
- `FloatRangeType::nextUp()`: `0xFFFFFFFF`（32-bit では float）を排除し、16-bit 語 4 つ（`n4`）で繰り上げ／繰り下げ。
- `FloatRangeType::toInteger()`: ホストの int 範囲で判定。
- `IntegerRangeType::createAll*`: `>= PHP_INT_MAX`（64-bit の丸めに依存）をやめ、`FLOAT_ABOVE_INT_MAX = PHP_INT_MAX + 1.0`（64-bit で 2^63、32-bit で 2^31、どちらも正確）と `ceil`/`floor` で判定。`IntegerRangeTypeTest` は `PHP_INT_MAX + 1.0` を使い、`(float) PHP_INT_MAX` の期待値は `PHP_INT_SIZE` で分岐。
- 32-bit での再検証はレビュー担当の 386 環境に依頼（ローカルに 32-bit PHP なし）。

### 8.8 `FloatRangeType` を継承なしにした（2026-08-22 追記）

1.9.0 の型リファクタリング（`ArrayType` と `ConstantArrayType` の切り離し）の原則に従い、`final class FloatRangeType implements CompoundType` に変更。`FloatType` は継承しない。

- `FloatType::isSuperTypeOf()`/`accepts()` は `instanceof self` の次に `CompoundType` へ委譲するので、`FloatRangeType::isSubTypeOf()`/`isAcceptedBy()` で答える。再帰を避けるため `isSubTypeOf()` は float 族を直接判定: 素の `FloatType` → Yes、`ConstantFloatType` → 含めば Maybe／さもなくば No、自クラス → `isSuperTypeOf`、union → or、intersection → `isSuperTypeOf`。
- これで `ConstantFloatType::isSuperTypeOf()` の override（trait の alias）は不要になった（trait が `CompoundType` を `isSubTypeOf` に委譲する）。
- `FloatType` の定型メソッド 41 個と trait 6 つ（`NonArrayTypeTrait` 等、`UndecidedComparisonTypeTrait`）を `FloatRangeType` に持たせた。`ConstantArrayType` が `ArrayType` の API を重複して持つのと同じ。
- `get_class($x) === parent::class` は `FloatType::class` に、`parent::toInteger()` は `new IntegerType()` に、機能コミットの `parent::looseCompare()` は `new BooleanType()` に。
- `IntegerType`/`IntegerRangeType`/`ConstantIntegerType`/`ConstantStringType` は今も継承で繋がっている（リファクタリングは配列で止まっている）。`IntegerRangeType` 側の切り離しは本提案の範囲外。
- `final` にしたので `instanceof FloatRangeType` は表現の判定として曖昧さがない。S1 は「`TypeCombinator` 側のフックを `Type` メソッドにするか」に絞られる（論点 7）。

## 9. 未解決の論点（Ondřej に確認すべきこと）

1. **`-inf`/`inf` の字句解析**: phpdoc-parser に手を入れてよいか。入れる場合 `-inf` 専用トークンか、`-` + 識別子の一般化か。暫定で `float<min, max>` を別名として受理するか（PHP_FLOAT_MIN との混乱を承知の上で）。
2. **開区間の表記**: 案 A `float<[a, b)>` か案 B `float<a, b, closed-open>` か、文字列 DSL `float<'[a, b)'>` か（§7.1.3）。出力は A、入力は Phase 1 閉区間のみ → Phase 2 で A、という分離案を提示する。
3. **`float<-inf, inf>` は `float` に正規化しない** ことの受容。`TypeCombinator::union(float<-inf, inf>, NAN)` は `float` に戻す一方、`float` から NaN を引いた結果はそのまま表示される。エラーメッセージに `float<-inf, inf>` が出るようになる。
4. **int 境界の受理**（`float<0, 1>`）と **`-0.0` の正規化**（`0.0` に潰す）。
5. **#15094 のルールの配置**: `float` 引数をそのまま `echo` するコードは膨大で、level 付けや bleeding edge 限定、`phpVersion >= 8.5` 限定が必要。`strict-rules` 側という選択肢も。
6. **`positive-float` が INF を含む**ことの明文化。mvorisek の「well-defined でなければ導入しない」に答えるため、ドキュメントに `positive-float = float<(0.0, inf]>` と書く。
7. **算術伝播の範囲**: Phase 2 で `+ − × ÷` まで入れるか、単項マイナス/`abs` 等の単調関数に限るか。`InitializerExprTypeResolver::integerRangeMath()` に float 版を並置すると 1 ファイルがさらに肥大する。
8. **正準化の方式**（§6.6）: 代数を正準閉区間で行い表示だけ開閉を保持する案を採るか。`next_up` の自前実装を `FloatRangeType` の private helper に置くか `PHPStan\Type\Helper` に出すか。
9. **`Type::isNan()` の追加**の是非（`Type` インターフェース拡張は `@api` 影響あり。ただし `@api-do-not-implement` 相当なので追加は許容されているはず）。

---

## 10. 参考リンク

- phpstan/phpstan: [#6963](https://github.com/phpstan/phpstan/issues/6963), [#15094](https://github.com/phpstan/phpstan/issues/15094), [#13859](https://github.com/phpstan/phpstan/issues/13859), [#9250](https://github.com/phpstan/phpstan/issues/9250), [#13504](https://github.com/phpstan/phpstan/issues/13504), [#14394](https://github.com/phpstan/phpstan/issues/14394), [#11465](https://github.com/phpstan/phpstan/issues/11465), [#12865](https://github.com/phpstan/phpstan/issues/12865), [#9182](https://github.com/phpstan/phpstan/issues/9182), [#10297](https://github.com/phpstan/phpstan/issues/10297), [#13097](https://github.com/phpstan/phpstan/issues/13097), [#6683](https://github.com/phpstan/phpstan/issues/6683)
- phpstan/phpstan-src: [#3036](https://github.com/phpstan/phpstan-src/pull/3036), [#4040](https://github.com/phpstan/phpstan-src/pull/4040), [#4368](https://github.com/phpstan/phpstan-src/pull/4368), [#5321](https://github.com/phpstan/phpstan-src/pull/5321), [#5332](https://github.com/phpstan/phpstan-src/pull/5332), [#1443 FILTER_VALIDATE_INT range](https://github.com/phpstan/phpstan-src/pull/1443), [#1961 range multiplication and division](https://github.com/phpstan/phpstan-src/pull/1961)
- vimeo/psalm: [#5533 positive-float](https://github.com/vimeo/psalm/issues/5533)
- PHP: [RFC: Warnings for PHP 8.5](https://wiki.php.net/rfc/warnings-php-8-5), [`Random\IntervalBoundary`](https://www.php.net/manual/en/enum.random-intervalboundary.php), [`Random\Randomizer::getFloat`](https://www.php.net/manual/en/random-randomizer.getfloat.php), [`PHP_FLOAT_MIN`](https://www.php.net/manual/ja/reserved.constants.php#constant.php-float-min), [`filter_var` FILTER_VALIDATE_FLOAT](https://www.php.net/manual/en/filter.constants.php)
- 予備調査: `20260822-range-syntax-survey-ja.md`（英訳: `20260822-range-syntax-survey.md`）（Dijkstra EWD831, Kotlin `..<`, Swift, Rust, Boost.ICL, Julia IntervalSets.jl, IEEE 1788, PostgreSQL range types）
- 参照コード（phpstan-src `2.2.x`）: `src/Type/IntegerRangeType.php`, `src/Type/FloatType.php`, `src/Type/Constant/ConstantFloatType.php`, `src/Type/Traits/ConstantNumericComparisonTypeTrait.php`, `src/Type/FiniteTypeSet.php:95`, `src/Type/Php/FilterFunctionReturnTypeHelper.php:292`, `src/PhpDoc/TypeNodeResolver.php:773`, `src/Analyser/ExprHandler/BinaryOpHandler.php:519-560`, `src/Reflection/InitializerExprTypeResolver.php:2427-2440`, `vendor/phpstan/phpdoc-parser/src/Lexer/Lexer.php:178-179`, `vendor/phpstan/phpdoc-parser/src/Parser/TypeParser.php:451-504`

### 付録: 本ノートで使った検証スクリプト

`20260822-float-range-probe/` に保存した。

- `php-float-semantics-probe.php` — §2 の PHP 意味論（`php php-float-semantics-probe.php`）
- `phpstan-dumptype-probe.php` + `probe.neon` — §3.1 の `dumpType()` プローブ（phpstan-src で `bin/phpstan analyse --no-progress --error-format=raw -c probe.neon phpstan-dumptype-probe.php`）
- `phpdoc-parser-syntax-probe.php` — §7.1.1 の構文解析可否（`require` のパスを phpstan-src の `vendor/autoload.php` に合わせて `php phpdoc-parser-syntax-probe.php`）
