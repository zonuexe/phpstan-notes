# `float-range-type` コンセプト敵対的レビュー（第 7 巡・設計観点）

日付: 2026-09-02
対象: ローカルブランチ `float-range-type` の機能コミット bdd08e5fa「Add float ranges: PHPDoc syntax, comparison narrowing and finite-point removal」（= `backup/float-range-type-pre-rebase-20260902`。`phpstan/2.2.x` に rebase 済みの 169a8aa4b と内容同一）。基盤 `float-nan`（9b9d48e08）は別レビュー担当だが、「基盤の上にこの機能を載せた設計」として必要な範囲で参照した。
方法: 読み取り専用チェックアウト `scratchpad/review-float-range-type` の `bin/phpstan`（level 9, phpVersion 80500）でプローブを実行し、比較対象としてメイン worktree（現行 2.2.x 系、float range なし。以下「main」）、および `git archive HEAD~1` で展開した `float-nan` 単体（以下「base」）で同じプローブを実行した。PHP 8.5.9。プローブは `scratchpad/probes-range/` に置き、§6 に一覧する。凡例: **[検証済み]** = 自分で実行・実読した、**[疑い]** = 状況証拠のみ。

---

## 1. 結論

**判定: 現状のまま Draft PR に出すべきでない。「修正後可（Draft）」。再設計は不要。**

「NaN を含まない凸集合 + 開閉境界 + 正準化」というモデルそのものは健全で、比較絞り込み・点除去・`-0.0`・2^53 境界・NaN 対称性など、素朴な設計が踏み外す点はほぼ全て正しく処理されている（§3）。問題は、**この機能が既存コードの「`float` は上限のない top 型である」という暗黙の前提を大量に破る**のに、その前提に依存していた 2 箇所（算術の union 経路と `min`/`max` の三項書き換え）が未修正のまま残っていることである。どちらも PR の主機能（`!==`/`if ($f)` の点除去と比較絞り込み）の直接の帰結として、`if ($f !== 0.0) { 1 / $f === 0.0 }` や `max(0.0, min(1.0, $f))` のような**最も普通の float コード**で `identical.alwaysFalse` / `function.impossibleType` の誤検知を出す。Ondřej が最初に試すのはまさにこの手のコードなので、Draft でもこのまま出せば「型が嘘をつく」第一印象になる。

最重要リスク 3 つ:

1. **[Blocker] 算術が range を素通しする（健全性）**: `InitializerExprTypeResolver::resolveCommonMath()`/`integerRangeMath()` は union の非 int メンバをそのまま結果に流す。`$f !== 0.0` の後の `1 / $f` が `NAN|float<[-inf, 0.0)>|float<(0.0, inf]>`（0.0 を含まない）になり、`1 / INF === 0.0` が「常に false」と報告される。この経路は main でも `0.5|int<1,2>` + 1 → `0.5|int<2,3>` と壊れている（既存バグ）が、main では PHPDoc で union を書かない限り到達しない。本 PR は `!==`・`if ($f)`・`== null`・`== true`・比較の偽側すべてでこの union を生成するため、既存バグが float コードの既定経路になる。
2. **[Blocker] `max(0.0, $f)` から NaN が消える（健全性）**: `MinMaxFunctionReturnTypeExtension` は `max($a,$b)` を `$a < $b ? $b : $a` に書き換えて型付けする。本 PR の NaN 精密な絞り込みにより真側から NAN が除かれ、`max(0.0, NAN) = NAN` という実機挙動が型から消える。`max(0.0, min(1.0, $f))` → `float<0.0, 1.0>`、`is_nan()` が「常に false」。これは HEAD が導入した退行（base・main は `float`）。
3. **[Major] 構文と実用価値のギャップ**: (a) `describe()` が出す `float<-inf, inf>`・`float<[0.0, 1.0)>` はどれも PHPDoc に書き戻せず、書ける形は `float<min, max, closed-open>` という第 3 の語彙、(b) `-inf` は字句解析できないのに `float<-1e400, 0.0>` で `float<-inf, 0.0>` が作れてしまう、(c) #6963 の動機（`ceil` で range が失われる）はこの PR で 1mm も動かず、`@return float<0.0, 0.5>` の関数で `return unit() / 2;` が即 `return.type` エラーになる、(d) Psalm 6.16 は `float<0, 1>` を `InvalidDocblock` で拒否する。「注釈は書けるが、比較以外に使うと壊れる」構文を安定版に入れると、一方通行の互換性コミットになる。

---

## 2. 発見事項

### Blocker

#### B1. 算術の union 経路が `FloatRangeType`（と定数 float）を素通しし、到達可能な値を型から消す [検証済み]

**主張**: `+ - * /` の左右どちらかが int 定数/int range/union のとき、`resolveCommonMath()`（`src/Reflection/InitializerExprTypeResolver.php:2253-2261`、`$unionParts[] = $numberType;`）と `integerRangeMath()`（同 :2332-2341、`$unionParts[] = $type->toNumber();`）は union の非 int メンバを演算せずに結果へ流す。メンバが `FloatType`（top）なら無害だったが、`FloatRangeType` や `ConstantFloatType` は有界なので不健全になる。

**証拠**（`probes-range/arith-precise.php`、branch / main）:

