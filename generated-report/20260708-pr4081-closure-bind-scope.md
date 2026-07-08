# PR #4081 再構成レポート（`Closure::bind()` scope binding improvements）

対象: https://github.com/phpstan/phpstan-src/pull/4081 （author: zonuexe / USAMI Kenta）
Repo: `/Users/megurine/repo/php/phpstan-src`
作成日: 2026-07-08

---

## 0. 結論（先に）

- **このPRは現在も必要**。最新 `2.2.x`（`670bcc1dc`）でバグは再現する。
- ただし PR の元コード（`MutatingScope::getType()` 内の `ClassConstFetch` 分岐を直接パッチ）は
  **もう当たらない**。2.2.x で式の型解決は `ExprHandler` 群へ分解され、
  `ClassConstFetch` は `src/Analyser/ExprHandler/ClassConstFetchHandler.php` が担当するようになった。
  → rebase は「機械的な rebase」ではなく、新アーキテクチャへの**移植（再構成）**が必要。
- 問題の本質は「**型推論経路（`getType`）と ルール検査経路（`processExprNode`）で、`Closure::bind()` の
  第3引数スコープの扱いが異なる**」こと。整理すると残作業は明確に切り分けられる（§5）。
- Node アトリビュート方式（著者の当初アプローチ）は**型推論経路に対して正しい選択**であることを
  実機検証で確認済み。移植版で `self::A` / `parent::A` / `static::A` の型解決が通ることを確認した（§4）。

---

## 1. PR概要とチェックリスト

- Title: `` `Closure::bind()` scope binding improvements ``
- Base: `2.1.x`（当時）/ Head branch: `fix/bind-class-scope`
- State: **OPEN / WIP / CONFLICTING**（description に "This PR still doesn't work as intended"）
- PR description のチェックリスト（原文）:
  - [x] Add bound scope to Name node (`Closure::bind()`)
  - [ ] Add bound scope to Name node (`Closure::bindTo()`)
  - [ ] Support const fetch
  - [ ] Support static method call
  - [ ] Fix `ClassConstantRule`
  - [ ] Fix `StaticMethodCallCheck`
  - [ ] Fix `InstantiationRule`

PRコメントは無し。

### コミット（ブランチ上、`599ac2ba2` 起点）
```
b3c42c825 debug     ← var_dump / debug_backtrace 混入（要削除）
65143d15e test      ← NodeScopeResolverTest に全テストskipのハック（要削除）
4b41e8b41 wip
f06b41b27 Add bound class scope to node attribute  ← 実質の本体
```

### 変更ファイル（PR差分）
| ファイル | 内容 | 再構成での扱い |
|---|---|---|
| `src/Parser/ClosureBindArgVisitor.php` | scopeStack + `SCOPE_ATTRIBUTE_NAME` + `leaveNode` | **維持**（2.2.xでも同一ファイル、そのまま移植可） |
| `src/Analyser/MutatingScope.php` | `getType()` の `ClassConstFetch` 分岐にscope注入 | **移設**（当該分岐は消滅 → `ClassConstFetchHandler` へ） |
| `src/Reflection/ClassReflection.php` | `var_dump`+`debug_backtrace` | **破棄**（デバッグ） |
| `src/Reflection/InitializerExprTypeResolver.php` | `var_dump` | **破棄**（デバッグ） |
| `src/Type/ObjectType.php` | コメントアウトした `var_dump` | **破棄**（デバッグ） |
| `tests/.../NodeScopeResolverTest.php` | `yield bug-x.php; return;` の全skipハック | **破棄** |
| `tests/.../nsrt/bug-x.php` | assertType テスト（期待値に誤りあり） | **作り直し**（§6） |

---

## 2. Git状態と rebase の距離

- merge-base(`HEAD`, `origin/2.2.x`) = `599ac2ba2`（`origin/2.2.x` の祖先）。
- ブランチは `origin/2.2.x` から **3036 コミット遅れ**。
- ただし PR が触る意味のあるファイルは少なく、`ClosureBindArgVisitor.php` は
  base と 2.2.x で**バイト一致**（衝突なし）。
