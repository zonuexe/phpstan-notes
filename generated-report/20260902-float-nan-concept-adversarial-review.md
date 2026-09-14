# `float-nan` ブランチ コンセプト敵対的レビュー（2026-09-02）

対象: `backup/float-nan-pre-rebase-20260902` = 9b9d48e08（base 79a31bb78、= `float-nan` 8351fc971 と同内容）。読み取り専用チェックアウト `scratchpad/review-float-nan`。比較対象の「base」は `/Users/megurine/repo/php/phpstan-src`（`php-int-size` tip 9fb9e6346 = 2.2.x + ConstantResolver 差分のみ。float 周りは 2.2.x と同一）。ホスト PHP 8.5.9 (64-bit)。

凡例: **[検証済み]** = 自分でプローブを実行して確認した / **[疑い]** = コード読解のみ、または実行環境がなく未確認。

---

## 1. 結論

**判定: 修正後可（3 点を直せば PR 化してよい。再設計は不要）。** 値モデル（`float ≡ float<-inf, inf>|NAN`、区間は NaN を含まない凸集合、正準閉区間で代数）は健全で、素朴な設計が踏む穴を複数回避している（§3）。問題は値モデルそのものではなく **PR 分割境界の置き方** にある: 「区間は入れるが `negate()`・有限点除去・比較絞り込みは次の PR」という線引きが、この PR 単体で **到達可能な不健全さ** を 2 つ生んでいる。

最重要リスク 3 つ:

1. **[Blocker B1] `T of float` に対する `isSubTypeOf()` が無条件 Yes** — `@return T` の関数で NaN ガード済み float を返すと base では報告される `return.type` が消える（偽陰性の回帰）。`FloatRangeType::isSubTypeOf()` が `TemplateFloatType` を `isFloat()->yes()` で素通しするため。
2. **[Blocker B2] 単項マイナスが区間を写像しない** — `if ($f !== INF) { -$f }` の型が `float<[-inf, inf)>` のまま（正しくは `float<(-inf, inf]>`）。`getUnaryMinusTypeFromType()` は非定数型をそのまま返し、`IntegerRangeType` だけ後段で特別扱いされる。`negate()` を次の PR に送ったが、非対称区間はこの PR で既に生成される。
3. **[Blocker B3] `FloatRangeType` が `FloatType` を継承しないことのエコシステム影響** — Rector の `PHPStanStaticTypeMapper` はクラス単位で mapper を引き、該当なしなら `NotImplementedYetException` を投げる（`Type::class` のフォールバック mapper は存在しない）。NaN ガード付きの普通の float コードの推論型に `FloatRangeType` が現れるので、Rector の型宣言系ルールがクラッシュしうる。`ConstantArrayType` の分離は 1.9 のメジャー整理として告知込みで行われた前例であり、2.2.x のマイナーで「推論型に現れる非サブクラス型」を足すのは別種のイベント。

このほか Major として、**条件の順序で絞り込み結果が変わる**（M1）、**invariant ジェネリクスでの新規偽陽性**（M3）、**PHPStan 自身の PHP 8.5 警告が `$x < NAN` 経路に残る**（M4）がある。

---

## 2. 発見事項

### Blocker

#### B1. `FloatRangeType::isSubTypeOf(TemplateFloatType)` が Yes → `@return T of float` の偽陰性 [検証済み]

**主張**: `src/Type/FloatRangeType.php:386-389`

```php
if ($otherType->isFloat()->yes()) {
    // plain float; FloatType::isSuperTypeOf() delegates to this method for compound types
    return IsSuperTypeOfResult::createYes();
}
```

`TemplateFloatType extends FloatType` なので `isFloat()->yes()` が真になり、`T of float` に対して「区間は T の部分型」と答える。`IntegerRangeType::isSubTypeOf()` は `$otherType instanceof parent` → `$otherType->isSuperTypeOf($this)` に委ねるので `TemplateIntegerType::isSuperTypeOf()`（bound ∧ Maybe）→ Maybe が返る。float だけ Yes。

**証拠**:
- `probes-nan/unit-probe.php`（ブランチ）: `float<0,1>->isSubTypeOf(T of float): Yes` / `int<0,1>->isSubTypeOf(U of int): Maybe` / `T of float ->accepts(float<0,1>): Yes` / `U of int ->accepts(int<0,1>): Maybe` / `nonNan->isSubTypeOf(T of float | int): Yes`。
- `probes-nan/p8-templates-order.php`:
  ```php
  /** @template T of float @param T $t @return T */
  function retT(float $t, float $u): float { if (!is_nan($u)) { return $u; } return $t; }
  ```
  base: `15: Function P8\retT() should return T of float but returns float. [return.type]`、`28:` 同様（`is_finite` 版）。ブランチ: **両方とも報告なし**。`retTPlain`（ガードなし、`float` を返す）は両方で報告される。`retTInt`（`int<1, max>` を返す）は両方で報告される。
- 経路: `TemplateTypeArgumentStrategy::accepts()` → `$right instanceof CompoundType` → `FloatRangeType::isAcceptedBy()` → `isSubTypeOf(TemplateFloatType)` → Yes。

**影響**: 健全性の回帰。`@template T of float` は稀だが、`@template T of float|int`（`N of float|int ->accepts(float<0,1>): Yes` も確認）や `T of mixed` 経由でも同じ経路を通りうる（`M of mixed ->isSuperTypeOf(float<0,1>): Maybe` は正しい）。

**対処案**: `isSubTypeOf()` で `isFloat()->yes()` の前に `if ($otherType instanceof TemplateType) { return $otherType->isSuperTypeOf($this); }` を置く（`TemplateTypeTrait::isSuperTypeOf()` が bound ∧ Maybe を返す）。より一般には `CompoundType` を union / intersection / template で分岐せず `$otherType instanceof CompoundType && !$otherType instanceof UnionType` → `$otherType->isSuperTypeOf($this)` に寄せると、`instanceof IntersectionType` の baseline 行（§2 M9）も不要になる。回帰テスト: nsrt に `retT` を追加。