| 式（`$f: float`, `if ($f !== 0.0)` 内） | branch | main | 実機で反例 |
|---|---|---|---|
| `1 / $f` | `NAN\|float<[-inf, 0.0)>\|float<(0.0, inf]>` | `float` | `1 / INF === 0.0` |
| `2 / $f`, `$f / 2`, `$f * 2`, `$f + $f`, `$f + 0`, `0 + $f` | 同上（素通し） | `float` | `5.0E-324 / 2 === 0.0`（PHP 8.5.9 で確認） |
| `$f + 1.0`, `$f - 1.0`, `$f ** 2` | `float` | `float` | （float 定数側の経路は正しく広がる） |
| `@param float<0.0, 1.0>\|int<5, 6> $mr` → `$mr + 1` | `float<0.0, 1.0>\|int<6, 7>` | （main では構文不可） | `0.5 + 1 === 1.5` |
| `$mr * 2` | `float<0.0, 1.0>\|int<10, 12>` | | |
| `@param 0.5\|int<1, 2> $x` → `$x + 1` | `0.5\|int<2, 3>` | **`0.5\|int<2, 3>`** | `0.5 + 1 === 1.5`（**main でも壊れている**） |

誤検知の実例（同ファイル `alwaysFalseBug`/`alwaysFalseBug2`）: `$h = 1 / $f; if ($h === 0.0)` → "Strict comparison using === between NAN|float<[-inf, 0.0)>|float<(0.0, inf]> and 0.0 will always evaluate to false"、`$k = $f / 2; if ($k === 0.0)` → 同、`$z = $x + 1; if ($z === 1.5)` → 同。3 つとも実機で到達可能。

**影響**: level 4 以上で `identical.alwaysFalse`・`smaller.alwaysFalse` 系の誤検知、および dead code 判定の誤り。`$f !== 0.0` はゼロ除算ガードとして極めて一般的なので、本 PR を入れた瞬間に多くのプロジェクトで発火しうる。base（float-nan 単体）でも `!is_infinite($f)` の真側（`NAN|float<(-inf, inf)>`）から `$f * 2` → `NAN|float<(-inf, inf)>`（`PHP_FLOAT_MAX * 2 = INF` が消える）で到達するが（`probes-range/attrib2.php` で確認）、HEAD が到達経路を桁違いに増やす。

**対処案**: `resolveCommonMath()` :2253-2261 と `integerRangeMath()` :2332-2341 の union 分岐で非 int メンバをそのまま流すのをやめ、メンバごとに再帰して演算する（`getUnaryMinusTypeFromType()` が本 PR で採ったのと同じ形）。`FloatRangeType` メンバは float 一般経路に落ちて `float` に広がり、`ConstantFloatType` メンバは定数計算される。同時に `tests/PHPStan/Analyser/nsrt/float-range-types.php` に「`!== 0.0` の後の `1 / $f` は `float`」「`float<a,b>|int<c,d>` の四則は float 側が `float`」を固定する。これは CLAUDE.md の「隣接コードの同じバグを探す」に該当し、PR に含めるべき。main の `0.5|int<1,2>` の件は独立の upstream バグとして報告できる（本 PR の前提を強くする）。

#### B2. `max($a, $b)` の三項書き換えが NaN 精密な絞り込みと組み合わさって NaN を落とす [検証済み]

**主張**: `src/Type/Php/MinMaxFunctionReturnTypeExtension.php:58-83`（コメント "rewrite min($x, $y) as $x < $y ? $x : $y"、`max` 分岐は :77-83）は 2 引数の `max` を `$a < $b ? $b : $a` として `$scope->getType(new Ternary(...))` で型付けする。PHP の `max` は `zend_compare($b, $a) > 0 ? $b : $a` であり、`NAN <=> x` が常に 1 なので **後ろの NaN が勝つ**（`max(0.0, NAN) = NAN`, `max(NAN, 0.0) = 0.0`、PHP 8.5.9 で確認）。三項 `0.0 < $f ? $f : 0.0` は `$f = NAN` で `0.0` を返すので、書き換え自体が NaN に対して誤りだった。main では `$f > 0.0` の真側が `float` のまま（NaN を含む）なので隠れていたが、本 PR で真側が `float<(0.0, inf]>` になり顕在化した。

**証拠**（`arith-precise.php`）:

| 式 | branch | main | 実機 |
|---|---|---|---|
| `max(0.0, $f)` | `float<0.0, inf>` ✗ | `float` | `max(0.0, NAN) = NAN` |
| `max($f, 0.0)` | `NAN\|float<0.0, inf>` ✓（過大近似） | `float` | `max(NAN, 0.0) = 0.0` |
| `min(1.0, $f)` | `NAN\|float<-inf, 1.0>` ✓ | `float` | `min(1.0, NAN) = NAN` |
| `min($f, 1.0)` | `float<-inf, 1.0>` ✓（正確） | `float` | `min(NAN, 1.0) = 1.0` |
| `max(0.0, min(1.0, $f))` | `float<0.0, 1.0>` ✗ | `float` | `NAN` |
| `min(1.0, max(0.0, $f))` | `float<0.0, 1.0>` ✗ | `float` | `NAN` |
| `max($unit, $f)`（`$unit: float<0.0,1.0>`） | `float<0.0, inf>` ✗ | `mixed` | `max(0.5, NAN) = NAN` |

誤検知の実例（`clampIdiom`）: `$c = max(0.0, min(1.0, $f)); if (is_nan($c))` → "Call to function is_nan() with float<0.0, 1.0> will always evaluate to false"。この後の `echo $c;` は PHP 8.5 で NAN 警告を出す経路であり、#15094 のルールを載せた瞬間に**偽陰性**にもなる。