- 一方 `MutatingScope.php` は大規模リファクタあり:
  - `resolveType()` が `ExprHandler`（`ExprHandler::EXTENSION_TAG` タグ付きサービス）へディスパッチする方式に。
  - `getType()` 内の `ClassConstFetch` 直接ハンドリングは撤去 → `ClassConstFetchHandler` へ。
  - よって PR の MutatingScope パッチは**アンカーが消滅**し、そのままでは rebase 不能。

---

## 3. 根本原因の分析（実機検証つき）

`Closure::bind($closure, $newThis, $newScope)` の第3引数 `$newScope` は、クロージャ本体内の
`self` / `parent` / `static` および private/protected アクセスの解決基準クラスを与える。
PHPStan にはこれを扱う既存機構 `Scope::enterClosureBind()` /
`inClosureBindScopeClasses`（`NodeScopeResolver` 2974行付近で `Closure::bind` を検出して起動）がある。

### 検証環境
`origin/2.2.x`（`670bcc1dc`）を worktree に checkout、`composer install` 済み。
`\PHPStan\dumpType()` を使い `phpstan analyse -l 9` で型を観測。

### 3-1. 型推論（`getType`）: **壊れている**
```php
class Foo { protected const A = 'Foo'; }
final class Bar extends Foo { protected const A = 'Bar'; }

Closure::bind(static fn () => Foo::A,    null, Foo::class)()  // 'Foo'     ← OK（明示クラス名）
Closure::bind(static fn () => self::A,   null, Foo::class)()  // *ERROR*   ← NG（self解決失敗）
Closure::bind(static fn () => self::A,   null, Bar::class)()  // *ERROR*   ← NG
Closure::bind(static fn () => parent::A, null, Bar::class)()  // *ERROR*   ← NG
```
グローバルスコープからだと `self` が `*ERROR*` + `outOfClass.self`。
**クラスメソッド内**から呼んでも `self::A` は bind scope の `Foo` ではなく
**外側クラス `Bar`** に解決される（誤り）。

**理由**: `Closure::bind(...)()` の戻り型は
`ClosureBindDynamicReturnTypeExtension::getTypeFromStaticMethodCall()` が
`$closureType = $scope->getType($args[0]->value)` として**外側スコープで計算済みの ClosureType を
そのまま返す**。クロージャ本体の `self::A` の型は、この外側スコープでの解析時点で確定してしまい、
bind scope（第3引数）は一切適用されない。つまり **bind scope は型推論経路に届かない**。
`enterClosureBind()` が設定する `inClosureBindScopeClasses` はクロージャ本体スコープには伝播するが、
戻り型計算はそれとは別経路なので効かない。

### 3-2. アクセシビリティ（ルール検査経路）: **明示クラス名なら既に動く**
```php
$c1 = Closure::bind(function () { return Foo::SECRET; }, null, Foo::class);        // private const
$c2 = Closure::bind(function () { return $this->priv(); }, new Foo(), Foo::class); // private method
```
→ どちらも **アクセスエラーは出ない**。`processExprNode` 経路は `closureBindScope`（=`inClosureBindScopeClasses`）
を使うため、明示クラス名の private/protected アクセスは既に許可される。

### 3-3. `self`/`parent`/`static` キーワード: ルール経路も NG
`self::A` 等は型推論だけでなく、`ClassConstantRule` /
`InstantiationRule` / `StaticMethodCallCheck` が
`if (!$scope->isInClass()) { "Using %s outside of class scope." }` を出す。
これらのルールは bind scope を見ないので、グローバルスコープの束縛クロージャ内で誤検知する。

### まとめ（2軸）
| | 明示クラス名 (`Foo::A`) | `self`/`parent`/`static` |
|---|---|---|
| 型推論 (`getType`) | OK | **NG（本PRの主対象）** |
| アクセシビリティ規則 | OK | **NG（outOfClass 誤検知）** |

---

## 4. 再構成アプローチと実機検証

