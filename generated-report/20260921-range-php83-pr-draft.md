# PR: `range()` の戻り型（phpstan-src #6492）

https://github.com/phpstan/phpstan-src/pull/6492

元レポート: [20260921-range-php83-status-and-outlook-ja.md](20260921-range-php83-status-and-outlook-ja.md)

- `phpstan/phpstan-src` PR #6492 / base `2.2.x` / head `zonuexe:10022/range-php83` / commit `247646ee2`
- `phpstan/2.2.x`（`1dfc161e7`）に rebase 済み。rebase 後に再検証: paratest 21580 OK（skipped 64）/ 自己解析 No errors / phpcs 2693 files clean

---

## タイトル

```
Improve range() return type for invalid steps and long ranges
```

## 本文（提出したもの）

````markdown
Ref https://github.com/phpstan/phpstan/issues/10022

Two inaccuracies in the return type of `range()`:

1. An invalid `$step` makes `range()` throw a `ValueError`, but the extension still returned an array type. It now returns `never` when every combination of the constant arguments throws.
2. For a range longer than `RANGE_LENGTH_THRESHOLD` the extension generalized the argument types, which predates the PHP 8.3 changes. It now generalizes the values the range consists of:

```diff
 // range('A', 'z')
-non-empty-list<int|(literal-string&lowercase-string&non-falsy-string)|(literal-string&non-falsy-string&uppercase-string)>
+non-empty-list<literal-string&non-empty-string>

 // range(1, 200, 1.0)
-non-empty-list<float>
+non-empty-list<int<1, 200>>
```

The extension folds the constant arguments by calling the native `range()`, so a caught `ValueError` only says that the *runtime* rejects the step. I gated both changes on `PhpVersion` wherever PHP 8.3 changed the behaviour. A `$step` of `0`, or one wider than the range, has been a `ValueError` since PHP 8.0, while a negative `$step` on an increasing range and a non-finite `$step` only became one in 8.3. An integral float `$step` produces ints since 8.3 and floats before that. Analysing for PHP 8.2 still gives `non-empty-list<int>` for `range(2, 5, -1)` and `non-empty-list<float>` for `range(1, 200, 1.0)`. `RangePhp82Test` covers that axis: it sets `phpVersion` to 8.2 and runs on 8.3+.

**This does not close phpstan/phpstan#10022.** PHPStan already infers the PHP 8.3 result for the reproducer in that issue, `range('1', 'a')`, because the extension folds the constant arguments through the native `range()`. For the same reason the folded *values* follow the PHP version PHPStan runs on rather than the configured `phpVersion`: with `phpVersion: 80200` on a PHPStan running on 8.5, `range('1', 'a')` still comes out as the 49 element character range instead of `array{1, 0}`. Making the values follow `phpVersion` means reimplementing `range()` down to the rounding of `start + i * step`, and I did not want to put that in this PR. This PR fixes what you can decide without knowing the values: which steps PHP rejects, and how to generalize a range past the threshold.
````

## 本文（確認用・日本語）

> Ref https://github.com/phpstan/phpstan/issues/10022
>
> `range()` の戻り型にある 2 つの不正確さ:
>
> 1. 不正な `$step` は `range()` に `ValueError` を投げさせるが、拡張は配列型を返したままだった。定数引数の全組合せが throw するときは `never` を返すようにした。
> 2. `RANGE_LENGTH_THRESHOLD` を超えるレンジについて、拡張は引数の型を一般化していた。これは PHP 8.3 の変更以前の前提。レンジを構成する値を一般化するようにした:
>
> ```diff
>  // range('A', 'z')
> -non-empty-list<int|(literal-string&lowercase-string&non-falsy-string)|(literal-string&non-falsy-string&uppercase-string)>
> +non-empty-list<literal-string&non-empty-string>
>
>  // range(1, 200, 1.0)
> -non-empty-list<float>
> +non-empty-list<int<1, 200>>
> ```
>
> この拡張はネイティブの `range()` を呼んで定数引数を畳み込むので、捕まえた `ValueError` は「**実行時**が step を拒否した」ことしか意味しない。そこで、PHP 8.3 で挙動が変わった箇所では `PhpVersion` でゲートした。`$step` が 0、または幅を超える場合は PHP 8.0 から `ValueError` である一方、増加列への負の `$step` と非有限の `$step` が `ValueError` になったのは 8.3 から。また整数値の float `$step` は 8.3 以降 int を、それ以前は float を返す。よって解析対象が PHP 8.2 なら `range(2, 5, -1)` は `non-empty-list<int>`、`range(1, 200, 1.0)` は `non-empty-list<float>` のままになる。`RangePhp82Test` がこの軸をカバーする。`phpVersion` を 8.2 に設定し、テスト自体は 8.3+ で走る。
>
> **これは phpstan/phpstan#10022 を close しない。** あの issue の再現コード `range('1', 'a')` について、PHPStan はすでに PHP 8.3 の結果を推論できている。拡張がネイティブの `range()` を通して定数引数を畳み込んでいるからで、同じ理由により畳み込まれる**値**は、設定された `phpVersion` ではなく PHPStan が動いている PHP のバージョンに従う。実行時 8.5 の PHPStan に `phpVersion: 80200` を設定しても、`range('1', 'a')` は `array{1, 0}` ではなく 49 要素の文字レンジのままになる。値を `phpVersion` に従わせるには `range()` を float の `start + i * step` の丸めに至るまで実装し直す必要があり、それをこの PR に入れたくなかった。この PR が直すのは、値を知らなくても判断できる部分。どの step を PHP が拒否するか、しきい値を超えたレンジをどう一般化するか。

### 任意で足す一文（日本語）

> 引数の型が署名に合わないために throw する呼び出し（`range(2, 5, false)`、`range(2, 5, 'a')`）はここでは手を付けていない。すでに `argument.type` として報告されており、`never` にするとその後ろのエラーを隠してしまうため。別途やりたい。

---

## 変更ファイル

| ファイル | 内容 |
|---|---|
| `src/Php/PhpVersion.php` | `hasStricterRangeFunction()`（`>= 80300`） |
| `src/Type/Php/RangeFunctionReturnTypeExtension.php` | `throwsOnAnalysedVersion()` / `getLargeRangeType()` / `$hasSkippedCombination` |
| `tests/PHPStan/Analyser/nsrt/bug-10022.php` | `// lint >= 8.3` |
| `tests/PHPStan/Analyser/nsrt/bug-10022-php82.php` | `// lint < 8.3` |
| `tests/PHPStan/Analyser/RangePhp82Test.php` + `nodeScopeResolverPhp82.neon` + `data/range-php82.php` | 解析対象 8.2 固定 |

## 次の PR: T1（引数型不一致による必至 throw）

この PR がマージされたあと、`*NEVER*` の対象を「モード非依存で必ず throw する引数型」に広げる。詳細は元レポート §6.2.3 の T1。

- 対象: `range($x, $y, false)` / `range($x, $y, null)`（weak でも 0 に強制され `ValueError`）、`range($x, $y, 'a')`（非数値文字列は weak でも `TypeError`）、配列などの非スカラー
- `declare(strict_types=1)` を見る必要はない（両モードで必ず throw するものだけを対象にする）
- 実装は `$hasSkippedCombination` を「スキップした組合せが必ず throw する種類なら throwing 側に数える」に変えるだけ
- 論点: すでに `argument.type` が出ている行を unreachable にする副作用を許容するか
- strict 限定の T2（`RoundFunctionReturnTypeExtension` と同型）と weak の強制セマンティクス T3 はさらに別、ないし見送り