**対処案**: `min` の書き換え `$a < $b ? $a : $b` は NaN でも偶然正しい（`0.0 < NAN` が false → `$b = NAN`、`NAN < 0.0` が false → `$b = 0.0`）。`max` だけ `$b < $a ? $a : $b` に入れ替えれば PHP の意味論と一致する（`max(0.0, NAN)`: `NAN < 0.0` false → `$b = NAN` ✓、`max(NAN, 0.0)`: `0.0 < NAN` false → `$b = 0.0` ✓、非 NaN では通常の max）。加えて nsrt に `max(0.0, $f)`/`max($f, 0.0)`/`min` の 4 通りと `max(0.0, min(1.0, $f))` を固定する。より一般に、「比較で PHP 組み込みを模倣している箇所」（`min`/`max`、`array_sum`、`range`、`in_array` 等）は本 PR 以降 NaN の非対称性を個別に検証する必要がある、と PR 本文に書く。

### Major

#### M1. 点除去で生まれる range union が既存の超線形経路（#15004 系）に float コードを常時流し込む [検証済み]

**主張**: `!==`/`===`ガードを積むと、int では定数（`FiniteTypeSet` の O(1) 経路）になるが、float では常に `FloatRangeType` の断片になる。range union の集合演算は既存コードでも超線形であり、本 PR はそれを float の既定経路にする。

**証拠**（`probes-range/perf-*.php`、`/usr/bin/time`）:

| 入力 | branch | main |
|---|---|---|
| `if ($f === k/1000) return;` × 50 / 100 / 200 / 300 | 1.5 / 2.4 / 15.4 / **59.6 s** | – / – / – / 1.55 s |
| `if ($i === 2k) return;` × 300（結果は定数 union） | 2.2 s | 2.4 s |
| `if ($i === 3k) return;` × 300（結果は **int range** union） | 35.8 s | **31.4 s** |
| `$f !== c && …` × 200（1 条件） | 43.8 s | – |
| `$i !== 3k && …` × 100（int range union） | 2.5 s | 3.2 s |
| `$f !== c && …` × 100 | 6.7 s（level 0 でも 5.5 s） | – |
| `if ($f === c) return;` × 200: level 0 / level 9 | 2.3 s / 16.2 s | |

純粋な型代数（`TypeCombinator::remove` を 300 回、`probes-range/perf-algebra.php`）は 0.26 s、`nextUp()` 呼び出し 362k 回、1 回 0.66 µs なので、`FloatRangeType` 自体は遅くない。遅いのは range union を抱えたまま scope/rule が走る既存経路で、int range でも同じ（main の 31 s）。upstream には #15004「Performance: super-linear analysis on literal-union !== narrowing chains」（open、staabm が blackfire で `UnionType::isSubTypeOf`/`getFiniteTypes` をホットパスと特定）がある。

**影響**: 現実的な件数（≤ 50）では 1.5 倍程度で問題ないが、150 を超えると分単位になる。数値コードの `match(true)` や大量の `=== 0.5` 分岐で顕在化しうる。CLAUDE.md の「limit で誤魔化さない」に従うと、range 専用の順序付き集合構造（ソート済み区間の二分探索）が必要で、本 PR の範囲を超える。

**対処案**: PR 本文で #15004 との関係を明記し、「float は `!==` で必ず range 断片になるため、int より早く #15004 の経路に入る」と数字付きで書く。Ondřej に「#15004 の解決を先に置くか、float の点除去だけ（`FloatType::tryRemove(ConstantFloatType)`）を bleeding edge 下に置くか」を問う。

#### M2. #6963 の動機はこの PR で満たされず、注釈は「比較以外に使うと壊れる」 [検証済み]

**主張**: PR 草稿は "Prototype for #6963" と書くが、#6963 の OP のプレイグラウンド（`count($list) / 4` → `ceil()` → `(int)` → `array_chunk` の `int<1, max>` 引数）は本 PR で無変化。注釈 `float<0.0, 1.0>` の値は比較・単項マイナス・`abs`・`min/max`・`(int)`・`(bool)` に限られ、四則・`ceil/floor/round`・`sqrt`・`**`・`++`・`+=`・`array_sum`・`Randomizer::getFloat`・`lcg_value` はすべて `float` に落ちる。

**証拠**（`probes-range/motivation.php`）: `ceil($i / 2)` → `float`（main と同じ）、`(int) ceil($i / 2)` → `int`。`@return float<0.0, 0.5> function half() { return unit() / 2; }` → "should return float<0.0, 0.5> but returns float"。`clamp2()`（`if ($f < 0.0) return 0.0; if ($f > 1.0) return 1.0; return $f;`）→ "should return float<0.0, 1.0> but returns float"（実型は `NAN|float<0.0, 1.0>`、M4 参照）。対照的に `int<0, 10>` は `* 2` → `int<0, 20>`、`+ 1` → `int<1, 11>`、`+= 5` → `int<5, 15>`。

**影響**: 利用者は注釈を書いた直後に `return.type` に当たり、`@phpstan-ignore` かキャストで逃げる。Ondřej の立場（#11465/#12865 で「float range が入れば自動的に動く」と期待している）と、この PR が提供するものの間にギャップがある。

**対処案**: PR 本文の冒頭で「比較と点除去のみ。算術は別 PR」と明記し、**#6963 を Closes に書かない**。`clamp` は `is_nan()` ガードを先に置くと通る（`clamp3` で `float<0.0, 1.0>` を確認）ので、その idiom を nsrt と PR 本文に入れる。

#### M3. 構文が一方通行: 出力は書き戻せず、`-inf` は書けず、無限大に 3 つの綴りがある [検証済み]

**証拠**（`probes-range/syntax-param.php`, `roundtrip.php`）:

| 入力 | 結果 |
|---|---|
| `float<min, max>` | `float<-inf, inf>` |
| `float<-inf, 1.0>` | `phpDoc.parseError` "Unexpected token \"-inf,\""、パラメータは `mixed` |
| `float<0.0, INF>`（PHP 定数の綴り） | `parameter.unresolvableType`（`inf` 小文字のみ受理） |
| `float<PHP_FLOAT_MAX, inf>`, `float<PHP_INT_MAX, inf>` | unresolvable（`self::LOW` はクラス定数として解決される） |
| `float<-1e400, 0.0>` | **`float<-inf, 0.0>`**（`-1e400` が `-INF` に丸まる。`-inf` の抜け道） |
| `float<0.0, 1e-400>` | `0.0`（上端が 0 に潰れて 1 点） |
| `float<0x10, 100>` | `float<0.0, 100.0>`（`(int) '0x10'` = 0。`int<0x10, 20>` → `int<0, 20>` も同じ既存バグ） |
| `float<0, 1, ClosedOpen>`, `'closed-open'`, `\Random\IntervalBoundary::ClosedOpen`, `OPEN-OPEN` | unresolvable（kebab-case 小文字のみ） |
| エラーメッセージ | `float<[0.0, 1.0)>`, `float<(0.0, inf]>`, `float<(-inf, inf)>`, `float<-inf, inf>` を印字（いずれも PHPDoc 不可） |
| `toPhpDocNode()`（`dumpPhpDocType`, `VarTagTypeRuleHelper`） | `float<min, max, open-open>`（有限）。往復は成立するが describe とも入力とも別語彙 |

無限大の綴り: 定数型は `INF`/`-INF`、range の describe は `inf`/`-inf`、入力は `inf`（上端のみ）/`min`/`max`、`toPhpDocNode` は `max`/`min`。spec §6.3 自身が「`float<min, max>` は `PHP_FLOAT_MIN`（正の最小正規化数）と混同される」と論じているのに、プロトタイプは `min` を採用し `toPhpDocNode` でも出力する。旧 PHPStan（main）では `float<0, 1>` は level 2 の `parameter.unresolvableType` + パラメータ `mixed`（`probes-range/ecosystem.php`）。

**影響**: 一度 `min`/`max`/`closed-open` を安定版で受理すると、後で `float<-inf, inf>` や括弧記法を入れても BC のため永久に残る。`-1e400` の抜け道があるということは、字句解析器の制限は本質的でなく phpdoc-parser 側の 1 トークン追加で解ける（phpdoc-parser も Ondřej 管理）。

**対処案**: PR 前に phpdoc-parser へ `-inf` トークン（または `-` + 識別子）の PR を出し、PHPStan 側は `float<-inf, inf>` のみ受理して `min`/`max` エイリアスを持たない。`inf` は大文字 `INF` も受理する（PHP 定数と同綴り）。`closed-open` は暫定として PR 本文で「表示は括弧形、入力は暫定キーワード。括弧形の入力は phpdoc-parser の文法拡張を待つ」と明記し、Ondřej に選ばせる。

#### M4. `VerbosityLevel::getRecommendedLevelByType()` に `FloatRangeType` が無く、メッセージから NAN が消える [検証済み]

**主張**: `src/Type/VerbosityLevel.php:185` は `IntegerRangeType` を見て `value()` 冗長度に上げるが `FloatRangeType` を見ない。`typeOnly` では `UnionType::describe()` が定数を一般化するので `NAN|float<0.0, 1.0>` は `float` と印字され、原因が NaN であることが利用者に伝わらない。

**証拠**（`probes-range/messages.php`）: `takesInt01(2)`（`int<0, 1>` 引数）→ "2 given"、`takesUnit(2)`（`float<0.0, 1.0>` 引数）→ "**int** given"、`takesUnit(NAN)` → "**float** given"、`clamp2()` → "returns **float**"（`dumpType` は `NAN|float<0.0, 1.0>`）。

**影響**: B2 と同様、最も普通のコード（clamp）で「float<0.0, 1.0> を返すべきなのに float を返す」という、修正手段の分からないメッセージになる。CLAUDE.md の「隣接コードの同じバグ」の典型。

**対処案**: `VerbosityLevel.php:185` の条件に `|| $type instanceof FloatRangeType` を足す（1 行）。加えて、NAN 定数を含む union は `typeOnly` でも `NAN` を残す方が親切だが、それは `UnionType::describe()` の方針変更なので Ondřej に委ねる。

#### M5. エコシステム影響: baseline が壊れ、`describe()` が変わり、Psalm が構文を拒否する [検証済み]

**証拠**:
- baseline（`probes-range/baseline-src.php`）: main で生成した baseline に `expects int, float given` が count 3 で入る。branch で同じ baseline を読むと `if ($f > 0) takesInt($f)` が `float<(0.0, inf]> given` に変わり、**新規エラー + `ignore.count`（3 回のはずが 2 回）**の 2 件が出る。NAN を含む union（`!== 0.0`、`if ($f)`、`array_filter`）は `typeOnly` で `float` に畳まれるため壊れない。壊れるのは比較の真側だけだが、`> 0` ガードは頻出。
- Psalm 6.16.1（`probes-range/psalm-src.php`）: `float<0, 1>` → "InvalidDocblock: Cannot create generic object with reserved word"、`float<0.0, 1.0, closed-open>` → "InvalidDocblock: Unrecognized type closed-open"。`int<0, max>` は受理される。ライブラリが `float<...>` を書くと Psalm 利用者にエラーが出る。（副産物: Psalm は `e4(NAN)` で PHP 8.5 の NAN→string 警告を踏んでクラッシュする。Psalm 側のバグ。）
- describe の変化（`tests` の期待値 15 件と `probes-range/comparisons.php` の diff）: `array_filter(array<bool|float|int|string>)` の値型が `float|…` から `NAN|float<[-inf, 0.0)>|float<(0.0, inf]>|…` に、`mixed~(int<3, max>|true)` が `mixed~(NAN|float<3.0, inf>|int<3, max>|true)` に。IDE のホバー・`dumpType`・エラーメッセージが一様に長くなる。