### 4-1. なぜ「scopeベース修正」だけでは不十分か
最初に「`ClassConstFetchHandler` が `inClosureBindScopeClasses` を見て bind class の reflection を
`getClassConstFetchTypeByReflection` に渡す」案を実装・検証したが **効かなかった**。
理由は §3-1 の通り、クロージャ本体の型は外側スコープで先に確定し、そのスコープには bind class が
入っていないため（`getClosureBindScopeClassReflection()` が null を返す）。

### 4-2. Node アトリビュート方式（著者アプローチ）が正しい
`ClosureBindArgVisitor` がパース時に、`Closure::bind()` の引数部分木内の `self`/`parent`/`static`
（`Name::isSpecialClassName()`）ノードへ、第3引数の式を `SCOPE_ATTRIBUTE_NAME` として付与する。
アトリビュートは**AST と共に移動する**ので、どのスコープで `getType` されても参照できる
＝ **型推論経路に確実に届く**。これが著者が Node アトリビュートを選んだ理由。

### 4-3. 移植版の実装（検証済み・worktree上）
`ClosureBindArgVisitor.php` はブランチ版をそのまま移植。
`ClassConstFetchHandler::resolveType()` を次のように改修:

```php
$classReflection = $scope->isInClass() ? $scope->getClassReflection() : null;
$bindScopeReflection = $this->resolveClosureBindScopeReflection($scope, $expr->class);
if ($bindScopeReflection !== null) {
    $classReflection = $bindScopeReflection;
}
return $this->initializerExprTypeResolver->getClassConstFetchTypeByReflection(
    $expr->class, $expr->name->name, $classReflection,
    static fn (Expr $e): Type => $scope->getType($e),
);
```
`resolveClosureBindScopeReflection()` は `$expr->class` が
`SCOPE_ATTRIBUTE_NAME` を持つ `Name` のとき、格納された第3引数 Expr を
`$scope->getType(...)->getClassStringObjectType()->getObjectClassNames()` で単一クラスに解決し、
その `ClassReflection` を返す（`Foo::class` に限らず `$obj` などにも一般化）。

### 4-4. 検証結果（改修後）
```
Closure::bind(static fn () => self::A,   null, Foo::class)()  →  'Foo'  ✓（旧 *ERROR*）
Closure::bind(static fn () => self::A,   null, Bar::class)()  →  'Bar'  ✓
Closure::bind(static fn () => parent::A, null, Bar::class)()  →  'Foo'  ✓
（クラスメソッド内）Closure::bind(fn=>self::A, null, Foo::class)() → 'Foo' ✓（旧 'Bar'）
通常コードの self/parent/static/明示クラス名 → 変化なし（回帰なし）✓
```
※ 型は解決されたが、`outOfClass.self` 等のルール誤検知は**残る**（§5 の残作業）。
Node アトリビュートは特殊クラス名ノードにのみ付くため、通常コードには影響しない。

---

## 5. 残作業（チェックリストの再定義）

型推論経路とルール経路を分けて整理する。

### A. 型推論（getType）— Node アトリビュート方式で対応
1. **const fetch**（`ClassConstFetchHandler`）… §4 で実装・検証済み（PR: "Support const fetch"）。
2. **static method call の戻り型 / `self::method()`**（`StaticCallHandler` 相当）… 同様に
   `self`/`parent`/`static` レシーバへアトリビュート解決を適用。
3. **`new self` / `new static`**（`NewHandler` / インスタンス化の型）。
4. **`self::class` / `static::class`** リテラル（`::class` は既に一部通る。要確認）。

### B. ルール検査（accessibility / outOfClass）
5. **`ClassConstantRule`**（`processSingleClassConstFetch`, 2.2.x で `!$scope->isInClass()` を判定）…
   bind scope アトリビュートがあるときは outOfClass を出さず、bind class を基準にアクセス判定。
6. **`StaticMethodCallCheck`**。
7. **`InstantiationRule`**（`outOfClass.static/self/parent`）。

> 設計判断: A は Node アトリビュート必須（scope では届かない）。
> B は「アトリビュートから bind class を得て判定基準クラスを差し替える」共通ヘルパを
> 用意して各ルールで使い回すのが望ましい。B の一部（明示クラス名の private/protected）は
> `inClosureBindScopeClasses` で既に動くため、B の対象は主に `self/parent/static` キーワード。

