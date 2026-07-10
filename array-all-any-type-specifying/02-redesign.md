# array_all / array_any 型絞り込みの再設計

日付: 2026-07-10
ブランチ: `array-all-type-specifying` (phpstan/2.2.x ベース)

## 意味論(何を narrowing できるか)

`array_all([], $cb) === true`、`array_any([], $cb) === false` が全ての鍵。

| 関数 | コンテキスト | 意味 | narrowing |
|------|-------------|------|-----------|
| array_all | truthy | 全要素が述語を満たす(空でも true) | 要素の値/キーを **述語-truthy** で絞る。空配列を含むので `array<K', V'>` で健全 |
| array_all | falsy | 少なくとも1要素が述語を満たさない | **non-empty-array**(要素型は表現不能) |
| array_any | truthy | 少なくとも1要素が述語を満たす | **non-empty-array**(コールバックが解析不能でも成立) |
| array_any | falsy | 全要素が述語を満たさない(空でも false) | 要素の値/キーを **述語-falsey** で絞る |

array_all truthy と array_any falsy は双対(filterByTruthyValue vs filterByFalseyValue の違いだけ)。

## アーキテクチャ

ArrayFilterFunctionReturnTypeHelper から 2 つの関心事を共有サービスに抽出し、3 者(filter系 / all / any)で共用する。

1. **コールバック正規化ヘルパー**(新規サービス、名前は実装者裁量。例 `ArrayPredicateCallbackResolver`)
   - 入力: callback の Expr + Scope
   - 出力: `(itemVar: ?Variable, keyVar: ?Variable, expr: Expr)` のリスト(定数文字列 callable の union で複数)、または null(解析不能)
   - 対応: ArrowFunction / 単一 return Closure / first-class callable / 定数文字列 callable
   - ArrayFilter 用の USE_ITEM/USE_KEY/USE_BOTH モードと、array_all/any 用の「(value, key) 固定」の両方を賄えるようにする
   - **bail 条件**: パラメータが by-ref、variadic、`Variable` でない、名前が動的
2. **要素スコープ narrowing**(ArrayFilterFunctionReturnTypeHelper::processKeyAndItemType 相当の共有化)
   - `MutatingScope::assignVariable()` で itemVar/keyVar に要素型を割り当て → `filterByTruthyValue($expr)` / `filterByFalseyValue($expr)` → 変数型を読み戻す
3. **拡張 2 クラス**(薄く保つ)
   - `ArrayAllFunctionTypeSpecifyingExtension`(書き直し)
   - `ArrayAnyFunctionTypeSpecifyingExtension`(新規)
   - どちらも `FunctionTypeSpecifyingExtension` + `TypeSpecifierAwareExtension`、`!$context->null()` ガード

### 要素絞り込みの型構築

`$scope->getType($arrayArg)->getArrays()` の各メンバーごとに処理して union:

- **一般の ArrayType / IntersectionType 由来**: 反復キー型・値型を narrowing。
  - **narrowing 結果の key または value が NeverType の場合、そのメンバーの指定型は `ConstantArrayType([], [])`(= `array{}`)にする**。never にしない。
    - 根拠(プローブ E の不健全性の修正): `list<mixed>` + `is_string($k)` は「空 list なら array_all は true」なので truthy 分岐は到達可能で、型は `array{}`。
    - 元の型が確実に非空(`non-empty-list` 等)の場合は、スコープ側の交差 `non-empty ∩ array{} = never` が自動的に never を導き、「always false」報告も正しく出る。拡張側で isIterableAtLeastOnce の分岐は不要 — 交差に任せるのがエレガント。
  - それ以外は `new ArrayType($newKey, $newValue)`。既存型との交差で list / non-empty 等のアクセサリは保存される(既存テスト test9/test10 が担保)。
- **ConstantArrayType**: オフセット単位の精密化(array_filter の filterByTruthyValue を参考に、ただし意味論が違う):
  - 必須オフセットの値の narrowing が Never → **そのメンバー全体が never**(その shape では all が true になりえない)
  - 任意(optional)オフセットの narrowing が Never → **オフセットを除去**(述語を満たすには存在しないしかない)
  - それ以外 → 値を narrowing 後の型に置換、**optionality は維持**(array_filter と違い、残存要素が消えることはないので optional 化しない)
  - unsealed 部分: narrowing し、Never なら sealed にする
- 実装の複雑さが利益に見合わない場合、ConstantArrayType の精密化は一般パス(iterable key/value)へのフォールバックでも可。ただしその場合も E の不健全性だけは必ず回避すること。

### non-empty narrowing の構築

falsy(array_all) / truthy(array_any) では、**コールバックの形にかかわらず**(閉包変数でも)適用できる:

```php
$this->typeSpecifier->create(
    $arrayArg,
    TypeCombinator::intersect(new ArrayType(new MixedType(), new MixedType()), new NonEmptyArrayType()),
    TypeSpecifierContext::createTruthy(), // 現在の分岐で「~である」と主張するため truthy で create
    $scope,
);
```

拡張が falsy コンテキストで呼ばれても、返した SpecifiedTypes はその分岐にそのまま適用される(要実証 — nsrt テストで確認)。既存拡張の先行例を探して倣うこと。

## 意図的にスコープ外とするもの(ノートに残す将来課題)

- 閉包を変数に入れたケース(`$cb = fn($v) => ...; array_all($arr, $cb)`)の要素 narrowing — ClosureType は AST を持たないため不可。ただし non-empty narrowing は適用される
- 0 引数コールバック + 非空配列からの**外側キャプチャ変数**の narrowing
- `array_find` / `array_find_key` の truthy → non-empty 拡張(別 PR。`!== null` は null コンテキスト対応が必要で機構が異なる)
- 複数文 Closure の解析

## テスト計画

- `tests/PHPStan/Analyser/nsrt/array-all.php`: **既存ケース(元 PR 著者のもの)は全て維持**。ただし現行実装の欠陥に由来する期待値は修正(特に E 相当があれば)。追加:
  - 否定形 `!is_null($v)` / `$v !== null` → `array<mixed~null>`
  - 素の truthiness `fn($v) => $v`
  - `list<mixed>` + `is_string($k)` → `array{}`(E の regression)
  - `non-empty-list` + `is_string($k)` → never + always-false エラーが正当に出ること(AnalyserIntegrationTest か nsrt でエラー検証)
  - falsy → `non-empty-array`
  - 2引数 FCC / 2引数ユーザー関数の文字列 callable
  - `@phpstan-assert-if-true`(既に動くことの regression 化)
  - ConstantArrayType(shape)のケース
  - by-ref パラメータ → narrowing しない
- `tests/PHPStan/Analyser/nsrt/array-any.php`: 新規、上の双対ケース
- 既存の array_filter / array_find / array_find_key テストがリファクタ後も通ること
- red/green: 拡張ファイルを退避して nsrt テストが落ちることを確認(composer dump-autoload を忘れない)

## 判断記録

- **specifyTypesInCondition + 名前マッチ方式を捨てて assignVariable + filterBy*Value 方式に全面移行**。理由: sureNot/truthiness/否定形を原理的に扱えない、スコープ非依存性が偶然任せ、先行機構(array_filter)と重複。
- **拡張は 2 クラスに分ける**(ArrayFind/ArrayFindKey が別クラスである codebase 慣行に合わせる)。
- **ArrayFilterFunctionReturnTypeHelper のリファクタを同一 PR に含める**。重複を残す方が将来の乖離リスクが高い。既存テストがガードする。
- E の修正は「Never → array{} に置換し、非空性はスコープの交差に委ねる」方式。拡張内での場合分けを最小化。