**影響**: minor リリースでの型推論改善に伴うメッセージ変化は upstream の慣行内だが、bleeding edge 無しで入れると baseline を持つ全プロジェクトに影響する。Psalm 非互換は `int<>` 導入時にもあった歴史なので致命的ではないが、PR 本文で触れるべき。

**対処案**: PR 本文に「変わるメッセージの類型」を列挙し、Ondřej に bleeding edge 配下にするか問う（Q1）。

### Minor

#### m1. union 内の `IntegerRangeType` は単項マイナスで反転されない（既存バグ、本 PR の再帰で半分だけ直る） [検証済み]
`-(float<0.0, 1.0>|int<5, 6>)` → `float<-1.0, 0.0>|int<5, 6>`（`probes-range/arith-precise.php:38`）。main でも `-(float|int<5, 6>)` → `float|int<5, 6>`、`-(0.5|int<1, 2>)` → `0.5|int<1, 2>`（`probes-range/neg-union.php`, `const-union.php`）。HEAD の `getUnaryMinusTypeFromType()` のメンバ再帰は定数と `FloatRangeType` を直したが、`IntegerRangeType` メンバは `getConstantScalarValues()` が空で素通り。`if ($y === -5)` が「常に false」になる。対処: 再帰内で `IntegerRangeType` を `Mul(-1)` 経路か新設 `negate()` に回す。

#### m2. `FloatRangeType::looseCompare()` がスタブで、int range との対称性が無い [検証済み]
`int<0, 1> == 2` は `false` + `equal.alwaysFalse` だが `float<0.0, 1.0> == 2.0` は `bool` で報告なし（`arith-union.php:51`）。`$u <=> 2.0` も `int<-1, 1>`（int 側も同じ）。健全だが「int と同じ精度」を期待されると不足。

#### m3. 境界の暗黙の丸めが無警告 [検証済み]
`float<9007199254740993, 9007199254740995>` → `float<9007199254740992.0, 9007199254740996.0>`（M3 の表も参照）。double の集合としては正しいが、書いた値と違う値が印字される。`int<…>` には無い現象なので、doc に一言。

#### m4. ループ後の型に NAN が現れる [検証済み]
`for ($f = 0.0; $f < 1.0; $f += 0.1) {}` の後で `$f` は `NAN|float<1.0, inf>`（`probes-range/loops.php:12`）。健全だが、int の `int<10, max>` に慣れた利用者は「なぜ NaN？」と issue を立てる。`ConstantFloatType::generalize()` が `float`（NaN 込み）に広げる以上避けられないので、FAQ 的に PR 本文へ。

#### m5. `$f . ''` が `'NAN'|(numeric-string&uppercase-string)` になる [検証済み]
`arith-precise.php:27`。`!== 0.0` の後の文字列化で `'NAN'` が union に出る。#15094 のルールの材料としては正しいが、main の `numeric-string&uppercase-string` から見ると冗長。

#### m6. `FilterFunctionReturnTypeHelper.php:293` のコメント「PHPStan does not yet support FloatRangeType」が古い [検証済み]
`FILTER_VALIDATE_FLOAT` の `min_range`/`max_range` は未対応のまま（`arith-union.php:24-28` で `float`）。PR 本文は「含めない」と書いているので整合はしているが、コメントは直すべき。

#### m7. `toArrayKey()` が `IntegerType` を返す [検証済み]
`[$u => 1]`（`$u: float<0.0, 1.0>`）→ `non-empty-array<int, 1>`。`toInteger()`（`int<0, 1>`）を返せる。`ConstantFloatType(1.0)` は `array{1: 1}` になるので不揃い。

#### m8. `$f !== $f` の alwaysFalse / `NAN == NAN` の alwaysTrue が既存のまま [検証済み]
`comparisons.php:52-54, 98-100`, `arith-union.php:44`。main と同じ既存誤検知だが、本 PR で PHPStan が `NAN|…` を至る所に印字するようになるため「NaN を知っているのに `$f !== $f` を常に false と言う」矛盾が目立つ。spec §3.3 は Phase 0 としていたが PR には無い。

#### m9. `@api` 無し、`Random\IntervalBoundary` は名前だけ借りて綴りが違う [検証済み]
`FloatRangeType` に `@api` は無く（`IntegerRangeType` にはある）、`fromInterval()`/`getMin()`/`getMax()`/`isMinInclusive()`/`isMaxInclusive()` は公開。PHP の enum case は `ClosedOpen`（PascalCase）で、PHPDoc は `closed-open`。「PHP 8.3 の語彙」と言うなら `\Random\IntervalBoundary::ClosedOpen`（ConstFetchNode、§7.1.3 の B'）を受理する方が主張と一致する。

### Question

#### Q1. bleeding edge 配下にしないのか [疑い]
M1・M5 の通り、型推論結果とメッセージが広範に変わる。upstream の最近の慣行（`list{}` 等）では bleeding edge を経由している。PR 本文で先回りして問う。

#### Q2. `float<-inf, inf>` がメッセージに出ることの受容 [検証済み]
`@return float<min, max>` の関数で `return $f;` → "should return float<-inf, inf> but returns float"（`roundtrip.php:25`）。spec §9-3 と同じ論点だが、書けない綴りで出る点が追加の摩擦。