### C. `Closure::bindTo()` 対応（PR未着手）
- `ClosureBindToVarVisitor` / `ClosureBindToDynamicReturnTypeExtension` が別に存在。
  `$closure->bindTo($newThis, $newScope)` の第2引数を同じ枠組みで scope アトリビュート化する。

### D. エッジケース（テストで固めるべき）
- ネストした `Closure::bind`（scopeStack の push/pop の正しさ）。
- 第3引数が変数 / オブジェクト / class-string 変数のとき。
- 第3引数省略（= 既定 `"static"`、アトリビュートは `null` → 外側クラス維持）。
- `static fn` と 非static クロージャの差。
- enum / interface constant、`parent` が無いクラスでの `parent::`。

---

## 6. テストの作り直し

元 `bug-x.php` は WIP スクラッチで、**期待値に誤りがある**:
```php
assertType("'Foo'", Closure::bind(static fn () => Bar::A, null, Bar::class)());  // 実際は 'Bar'
```
`Bar::A` は `'Bar'`。§3-1 の実機観測とも矛盾する。
→ nsrt テストは正しい期待値で新規作成する。命名は `closure-bind-scope.php` 等、
`NodeScopeResolverTest` のハック（全skip）は削除して通常の data-file 探索に戻す。

推奨する最小テスト（型推論）:
```php
Closure::bind(static fn () => self::A,   null, Foo::class)()  // 'Foo'
Closure::bind(static fn () => self::A,   null, Bar::class)()  // 'Bar'
Closure::bind(static fn () => parent::A, null, Bar::class)()  // 'Foo'
Closure::bind(static fn () => Foo::A,    null, Bar::class)()  // 'Foo'
```

---

## 6.5. 実装状況（本セッションで完了した分）

新ブランチ **`fix/bind-class-scope-2.2.x`**（`origin/2.2.x` = `670bcc1dc` 起点、既存 `fix/bind-class-scope` は温存・未 push）に、
デバッグを一切含まないクリーンなコミットを2つ作成済み:

1. `4444a3a82` **Resolve self/parent/static class constants inside Closure::bind() scope**
   - `ClosureBindArgVisitor`: scopeStack + `SCOPE_ATTRIBUTE_NAME` + `leaveNode`（ブランチ版を移植）。
   - `ClassConstFetchHandler`: bind scope アトリビュートを解決して bound class の reflection を
     `getClassConstFetchTypeByReflection` に渡す（**型推論**）。
   - nsrt テスト `tests/PHPStan/Analyser/nsrt/closure-bind-scope.php` 新規（正しい期待値）。
2. `feaa4b0db` **Make ClassConstantRule aware of Closure::bind() scope**
   - 共通サービス `src/Analyser/ClosureBindScopeResolver.php` を新設し、Handler と Rule で共用。
   - `ClassConstantRule`: bound class があるとき outOfClass を出さず、self/static→bound class、
     parent→bound class の親、で型・アクセシビリティ判定（**ルール検査**）。
   - `ClassConstantRuleTest` のコンストラクタ引数を追随。

**検証結果（実機）**:
| ケース | 期待 | 結果 |
|---|---|---|
| `Closure::bind(fn=>self::A, null, Foo::class)()` 型 | `'Foo'` | ✓ |
| 同上 `Bar::class` | `'Bar'` | ✓ |
| `parent::A` bound `Bar` 型 | `'Foo'` | ✓ |
| クラス内から bind、self の解決 | bound class 優先 | ✓ |
| bound scope 内の protected/private const アクセス | エラー無し | ✓ |
| `self::A` の outOfClass 誤検知 | 消える | ✓ |
| **非** bound の `static fn () => self::A` | outOfClass 継続 | ✓（回帰なし） |

テスト: `ClassConstantRuleTest`（15/15）, nsrt `closure|constant|late-binding` フィルタ（58/58）green。

→ チェックリスト **"Support const fetch" 完了 / "Fix ClassConstantRule" 完了**（self/parent/static + アクセシビリティ）。
落とし穴メモ: `#[AutowiredService]` を足したら **`composer dump-autoload`** で attributes 再生成が必要。
`instanceof Name` の `use PhpParser\Node\Name;` 忘れは fatal にならず**常に false**になり静かに機能を殺すので注意。