#### B2. 単項マイナスが `FloatRangeType` を写像しない [検証済み]

**主張**: `src/Reflection/InitializerExprTypeResolver.php:2749-2793`。`getUnaryMinusTypeFromType()` は定数値がなければ `$type` をそのまま返し、`getUnaryMinusType()` は `IntegerRangeType` のときだけ `Mul(-1)` に迂回する。`FloatRangeType` には何もしない。範囲 PR の `negate()` を待つ設計だが、**この PR 単体で非対称な区間が生成される**（`$f !== INF`、`$f !== -INF`、`is_finite($f) && $f !== c`）。

**証拠** (`p8-templates-order.php`、ブランチ):
```
102: $f        => float<[-inf, inf)>      // if (!is_nan($f) && $f !== INF)
103: -$f       => float<[-inf, inf)>      // 正しくは float<(-inf, inf]>
105: $f * -1   => float                   // 健全（広がるだけ）
108: 0 - $f    => float                   // 健全
111: $f        => float<(-inf, 0.5)>|float<(0.5, inf)>   // is_finite && $f !== 0.5
112: -$f       => float<(-inf, 0.5)>|float<(0.5, inf)>   // 正しくは (-inf, -0.5)|(-0.5, inf)
117/118: 同様（-0.5 版）
```
base はすべて `float`。

**影響**: `if ($f !== INF) { $g = -$f; if ($g === INF) {...} }` で PHPStan は `$g === INF` を「常に false」と報告しうる（`float<[-inf, inf)>->isSuperTypeOf(INF)` = No）が、`$f === -INF` のとき実行時は true。型代数の基本不変条件（演算は集合を写像する）が破れている。

**対処案**: この PR に `FloatRangeType::negate()`（`[a, b]` → `[-b, -a]`、開閉入れ替え、`-0.0` は `fromInterval` が正規化）を入れ、`getUnaryMinusType()` で `FloatRangeType`（および union のメンバ）に適用する。範囲 PR 草稿の「unary minus on unions member by member」もここに前倒しする。最低限の代替は `-` で `FloatRangeType` を `FloatType` に広げること（健全だが情報を捨てる）。

#### B3. 非 `FloatType` サブクラスであることのエコシステム影響（Rector が例外を投げる） [疑い: rector-src main のコード読解。実行はしていない]

**主張**: `FloatRangeType` は `final class FloatRangeType implements CompoundType` で `FloatType` を継承しない（§8.8 の判断）。PHPStan の型を消費する側の代表 Rector は `PHPStanStaticTypeMapper` で mapper を `getNodeClasses()` + `instanceof` で選び、該当なしなら例外を投げる。

**証拠** (`gh api repos/rectorphp/rector-src/contents/...` で取得):
- `src/PHPStanStaticTypeMapper/PHPStanStaticTypeMapper.php` 28-45 行: `if (! $typeMapper instanceof TypeMapperInterface) { throw new NotImplementedYetException(__METHOD__ . ' for ' . $type::class); }`（`mapToPHPStanPhpDocTypeNode` と `mapToPhpParserNode` の両方）。62-68 行: `foreach ($typeMapper->getNodeClasses() as $nodeClass) { if (! $type instanceof $nodeClass) continue; ... }`。
- `TypeMapper/` 29 ファイルのうち `getNodeClasses()` に `Type::class` / `CompoundType::class` を含むものは **0**（全ファイルを grep）。`FloatTypeMapper::getNodeClasses()` は `[FloatType::class]`。
- 到達経路: Rector の型宣言推論は `$scope->getType()` を使う。`if (is_nan($x)) { return 0.0; } return $x;` の推論戻り値は `0.0|float<-inf, inf>` → `UnionTypeMapper` → メンバ `FloatRangeType` → 例外。

**同種の箇所（phpstan-src 内）**: `src/Type/Generic/TemplateTypeFactory.php:86`（`$bound instanceof FloatType && $boundClass === FloatType::class`）、`src/Rules/Generics/TemplateTypeCheck.php:128`（`$boundTypeClass !== FloatType::class` の許可リスト）。PHPDoc 構文がないので今は到達しないが、構文が入った瞬間に `@template T of float<0.0, 1.0>` は「サポートされない bound」になる。vendor 内の phpstan 拡張（strict-rules, nette, phpunit, deprecation-rules）には `instanceof FloatType` なし [検証済み: grep]。GitHub コード検索 `instanceof FloatType` / `FloatType::class` の PHPStan 関連ヒットは rector-src（6 ファイル）と phpstan-doctrine（Doctrine 側の FloatType）。

**影響**: IntegerRangeType（`extends IntegerType`）は Rector の `IntegerTypeMapper` に吸収されるが、FloatRangeType は吸収されない。「型が推論に現れる」= ユーザーが PHPDoc を書かなくても発生するので、Rector 側の対応（`FloatTypeMapper::getNodeClasses()` に追加 + `@param FloatType $type` の型緩和）を **リリース前** に揃える必要がある。

**対処案 / Ondřej への質問文案**:
> `FloatRangeType` is a `final class implements CompoundType` that does not extend `FloatType`, following the `ArrayType`/`ConstantArrayType` split. Unlike a PHPDoc-only type, it shows up in inferred types of ordinary code (`if (!is_nan($f))`), so class-dispatching consumers see a new `Type` class in a minor release - Rector's `PHPStanStaticTypeMapper` throws `NotImplementedYetException` for it. Would you rather (a) keep the split and coordinate a Rector mapper before the release, or (b) have `FloatRangeType extends FloatType` for now, like `IntegerRangeType extends IntegerType`, and split later?

### Major

#### M1. 条件の順序で絞り込み結果が変わる（有限点除去の片側実装） [検証済み]

**主張**: `FloatType::tryRemove(有限点)` は `null`（意図的に先送り）だが、`FloatRangeType::tryRemove(有限点)` は実装済み。結果、同じ事実の連言でも順序で型が変わる。

