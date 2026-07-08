# ImpurePoint と SimpleImpurePoint の相互関係

PHPStan が「関数・メソッド・クロージャの副作用(不純な振る舞い)」を追跡するために使う 2 つのクラス、
[`PHPStan\Analyser\ImpurePoint`](src/Analyser/ImpurePoint.php) と
[`PHPStan\Reflection\Callables\SimpleImpurePoint`](src/Reflection/Callables/SimpleImpurePoint.php)
の役割・違い・つながりを整理する。

---

## 1. 「Impure Point(不純点)」とは何か

**不純点** = コード中で副作用が発生しうる 1 箇所。
`echo`、`die`、プロパティ代入、関数呼び出し、`new`、`include`、`yield`、スーパーグローバルアクセスなどが該当する。
識別子の全種類は `ImpurePointIdentifier` 型に列挙されている:

```
'echo'|'die'|'exit'|'propertyAssign'|'propertyAssignByRef'|'propertyUnset'|'methodCall'
|'new'|'functionCall'|'include'|'require'|'print'|'eval'|'superglobal'|'yield'|'yieldFrom'
|'static'|'global'|'betweenPhpTags'|'staticPropertyAccess'
```

この型は `ImpurePoint` 側で `@phpstan-type` として定義され、`SimpleImpurePoint` 側は
`@phpstan-import-type ImpurePointIdentifier from ImpurePoint` で**インポートして共有**している。
両者は同じ識別子ボキャブラリを使う。

PHPStan が不純点を使う目的(`SimpleImpurePoint` の docblock より):

- `@phpstan-pure` として宣言された文脈の中で、不純な関数呼び出しを検出する
- 純粋関数の戻り値が使われていない箇所を報告する(`expr.resultUnused`)
- 式が副作用を持つかどうかを判定する(デッドコード検出など)

---

## 2. 2 つのクラスの違い

| | `SimpleImpurePoint` | `ImpurePoint` |
|---|---|---|
| 名前空間 | `PHPStan\Reflection\Callables` | `PHPStan\Analyser` |
| レイヤー | **リフレクション層**(シグネチャレベル) | **解析層**(AST/スコープレベル) |
| 位置情報 | 持たない(抽象的な「この callable は副作用を持ちうる」) | 持つ(`Scope` と具体的な `Node` に紐づく) |
| 生成タイミング | callable の型情報を組み立てるとき | AST を歩いて実際の呼び出し箇所を処理するとき |
| フィールド | `identifier`, `description`, `certain` | `scope`, `node`, `identifier`, `description`, `certain` |
| ファクトリ | `createFromVariant()` を持つ | なし(コンストラクタ直接) |

共通フィールドは 3 つ:

- **`identifier`** — 不純点の種類(`ImpurePointIdentifier`)
- **`description`** — 人間可読な説明(例: `"call to function preg_replace_callback()"`)
- **`certain`** — 確実に不純か(`true`)、不純かもしれない(`false`)かのフラグ

`ImpurePoint` はこれに **`scope`(発生時のスコープ)** と **`node`(該当 AST ノード)** を加えた、
「位置つきの `SimpleImpurePoint`」と考えると分かりやすい。

---

## 3. 関係の核心: SimpleImpurePoint → ImpurePoint への「昇格」

`SimpleImpurePoint` は**位置を持たない抽象的な副作用記述**であり、
`ImpurePoint` は**具体的な呼び出し箇所に紐づいた副作用記述**である。
実際の解析中、前者は後者へ変換(昇格)される。

変換は各 ExprHandler で行われる。代表例は
[`FuncCallHandler`](src/Analyser/ExprHandler/FuncCallHandler.php:143):

```php
// クロージャ/callable 値の呼び出し: getImpurePoints() が返す SimpleImpurePoint 群を昇格
$impurePoints = array_merge($impurePoints, array_map(
    static fn (SimpleImpurePoint $impurePoint) => new ImpurePoint(
        $scope,                       // ← 解析層の情報を付与
        $expr,                        // ← 該当 AST ノード
        $impurePoint->getIdentifier(),
        $impurePoint->getDescription(),
        $impurePoint->isCertain(),
    ),
    $parametersAcceptor->getImpurePoints(),
));
```