## 6.6. 実装状況（自然な延長を完成 — 追加分）

コミット3つ目 `1fb897ecd` **Resolve self/parent/static in Closure::bind() scope for static calls, properties and instantiation**:
- `MutatingScope::resolveName()` / `resolveTypeByName()` を Name アトリビュート対応に（`resolveClosureBindScopeClassName()` 追加）。
  これで **静的メソッド呼び出しの戻り型 / 静的プロパティ型 / `new self|parent|static`** が bound class で解決（クラス外でも）。
  const fetch と `::class` は既存の `ClassConstFetchHandler` 経由で処理済み（別経路）。
- ルール **`StaticMethodCallCheck` / `AccessStaticPropertiesCheck` / `InstantiationRule`** の outOfClass ガードを
  `ClosureBindScopeResolver` 経由で緩和（アクセシビリティは bound class 基準）。
- `ClosureBindArgVisitor` を再設計: **第1引数のクロージャ本体内のみ** rescope（第2/第3引数は対象外）。
  これで `Closure::bind($fn, $this, self::class)` の第3引数 `self::class` を自己参照 tag する不具合と、
  格納クロージャ（`$fx`）の誤 tag を回避。

**検証（実機マトリクス）**: `self::sm()`→戻り型解決 ✓ / `parent::sm()` ✓ / `self::$prop` ✓ /
`new self()`→`Foo` ✓ / `new parent()`→`Foo` ✓ / `self::class`・`parent::class` ✓。
全ケースで outOfClass 誤検知が消え、型が正しく解決。通常コードは無変化（回帰なし）。

**テスト**: nsrt `closure-bind-scope.php` に静的呼び出し/プロパティ/new を追加（green）。
影響ルールの各テスト（`InstantiationRuleTest` 46 / `AccessStaticPropertiesRuleTest` 9 /
`AccessStaticPropertiesInAssignRuleTest` 5 / `ClassConstantRuleTest` 15 / `StaticMethodCallableRuleTest` /
`ForbiddenNameCheckExtensionRuleTest`）のコンストラクタ引数を追随。nsrt 広域回帰 76/76 green。
PHPStan 自己解析（変更8ファイル）green。

> **既知の環境問題**: `CallStaticMethodsRuleTest::testClosureBind` は PHP 8.5.8 + このfixtureで
> **セグフォルト（exit 139）**。**本セッション変更前のベースラインでも同様にクラッシュ**するため
> 本変更起因ではない（当該 data ファイルの `analyse` 単体は誤検知なし・クラッシュなしを確認済み）。

### チェックリスト最終状態
- [x] Add bound scope to Name node（`Closure::bind()`）
- [x] Support const fetch
- [x] Support static method call（＋静的プロパティ・`new self/parent/static`）
- [x] Fix `ClassConstantRule`
- [x] Fix `StaticMethodCallCheck`
- [x] Fix `InstantiationRule`（＋`AccessStaticPropertiesCheck`）
- [ ] Add bound scope to Name node（`Closure::bindTo()`）… 未着手（別途）
- [ ] `static::` の遅延静的束縛＋束縛`$this`の型（#5987 の残り）… **#2972 の領域**（§8.1）

## 7. 再構成の手順（提案）

1. `origin/2.2.x` から新規ブランチ（既存 `fix/bind-class-scope` は force-push せず温存）。
2. コミット a: `ClosureBindArgVisitor` に scopeStack + `SCOPE_ATTRIBUTE_NAME` + `leaveNode`。
3. コミット b: `ClassConstFetchHandler` に bind-scope 解決（§4-3）。デバッグ混入は一切持ち込まない。
4. コミット c: nsrt テスト新規（§6）。`NodeScopeResolverTest` はハック削除。
5. 以降、§5 の B/A2〜4 を順次追加。各ステップで `phpstan analyse` と phpunit を回す。
6. push は ff を基本、force-push/rebase は要確認（[[pr-push-policy]]）。

---

## 8. 関連 issue / PR のトリアージ