**証拠** (`p8-templates-order.php`、ブランチ):
```
76: if ($f !== 0.0 && !is_nan($f))  => float<-inf, inf>
79: if (!is_nan($f) && $f !== 0.0)  => float<[-inf, 0.0)>|float<(0.0, inf]>
82: if ($f !== 0.0 && is_finite($f)) => float<(-inf, inf)>
85: if (is_finite($f) && $f !== 0.0) => float<(-inf, 0.0)>|float<(0.0, inf)>
89/94: ネストした if でも同様
```
`unit-probe.php`: `remove(float, 0.0|NAN) = float<-inf, inf>` / `remove(float, NAN|0.0) = float<[-inf, 0.0)>|float<(0.0, inf]>`（`TypeCombinator::remove` は union メンバを順に引くため、`UnionType` を直接構築した場合に順序依存）。base はすべて `float`。

**影響**: 健全性は保たれる（どちらも過大近似）が、PHPStan の絞り込みは通常、独立した事実の連言で順序に依存しない。ユーザーは `is_nan` ガードを前後どちらに書くかで `identical.alwaysFalse` 等の報告が変わることになる。自ブランチの nsrt（`is_finite($f) && $f !== 0.0` → 分割）は片方の順序だけを固定している。

**対処案**: NaN PR の中で一貫させる。(a) `FloatType::tryRemove(有限点)` も実装する（範囲 PR の一部を前倒し。その場合 M2 の表示問題を `ConstantNumericComparisonTypeTrait` 側で同時に直す必要がある）、または (b) `FloatRangeType::tryRemove(有限点)` を `null` にして有限点除去を範囲 PR に完全に送る（最小・一貫。nsrt の `is_finite && !== 0.0` ケースは削る）。NaN PR としては (b) を推奨。

#### M2. `$f > c` の真側に `0.0` 減算の分割区間が現れる [検証済み]

**主張**: PR 草稿は「有限点を素の float から引かないので `$f > 10.0` が `NAN|float<[-inf, 0.0)>|…` にならない」と述べるが、`!is_nan` / `is_finite` で区間化した後は既存の `ConstantNumericComparisonTypeTrait` の `0.0` 減算（`getGreaterType()` 等）が区間に効き、同じ表示が出る。

**証拠** (`p3-ops.php`、ブランチ): `if (!is_finite($f)) return;` の後で `if ($f > 10.0)` の真側 = `float<(-inf, 0.0)>|float<(0.0, inf)>`（91 行）、`if ($f >= 0.0)` の真側も同じ（98 行）。`!is_nan` 版は `float<[-inf, 0.0)>|float<(0.0, inf]>`（126/131 行）。`max($f, 1.0)` も `float<[-inf, 0.0)>|float<(0.0, inf]>`（35 行）。

**影響**: 健全だが「`$f > 10.0` なのに負の区間が型に出る」のはレビューで真っ先に突かれる。M1 と同根（有限点除去が区間側にだけある）。

**対処案**: M1 の (b) を採れば消える。(a) なら比較絞り込み（範囲 PR）と同時に出す。

#### M3. invariant ジェネリクス位置での新規偽陽性 [検証済み]

**主張**: NaN ガード後の `float<-inf, inf>` はテンプレート推論でそのまま型引数になり、`Box<float>` / `ArrayObject<int, float>` に渡せなくなる。

**証拠** (`p8-templates-order.php` / `p6-preexisting.php`):
```
143: Property P8\Holder::$box (P8\Box<float>) does not accept P8\Box<float<-inf, inf>>.   // base: なし
165: Parameter #1 $b of function P8\takesBoxFloat expects P8\Box<float>, P8\Box<float<-inf, inf>> given.  // base: なし
173: ArrayObject<int, float<-inf, inf>>   (base: ArrayObject<int, float>)
174: Parameter #1 $ao ... expects ArrayObject<int, float>, ArrayObject<int, float<-inf, inf>> given.  // base: なし
```
前例: `new IBox($positiveInt)` → `IBox<int<1, max>>` は base でも `IBox<int>` に渡せない（p6 44 行）。`new Box(1.5)` → `Box<1.5>` も同様。つまり PHPStan は範囲/定数を型引数推論で一般化しない方針であり、本 PR はその方針を float に拡張しただけ。

**影響**: 方針として一貫しているが、`is_nan` ガード済みコードが既存プロジェクトで新たに `argument.type` を出す回帰。Doctrine `Collection<int, float>`（invariant）等で顕在化する。

**対処案**: PR 本文で明示し Ondřej に委ねる。緩和案: テンプレート引数推論時に `FloatRangeType` を `generalize()` する（`int<1, max>` との非対称が生じるので推奨しない）。

#### M4. PHP 8.5 警告を PHPStan 自身が出す経路が `$x < NAN` に残る（commit 1 の隣接バグ） [検証済み]

**主張**: commit 1 は `ConstantFloatType` の 5 キャストを警告なしにしたが、同じ定数 NAN が `ConstantNumericComparisonTypeTrait` と `IntegerRangeType::createAll*()` に流れる経路は手つかず。しかも結果型が誤り。

**証拠** (`warn-probe.php`、ブランチ):
```
NAN->getSmallerType()          => mixed~(int<0, max>|true)   [W] The float NAN is not representable as an int, cast occurred @ IntegerRangeType.php:175 | unexpected NAN value was coerced to bool @ ConstantNumericComparisonTypeTrait.php:24
NAN->getGreaterOrEqualType()   => mixed~(0.0|int<min, -1>|false|null)  [W] ... IntegerRangeType.php:107 | ... ConstantNumericComparisonTypeTrait.php:69
createAllSmallerThan(NAN)      => int<min, -1>   [W] IntegerRangeType.php:107
NAN->looseCompare("NAN")       => true           [W] unexpected NAN value was coerced to string @ LooseComparisonHelper.php:24   （実行時 NAN == "NAN" は false）
```
解析コード側: `p7-warnings.php` 23 行 `if ($m < NAN)` の真側 = `mixed~(0.0|int<min, -1>|false|null)`（正しくは never: `$x < NAN` はすべて false）。PHPStan CLI は自ソース内の警告を `FileAnalyser::collectErrors()` で握りつぶすので（解析対象ファイル外の警告は捨てる、`src/Analyser/FileAnalyser.php:272-293`）、ユーザーには見えないが PHPUnit では例外化される。