#### Q3. int 側の 2^53 不正確さ [検証済み・既存]
`$i > 9007199254740992.0` の偽側が `int<min, 9007199254740992>` で、実機では `9007199254740993 > 9007199254740992.0` が false（int→double 変換で等しくなる）なので偽側に 9007199254740993 が入るべき（`comparisons.php:74`）。float 側（`$f > 9007199254740993` → 境界 `9007199254740992.0`）は正確。`IntegerRangeType::createAllSmallerThanOrEqualTo(float)` の既存問題で本 PR の範囲外だが、両側の一貫性として質問に値する。

#### Q4. `$f == ""` の真側が `0.0` [検証済み・既存]
PHP 8 では `0.0 == ""` は false（実機確認）。main も同じ結果（`comparisons.php:26`）。本 PR の範囲外。

---

## 3. 現設計が正しくやっていること（PR 本文で守るべき論点）

いずれも [検証済み]（`probes-range/comparisons.php`, `negzero.php`, `arith-union.php`）。

1. **偽側の NaN 保持と「相手が NaN でありうるなら絞り込まない」**: `if ($u > $f)`（`$u: float<0.0,1.0>`, `$f: float`）の else で `$u` は `float<0.0, 1.0>` のまま、`$f` は `NAN|float<0.0, inf>`。`if ($f > 0 || $f <= 0) {} else {}` の else は `NAN`、`match(true) { $f > 0.5 => …, $f <= 0.5 => … }` は "does not handle remaining value: true"、`else { if (is_nan($f)) … }` で `NAN` / `float<-inf, 0.0>` に分かれる。素朴に「偽側 = 補集合」とすると全部壊れる。
2. **`$f !== NAN` は `float` のまま**（NAN を除かない）: `NAN !== NAN` は true なので正しい。`$f === NAN` の真側が `NAN`（never ではない）は #14394 の既知の不正確さで、健全側に倒れている。
3. **`-0.0` を 1 点として扱う**: `takesPositive(-0.0)`（`float<(0.0, 1.0]>`）は拒否、`takesUnit(-0.0)` は受理、`$f !== 0.0` の後の `$f === -0.0` は never で `identical.alwaysFalse`、`if ($f === -0.0)` の中で `(string) $f` は `'-0'|'0'`。
4. **正準化**: `float<0.0, 1.0, open-open>` の中で `=== 5.0E-324` と `=== 0.9999999999999999` は到達可能、`=== 0.0`/`=== 1.0` は never。`float<0.0, 5.0E-324, open-open>` は never。`float<5.0E-324, 0.9999999999999999>` と `(0.0, 1.0)` は同一。`$f < $halfOpen`（`[0.0, 1.0)`）→ `float<[-inf, 0.9999999999999999)>`（前回レビュー P2 の修正が効いている）。
5. **int↔float 比較の double 変換**: `$f > 9007199254740993` → `float<(9007199254740992.0, inf]>`（PHP が int を double に変換して比較する事実と一致）、`$f > PHP_INT_MAX`（`int<2147483647, 9223372036854775807>` としてモデル化されている）→ 真側 `float<(2147483647.0, inf]>`・偽側 `float<-inf, 9.223372036854776E+18>` と存在量化の意味論で一貫。
6. **結合と穴**: `float<0.0, 1.0, closed-open>|float<1.0, 2.0, open-closed>` は結合しない（1.0 に穴）、`float<0.0, 1.0>|1.0` は `float<0.0, 1.0>`、`float<0.0, 1.0>|float<1.0, 2.0>` は `float<0.0, 2.0>`。
7. **受理**: `float<0.0, 1.0>` は `int<0, 1>` を受理し `int<0, 2>`・`int`・`float` を拒否、strict でも int→float は受理（PHP の暗黙変換と一致）。
8. **`min`/`max` の片側は既に正しい**: `max($f, 0.0)`・`min(1.0, $f)`・`min($f, 1.0)` の 3 通りは PHP の「後ろの NaN が勝つ」規則に対して健全。壊れているのは `max` の引数順 1 通りだけで、修正は書き換えの入れ替え 1 箇所（B2）。
9. **`$f == true`/`== null`/`if ($f)`/`!$f`** の真偽側が `0.0` と `NAN|float<[-inf, 0.0)>|float<(0.0, inf]>` に正しく分かれる（`(bool) NAN` は true）。

---

## 4. Phase 2: 既存レビューとの照合

参照: `20260822-float-range-adversarial-review.md`（初回 + 再レビュー 3 回）、`20260822-float-nan-branch-adversarial-review.md`、spec §8.3/§8.4。

