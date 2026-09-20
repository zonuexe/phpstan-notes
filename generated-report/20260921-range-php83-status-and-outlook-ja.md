# range() の PHP 8.3 対応 — 現状と展望

調査日: 2026-09-21

- 対象 issue: [phpstan/phpstan#10022 Support PHP 8.3 `range()` changes](https://github.com/phpstan/phpstan/issues/10022)
  （2023-10-17 起票、feature-request、open、リンクされた PR なし）
- 検証環境: PHP 8.5.10 (homebrew) / phpstan-src `2.2.x` @ `14ad31b5cf4f`
- 作業ブランチ: `10022/range-php83`（worktree `~/repo/php/phpstan-src-10022-range-php83`、
  commit `343fe8ac291b`、**未 push**）
- 関連: [20260822-float-range-type-spec-research-ja.md](20260822-float-range-type-spec-research-ja.md)
  （float 区間型 = 別機能の提案）、[20260822-range-syntax-survey-ja.md](20260822-range-syntax-survey-ja.md)

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
4. 上流 PR 化するならこの commit をそのまま使える（PR 本文に `Closes .../issues/10022`）。

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
- ゲートを入れる理由: 畳み込みは実行時 PHP、例外の期待は解析対象 PHP に合わせるため。
  例: 実行時 8.5 + 対象 8.2 で `range(2, 5, -1)` は「対象では範囲を返す」ので `*NEVER*` にしない。
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
- 実行時 `range()` の値をそのまま信用するので、8.2 ランタイムでは従来どおりの型（例:
  `range(1, 200, 1.0)` → `list<float>`）になる。lint ゲートされたテストと同じ思想。

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

8.3+ は `ValueError`（"must be a finite number"）。定数畳み込みの `ValueError` 経路に乗るので
原理的には `*NEVER*` になるはずだが、`ConstantFloatType` で INF/NAN を表現できるケースを
テストしていない（未検証）。

### 5.4 「必ず throw する」の表現

今回は return type extension が `NeverType` を返す方式（`array_chunk` / `array_combine` と同型）。
「条件次第で throw する」を表す `DynamicFunctionThrowTypeExtension` は range() には未実装
（`ArrayCombineFunctionThrowTypeExtension` は存在する）。不要とは思うが、
上流が「throw 型として表現すべき」と言う可能性はある。

---

## 6. 展望

| 選択肢 | 内容 | 備考 |
|---|---|---|
| A（推奨） | commit `343fe8ac291b` をそのまま PR 化 | PR 本文に `Closes https://github.com/phpstan/phpstan/issues/10022`。commit message は変更内容のみ（リポジトリの流儀） |
| B | (c) の型拡張も追加コミットで入れる | `bug-2378.php` / `range-numeric-string.php` の分割が必要。偽陽性リスクの説明が PR に要る |
| C | 実行時/対象バージョンの分離（§5.1） | 別 issue として起票するのが妥当。`range()` の完全自前実装 |
| D | §5.2 のメモリ改善 | 単独の小 PR にできる |

判断ポイント:

- issue #10022 は 2023 年から open のまま。今回の修正で「実行時 8.3+ の PHPStan では
  8.3 仕様どおり」になるので、PR の説明では **何が直り何が直らないか**（実行時依存は残る）を明記する。
- 8.3 以前の CI ジョブが落ちないことは `bug-10022-php82.php` が保証する（ローカルに 8.2 が無いため
  ソース読解で期待値を決めており、初回 CI で要確認）。

---

## 7. 再現手順

```bash
cd ~/repo/php/phpstan-src-10022-range-php83   # branch 10022/range-php83

# 新規テストのみ
vendor/bin/phpunit tests/PHPStan/Analyser/NodeScopeResolverTest.php --filter bug-10022

# 失敗することの確認（修正を外す）
git stash push -- src/ && vendor/bin/phpunit tests/PHPStan/Analyser/NodeScopeResolverTest.php --filter bug-10022; git stash pop

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
  - 7.4: step 不正は warning + `false`
  - 8.0/8.2: `zend_argument_value_error(3, "must not exceed the specified range")`（step <= 0 / step > 幅）
  - 8.5: 加えて「増加列への負 step」「非有限 step」、メッセージも個別化
- php.net `function.range.php` の changelog（8.3.0 の項目）
- PHP-8.4 / PHP-8.5 の `UPGRADING` / `NEWS`（range() の記載なし = 8.3 仕様のまま）