通常の名前つき関数呼び出しでは、ファクトリを直接呼んでから昇格する
([`FuncCallHandler`](src/Analyser/ExprHandler/FuncCallHandler.php:155)):

```php
$impurePoint = SimpleImpurePoint::createFromVariant($functionReflection, $parametersAcceptor, $scope, $expr->getArgs());
if ($impurePoint !== null) {
    $impurePoints[] = new ImpurePoint($scope, $expr, $impurePoint->getIdentifier(), $impurePoint->getDescription(), $impurePoint->isCertain());
}
```

`MethodCallHandler` / `StaticCallHandler` も同じパターンでメソッド呼び出しを昇格する。

```
 リフレクション層                     解析層(NodeScopeResolver / ExprHandler)
┌────────────────────────┐          ┌──────────────────────────────────────┐
│ SimpleImpurePoint      │          │ ImpurePoint                          │
│  - identifier          │  昇格     │  - scope   ← 追加                    │
│  - description         │ ───────▶ │  - node    ← 追加                    │
│  - certain             │  new     │  - identifier / description / certain │
│  (位置なし)             │          │  (位置あり)                          │
└────────────────────────┘          └──────────────────────────────────────┘
```

---

## 4. SimpleImpurePoint::createFromVariant() のロジック

[`createFromVariant()`](src/Reflection/Callables/SimpleImpurePoint.php:54) は、関数/メソッドのリフレクションと
その variant(`ParametersAcceptor`)から `SimpleImpurePoint` を作る中心的ファクトリ。

判定の流れ:

1. **純粋なら `null` を返す** — `$function->hasSideEffects()->no()` のとき。不純点は作られない。
2. **`certain`(確実性)の決定**:
   - `$certain = $function->isPure()->no();`
     — `isPure()` が明確に「No」(=確実に不純)なら `true`。
   - `$certain = $certain || $variant->getReturnType()->isVoid()->yes();`
     — 戻り値が `void` の関数は「何か作用しないと無意味」なので確実に不純とみなす。
3. **副作用フリップ関数の特別扱い** (`SIDE_EFFECT_FLIP_PARAMETERS`):
   - `print_r` / `var_export` / `highlight_string` は、第 2 引数(`$return`)が truthy だと
     「出力せず文字列を返す」= **純粋になる**。
   - 該当引数(名前つき/位置引数の両方に対応)が truthy と判定できれば `null` を返し、不純点を作らない。
4. 上記を通過したら `SimpleImpurePoint` を生成:
   - 関数なら `identifier = 'functionCall'`, `description = "call to function foo()"`
   - メソッドなら `identifier = 'methodCall'`, `description = "call to method Foo::bar()"`

---

## 5. どこで消費されるか

昇格された `ImpurePoint` の配列は、解析結果オブジェクトを経由してルールに届く。

### 5.1 結果オブジェクトを通じた伝播

- [`ExpressionResult`](src/Analyser/ExpressionResult.php) / [`StatementResult`](src/Analyser/StatementResult.php:167)
  が `getImpurePoints(): ImpurePoint[]` を持ち、式・文の処理結果として不純点を運ぶ。
- `NodeScopeResolver` は関数本体・メソッド本体・クロージャを処理する際、
  文の不純点に加えて `yield` などの本体固有の不純点を合成し、仮想ノードへ渡す。
- クロージャ/callable 型([`ClosureType`](src/Type/ClosureType.php) が実装する
  [`CallableParametersAcceptor`](src/Reflection/Callables/CallableParametersAcceptor.php:41))は
  `getImpurePoints(): SimpleImpurePoint[]` を公開し、呼び出し時に昇格される。

### 5.2 純粋性ルール

[`PureFunctionRule`](src/Rules/Pure/PureFunctionRule.php)(および対応するメソッド版)は
`FunctionReturnStatementsNode::getImpurePoints()` を
[`FunctionPurityCheck`](src/Rules/Pure/FunctionPurityCheck.php:77) に渡す。ここで各 `ImpurePoint` が消費される:

```php
foreach ($impurePoints as $impurePoint) {
    // certain に応じて "Impure" / "Possibly impure" を出し分け
    RuleErrorBuilder::message(sprintf(
        '%s %s is not allowed in pure %s.',
        $impurePoint->isCertain() ? 'Impure' : 'Possibly impure',
        $impurePoint->getDescription(),
        ...
    ))
    ->line($impurePoint->getNode()->getStartLine())    // ← ImpurePoint が持つ node の位置
    ->identifier(sprintf('%s.%s',
        $impurePoint->isCertain() ? 'impure' : 'possiblyImpure',
        $impurePoint->getIdentifier(),                 // ← identifier がエラー識別子に使われる
    ))
    ...
}
```

→ `@phpstan-pure` と宣言された関数の中に不純点があれば、
「Impure call to function foo() is not allowed in pure Function bar().」のようなエラーになる。
ここで **`ImpurePoint` が `Node` を持つからこそ、正確な行番号を報告できる**(`SimpleImpurePoint` だけでは不可能)。

### 5.3 デッドコード検出

`src/Rules/DeadCode/` 配下の各ルール/コレクター:

- `FunctionWithoutImpurePointsCollector` / `MethodWithoutImpurePointsCollector` /
  `ConstructorWithoutImpurePointsCollector` — 不純点を **1 つも持たない** 関数/メソッド/コンストラクタを収集
- `CallToFunctionStatementWithoutImpurePointsRule` など — そうした純粋な呼び出しが
  文として単独で書かれている(=戻り値も副作用も捨てられている)箇所を「無意味な呼び出し」として報告
- `PossiblyPureCallTransitivePurityResolver` — 推移的な純粋性の解決

つまり「不純点が空か否か」が、**その式が捨ててよい(=デッドコード)かどうか**の判定基準になる。

---

## 6. まとめ(相互関係の一枚図)

```
                          ┌─────────────────────────────────────────────┐
     リフレクション層       │  FunctionReflection / MethodReflection       │
                          │    ├ hasSideEffects(): TrinaryLogic          │
                          │    └ isPure(): TrinaryLogic                  │
                          └───────────────────┬─────────────────────────┘
                                              │ createFromVariant()
                                              ▼
                          ┌─────────────────────────────────────────────┐
                          │  SimpleImpurePoint (位置なし)                 │
                          │    identifier / description / certain         │
                          │  ClosureType 等の getImpurePoints() でも公開   │
                          └───────────────────┬─────────────────────────┘
                                              │ 各 ExprHandler で new ImpurePoint(scope, node, ...)
       解析層                                 ▼
                          ┌─────────────────────────────────────────────┐
                          │  ImpurePoint (位置あり)                       │
                          │    scope / node / identifier / description /  │
                          │    certain                                    │
                          └───────────────────┬─────────────────────────┘
                                              │ ExpressionResult / StatementResult
                                              │ → 仮想ノード(FunctionReturnStatementsNode 等)
                                              ▼
       ルール層             ┌─────────────────────────────────────────────┐
                          │  PureFunctionRule / FunctionPurityCheck       │  … @phpstan-pure 違反検出
                          │  DeadCode/*WithoutImpurePoints*               │  … 無意味な呼び出し検出
                          └─────────────────────────────────────────────┘
```

- **`SimpleImpurePoint`** = リフレクション/シグネチャレベルの「この callable は副作用を持ちうる」という抽象記述。位置を持たない。`createFromVariant()` で `hasSideEffects()`/`isPure()`/戻り値 void/フリップ引数から生成される。
- **`ImpurePoint`** = それを実際の呼び出し箇所で `Scope` と `Node` に結び付けた具体記述。ルールが行番号つきエラーを出したり、デッドコードを判定したりするのに使う。
- 両者は `ImpurePointIdentifier` 型を `@phpstan-type` / `@phpstan-import-type` で共有し、識別子の語彙を統一している。
- データの流れは **リフレクション層(Simple)→ ExprHandler で昇格 → 解析結果 → ルール層** の一方向。