| 本レビューの項目 | 分類 | 備考 |
|---|---|---|
| B1 算術パススルー | **新規** | 既存レビューは算術を「follow-up 扱い」としてのみ言及。パススルーの不健全性は未指摘。main の `0.5\|int<1,2>` も未指摘 |
| B2 `max` の NaN 落ち | **新規** | 既存レビューは `min`/`max` を扱っていない |
| M1 range union の性能 | **新規** | #15004 は upstream に存在するが既存レビュー・spec に言及なし |
| M2 #6963 未達・注釈の実用価値 | 既出・解決済みだが不十分 | 初回 Blocker 1「5 件を同時に解決は事実ではない」は #15094/#13859/#9250/#13504/#14394 を扱い「groundwork」に改めた（§8.3）。しかし **#6963 自体**（`ceil`）が未達であることと、`return $x / 2` が即エラーになる実用上の帰結は未指摘 |
| M3 構文の一方通行（`-inf`, `min`/`max`, `INF`, 3 綴り, `-1e400`） | 既出・解決済みだが不十分 | 初回 4「toPhpDocNode が意味を失う」は `closed-open` 往復で解決（§8.3）。describe → PHPDoc の不成立、`min` の混同（spec §6.3 自身の指摘）、`INF` 大文字拒否、`-1e400` 抜け道は新規 |
| M4 VerbosityLevel の欠落 | **新規** | |
| M5 baseline / Psalm / describe 変化 | **新規** | |
| m1 union 内 int range の単項マイナス | **新規** | 既存の union 再帰は §8.2 で「定数+range の union で range を落とす」修正として記載、int range メンバの取りこぼしは未指摘 |
| m2 looseCompare スタブ | 新規 | |
| m3 境界の丸め、m4 ループ後の NAN、m5 `'NAN'` 連結、m7 toArrayKey | 新規（軽微） | |
| m6 stale コメント | 新規 | |
| m8 `$f !== $f` | 既出（spec §3.3 Phase 0）・未解決 | PR に含めない判断は妥当だが本文で先回りすべき |
| m9 `@api` / IntervalBoundary 綴り | 既出（issue 草稿 Q6）・未解決 | 綴りの論点は新規 |
| Q3 int 側 2^53 | 新規（既存バグ） | |
| 初回 3「successor 未処理」 | 既出・解決済みで妥当 | `(0.0, 5.0E-324)` → never、正準 equals を再検証 |
| 初回 6「open bound → int」 | 既出・解決済みで妥当 | `(int) float<0.0, 1.0, open-open>` の経路は `toInteger()` が正準境界で切り捨て（コード確認）。`(int) $u` → `int<0, 1>` |
| 再 P1/P4 述語の pure int / numeric-string | 既出・解決済みで妥当 | `is_finite($unit)` → alwaysTrue、`is_finite(float)` → `float<(-inf, inf)>` / `-INF\|INF\|NAN` を再確認。numeric-string union は今回未再検証 |
| 再 P2 1 ULP | 既出・解決済みで妥当 | `compareAgainstHalfOpenRange` を再確認 |
| S1 `instanceof FloatRangeType` 散在 / P3 baseline | 既出・未解決（開示済み） | 判断は変えない。本レビューの B1/B2 は「散在した具象分岐の外側にある、`float` = top を仮定したコード」が壊れる例であり、S1 の議論を補強する |
| float-nan レビュー: 32-bit `nextUp`/castToInt/`PHP_INT_MAX` | 既出・解決済みで妥当（コード上） | `unpack('n4', pack('E'))`・`FLOAT_ABOVE_INT_MAX = PHP_INT_MAX + 1.0`・`castToInt(): ?int` を実読。32-bit 実機は未検証 |

既存レビューが「解決済み」とした項目の再検証で解決が不十分だったものは M2・M3 の 2 件。いずれも「往復が成立する／過大申告を撤回した」という形式的解決で、利用者の体験（書けない綴りが出る、注釈を使うと壊れる）は残っている。

---

## 5. 分類

**PR 前に直すべき**
- B1 算術の union 経路のメンバ再帰（+ nsrt）
- B2 `max` の書き換えを `$b < $a ? $a : $b` に（+ nsrt 4 通り + clamp idiom）
- M4 `VerbosityLevel.php:185` に `FloatRangeType`（1 行）
- m1 union 内 `IntegerRangeType` の負号（B1 と同じ関数群）
- m6 stale コメント
- M3 のうち: `INF` 大文字の受理、`-1e400` が `-inf` になる事実の nsrt 化（少なくとも挙動を固定）

**PR 本文で先回りして説明すべき**
- M2: #6963 の OP のケースは未達、算術は別 PR、`clamp` は `is_nan()` ガードが要る（nsrt に例）
- M1: #15004 との関係と数字（300 ガードで 60 s、int range でも 31 s）
- M5: 変わるメッセージの類型、baseline への影響、Psalm 非互換
- m4/m5/Q2: `NAN|…` と `float<-inf, inf>` がユーザーの目に触れる場面
- m8: `$f !== $f` は別 PR

**Ondřej に委ねてよい（質問文案）**
- M3 構文: "Would you accept a phpdoc-parser change that lexes `-inf` (a `-` prefix on the `inf` identifier, or a dedicated token) before this lands, so that `float<-inf, inf>` is the only spelling and no `min`/`max` alias has to be kept for BC? Today `float<-1e400, 0.0>` already produces `float<-inf, 0.0>` because the literal overflows, so the lexer restriction is not load-bearing. Related: `INF` (uppercase, the PHP constant) is currently rejected as a bound; should both cases be accepted?"
- M3 開閉: "For open bounds the parseable form is the `Random\IntervalBoundary` vocabulary as a third argument (`float<0.0, 1.0, closed-open>`), while `describe()` prints `float<[0.0, 1.0)>`. Are you fine with two spellings, or would you rather see the bracket form parse (a context-sensitive extension of `GenericTypeNode` in phpdoc-parser) before anything ships?"
- Q1: "This changes inferred types and messages wherever a float is compared or tested for zero (`$f > 0` → `float<(0.0, inf]>`, `!== 0.0` → `NAN|float<[-inf, 0.0)>|float<(0.0, inf]>`), which invalidates baseline entries that mention `float given`. Should the narrowing be gated behind bleeding edge for 2.2?"
- M1: "Every `!==` on a float now yields a range union, so float code enters the super-linear path of #15004 much sooner than int code (300 `=== c` guards: 60 s here vs 1.5 s today; the same guards on int ranges already take 31 s on 2.2.x). Do you want #15004 addressed first, or is it acceptable to land narrowing and treat the perf as a known limitation?"
- m9: "`FloatRangeType` is `final` and without `@api`; `IntegerRangeType` is `@api`. Extension authors will want `getMin()`/`getMax()`/`isMinInclusive()`/`isMaxInclusive()`. Mark it `@api` now, or wait until arithmetic lands?"
- S1/P3（既存）: `instanceof FloatRangeType` の散在と baseline 1 件。