このPR（＝`Closure::bind()` の第3引数スコープ内での `self`/`parent`/`static` 解決）が
各 issue を解決するかを実機検証込みで判定。

凡例: ✅本セッションの実装で解決 / 🟡PR完成（残チェックリスト実装）で解決 / 🟨一部のみ解決 /
❌別問題（本PRの射程外）/ ✔️既に他で解決済み（回帰ガードのみ）

| # | 種別/状態 | 判定 | 要点 |
|---|---|---|---|
| [phpstan#7675](https://github.com/phpstan/phpstan/issues/7675) | issue CLOSED | ✔️ | `Closure::bind` 内の定数のクラス誤認識。**PR #1543 で解決済**。本ブランチで `bug-7675.php` はエラー無し（回帰なし）。 |
| [phpstan-src#1543](https://github.com/phpstan/phpstan-src/pull/1543) | PR MERGED | ✔️ | #7675 の修正本体（`MutatingScope` の `ThisType` 誤判定）。先行実装。本PRと共存可。 |
| [phpstan#5987](https://github.com/phpstan/phpstan/issues/5987) | issue OPEN bug | 🟨→大幅前進 | **本PRの主戦場**。本セッション実装後の再トリアージ: `self::class`/`self::K`/`self::getScope()`/`parent::K`/`parent::getScope()`/`$this->getScopePriv()`(private) → **全て解決 ✅**。残る失敗は `static::class`/`static::K`/`static::getScope()`(→ scope class B に解決、本来は束縛object C の遅延静的束縛で C)・`$this->getScope()`(束縛`$this`の型が scope class B になる) の**4件のみ**。これらは `static` LSB＋束縛`$this`の型の問題で **#2972 の領域**（node-attribute方式では射程外、§8.1）。self/parent/scope-class 系は完了。 |
| [phpstan-src#2972](https://github.com/phpstan/phpstan-src/pull/2972) | PR OPEN **CONFLICTING/停滞** | ⚠️競合 | "Narrow down static and $this together"。#5987 を**scopeベース**で包括修正しようとし、`ClassConstantRule`/`StaticMethodCallCheck`/`AccessStaticPropertiesRule`/`InitializerExprTypeResolver` を編集。author=schlndh、最終更新2024-12（約1.5年停滞）。**本PRの `ClassConstantRule` 変更と衝突領域**。→ 要調整（§8.1）。 |
| [phpstan#8569](https://github.com/phpstan/phpstan/issues/8569) | issue OPEN bug | 🟡 | `Closure::bindTo()` で private メソッド呼び出し。本PRの `bind()`＋定数のみでは未解決。チェックリスト "bindTo" ＋ 静的/メソッド呼び出しスコープの実装が必要。 |
| [phpstan#11010](https://github.com/phpstan/phpstan/issues/11010) | issue OPEN feat | ❌ | `@param-closure-scope` PHPDoc タグの新設要望（ユーザーランド関数が内部で bind するケース）。本PRは**組み込み `Closure::bind()` 呼び出し箇所**が対象で、docblock タグ機構は別物。ただし思想は同じ（`@param-closure-this` の scope 版）。将来 `ClosureBindScopeResolver` の概念を reflection metadata 経由で流用可。 |
| [phpstan#13458](https://github.com/phpstan/phpstan/issues/13458) | issue OPEN feat | ❌ | `Closure::bind` 経由の **private プロパティ書き込み**が「never assigned」ルールに見えない。プロパティアクセス＋変異追跡ルールの話で、定数の本PRとは別。author自身「very edge case」。 |
| [phpstan#1348](https://github.com/phpstan/phpstan/issues/1348) | issue OPEN bug | ❌ | クロージャを**変数に格納**してから bind すると scope エラー誤検知。本PRの Node アトリビュートは**インラインの `Closure::bind(...)` 引数部分木にのみ**付与するため、格納クロージャは原理的に対象外。`$this` 可用性の話（closed PR #5543）とも関連。 |
| [phpstan-src#5543](https://github.com/phpstan/phpstan-src/pull/5543) | PR CLOSED | ❌ | 非static closure 外で `$this` を `object` として可用にする（#1348関連）。bot により close。別機構。本PRは扱わない。 |
| [phpstan#13386](https://github.com/phpstan/phpstan/issues/13386) | issue OPEN feat | ❌ | `$this` 使用時に `Closure::bind` 第2引数を非null必須にする**新規ルール**要望。全く別。 |
| [phpstan#11953](https://github.com/phpstan/phpstan/issues/11953) | issue OPEN feat | ❌ | 束縛クロージャの**戻り値としてのシグネチャ保持**（`Closure(): int` を返す）要望。`ClosureBindDynamicReturnTypeExtension` の話で、本PRは同拡張を触らない。 |
| [phpstan#14818](https://github.com/phpstan/phpstan/issues/14818) | issue OPEN feat | ❌ | static closure に `bindTo($obj)` した際の PHP 警告を再現する**新規ルール**要望。別。 |
| [phpstan#6319](https://github.com/phpstan/phpstan/issues/6319) | issue CLOSED bug | ✔️回帰ガード | `Closure::bind` の**評価順**: 入力式が評価された**後に**文脈を rebind すべき（従来は文全体を先に rebind）。例 `Closure::bind(self::isLower() ? fn()=>'LC' : ..., null, A::class)` で条件の `self::isLower()` は外側スコープ。**本セッションの visitor 再設計（第1引数のクロージャ本体のみ rescope、兄弟の式は対象外）はこの修正と整合**。実機で `self::isLower()` 誤検知なしを確認（回帰なし）。※ 逆に「元PRの広域tag方式（Closure::bindサブツリー全体をtag）はこの #6319 を再発させる」点が本再設計の根拠。 |
| [phpstan#4865](https://github.com/phpstan/phpstan/issues/4865) | issue CLOSED bug | ✔️回帰ガード | 既存関数 `$this->hydrate()` を `Closure::bind($this->hydrate(), $instance, get_class($instance))` の第1引数に渡すと "Call to undefined method" / "Undefined variable $this"。第1引数が**インラインでない**（MethodCall）ため本 visitor の rescope 対象外＝影響なし。`testBug4865`（期待エラー0件）green（回帰なし）。 |

### 8.1. PR #2972 との関係（重要）
#5987 に対し本PR（Nodeアトリビュート方式）と #2972（scopeベース方式）が**競合**する。相違:
- 本PR: パース時に `self/parent/static` ノードへ bind scope を付与 → **型推論経路に確実に届く**。
  現状は定数のみ。メソッド/プロパティ/`new` へ横展開が必要。
- #2972: scope 側で `static` と `$this` の関係を整理 → メソッド/プロパティ/定数を横断的に扱うが、
  §3-1 で示した「型推論経路（`ClosureBindDynamicReturnTypeExtension` が外側スコープで確定）」に
  どこまで効くかは要確認。CONFLICTING で長期停滞。
- **推奨**: #5987 を本PRのスコープに含めるなら、#2972 の変更範囲（`StaticMethodCallCheck` /
  `AccessStaticPropertiesRule` / `InitializerExprTypeResolver`）を参照しつつ、
  `ClosureBindScopeResolver` を共通軸に据えて統合する。二重実装・レビュー衝突を避けるため、
  メンテナ（ondrejmirtes）へ #2972 の扱い（引き継ぎ/close）を確認するのが望ましい。

### 8.2. まとめ
- **直接的に前進させる価値が高い**: #5987（本PRの中核。定数は解決済、残りで完全化）、
  #8569（bindTo＝チェックリスト項目）。
- **既に解決済み・回帰ガードのみ**: #7675 / #1543。
- **本PRの射程外（別issueとして扱うべき）**: #11010, #13458, #1348, #13386, #11953, #14818,
  #5543。ただし #11010 は「scope をユーザーランドへ露出する」将来拡張として本PRの基盤を再利用可能。

## 付録: 検証に使った再現ファイル
`/tmp/repro-bind.php`, `/tmp/repro-bind3.php`, `/tmp/repro-access.php`, `/tmp/repro-regress.php`
worktree: `.../scratchpad/wt-22x`（`origin/2.2.x` + 移植版パッチ適用済み・検証用）。
