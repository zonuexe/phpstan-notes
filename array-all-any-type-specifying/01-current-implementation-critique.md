# PR #5134 (array_all 型絞り込み) 現行実装の批評的分析

日付: 2026-07-10
対象: `src/Type/Php/ArrayAllFunctionTypeSpecifyingExtension.php`(PR #5134 を 2.2.x にスカッシュしたもの、ブランチ `array-all-type-specifying`、コミット 64eb09553)

## 現行実装の仕組み

1. `array_all($arr, $cb)` の truthy コンテキストのみ処理
2. `$cb` が ArrowFunction または「単一 return の Closure」の場合のみ、本体式を取り出す
3. `TypeSpecifier::specifyTypesInCondition($scope, $本体式, $context)` を**外側スコープで**実行
4. `getSureTypes()` から、コールバックのパラメータ名と一致する `Variable` のエントリを名前マッチで拾う
5. `new ArrayType($keyType ?? mixed, $valueType ?? mixed)` を `TypeSpecifier::create()` で指定

## プローブによる実証(test.php、リポジトリルート)

`php bin/phpstan analyse -l 8 test.php` + `\PHPStan\dumpType()` で確認した。

| # | 入力 | 結果 | 判定 |
|---|------|------|------|
| A | 外側 `$value='hello'`、`fn ($value) => $value > 5` on `array<mixed>` | `array<mixed~(0.0\|bool\|int<min, 5>\|null)>` | ○ 汚染なし(懸念していた外側変数の型の混入は起きない) |
| B | ↑の後の外側 `$value` | `'hello'` のまま | ○ 外側変数への漏れなし |
| C | `fn ($v) => !is_null($v)` | **絞り込みなし** (`array<mixed>`) | ✗ sureNotTypes を無視しているため否定形が全滅 |
| D | `fn ($v) => $v !== null` | **絞り込みなし** | ✗ 同上 |
| E | `list<mixed>` + `fn ($v, $k) => is_string($k)` | **`*NEVER*` + 「always evaluate to false」誤報** | ✗✗ **不健全**。空 list は `array_all` が true を返すので never は誤り。正解は `array{}` |
| F | `!array_all($arr, fn ($v) => is_int($v))` (falsy) | 絞り込みなし | ✗ falsy ⇒ 配列は非空(空配列は true を返すため)が表現できていない |
| G | `fn ($v) => is_int($v) && $v > 5` | `array<int<6, max>>` | ○ 連言+比較は動く |
| H | `'is_int'` (文字列 callable) | 絞り込みなし | △ `is_int` は実行時 ArgumentCountError なので narrowing 不要だが、**2引数を受け取れるユーザー関数名の文字列**は正当なのに未対応 |
| I | `fn ($v) => self::isDateTime($v)` (`@phpstan-assert-if-true`) | `array<DateTime>` | ○ **意外にも動く**(specifyTypesInCondition が式全体を処理するため)。PR 本文の「future scope」記載は実は不正確 |
| J | 外側 `$value='hello'`、`fn ($value) => $value` (素の truthiness) | 絞り込みなし | ✗ 期待は `array<mixed~falsy>`。プレーンな truthiness が narrowing にならない |
| K | `array<int\|string>` + `fn ($v) => $v > 5` | `array<int<6, max>\|string>` | ○ 要素型にアンカーされた絞り込みになっている |
| L | 外側 `?int $value`、`fn ($value) => $value` | 絞り込みなし | ✗ J と同じ欠落(外側型による誤絞り込みは起きなかった) |

### 総括

- **決定的な欠陥は E(不健全な never)と C/D/J(否定形・truthiness の欠落)と F(falsy 未対応)**。
- 名前マッチ+getSureTypes 方式は「sure types に載る形の条件」しか拾えず、**「~を除去する」(sureNot)系の narrowing が原理的に表現できない**。
- 外側スコープ汚染は当初懸念したほど起きない(TypeSpecifier の多くのパスがスコープ型に依存しない sure type を作るため)が、これは**偶然に依存した設計**であり保証がない。
- `array_any` は拡張自体が存在しない。

## 既存の優れた先行機構: ArrayFilterFunctionReturnTypeHelper

`src/Type/Php/ArrayFilterFunctionReturnTypeHelper.php` が既に同型の問題を解いている:

- コールバック正規化: ArrowFunction / 単一 return Closure / **first-class callable** / **定数文字列 callable** をすべて「(itemVar, keyVar, bool式)」に正規化(FCC・文字列はダミー引数付きの合成 FuncCall を作る)
- **`MutatingScope::assignVariable()` で item/key 変数を配列の要素型で定義してから `filterByTruthyValue($expr)`** → 変数の narrowing 後の型を読み戻す(`processKeyAndItemType`)
  - これにより否定形・truthiness・カスタムアサーション・比較演算がすべて統一的に、かつ要素型にアンカーされて動く
  - 外側スコープの同名変数問題も原理的に発生しない
- ConstantArrayType はオフセット単位で処理(unsealed 部分も処理)
- `array_find` / `array_find_key` の戻り値型拡張はこのヘルパーを再利用している

現行 PR 実装はこの機構を知らずに車輪を再発明し、劣った版になっている。**再設計はこの機構への合流が正道**。

## その他の観察

- コールバックパラメータが by-ref (`fn (&$v)`) や variadic の場合のガードがない(現状は素通りで narrowing してしまう。by-ref は要素書き換えがありうるので bail すべき)
- `TypeSpecifier::create()` の 2.2.x シグネチャは `create(Expr, Type, TypeSpecifierContext, Scope): SpecifiedTypes`
- `#[AutowiredService]` 登録なので新ファイル追加後は `composer dump-autoload`(vendor/attributes.php 再生成)が必要 — ローカル検証時のハマりどころ