---

## 6. 実行したプローブ一覧

すべて `/private/tmp/claude-501/-Users-megurine-repo-php-phpstan-src/57c31198-af9c-47de-b856-bda8e5738efb/scratchpad/probes-range/`。`*.out` が branch、`*.main.out` が main の出力（パス接頭辞を除去済み）。`run.sh <file>` / `run-main.sh <file>` で再実行できる（`-c probe.neon` / `probe-main.neon`、tmpDir 分離）。base（float-nan 単体）は `scratchpad/base-float-nan/`（`git archive HEAD~1` + vendor コピー）で `probe-base.neon`。

| ファイル | 要点 |
|---|---|
| `syntax-param.php` | `@param` 構文 55 種（`min/max/inf/-inf/INF`、int 境界、16 進、逆転、1 点、NAN、境界キーワードの綴り違い、`1e400`/`1e-400`、`self::CONST`、既定値） |
| `syntax-alias.php` | `@phpstan-type`/`@phpstan-import-type` 経由、逆転・空区間・`-inf` |
| `motivation.php` | `float<0.0, 1.0>` と `int<0, 10>` に同じ演算を通した対照表、#6963 の `ceil` 再現、`half()`/`clamp2()`/`fromInt()` |
| `roundtrip.php` | エラーメッセージに出る describe 形、`float<-inf, inf>` の `@return` |
| `negzero.php` | `-0.0` の定数・比較・受理、正準境界（`5.0E-324`, `0.9999999999999999`, `PHP_FLOAT_MAX`）、有限区間と `INF` |
| `comparisons.php` (+ `.main.out`, diff) | 演算子 × 相手型（int, int\|float, numeric-string, ?float, bool, mixed, string, range 同士）の真偽側、`<=>`、`== NAN`、`match` 網羅性、alwaysTrue/False 報告。main との差分で退行/改善を分離 |
| `arith-union.php`, `arith-precise.php` (+ `.main.out`) | union 上の四則・単項マイナス・`abs`・`min`/`max`・`filter_var`・`is_finite`、`== NAN` 系の真偽側を区別、int range との対称性、alwaysFalse 誤検知の再現 3 件 + clamp idiom |
| `attrib.php`, `attrib2.php` | base vs HEAD で `is_finite`/`!is_nan`/`!is_infinite` の後の算術と `min`/`max`（帰属の判定） |
| `neg-union.php`, `const-union.php` | `-(float\|int<5,6>)`、`(0.5\|int<1,2>) + 1` を main と比較（既存バグの確認） |
| `loops.php` | while/for/foreach での安定性、20 個の `!==` の union、`/ 2` の反復 |
| `int-interaction.php`, `int-interaction-strict.php` | 非 strict/strict での受理、配列キー、`is_int`/`is_float`/`is_numeric`、組み込み関数への引数 |
| `messages.php` (+ main) | `getRecommendedLevelByType` の差（`int<0,1>` vs `float<0.0,1.0>`）、`clamp2`/`clamp3`/`clamp4` |
| `ecosystem.php`（main）, `psalm-src.php` + `psalm-eco.xml`（Psalm 6.16.1） | 旧 PHPStan と Psalm の反応 |
| `baseline-src.php`, `baseline-from-main.neon`, `baseline-branch.neon` | main で生成した baseline を branch で読む |
| `perf-float{,-50,-100,-200}.php`, `perf-int.php`, `perf-int-gap.php`, `perf-int-range-return-300.php`, `perf-float-gt.php`, `perf-float-lt.php`, `perf-float-noreturn.php`, `perf-float-chain{,-100}.php`, `perf-int-chain-100.php`, `level0.neon`…`level5.neon` | 計時（branch/main、level 別、if-return/chain、定数/int range/float range） |
| `perf-algebra.php` + `FloatRangeTypeInstrumented.php` | 純粋な型代数の計時と `nextUp()` 呼び出し数（リポジトリ非改変。`vendor/autoload.php` の後に計測用コピーを require） |
| `prepend-stats.php`, `*Stats.php` | 実解析中の呼び出し数プロファイル（クラス先頭への挿入に失敗し未完。`prepend-count.php` は `bin/phpstan` のローダ処理と衝突） |

実機確認（`php -r`）: `NAN > null`、`-0.0 >= 0.0`、`PHP_INT_MAX == 2^63`、`9007199254740993 == 9007199254740992.0`、`max/min` × NAN の 4 通り、`NAN <=> x`、`0.0 == ""`、`(int) "0x10"`、`5.0E-324 / 2 === 0.0`、`1 / INF`、`max(0.0, min(1.0, NAN))`、`PHP_FLOAT_MAX * 2`。

upstream 読み取り: #6963（本文 + 5 コメント）、#13859、#15094、#9250、#13504、#11465、#12865、#14394、#15004、`gh search issues` 5 語（"float range" 6 件、"positive-float" 34 件中関連 ~8、"float<" 50 件中関連 ~12、"non-negative-float" 7 件、"NAN float" 0 件）。Ondřej の立場は「IntegerRangeType をコピーするな。開閉区間を検討せよ」（#6963）と「float range が入れば自動的に動く」（#11465, #12865）で、機能自体には前向き。float 定数演算の精度について Ondřej 自身の発言は見つからず、mvorisek の `0.1` 表現の懸念（#6963）と「非有限で well-defined でなければ導入しない」（#13859）のみ。