**影響**: commit 1 のメッセージ「without PHP 8.5 warnings」の主張が、同じ定数の隣の経路で破れている。spec §8.2 は範囲ブランチで「NaN 定数に対する getSmallerType() 系は never」と直したと記すが、float-nan には入っていない（分割時の取りこぼし）。

**対処案**: commit 1 に含める: trait の 4 メソッドで `is_nan($this->value)` なら `never`（真側は空集合）、`IntegerRangeType::createAll*()` で `is_nan($value)` なら `NeverType`。`LooseComparisonHelper` は別件（#14394 系）として言及に留めてよい。

#### M5. commit 2 は独立した既存バグ修正であり単独 PR にすべき [検証済み]

**主張**: base で `createAllSmallerThan(2^63)` は `*NEVER*`（正しくは `int`）、`createAllGreaterThanOrEqualTo(2^63)` は `int<-9223372036854775808, max>` = `int`（正しくは never）、いずれも警告付き。解析コードでは `if ($m < 9223372036854775808.0)` の真側が `mixed~(int<-9223372036854775808, max>|true)`（**すべての int を除去**）になる。

**証拠**: `warn-probe.php` base 出力、`p7-warnings.php` 17-18 行の base/branch diff。ブランチは `int` / `*NEVER*` / `mixed~true` に修正。境界 2^63 以外で挙動が変わらないことはコード読解で確認（`ceil`/`floor` 後の `>= 2^63` 比較は、2^63 未満の double では旧 `> PHP_INT_MAX`（= `> 2^63` として評価）と一致）。

**影響**: NaN PR に同梱すると「NaN の話」の中に int 境界の修正が紛れ、レビューでノイズになる。spec §8.5 の「2.2.x 単体で再現せず」は誤りで、`$m >= 9223372036854775808.0` の 1 行で再現する。

**対処案**: commit 2 を先行 PR にする（本文: `Closes` なし、再現 `if ($m < 9223372036854775808.0)`）。なお `IntegerRangeTypeTest` の `PHP_INT_SIZE < 8` 分岐は CI に 32-bit ジョブがないので実行されない [検証済み: `.github/workflows` に i386/32bit なし]。

#### M6. `FloatRangeType::toString()` が ±INF を含む区間にも `numeric-string` を返す [検証済み]

**主張**: `(string) INF` は `"INF"` で `is_numeric("INF")` は false。`float<-inf, inf>->toString()` = `numeric-string&uppercase-string` は不健全。`FloatType::toString()` の既存不健全（`"NAN"`/`"INF"` も numeric-string 扱い）をそのまま写した。

**証拠**: `php -r`: `is_numeric("INF") => false`, `(string) INF => "INF"`。`unit-probe.php`: `nonNan->toString(): numeric-string&uppercase-string`, `INF->toString(): 'INF' isNumericString=No`。

**影響**: 新クラスは ±INF を精密に扱うのが存在意義なのに、文字列化で精度を捨てている。有限区間 `(-inf, inf)` だけが本当に numeric-string。

**対処案**: `contains(INF)`/`contains(-INF)` に応じて `'INF'`/`'-INF'` を union する。`FloatType::toString()` に `'NAN'` を足すかは別 PR（#15094 のルール設計と絡む）。

#### M7. `(int)` の範囲外 float を `int` に退化させる挙動変更が既存出力を変える [検証済み]

**主張**: base は `(int) 1e19` → `-8446744073709551616`（ホストの wrap 値、警告付き）、`[1e19 => 1]` → `array{-8446744073709551616: 1}`、`~1e19` → `8446744073709551615`。ブランチは `int` / `non-empty-array<int, 1>` / `int`。PHP マニュアルが「未定義」とする以上、`int` は正しいが、既存テストに現れないだけで **観測可能な挙動変更**。

**証拠**: `p7-warnings.php` 44/49/93 行の base/branch diff、`p6-preexisting.php` 39 行。

**`php-int-size`（#6030）との相互作用**: `castToInt()` と `FloatRangeType::toInteger()` と `FLOAT_ABOVE_INT_MAX` はすべて **ホスト** の `PHP_INT_MAX` を使う。#6030 の立場（「`phpIntSize` は 3 定数の型だけを変え、下流はホスト幅のまま」、zonuexe の #6030 コメント）と整合しているが、`phpIntSize: 8` を設定して 32-bit ホストで走らせると `(int) 3e9` は `int` に退化する（健全だが情報損失）。逆に 64-bit ホストで設定なしのとき `(int) 3e9` は `3000000000` 定数（32-bit ターゲットでは wrap するはず）— これは #14948 が指摘する既存の半端なモデルと同じ。

**対処案**: PR 本文に「ホスト幅に従う。#6030 が入れば `ConfiguredPhpIntSizeHelper` を参照する余地があるが Type クラスは DI を持たないので今はしない」と一言。Ondřej への質問（issue 草稿 Q5）はそのままでよい。

#### M8. PR 分割境界: 「見える機能が `is_nan()` 絞り込みだけの 849 行の型クラス」 [検証済み（代替案の実現可能性はコード読解）]

**両論**:

- *現行分割を支持する側*: `float ~ NAN` を表現する型が必要で、`FloatRangeType` はそれを「NaN を含まない凸集合」として一般化する。範囲 PR で捨てるものがない。`is_finite`（`(-inf, inf)`）と `is_infinite`（`-INF|INF`）が同じ機構で出る。正準化・`nextUp` は範囲 PR で必要になる部品であり、先に安定させる価値がある。
- *最小設計を支持する側*: #15094 が要求するのは `float~NAN` の表現だけで、OP 自身がその表記を期待している。PHPStan には `SubtractableType`（`MixedType`/`ObjectType`/`ObjectWithoutClassType`/`StaticType` が実装）とその union/intersect/remove の畳み込み（`TypeCombinator::unionWithSubtractedType()` ほか、`TypeCombinator.php` 655-915/1792-1899 行）が **既にある** [検証済み: grep]。`FloatType implements SubtractableType` にすれば `float~NAN`、`float~(NAN|INF|-INF)`（= is_finite 真側）、`float~(-INF|INF)`（= is_infinite 偽側）がすべて既存機構で表現でき、`FloatType` のクラスは変わらないので B3（Rector）・M3（ジェネリクス）・B1・B2 のどれも起きない。`toPhpDocNode()` も `float` で自然。順序情報がないので `$f > 0.0` は表せないが、それは範囲 PR の仕事。
- *判定*: NaN PR として見ると、現行の分割は **区間型の代数を半分（`negate`・有限点除去・比較）だけ持ち込んだ状態** で、それが B2/M1/M2 の直接原因。選択肢は 2 つ: (i) 現行方針を維持し、この PR 内で `negate()` を足し有限点除去を区間側からも外して「NaN・±INF・区間の除去と述語」に閉じる（B1/B2/M1/M4 の修正）、(ii) NaN PR を `SubtractableType` 版に縮め、範囲 PR で `FloatRangeType` に置き換える（置き換え時に `float~X` の表現を捨てる churn が発生する）。Ondřej が「IntegerRangeType をコピーするな」と言った文脈では、(ii) の方が「float は違う」という彼の直感に沿うが、(i) でも B1/B2/M1 を直せば受理範囲に入る。本レビューは (i) + 修正を推奨しつつ、(ii) を PR 本文の "Alternatives considered" に書くことを勧める。

### Minor

