# range() の PHP 8.3 対応 — 現状と展望

調査日: 2026-09-21（第2巡レビュー §6 → 修正 §6.1 → `strict_types` 検証 §6.2、すべて同日）

- 対象 issue: [phpstan/phpstan#10022 Support PHP 8.3 `range()` changes](https://github.com/phpstan/phpstan/issues/10022)
  （2023-10-17 起票、feature-request、open、リンクされた PR なし。**起票者は zonuexe = 本レポートの作者本人**）
- 検証環境: PHP 8.5.10 (homebrew) / phpstan-src `2.2.x` @ `14ad31b5cf4f`
- **提出済み PR: [phpstan/phpstan-src#6492](https://github.com/phpstan/phpstan-src/pull/6492)**
  （base `2.2.x`、head `zonuexe:10022/range-php83`、commit `247646ee2`）
  - `phpstan/2.2.x` @ `1dfc161e7` に rebase 済み。rebase 後に全ゲート再検証
    （paratest 21580 OK / 自己解析 No errors / phpcs clean）
  - 第2巡レビュー前の状態は `10022/range-php83-pre-review`（= 旧 commit `343fe8ac291b`）に退避
- 関連: [20260822-float-range-type-spec-research-ja.md](20260822-float-range-type-spec-research-ja.md)
  （float 区間型 = 別機能の提案）、[20260822-range-syntax-survey-ja.md](20260822-range-syntax-survey-ja.md)
- PR ドラフト: [20260921-range-php83-pr-draft.md](20260921-range-php83-pr-draft.md)

---

## 0. TL;DR

1. **issue の再現コード `range('1', 'a')` は、PHPStan を PHP 8.3+ で動かしている限りすでに正しく推論される。**
   `RangeFunctionReturnTypeExtension` が解析時に *実行時 PHP の* `range()` を呼んで定数畳み込みしているため。
2. ただし齟齬が残っていた。そのうち 2 つを修正し、commit `343fe8ac291b` にまとめた。
   - 不正 step で `ValueError` になる呼び出しが `*NEVER*` にならない → 修正
   - 50 要素超の一般化パスが PHP 8.3 仕様を反映していない → 修正
3. 残りは判断待ち / 構造的:
   - 非定数 `string` / `numeric-string` 引数の戻り型 → **見送り**（理由は §4、判断待ち）
   - **実行時 PHP 依存**（`phpVersion` 設定が range() の値に効かない）→ §5.1、別 PR 級
4. **第2巡の批評的レビュー（§6）で、`343fe8ac291b` をそのまま PR 化すべきでないと判断した。**
   `*NEVER*` のバージョンゲートが `>= 8.0` でしかなく、解析対象 8.0–8.2 で誤った `*NEVER*` を返して
   実在のエラーを握り潰すことを実測で確認した（B1）。大レンジの一般化も対象バージョン非依存だった
   パスを実行時依存に変えていた（B2）。
   **B1–B4 を修正し `phpVersion` 軸のテストを追加して commit `6b08204854` に差し替え済み（§6.1）。
   この状態なら PR に出せる。**
5. 呼び出し側の `declare(strict_types=1)` の有無はこの変更に影響しない（§6.2 で実測確認）。
   ただし「引数の型が合わず必ず throw する」ケース（`range(2,5,false)` 等）は両モードとも
   未モデル化で、`*NEVER*` になっていない。`RoundFunctionReturnTypeExtension` に先例があるが
   別 PR 相当（§6.2.3–6.2.5、§7 の選択肢 F）。
6. issue #10022 は**このレポートの作者自身が起票**したもので、本人のコメントが
   「この拡張は `PhpVersion` ではなくネイティブ `range()` の挙動に依存している」と指摘している。
   つまり issue の核心は §5.1 そのもので、この commit では解決しない。PR 本文は `Closes` ではなく
   `Ref` にする（commit message は既に `Ref`）。

---

## 1. 前提: PHP 8.3 以降の range() 仕様

- PHP 8.4 / 8.5 の `UPGRADING` / `NEWS` に range() の変更は無い → **PHP 8.5 の仕様 = PHP 8.3 の仕様**。
- PHP 8.3 の変更点（php.net changelog + php-src `ext/standard/array.c`）:
  1. 両端が文字列なら**常にバイト列レンジ**。以前は片方が数値文字列だと、もう片方を int に暗黙キャストしていた。
  2. 整数値の float step は int 扱い（`range(2, 5, 1.0)` → int）。
  3. 不正 step の `ValueError`: 増加列への負の step / 非有限 step は **8.3 で追加**。
     `step <= 0` と「step > 幅」は **8.0 から** `ValueError`（7.x は warning + `false`）。
  4. 文字列の暗黙キャスト・空文字・複数バイト非数値文字列は `E_WARNING`（値は変わるが例外ではない）。
- レンジ種別の判定（8.3+ の実装）:
  - 単一バイト数値文字列（`'1'`…`'9'`）は **文字レンジ** → 返る値は string
  - 複数バイト数値文字列（`'12'`）は数値扱い → 返る値は int/float
  - 片方だけが文字列相当で他方が数値 → warning のうえ文字列側を 0 として数値レンジ

### 実測（PHP 8.5.10）

| 式 | PHP 8.5 の実挙動 |
|---|---|
| `range('1', 'a')` | `'1'`…`'a'` の 49 要素（すべて string） |
| `range('a', '1')` | 逆順の 49 要素（すべて string） |
| `range('1', '9')` | 文字列 `'1'`…`'9'`（数値ではない） |
| `range('1', '12')` | int `1…12` |
| `range('a', '12')` / `range('12', 'a')` | int（文字列側が 0 に落ちる。warning） |
| `range(2, 5, 1.0)` | int `[2,3,4,5]` |
| `range(1, 10, 2.5)` | float `[1.0,3.5,6.0,8.5]` |
| `range('a', 'z', 2.5)` | `[0.0]`（warning、両端 0 の float レンジ） |
| `range(2, 5, 0)` / `range('a','z',0)` | `ValueError`（cannot be 0） |
| `range(5, 6, 3)` | `ValueError`（step > 幅） |
| `range(2, 5, -1)` / `range('a','z',-1)` | `ValueError`（増加列に負 step） |
| `range(1, 200, 1.0)` | int 200 個 |
| `range('A', 'z')` | 58 要素（すべて string） |

---

## 2. 現状: 検証した齟齬

修正前の `2.2.x` @ `14ad31b5cf4f` と PHP 8.5 実挙動の比較。

### 一致していたもの（実行時 `range()` の畳み込みで説明できる）

`range('1','a')`、`range('a','1')`、`range('1','9')`、`range('1','12')`、`range('a','12')`、
`range(2,5,1.0)`、`range(1,10,2.5)`、`range(2,'',2)`、`range('','a')`、`range('a','z',5)` など。
要素数が `RANGE_LENGTH_THRESHOLD = 50` 以下なら `ConstantArrayType` を実測値から構築しているため、
実挙動と完全一致する（例: `range('1','a')` は 49 要素なのでぴったり）。

### 齟齬（修正前）

| # | ケース | PHP 8.5 | 修正前の PHPStan |
|---|---|---|---|
| a | `range(2, 5, 0)` / `range('a','z',0)` | `ValueError` | `non-empty-list<int>` / `<string>` |
| a | `range(5, 6, 3)` | `ValueError` | `non-empty-list<int>` |
| a | `range(2, 5, -1)` / `range('a','z',-1)` | `ValueError` | `non-empty-list<int>` / `<string>` |
| b | `range(1, 200, 1.0)` | int 200 個 | `non-empty-list<float>` |
| b | `range(1.0, 100.0)` | float のみ | `non-empty-list<float\|int>` |
| b | `range('A', 'z')` | 文字列のみ | `non-empty-list<int\|literal-string&…>` |
| c | `range($s, $s)`（`string`） | 数値レンジになる組合せもある | `non-empty-list<string>` |
| c | `range($a, $b)`（`numeric-string`） | 単一バイトなら文字レンジ | `non-empty-list<float\|int>` |
| d | `phpVersion: 80200` を設定 | — | 上記すべて実行時 8.5 のまま（設定が効かない） |

- (a)(b) は commit `343fe8ac291b` で修正済み。
- (c) は §4 の理由で見送り。(d) は §5.1。

### テスト側の裏付け

`range` を含む nsrt は PHP 8.5.10 で 7 tests OK。ただし
**issue の再現コードを直接 assert するテストは存在しなかった**（文字列レンジは `bug-2378.php` の
`'a'..'d'` 程度）。`(b)` を突くケースも未テストだった。

---

## 3. 実装した修正（commit `343fe8ac291b`）

`src/Type/Php/RangeFunctionReturnTypeExtension.php` の 1 ファイル + テスト 2 ファイル。

### 3.1 不正 step → `*NEVER*`

定数引数の全組合せで `@range()` が `ValueError` を投げる場合に `NeverType` を返す。

```php
// the constant values are folded using the runtime PHP version,
// so the ValueError is only expected if the analysed PHP version reports it too
if (
    $this->phpVersion->throwsValueErrorForInternalFunctions()
    && $constantCombinations > 0
    && $throwingCombinations === $constantCombinations
    && $startType->isConstantScalarValue()->yes()
    && $endType->isConstantScalarValue()->yes()
    && $stepType->isConstantScalarValue()->yes()
) {
    return new NeverType();
}
```

- `throwsValueErrorForInternalFunctions()`（8.0+）は**既存 API を流用**。range() の step 検証が
  `ValueError` になったのは 8.0 から（php-src 8.0 の `zend_argument_value_error(3, "must not exceed the specified range")` を確認）。
- ゲートの意図: 畳み込みは実行時 PHP、例外の期待は解析対象 PHP に合わせる。
  **ただしこの意図は達成できていない（§6 B1、§6.1.2 で修正）。** `throwsValueErrorForInternalFunctions()` は
  `versionId >= 80000` でしかなく、§1 で整理したとおり「増加列への負 step」「非有限 step」の
  `ValueError` は 8.3 で追加されたもの。実測（`phpVersion: 80200`、実行時 8.5）では
  `range(2, 5, -1)` / `range('a','z',-1)` / `range(1,10,INF)` / `range(1,10,NAN)` がすべて
  `*NEVER*` になる。対象 8.2 での実挙動はそれぞれ `[2,3,4,5]` 等で、例外は起きない。
- `isConstantScalarValue()->yes()` は「定数でない部分が残っていない」ことの確認。
  これが無いと `range($flag ? 5 : 6, 6, 3)` のような union で誤って `*NEVER*` になりうる
  （実際は片方の組合せが `array{6}` を返すので `array{6}`）。
- `never` ではなく `*NEVER*`（implicit never）になるのは `new NeverType()` の既定値。
  `array_chunk` / `array_combine` など既存拡張と同じ流儀。

### 3.2 50 要素超の一般化を「実測値」から導出

旧実装は引数の型から推定していた（`$stepType->isFloat()->yes()` → `list<float>`、それ以外は
`start|end|step` の union）。これは 8.3 以前の前提。

新実装は、しきい値を超えたときに**すでに実行時 `range()` が返した配列の先頭・末尾の値**から
要素型を決める（`range()` はどのパスでも単一型の値しか返さないので先頭・末尾で判定できる）:

```php
private static function getLargeRangeType(array $rangeValues): IntersectionType
{
    // range() only ever returns values of a single type
    $firstValue = $rangeValues[0];
    $lastValue = $rangeValues[count($rangeValues) - 1];

    if (is_string($firstValue) || is_string($lastValue)) {
        return self::getNonEmptyListOfType(new StringType());
    }
    if (is_float($firstValue) || is_float($lastValue)) {
        return self::getNonEmptyListOfType(new FloatType());
    }

    // the sequence is monotonic, so the first and the last value are its bounds
    if ($firstValue > $lastValue) {
        [$firstValue, $lastValue] = [$lastValue, $firstValue];
    }

    return self::getNonEmptyListOfType(IntegerRangeType::fromInterval($firstValue, $lastValue));
}
```

- int の `int<min,max>` 化は単調性（増加列・減少列のどちらでも両端が上下限）を利用。
- 実行時 `range()` の値をそのまま信用するので、**実行時**が 8.2 なら従来どおりの型（例:
  `range(1, 200, 1.0)` → `list<float>`）になる。lint ゲートされたテストと同じ思想。
  裏を返すと**解析対象**バージョンは一切見ていない: 実行時 8.5 + 対象 8.2 では
  `non-empty-list<int<1, 200>>` になり、対象での実挙動（float 200 個）と食い違う（§6 B2、§6.1.3 で修正）。
  旧実装のこのパスは引数の**型**だけを見ていて対象バージョン非依存だったので、ここは退行にあたる。
- 併せて、大きな文字レンジの戻り型が `non-empty-list<string>` に落ちる。range() の文字レンジは
  常に 1 バイトかつ両端が定数由来なので `non-empty-string&literal-string` まで言えるはずで、
  精度を落としている（§6 B4、§6.1.4 で修正）。

### 3.3 テスト

- `tests/PHPStan/Analyser/nsrt/bug-10022.php`（`// lint >= 8.3`）
  issue の再現（49 要素配列）+ (a) の `*NEVER*` 5 例 + 片方だけ throw する `array{6}` +
  (b) の 3 例
- `tests/PHPStan/Analyser/nsrt/bug-10022-php82.php`（`// lint < 8.3`）
  旧挙動の固定: `range('1','a')` = `array{1, 0}`、負 step 無視 = `array{2,3,4,5}`、
  整数値 float step = `non-empty-list<float>`
  （php-src 7.4 / 8.0 / 8.2 のソースを読んで確認。CI の 8.2 ジョブ + 7.4/8.0/8.1 の
  old-PHPUnit ジョブで走る）

### 3.4 検証結果

| ゲート | 結果 |
|---|---|
| `NodeScopeResolverTest`（全 nsrt 1760 tests） | OK |
| `paratest` 全スイート | **21556 tests OK**（skipped 64 = lint ゲート分） |
| `php bin/phpstan`（自己解析、result cache クリア後） | **No errors** |
| `phpcs`（build-cs 2.x） | clean |
| fail-before / pass-after | `git stash push -- src/` で修正を外すと `*NEVER*` → `list<int>` 等で失敗、戻すと成功 |

`tests/PHPStan/Analyser/data/bug-11026.php`（`range(1, 3/2)` の crash 回帰）は
PHP 8.5 では `ValueError` になるケースだが、`*NEVER*` を返しても `assertNoErrors` は維持される
ことを確認済み。

**ただしこのゲート表には `phpVersion` 軸が無い。** nsrt の `// lint` ゲートは
`TypeInferenceTestCase::isFileLintSkipped()` が `PHP_VERSION` を見るもので、**実行時**の出し分けしか
できない。解析**対象**バージョンを固定した型推論テストは別クラスを立てる必要がある
（既存例: `SubstrPhp8Test` / `LooseConstComparisonPhp8Test` が
`tests/PHPStan/Analyser/nodeScopeResolverPhp8.neon` を追加設定で読み込む形）。
B1 / B2 はこの軸のテストが無かったから見逃されていた（§6.1.6 で `RangePhp82Test` を追加）。

---

## 4. 見送った修正: 非定数 `string` / `numeric-string` 引数の戻り型

8.3+ では、`string` 型の引数でも数値レンジになる組合せがある
（`range('a', '12')` → int、`range('a','1.5')` → float）。逆に `numeric-string` でも
単一バイトなら文字レンジ（`range('1','9')` → string）。したがって厳密な型は
`non-empty-list<(float|int|string)>` になる。

**実装しなかった理由:**

1. **偽陽性の発生源になる。** `range($s, $s)`（文字レンジ用途）で `strtoupper($c)` 等が
   新規エラーになる。`numeric-string` でも `range($a, $b)` の算術が同様に壊れる。
2. **既存テストの契約と衝突する。** `bug-2378.php:20` は
   「`range($s, $s)` は `non-empty-list<string>`」を明示的に固定しており（issue #2378 の回帰）、
   広げるとこのテストを `// lint >= 8.3` / `// lint < 8.3` に分割する必要がある。
3. **`numeric-string` だけ広げると monotonicity 違反。** `numeric-string ⊂ string` なのに
   戻り型が広くなる。やるなら両方。

つまり「偽陽性を避ける（現行維持）」と「型の健全性を取る（両方広げる）」のトレードオフで、
どちらが良いかは Ondřej の判断領域。**推奨は現行維持**（実害の出る組合せが稀なため）。

---

## 5. 残っている構造的な論点

### 5.1 実行時 PHP 依存（最重要）

この拡張は解析時に *実行時* の `range()` を呼ぶ。テストも
`TypeInferenceTestCase::isFileLintSkipped()` が `PHP_VERSION` で判定する
`// lint` ゲートで実行時バージョンごとに出し分けている。したがって:

- PHPStan を PHP 8.2 で実行 → 対象が 8.5 でも `range('1','a')` は `array{1, 0}` になる（逆も然り）。
- `phpVersion: 80200` のような設定は range() の**値**には効かない（実測で確認、§2 の (d)）。
- これは `phpVersion` を尊重する他の拡張（`throwsValueErrorForInternalFunctions()` 等）と
  一線を画す既存の設計。直すには `range()` のセマンティクスを自前実装して
  対象バージョンで分岐する必要がある（float の `start ± i * step` の丸めまで一致させる必要があり、
  別 PR 級のリスク）。8.3 以前のテストは CI の 8.2 / 7.4 / 8.0 / 8.1 ジョブで走る。

### 5.2 しきい値超でも実体配列を作っている

`count($rangeValues) > RANGE_LENGTH_THRESHOLD` の判定は `@range()` を**呼んだ後**にあるため、
`range(0, 10_000_000)` のような式でも実際に配列が確保される（`RANGE_CHECK_*` により
HT_MAX_SIZE 超は `ValueError` になるので致命的ではないが、メモリは使う）。
int/int/int の組合せは実行時呼び出しをスキップして算術で判定できる余地がある（性能改善の候補）。

### 5.3 非有限 step（INF / NAN）

8.3+ は `ValueError`（"must be a finite number"）。**検証済み**: `range(1, 10, INF)` /
`range(1, 10, NAN)` はどちらも `*NEVER*` になる（`INF` / `NAN` 定数は `ConstantFloatType` として
解決される）。当初は B1 と同じ理由で対象 8.0–8.2 でも `*NEVER*` になっていた。
§6.1.2 の修正後は、8.2 でも「step > 幅」側の `ValueError` に落ちる INF だけが `*NEVER*` で、
NAN は（php-src 8.2 で比較がすべて false になり挙動が確定できないため）保守的に配列型のまま。

### 5.4 「必ず throw する」の表現

今回は return type extension が `NeverType` を返す方式（`array_chunk` / `array_combine` と同型）。
「条件次第で throw する」を表す `DynamicFunctionThrowTypeExtension` は range() には未実装
（`ArrayCombineFunctionThrowTypeExtension` は存在する）。不要とは思うが、
上流が「throw 型として表現すべき」と言う可能性はある。

---

## 6. 批評的レビュー（第2巡, 2026-09-21）

この節は §3 の commit `343fe8ac291b` を対象に、実測で裏を取りながら洗い直した結果。
検証は worktree `~/repo/php/phpstan-src-10022-range-php83`（実行時 PHP 8.5.10）で、
`phpVersion` を明示した設定ファイルを使って行った。

### 結論

**PR 化する価値はある。ただし `343fe8ac291b` のままでは出せない。** B1 / B2 を直し、
`phpVersion` 軸のテストを足すことが前提。→ **§6.1 で対応済み。**

### ブロッカー

| # | 内容 | 深刻度 | 対応 |
|---|---|---|---|
| B1 | `*NEVER*` のバージョンゲートが粗く、対象 8.0–8.2 で誤った `*NEVER*` を返す | 高（誤検知ではなく**見逃し**を作る） | 修正済 |
| B2 | `getLargeRangeType()` が対象バージョンを見ず、対象 8.2 で `range(1,200,1.0)` を int と誤る | 中（旧実装は対象非依存だったので退行） | 修正済 |
| B3 | スキップされた step 組合せが `$constantCombinations` に数えられない | 低 | 修正済 |
| B4 | 大きな文字レンジで `literal-string` / `non-empty-string` を落としている | 低（精度退行） | 修正済 |
| B5 | PR 本文を `Closes #10022` にすると誇大 | 中（体裁・信義） | `Ref` に統一 |

#### B1: `throwsValueErrorForInternalFunctions()` は 8.3 の追加分を表現できない

`PhpVersion::throwsValueErrorForInternalFunctions()` の実体は `versionId >= 80000`。
一方 §1 のとおり、range() の `ValueError` のうち

- `step` が 0 / `abs(step)` が幅を超える → **8.0 から**
- 増加列への負 `step` / 非有限 `step` → **8.3 から**

なので、8.3 で追加された 2 つについてはゲートが素通りする。実測（`phpVersion: 80200`）:

```
range(2, 5, -1)     => *NEVER*   （対象 8.2 の実挙動は [2,3,4,5]）
range('a','z',-1)   => *NEVER*   （同上、'a'…'z'）
range(1, 10, INF)   => *NEVER*
range(1, 10, NAN)   => *NEVER*
range(2, 5, 0)      => *NEVER*   （正しい）
```

`*NEVER*` は単なる不正確さでは済まない。値が never になると後続の解析が丸ごと消えるため、
**実在のエラーが黙って消える**:

```php
// phpVersion: 80200 / 実行時 8.5
foreach (range(2, 5, -1) as $v) { echo strlen($v); }  // 修正後: エラー無し（消えた）
foreach (range(2, 5,  1) as $v) { echo strlen($v); }  // 正しく argument.type
```

修正前は同じコードが `non-empty-list<int>` になり、`strlen()` のエラーが出ていた。
つまりこの commit は対象 8.0–8.2 について**偽陰性を新規に作っている**。

実際に問題になる書き方は「増加列 + 負 step」（`range(2, 5, -1)`）と非有限 step だけで、
よく使われる `range(5, 2, -1)` は 8.3+ でも正常なので影響は限定的。とはいえ、
PHPStan で「エラーが消える」方向の退行は上流レビューで最も嫌われる種類のもの。

**取りうる修正:**

1. （推奨・小）対象が 8.3 未満のときは、8.0 時点で確実に `ValueError` になる形にだけ `*NEVER*` を許す。
   最小形は「`step` の定数値が 0」だけを対象にする。`range(5, 6, 3)`（step > 幅）は
   8.0 でも `ValueError` だが幅の計算に文字列レンジの扱いが絡むので、保守的に諦めて
   従来の配列型に落としてよい（不正確だが無害）。
2. （大）step 検証を自前で対象バージョン別に実装する。文字列レンジの「幅」まで再実装が要るので
   §5.1 と同じリスクを背負う。この PR でやるべきではない。

#### B2: 大レンジの一般化が対象バージョンを見ていない

実測（実行時 8.5）:

| 式 | 対象 8.5 | 対象 8.2 の PHPStan | 対象 8.2 の実挙動 |
|---|---|---|---|
| `range(1, 200, 1.0)` | `non-empty-list<int<1, 200>>` ✓ | `non-empty-list<int<1, 200>>` | float 200 個 ✗ |
| `range('A', 'z')` | `non-empty-list<string>` ✓ | `non-empty-list<string>` | 文字レンジ ✓ |

食い違うのは「整数値の float step」だけ（8.3 の変更点 2 に対応）。
`getLargeRangeType()` に「対象 < 8.3 かつ step / 両端のいずれかが float なら `list<float>`」の
分岐を足せば両対応になる。5 行程度。

なお旧実装のこのパスは引数の型だけを見ていたので**対象バージョン非依存**だった。
issue #10022 本人コメントの「`PhpVersion` ではなくネイティブ `range()` に依存している」という
指摘の方向とは逆に、この commit は実行時依存の面積を広げている。ここは PR で説明が要る。

#### B3: スキップされた組合せが数に入らない

`$constantCombinations++` は step が `ConstantIntegerType` / `ConstantFloatType` のときにしか
実行されない。`ConstantBooleanType` / `NullType` の step は `continue` でスキップされるが、
`isConstantScalarValue()->yes()` は真のままなので、

```php
range(2, 5, $flag ? 0 : true)  // => *NEVER*
range(2, 5, $flag ? 0 : null)  // => *NEVER*
```

になる（`range(2, 5, true)` 単体は `non-empty-list<float|int>`）。
どちらも `argument.type` エラーが別に出るコードなので実害は小さいが、
「スキップした組合せがあったら `*NEVER*` を返さない」フラグを 1 つ足すだけなので直しておきたい。

#### B4: 文字レンジの精度退行

`getLargeRangeType()` の string 分岐が素の `StringType` を返している。
range() の文字レンジは常に 1 バイトで、両端も定数なので
`non-empty-string & literal-string` まで言える。
（修正前は `int|literal-string&…` という誤った型だったので「誤り→粗い正しさ」ではあるが、
正しさと精度は両立できる。）

#### B5: `Closes` ではなく `Ref`

issue #10022 の起票者はこのレポートの作者本人（zonuexe）で、本人の追記コメントが

> note: This extension relies on the behavior of the native `range()` function, not `PhpVersion`.

と、まさに §5.1 を指している。再現コード `range('1', 'a')` 自体は
phpstan-bot のコメントどおり 1.11.x の時点ですでに 8.3 の結果を返しており、
「実行時 8.3+ なら直っている」状態。したがってこの commit で issue が閉じるとは言いにくい。
commit message は既に `Ref` になっているので、PR 本文も `Ref` に揃える。

### 検証済みで問題なかった点

- `NeverType` を返す方式そのものは上流の既存流儀（`StrSplitFunctionReturnTypeExtension`、
  `ArrayCombineFunctionReturnTypeExtension` が `throwsValueErrorForInternalFunctions()` +
  `NeverType` の同型）。§5.4 の懸念は小さい。
- `*NEVER*`（implicit never）は unreachable 系ルールを発火させない（実測）。
  したがって「新規エラーが大量に出る」方向のリスクは無い。
- `bug-10022` / `range` を含む nsrt 8 tests、`AnalyserIntegrationTest` の bug-11026、
  変更 3 ファイルの phpcs はいずれも green（再確認済み）。
- §5.2 のメモリは実測で確認: `range(0, 20_000_000)` の解析で RSS 約 390MB、1.25 秒。
  この commit が作った問題ではないが、触っているブロックそのものなので同時に直す選択肢はある。


---

## 6.1 修正（commit `6b08204854`、`343fe8ac291b` を差し替え）

`src/Php/PhpVersion.php` + `src/Type/Php/RangeFunctionReturnTypeExtension.php` +
テスト 4 ファイル（うち 3 つが新規）。旧 commit は `10022/range-php83-pre-review` に退避してある。

### 6.1.1 `PhpVersion::hasStricterRangeFunction()`（8.3 ゲート）

```php
/**
 * PHP 8.3 rejects a negative or non-finite step, always builds a character range
 * from two string boundaries and no longer turns an integral float step into floats.
 */
public function hasStricterRangeFunction(): bool
{
	return $this->versionId >= 80300;
}
```

命名は既存の `hasStricterRoundFunctions()`（8.4 の round 変更）に合わせた。

### 6.1.2 B1: `throwsOnAnalysedVersion()`

`catch (ValueError)` は「**実行時**が step を拒否した」ことしか意味しない。これを
「**解析対象**でも拒否される」に翻訳する述語を挟み、真のときだけ `$throwingCombinations` を増やす。

```php
private function throwsOnAnalysedVersion(int|float|string $start, int|float|string $end, int|float $step): bool
{
	if (!$this->phpVersion->throwsValueErrorForInternalFunctions()) {
		return false;
	}

	if ($this->phpVersion->hasStricterRangeFunction()) {
		return true;
	}

	if (is_string($start) || is_string($end)) {
		// how a string boundary was coerced before PHP 8.3 is not modelled here
		return false;
	}

	$start = (float) $start;
	$end = (float) $end;
	if (!is_finite($start) || !is_finite($end)) {
		return false;
	}

	if ($start === $end) {
		// a step of 0 was only rejected for integer boundaries in this case
		return false;
	}

	// the sign of the step used to be ignored, so the step was only rejected
	// when it was 0 or wider than the range itself
	$step = abs((float) $step);

	return $step === 0.0 || abs($end - $start) < $step;
}
```

8.2 以前の条件は php-src `PHP-8.2` の `ext/standard/array.c` を読んで確定させた（推測ではない）:

- 先頭で `if (step < 0.0) step *= -1;` — **step の符号は捨てられる**。
- 3 つの経路（char / double / long）いずれも `low == high` の分岐では step を検証せず
  単一要素の配列を返す。ただし long 経路だけは分岐前に `if (step <= 0) err`。
  → 両端が等しいケースは経路依存なので、述語では一律 `false`（保守的に諦める）。
- それ以外は `high - low < step || step <= 0` → `ValueError`。
  → `$step === 0.0 || abs($end - $start) < $step` がそのまま対応する。
- `zend_isinf(high) || zend_isinf(low)` で境界が非有限なら別の `zend_value_error`。
  → `is_finite()` ガードで対象外にした。

文字列境界は 8.2 の `is_numeric_string()` による経路分岐（char / long_str / double_str）を
再実装しないと判定できないので、対象 < 8.3 では一律 `false`。
`range('a','z',0)` のような「8.2 でも本当は throw する」ケースを取りこぼすが、
従来どおりの配列型に落ちるだけで無害。§5.1 を侵食しない範囲に留めた。

### 6.1.3 B2: `getLargeRangeType()` の float 分岐

```php
if (
	is_float($firstValue)
	|| is_float($lastValue)
	// before PHP 8.3 a float argument produced floats even when none of
	// the arguments had a fractional part
	|| (
		!$this->phpVersion->hasStricterRangeFunction()
		&& (is_float($start) || is_float($end) || is_float($step))
	)
) {
	return self::getNonEmptyListOfType(new FloatType());
}
```

8.2 の `is_step_double || Z_TYPE(zlow) == IS_DOUBLE || Z_TYPE(zhigh) == IS_DOUBLE` に対応する。
なお「実行時 8.5 が文字レンジを返したが対象 8.2 では数値レンジ」（例 `range('1','z')`）は
値そのものが実行時依存なので直していない。これは §5.1 の領域で、≤50 要素の畳み込みも同じ前提。

### 6.1.4 B3 / B4

- B3: `$hasSkippedCombination` を追加。int/float/string 以外の定数（bool・null）で
  `continue` した場合は `*NEVER*` を返さない。
- B4: 文字レンジを `non-empty-string & literal-string` に。

### 6.1.5 `instanceof` を増やさない

当初 `throwsOnAnalysedVersion()` の引数を `Constant*Type` で受けて `instanceof` していたが、
自己解析で `phpstanApi.instanceofType`（`ignore.count` の 2→4 超過込み）が 3 件出た。
生のスカラー値（`int|float|string`）を渡す形に変え、`is_string()` / `is_float()` で分岐する
ようにして解消。CLAUDE.md の「`instanceof *Type` を使わない」にも沿う。

### 6.1.6 テスト

新規 `tests/PHPStan/Analyser/RangePhp82Test.php` +
`nodeScopeResolverPhp82.neon`（`phpVersion: 80200`）+ `data/range-php82.php`。
`DynamicMethodThrowTypeExtensionTest` と同じく `PHP_VERSION_ID < 80300` で空を返す
（assert の内容が「実行時 8.3+ × 対象 8.2」を前提にしているため）。

`nsrt/bug-10022.php` には INF / NAN と B3 のケースを追加し、`range('A','z')` の期待値を
`non-empty-list<literal-string&non-empty-string>` に更新した。

### 6.1.7 検証

| ゲート | 結果 |
|---|---|
| `RangePhp82Test` | 7 tests OK |
| `NodeScopeResolverTest --filter 'bug-10022\|range'` | 8 tests OK |
| `paratest` 全スイート | **21564 tests OK**（skipped 64） |
| `php bin/phpstan`（自己解析、result cache クリア後） | **No errors** |
| `phpcs`（`src/` 全体 + 新規テスト） | clean |

fail-before は src だけを差し戻して 3 状態で確認した:

| src の状態 | `RangePhp82Test` |
|---|---|
| ベース `14ad31b5c` | 3 失敗 — `*NEVER*` 期待の 3 行（機能が無いので当然） |
| レビュー前 `343fe8ac291b` | **4 失敗 — すべて退行**: L11/L12/L17 が誤って `*NEVER*`、L25 が `int<1, 200>` |
| 修正後 `6b08204854` | 7 OK |

レビュー前の状態がベースより**悪い**ことが、このテストで機械的に固定された。

---

## 6.2 `declare(strict_types=1)` の影響（確認済み）

第2巡のあとに出た指摘。**結論: §6.1 の変更には影響しない。ただし隣に未モデル化の領域がある。**

### 6.2.1 今回の変更に影響しない根拠

`range()` の署名は
`range(string|int|float $start, string|int|float $end, int|float $step = 1): array`。
拡張が実際に畳み込むのは start/end が `ConstantIntegerType|ConstantFloatType|ConstantStringType`、
step が `ConstantIntegerType|ConstantFloatType` の組合せだけで、**いずれも宣言型と完全一致する**。
一致する引数には型強制が起きないので、weak / strict のどちらでも値も例外も同じになる。

実測でも確認した。同内容で `declare(strict_types=1)` の有無だけ違う 2 ファイルを level 9 で解析し、
dumpType の出力もエラー一覧も完全に一致した。

拡張自身（`RangeFunctionReturnTypeExtension.php`）は `declare(strict_types = 1)` なので内部の
`@range()` 呼び出しは strict で走るが、上記フィルタが宣言型との一致を保証しているため
`TypeError`（`catch (ValueError)` では捕まらない）が漏れることもない。

### 6.2.2 実測マトリクス（PHP 8.5.10）

| 呼び出し | weak | strict |
|---|---|---|
| `range(2, 5, true)` | `[2,3,4,5]` | TypeError |
| `range(2, 5, false)` | **ValueError**（step 0） | TypeError |
| `range(2, 5, null)` | deprecation + **ValueError**（step 0） | TypeError |
| `range(2, 5, '2')` | `[2,4]` | TypeError |
| `range(2, 5, '2.5')` | `[2,4.5]`（float step） | TypeError |
| `range(2, 5, ' 2 ')` | `[2,4]`（前後空白は許容） | TypeError |
| `range(2, 5, 'a')` | **TypeError** | TypeError |
| `range(2, 5, [1])` | **TypeError** | TypeError |
| `range(true, 5)` | `[1,2,3,4,5]` | TypeError |
| `range(null, 5)` | deprecation + `[0,1,2,3,4,5]` | TypeError |

差が出るのはすべて、拡張が `continue` でスキップする組合せ。しかも B3 で入れた
`$hasSkippedCombination` により、そういう組合せが 1 つでもあれば `*NEVER*` を主張しない。
したがって両モードとも安全側（上位型）に倒れる:

- weak `range(2, 5, $flag ? 0 : true)` — 実際は `array{2,3,4,5}`、推論は `non-empty-list<float|int>`
- strict `range(2, 5, $flag ? 0 : true)` — 実際は必ず throw、推論は同じ

不正確だが誤りではない。

### 6.2.3 未モデル化の領域

上の表の**どの行も**、現状の PHPStan では両モードとも `non-empty-list<float|int>` ないし
`non-empty-list<(float|int|string)>` になり、`*NEVER*` にはならない。
「必ず throw する」と言えるのに言っていない行が 3 層に分かれる。

**T1 — モード非依存で必ず throw（`strict_types` を見なくても `never` が正しい）**

- `range($x, $y, false)` / `range($x, $y, null)` — weak では 0 に強制され `ValueError`、strict では `TypeError`
- `range($x, $y, 'a')` — 非数値文字列は weak でも `int|float` に強制できず `TypeError`
- `range($x, $y, [1])` — 配列は両モードで `TypeError`

**T2 — strict でのみ必ず throw（`$scope->isDeclareStrictTypes()` が要る）**

- `range($x, $y, true)`、`range($x, $y, '2')`、`range(true, $y)`、`range(null, $y)` など
  「weak なら強制されて成功する」引数

**T3 — weak 側の値を推論する（強制セマンティクスの実装が要る）**

- `true` → 1、`false`/`null` → 0、数値文字列 → int/float（`'2.5'` は float step になり結果も float）、
  前後空白つき数値文字列も許容。null は 8.1+ で deprecation も伴う。

### 6.2.4 先例と、今回入れなかった理由

先例はある。`RoundFunctionReturnTypeExtension`（`src/Type/Php/RoundFunctionReturnTypeExtension.php:72`）が
`$scope->isDeclareStrictTypes()` で許容型セットを切り替え、`isSuperTypeOf(...)->no()` なら
`new NeverType(true)` を返している。T2 をやるならこれと同型になる。
なお `isDeclareStrictTypes()` を使っている返り値型拡張は `src/` 全体でこの 1 件だけ。

入れなかった理由:

1. **すでに `argument.type` が出ている行にしか効かない。** 上の表の全行を PHPStan は
   weak モードでも `Parameter #3 $step of function range expects float|int, … given.` として
   報告する（実測）。そこに `never` を足すと後続が unreachable になり、**下流の実在エラーを隠す**。
   これは §6 B1 で問題にしたのと同じ性質のリスクで、しかも見返りが小さい。
2. **範囲が range() 固有ではない。** T1/T2 は「内部関数に宣言型と合わない定数を渡したら必ず throw」
   という一般則で、本来は個別の返り値型拡張ではなく共通の仕組みの話。
   round() が個別対応なのは例外的。
3. **T3 は別機能。** weak モードの強制セマンティクス（空白許容・`'2.5'` の float 昇格・
   null の deprecation）を `range()` の中に再実装することになり、§5.1 と同じ「range() 自前実装」の
   入口に立つ。

### 6.2.5 やるとしたら

T1 だけなら `strict_types` を見る必要がなく、既存の `$hasSkippedCombination` を
「スキップした組合せが**必ず throw する種類**なら throwing 側に数える」に変えるだけで済む。
副作用（unreachable 化）は残るので、それを許容するかどうかが判断ポイント。
T2 は round() と同型で実装できるが、同じ副作用がより広い範囲に及ぶ。
いずれも**別 PR**。今回のブランチには含めない。

---

## 7. 展望

| 選択肢 | 内容 | 備考 |
|---|---|---|
| A（推奨・**実施済み**） | B1–B4 を直し、`phpVersion: 80200` 固定の型推論テストを足して 1 本の PR にする | commit `6b08204854`。PR 本文は `Ref https://github.com/phpstan/phpstan/issues/10022`。ドラフトは [20260921-range-php83-pr-draft.md](20260921-range-php83-pr-draft.md) |
| A' | 2 本に割る: (1) 大レンジ一般化 + 回帰テスト、(2) `*NEVER*` | (2) の方が議論が要るので、(1) だけ先に出して様子を見る手もある。両者が同じ `hasStricterRangeFunction()` を使うので分割の手間は小さくない |
| B | (c) の型拡張も追加コミットで入れる | `bug-2378.php` / `range-numeric-string.php` の分割が必要。偽陽性リスクの説明が PR に要る。**A とは別 PR にすべき** |
| C | 実行時/対象バージョンの分離（§5.1） | issue #10022 の核心はこれ。別 issue/PR。`range()` の完全自前実装 |
| D | §5.2 のメモリ改善 | 単独の小 PR にできる（実測 390MB / 1.25s） |
| E | 出さない | この commit が直すのは実行時 8.3+ 限定のごく狭い齟齬。B1 のリスクに見合わないと判断するならあり得る |
| F（**次 PR に決定**） | 引数型不一致による必至 throw を `never` にする — §6.2.3 の T1 のみ | A のマージ後に別 PR。T1 は両モードで必ず throw する組合せだけなので `strict_types` を見る必要がない。実装は `$hasSkippedCombination` の分類を変えるだけ。論点は「既に `argument.type` が出ている行を unreachable にする副作用」 |
| F' | T2（strict 限定）/ T3（weak の強制セマンティクス） | さらに別、ないし見送り。T2 は `RoundFunctionReturnTypeExtension` と同型 |

判断ポイント:

- issue #10022 は 2023 年から open のまま。ただし再現コード自体は 1.11.x 時点で直っており、
  残っているのは §5.1（`PhpVersion` 非依存）。PR の説明では **何が直り何が直らないか** を明記する。
- 8.3 以前の CI ジョブが落ちないことは `bug-10022-php82.php` が保証する（ローカルに 8.2 が無いため
  ソース読解で期待値を決めており、初回 CI で要確認）。
  ただしこれは**実行時** 8.2 の保証であって、**対象** 8.2 の保証ではない（§3.4）。

---

## 8. 再現手順

```bash
cd ~/repo/php/phpstan-src-10022-range-php83   # branch 10022/range-php83

# 新規テストのみ
vendor/bin/phpunit tests/PHPStan/Analyser/NodeScopeResolverTest.php --filter bug-10022

# 失敗することの確認（修正を外す）
git stash push -- src/ && vendor/bin/phpunit tests/PHPStan/Analyser/NodeScopeResolverTest.php --filter bug-10022; git stash pop

# 解析対象バージョンを固定したテスト（B1 / B2 の回帰）
vendor/bin/phpunit tests/PHPStan/Analyser/RangePhp82Test.php

# レビュー前の状態と突き合わせる（4 失敗するのが正しい）
git checkout 10022/range-php83-pre-review -- src/
vendor/bin/phpunit tests/PHPStan/Analyser/RangePhp82Test.php; git checkout HEAD -- src/

# 全体
XDEBUG_MODE=off php tests/vendor/bin/paratest --runner WrapperRunner --no-coverage
php bin/phpstan clear-result-cache -q && php -d memory_limit=1G bin/phpstan --no-progress
XDEBUG_MODE=off php build-cs/vendor/bin/phpcs
```

環境メモ（このworktreeを作った際の手順）:

- `gw start 10022/range-php83 2.2.x --no-setup` → `cp -Rc ../phpstan-src/vendor .` → `composer install`
- `build-cs` と `tests/vendor` はメイン worktree から `cp -Rc` で持ってくる
  （`build-cs` は `phpstan/build-cs` の clone で git 管理外）
- メイン worktree では `vendor/attributes.php` が古く `#[AutowiredService]` の解決に失敗したため
  `composer dump-autoload` を実行した（開発環境側の話で、コミットには含まれない）

## 付録: 参考にした一次情報

- php-src `ext/standard/array.c` の `PHP_FUNCTION(range)` — PHP-7.4 / PHP-8.0 / PHP-8.2 / PHP-8.5 を比較
  （8.2 は `https://raw.githubusercontent.com/php/php-src/PHP-8.2/ext/standard/array.c` を取得して精読。
  §6.1.2 の述語はこの読解から導いた）
  - 7.4: step 不正は warning + `false`
  - 8.0/8.2: `zend_argument_value_error(3, "must not exceed the specified range")`（step <= 0 / step > 幅）
  - 8.5: 加えて「増加列への負 step」「非有限 step」、メッセージも個別化
- php.net `function.range.php` の changelog（8.3.0 の項目）
- PHP-8.4 / PHP-8.5 の `UPGRADING` / `NEWS`（range() の記載なし = 8.3 仕様のまま）