- **m1. 定数 NAN に対する既存の誤り（隣接バグ、前から存在）** [検証済み]: `max(1, NAN)` → `1`（実行時 NAN）、`max(NAN, 1)` → `1`（実行時 1、正しい）、`NAN == 'NAN'` → `true`「常に true」（実行時 false）、`NAN == true` → true（実行時 true、正しい）。`is_nan` 絞り込みで `$f: NAN` になる場面が増えるので露出が増える。`p1` 37-38/52 行、`p6` 19-24 行、`php-semantics.php`。
- **m2. `$f === $f` は `$f: NAN` でも「常に true」** [検証済み]: p1 28 行（同一変数の `===` は #14394 の VincentLanglet 指摘）。一方 `$f >= $f` は「常に false」（32 行）と正しく答える。PR は「値モデルに NAN を入れる」のに `NAN === NAN` の同一変数ケースだけ矛盾が残る。PR 草稿は #14394 を対象外と明記しているので、本文で触れるだけでよい。
- **m3. `is_nan($int)` 等が新たに「常に false」を報告** [検証済み]: p1 93 行、p2 23/26/27/37/38 行。実行時と一致（`is_nan(1)` false、`is_finite(PHP_INT_MAX)` true、非 strict の `is_nan("1e500")` は false、strict は TypeError）。レベル 4 以上で新規エラーが出るので changelog 級。
- **m4. 性能** [検証済み]: `bench-union.php`（200 定数 int / string / float、150 混合、remove、intersect）で base と差は ±5% の範囲。定数 float 200 個の union のみ +7%（`is_nan()` 呼び出しが定数ごとに入る）。共通経路への追加は `instanceof FloatRangeType` 判定 2 箇所と `is_nan` 1 回で、問題なし。
- **m5. `is_finite`/`is_infinite` の gating** [検証済み]: `float|null`、`bool|float`、`numeric-string|float`、`mixed` はどちら側も絞り込まれない（p2 18-19/28/35-36 行）。非 strict の `is_finite("1")` true / `is_infinite("1e500")` true / `is_finite(null)` true(deprecated) を実機で確認したので、保守的な判断として妥当。ただし真側で float 成分だけ絞る余地はある。
- **m6. union の表示順変更** [検証済み]: `UnionTypeHelper` の NAN 末尾規則で `max([1, NAN])` が base `NAN|1` → ブランチ `1|NAN`（p7 70 行）。既存テストは通るが観測可能。
- **m7. `describe()` が `VerbosityLevel` を無視** [検証済み]: `typeOnly` でも `float<-inf, inf>`（`unit-probe.php`）。`IntegerRangeType` と同じなので一貫。
- **m8. `*NEVER*|int<-9223372036854775807, max>`** [検証済み・既存]: `if ($i > -9223372036854775808.0)` で explicit `NeverType` が union に残る（p7 21 行、base も同じ）。本 PR の対象外だが commit 2 の隣。
- **m9. baseline の 6 行**: `FloatRangeType::isSubTypeOf()` の `instanceof IntersectionType` 1 件。`IntegerRangeType` と同じ免除だが、B1 の修正で `CompoundType` に寄せれば消せる。「設計上の妥協」というより「IntegerRangeType のコピー」の名残。
- **m10. Turbo 拡張との整合** [検証済み]: `TypeCombinatorCache` の C++ 側は `PHPStan\Type\` 配下のクラスを構造的にハッシュし、double はビット列で混ぜる（`turbo-ext/src/TypeCombinatorCache.cpp` 162-300 行）。`FloatRangeType` の 4 プロパティも NAN も安全。`ShadowedByTurboExtension` 付きクラスは本ブランチで触っていない。
- **m11. `toPhpDocNode()` の `float` への widening の消費者** [検証済み]: `VarTagTypeRuleHelper::isSuperTypeOfVarType()` は推論型を `toPhpDocNode()` 経由で再解決して緩める方向にしか使わないので偽陽性は出ない。`DumpPhpDocTypeRule` は `float` を出す。

### Question

- **q1.** `float<-inf, inf>` のエラーメッセージ表示（issue 草稿 Q3）。#15094 の OP は `float~NAN` を期待している。`describe()` で全域閉区間だけ `float~NAN`、`(-inf, inf)` を `finite-float` と印字する選択肢はないか（`mixed~X` が print-only の前例）。
- **q2.** `is_nan` は任意の型を絞るが `is_finite`/`is_infinite` は `int|float` 確定時のみ — 非対称の理由（コアース数値文字列）を PR 本文に書く。
- **q3.** `FloatRangeType` を `@api` にしない方針（issue 草稿 Q6）。`instanceof FloatRangeType` を `TypeCombinator`（8）/`UnionTypeHelper`（3）/`UnionType`（1）/`FloatType`（1）に散らしている（13 箇所、grep で確認）のは `IntegerRangeType` と同じだが、CLAUDE.md の「`Type` にメソッドを足す」方針とは逆行。Ondřej の好みを先に聞く価値がある。

---

## 3. 現設計が正しくやっていること（PR 本文で守るべき論点）

1. **正準閉区間での代数** [検証済み]: `(0.0, 1.0]` と `[5.0E-324, 1.0]` を `equals()`/`isSuperTypeOf()` で同一視し、`(0.0, 5.0E-324)` を never にする（`FloatRangeTypeTest`、`unit-probe.php` `equals=true`, union 両順序で `float<(0.0, 1.0]>`）。素朴な「開閉フラグの一致」実装は `[a, b) ∪ [b, c]` の結合や隣接 double の空区間を間違える。
2. **`float<-inf, inf>` を `float` に正規化しない一方、`union(float<-inf, inf>, NAN)` は `float` に畳む** [検証済み]: 4 通りの順序（`union(NAN, (-inf,inf), INF, -INF)` 等）すべて `float`、`equals(new FloatType())` true、`union(int, nonNan, NAN)` = `float|int`。`int<min, max>` → `int` の類推でここを正規化すると NaN 情報が消える。
3. **±INF を閉境界の値として扱い、有限 = `(-inf, inf)`** [検証済み]: `$f <= INF` は NaN 抜き区間で「常に true」、素の float では `bool`（p3 43-44 行 vs base `bool`）。実機 `NAN <= INF` false と一致。
4. **`is_finite`/`is_infinite` の gating** [検証済み]: 非 strict で `is_infinite("1e500")` true、`is_finite("1")` true、`is_finite(true)` true を確認。`numeric-string|float` を絞らない判断は正しい。`is_nan` は NAN 自身しか true にしないので任意型を絞ってよい（`is_nan("1e500")` false）。
5. **キャストの警告回避と 32-bit 安全性** [検証済み]: base は `ConstantFloatType(NAN)->toInteger()` 等 5 経路で PHP 8.5 警告を出す（`warn-probe.php`）。ブランチは 0。`(float) PHP_INT_MAX === PHP_INT_MAX + 1.0` が 64-bit で true であることを実機確認、2^63 の境界判定が `>=` で正しい。`nextUp()` の 16-bit 語演算は 32-bit ホストでも int に収まる。
6. **`mixed~NAN ∩ float = float<-inf, inf>`、`mixed~float<-inf, inf> ∩ float = NAN`** [検証済み]: `unit-probe.php`。`is_nan($m)` 偽側 → `is_float($m)` で `float<-inf, inf>`（p5 61 行）。減算型と区間型の双対が成立している。
7. **NAN の非有限扱い** [検証済み]: `ConstantFloatType(NAN)->getFiniteTypes()` = `[]`（既存）を維持したので、`$f !== NAN` の偽側が `float ~ NAN` にならず `float` のまま（p1 13 行）— `$f !== NAN` は NAN でも true なので、これは正しい。
8. **Turbo キャッシュ安全** [検証済み]: 上記 m10。

---

## 4. Phase 2: 既存レビューとの照合表

Phase 1 を書き終えてから `20260822-float-nan-branch-adversarial-review.md`（4055e3edc 対象、linux/386 実測）、`20260822-float-range-adversarial-review.md`（範囲ブランチ対象、3 回の再レビュー込み）、spec §8.3/§8.4 を読んだ。分類: **新規** / **既出・未解決** / **既出・解決済みだが不十分** / **既出・解決済みで妥当**。

| 本レビューの項目 | 既存レビューでの扱い | 分類 | 再検証コメント |
|---|---|---|---|
| B1 `T of float` への `isSubTypeOf()` Yes | 言及なし | **新規** | §8.8 で `FloatType` 継承をやめた際に導入された退行 [検証済み]: 継承版 4055e3edc（`backup/float-nan-pre-32bit-fix`）の `isSubTypeOf()` 347-358 行は `$otherType instanceof IntersectionType \|\| $otherType->isFloat()->yes()` → `$otherType->isSuperTypeOf($this)` に委ねていた（`TemplateFloatType::isSuperTypeOf()` は bound ∧ Maybe）。9b9d48e08 は再帰回避のため同分岐を `createYes()` に置き換え、テンプレートの Maybe を失った。 |
| B2 単項マイナス | 範囲レビュー §8.2「union の単項マイナス」は範囲ブランチで対応。float-nan には `negate()` なし | **既出・解決済みだが不十分（分割で脱落）** | 範囲ブランチでは `negate()` + union メンバごとの再帰で解決したが、float-nan にも非対称区間が現れることを分割時に見落とした。p8 で再現。 |
| B3 非サブクラスのエコシステム影響 | 範囲レビュー S1（`instanceof FloatRangeType` の散在）は phpstan-src 内部の話。外部消費者（Rector）の観点はなし | **新規** | §8.8 は「1.9 の配列分離の原則に従う」とするが、配列分離はメジャー整理として告知付きで行われた。Rector 側に mapper がないことを rector-src main で確認。 |
| M1 順序依存の絞り込み | 言及なし（§8.5 は「有限点は次の PR」と決めたが、区間側の `tryRemove(点)` が残ることの帰結は未検討） | **新規** | p8 76/79 行、unit-probe の `remove(float, 0.0\|NAN)` vs `remove(float, NAN\|0.0)`。 |
| M2 `$f > c` の分割区間表示 | §8.5 で「素の float に有限点除去を入れると `$f > 10.0` が意味不明になる」と認識し、素の float だけ外した | **既出・解決済みだが不十分** | 区間に絞り込んだ後は同じ表示が出る（p3 91/98 行）。回避は半分しか効いていない。 |
| M3 invariant ジェネリクスの偽陽性 | 言及なし | **新規** | `int<1, max>` の前例は base で確認（p6 44 行）。方針として一貫しているが回帰であることは PR 本文に要記載。 |
| M4 `$x < NAN` 経路の警告と誤った型 | spec §8.2「NaN 定数に対する getSmallerType() 系は never」（範囲ブランチで対応） | **既出・解決済みだが不十分（分割で脱落）** | float-nan の `ConstantNumericComparisonTypeTrait`／`IntegerRangeType::createAll*` は未修正。warn-probe で警告 8 件、型は誤り。 |
| M5 commit 2 の独立性 | §8.5「2.2.x 単体・再実験では再現せず原因は未特定」 | **既出・未解決（誤認あり）** | base で `createAllSmallerThan(2^63)` = `*NEVER*` + 警告、`$m < 9223372036854775808.0` の真側が全 int を除去、と 1 行で再現（warn-probe / p7 17-18 行）。独立バグとして先行 PR にできる。 |
| M6 `toString()` の ±INF | 言及なし | **新規** | `is_numeric("INF")` false を実機確認。 |
| M7 `(int)` 範囲外 → `int` とホスト幅 | float-nan レビュー High #1（32-bit で 64-bit 定数を格納できない）、範囲レビュー #5（64-bit 固定を推奨）、§8.7 で「ホストの int 範囲外は `int` に退化」に確定 | **既出・解決済みで妥当** | 両レビューの対立（決定的 64-bit vs ホスト依存）に対し、§8.7 の「範囲内なら定数、範囲外なら `int`」はどちらのホストでも健全。ただし 32-bit 実行は本レビューでは未検証（CI にも 32-bit ジョブなし）。#6030 との関係は PR 本文に一言必要。挙動変更（`(int) 1e19` が定数から `int` へ）は既存レビューが触れていない観測可能差分。 |
| M8 PR 分割境界 / `SubtractableType` 代替 | spec §8.1 で B 案（SubtractableType）を「#15094 を急ぐ暫定」として検討し A を推奨。既存レビューは分割境界を評価していない | **既出・未解決（再評価）** | `SubtractableType` の畳み込み機構が汎用に存在することを grep で確認。B 案は B1/B2/B3/M1/M3 を構造的に回避する。 |
| m1 定数 NAN の既存誤り（`max`, `== 'NAN'`） | 範囲レビュー #14394 関連で `$f === NAN` のみ言及 | **既出・未解決（範囲拡大）** | 対象外で妥当だが、`is_nan` 絞り込みで露出が増える点は新規。 |
| m2 `$f === $f` 常に true | 範囲レビュー Blocker 1（#14394 は未解決）、§8.3 で「独立した変更に切り離す」 | **既出・解決済みで妥当（スコープ外宣言）** | 妥当。ただし PR 本文に一言。 |
| m3 `is_nan($int)` 等の新規「常に false」 | 範囲レビュー P1（pure int が絞られない）→ §8.4 で修正 | **既出・解決済みで妥当** | p1 93-94 行、p2 で再検証: `*NEVER*` + 報告。妥当。 |
| m5 `is_finite`/`is_infinite` の gating | 範囲レビュー P4（numeric-string union の unsoundness）→ 3 回目で解消 | **既出・解決済みで妥当** | p2 22 行、nsrt `coercedStringPredicates` で再検証。非 strict の `is_infinite("1e500")` true を実機確認。 |
| m9 baseline 1 件 | 範囲レビュー P3（maintainer acceptance risk として残す） | **既出・未解決** | B1 の修正で `CompoundType` に寄せれば同時に消せる、という新しい出口を提示。 |
| m10 Turbo 整合 | 言及なし | **新規（問題なし）** | 構造ハッシュを確認、安全。 |
| 正準化（§3-1） | 範囲レビュー High #3（`(0.0, 5.0E-324)` が never にならない）→ §8.3 で `nextUp` 実装 | **既出・解決済みで妥当** | unit-probe / FloatRangeTypeTest で再検証。 |
| `toPhpDocNode()` の widening | 範囲レビュー High #4（二重仕様）→ §8.3 で案 B 構文と往復。float-nan では構文なしで `float` に widening | **既出・解決済みで妥当（NaN PR の範囲では）** | 消費者（`VarTagTypeRuleHelper`）は緩める方向にしか使わず偽陽性なし（m11）。 |
| `nextUp()` の 32-bit | float-nan レビュー High #2（`0xFFFFFFFF` が float）→ §8.7 で 16-bit 語 4 つ | **既出・解決済みで妥当（読解のみ）** | 64-bit では `dataNextUp` 全通過。32-bit 実機は未検証。 |
| `IntegerRangeType` の `>= PHP_INT_MAX` | float-nan レビュー High #3 → §8.7 で `PHP_INT_MAX + 1.0` | **既出・解決済みで妥当** | `(float) PHP_INT_MAX === PHP_INT_MAX + 1.0` を 64-bit で確認。32-bit では `2^31` が正確な double。 |
| 開区間の `toInteger()` | 範囲レビュー #6 → §8.3 で正準化により解決 | **既出・解決済みで妥当** | `dataToInteger` 通過。 |
| 過大申告・RFC 表現・survey | 範囲レビュー Blocker 1/2、#8 → §8.3 | **既出・解決済みで妥当** | issue 草稿は "groundwork" 表現、survey 不使用。 |

既存レビューが「解決済み」とした項目のうち、本レビューで **解決が不十分** と判定したのは M2（表示回避が半分）と、分割で脱落した B2・M4 の 3 件。いずれも「範囲ブランチでは直っているが float-nan に持ち込まれていない」型の問題で、分割作業の検証項目（§8.6「機能コミットが float-nan の差分を書き換えていないこと」）が「float-nan 単体で健全か」を含んでいなかったことが原因。

---

## 5. 分類

### PR 前に直すべき

1. **B1** `FloatRangeType::isSubTypeOf()` に `TemplateType`（できれば `CompoundType` 一般）の委譲を入れる。nsrt に `@return T of float` の偽陰性テストを追加。
2. **B2** `FloatRangeType::negate()` と `getUnaryMinusType()` の対応（union メンバごと）をこの PR に入れる。nsrt に `$f !== INF` → `-$f` を追加。
3. **M1/M2** 有限点除去を一貫させる。推奨: `FloatRangeType::tryRemove(有限点)` を `null` にして範囲 PR へ（nsrt の `is_finite && !== 0.0` は削除）。
4. **M4** `ConstantNumericComparisonTypeTrait` と `IntegerRangeType::createAll*()` の NaN ガード（commit 1 に含める）。
5. **M5** commit 2 を独立 PR として先に出す（再現: `if ($m < 9223372036854775808.0)`）。
6. **M6** `FloatRangeType::toString()` で ±INF を含む区間に `'INF'`/`'-INF'` を union する（小さく、型の存在意義に直結）。

### PR 本文で先回りして説明すべき

- **B3** 非サブクラス設計の理由と、Rector 等クラス分岐する消費者への影響（`FloatTypeMapper` に 1 行追加で済むこと、`toPhpDocNode()` が `float` を返すのでフォールバックは安全なこと）。あるいは (b) 継承版に切り替えた理由。
- **M3** `Box<float>` / `ArrayObject<int, float>` に NaN ガード済み値を渡すと invariant で弾かれる回帰。`int<1, max>` と同じ方針であること。
- **M7** `(int)` の範囲外 float が wrap 定数から `int` に変わる挙動変更、ホスト幅に従うこと、#6030 との関係。
- **M8** "Alternatives considered": `FloatType implements SubtractableType` 案を検討し採らなかった理由。
- **m2** #14394（`$f === $f`）は対象外。
- **m3** `is_nan($int)` 等が新たに「常に false」を報告する（changelog）。
- **m6** union の表示順で NAN が末尾に移る。
- **q2** `is_nan` は任意型、`is_finite`/`is_infinite` は `int|float` 確定時のみ、の非対称と根拠（`is_infinite("1e500")`）。
- 32-bit ホスト: `PHP_INT_MAX + 1.0`・16-bit 語の根拠と、CI に 32-bit ジョブがないこと。

### Ondřej に委ねてよい

- **q1** `float<-inf, inf>` の表示（`float~NAN` / `finite-float` という print-only 表記の是非）。
- **q3** `instanceof FloatRangeType` の散在（`IntegerRangeType` 踏襲）か `Type` メソッド化か。
- **m9** baseline 1 件の扱い（B1 の修正で消える可能性を添えて）。
- **M3** テンプレート引数推論で範囲を一般化するかどうか（現状の `int<1, max>` 方針との整合）。
- **B3** (a) 分離を維持して Rector と調整 / (b) 当面は継承、の選択。

---

## 6. 実行したプローブ一覧

すべて `/private/tmp/claude-501/-Users-megurine-repo-php-phpstan-src/57c31198-af9c-47de-b856-bda8e5738efb/scratchpad/probes-nan/`。ブランチは `review-float-nan/bin/phpstan -c probe.neon`（level 9, phpVersion 80500, 専用 tmpDir）、base は `/Users/megurine/repo/php/phpstan-src/bin/phpstan -c probe-base.neon`。

| ファイル | 要点 |
|---|---|
| `php-semantics.php` / `php-semantics-nonstrict.php` | PHP 8.5.9 実機: `(int) NAN/INF` = 0 + 警告、`(int) 1e19` = -8446744073709551616 + 警告、`(int) 2^63` = PHP_INT_MIN + 警告、`NAN === NAN` false、`max(1, NAN)` = NAN、`NAN == "NAN"` false、`NAN == true` true、`is_infinite("1e500")` true（非 strict）、`is_nan("1e500")` false、`is_numeric("INF")` false、`1 % NAN` は DivisionByZeroError、`[NAN => 1]` はキー 0 |
| `p1-nan-identity.php` | `===`/`==`/`<=>`/`in_array`/`match`/`switch`/配列キーでの NAN。同一変数 `$f === $f` 常に true（既存）、`$f !== $f` 常に false（既存）、`max(1, $f)` = 1（誤・既存） |
| `p2-predicates.php` | 3 述語 × `float\|null`, `numeric-string`, `int\|float\|null`, `positive-int`, `bool\|float`, リテラル union, `mixed`, `string`, `bool`。gating と「常に false」の新規報告 |
| `p3-ops.php` | NaN 抜き / 有限区間に対する 60 種の演算・キャスト・比較。`abs` = `float<0.0, inf>`、`$f <= INF` 常に true、`$f > 10.0` の分割区間表示（M2） |
| `p4-messages.php` | エラーメッセージに `float<-inf, inf>` / `float<(-inf, inf)>` が出る箇所、impossible check の報告 |
| `p5-remove-fold.php` | 分岐合流で `float` に戻ること、union メンバ絞り込み、`mixed~NAN` → `is_float` |
| `p6-preexisting.php` | base/branch 比較: `Box<float>` → `Box<float<-inf, inf>>`（M3）、`(int) 1e19`（M7）、定数 NAN の既存誤り（m1） |
| `p7-warnings.php` | 2^63 境界比較、`$m < NAN`、`%`/ビット演算/配列キー/`filter_var`/`**`/組み込み関数の定数 NAN・INF・1e19。base との diff: 2^63（M5）、`(int) 1e19`（M7）、union 順序（m6） |
| `p8-templates-order.php` | B1（`@return T` 偽陰性）、M1（順序依存）、B2（単項マイナス）、M3（invariant ジェネリクス）。base では全行 `float` |
| `warn-probe.php` | 型クラスを直接呼び、`set_error_handler` で PHP 警告を捕捉。base/branch で commit 1・2 の効果と、残存する警告（M4）を確認 |
| `unit-probe.php` | テンプレート健全性（B1）、intersect/union/remove の順序非依存性、`toString`、ソート決定性（shuffle 5 回で同一出力） |
| `bench-union.php` | `TypeCombinator` 性能（m4） |
| `self-analysis.log` | ブランチで `bin/phpstan`（phpstan.neon.dist + baseline）: `[OK] No errors`、36.9 s |
| 既存テスト（前回試行 `tests-*.log`） | NodeScopeResolverTest 1711、Type 6544、TypeSpecifierTest、AnalyserIntegrationTest、Rules/Comparison・Functions・Cast・Arrays: すべて OK |
