# `forward_static_call()` の遅延静的束縛（LSB）を静的解析器はモデル化しているか

作成日: 2026-07-15
発端: PHPマニュアルへの PR https://github.com/php/doc-en/pull/5681 （転送呼び出し / 非転送呼び出しの明文化）
対象: PHPStan / Psalm / Phan / Mago

---

## 0. 結論

- **どのツールも `forward_static_call()` の「LSB転送」意味論をモデル化していない。**
- **PHPStan と Psalm は `mixed`** を返す（呼び先の戻り値型すら解決しない）。
- **Phan と Mago は戻り値型は解決する**が、`call_user_func()` と**同じ扱い**で、転送/非転送を区別しない。
- 唯一 **PHPStan だけが `call_user_func()` を「非転送」として正しくモデル化**している（`Base` に解決）。
  Phan / Mago は `call_user_func` にも `static` を残しており、実行時（必ず `Base`）より広い＝不正確。

---

## 1. PHP の意味論（実測: PHP 8.5.8）

**転送呼び出し (forwarding call)**: `self::`, `parent::`, `static::`, `forward_static_call()`, `forward_static_call_array()`
→ 呼び出し元の `static`（遅延静的束縛の「呼ばれたクラス」）を**引き継ぐ**。

**非転送呼び出し (non-forwarding call)**: 明示的なクラス名 (`Base::method()`), `call_user_func()`
→ `static` は**名指ししたクラスにリセット**される。

```php
class Base {
    public static function create(): static { return new static(); }
    public static function name(): string { return static::class; }
}
class Caller extends Base {
    public static function test(): void { /* 下表の呼び出し */ }
}
class SubCaller extends Caller {}
SubCaller::test();
```

| 呼び出し | 実行時の結果 |
|---|---|
| `forward_static_call([Base::class,'create'])` | **`SubCaller`** |
| `forward_static_call_array([Base::class,'create'], [])` | **`SubCaller`** |
| `forward_static_call([Base::class,'name'])` | **`SubCaller`** |
| `self::create()` | `SubCaller` |
| `parent::create()` | `SubCaller` |
| `call_user_func([Base::class,'create'])` | **`Base`** |
| `Base::create()` | `Base` |

> 重要: 転送される `static` は「呼び出し元の `static`」であり、**サブクラスになり得る**
> （`SubCaller::test()` から呼ぶと `SubCaller`）。単なる定数 `Caller` ではない。
> 静的解析上は `static(Caller)`（Caller-or-subclass）にバインドするのが正しい。

---

## 2. 4ツールの推論比較

`/** @return static */ public static function create(): static` と `name(): string` に対して:

| 式 | 実行時 | **PHPStan** | **Psalm** | **Phan** | **Mago** |
|---|---|---|---|---|---|
| `forward_static_call([Base::class,'create'])` | `SubCaller` | **`mixed`** ❌ | **`mixed`** ❌ | `\Base\|static` 〜 | `Base&static` 〜 |
| `forward_static_call_array(...)` | `SubCaller` | **`mixed`** ❌ | **`mixed`** ❌ | `\Base\|static` 〜 | `Base&static` 〜 |
| `forward_static_call([Base::class,'name'])` | `string` | **`mixed`** ❌ | **`mixed`** ❌ | `string` ✅ | `string` ✅ |
| `call_user_func([Base::class,'create'])` | `Base` | **`Base`** ✅ | `mixed` ❌ | `\Base\|static` 〜 | `Base&static` 〜 |
| `Base::create()` | `Base` | `Base` ✅ | `Base` ✅ | `\Base` ✅ | `Base&static` 〜 |
| `self::create()` | `SubCaller` | `static(Caller)` ✅ | `Caller&static` ✅ | `\Caller` 〜 | `Caller&static` ✅ |

凡例: ✅=正確 / 〜=解決するが転送・非転送を区別しない（不正確） / ❌=`mixed`（未実装）

**読み取れること:**

1. **`forward_static_call` の LSB 転送を実装しているツールは存在しない。**
   期待される `static(Caller)` を返すツールはゼロ。
2. **PHPStan / Psalm は完全に素通し**（`mixed`）。呼び先の `string` すら解決しない。
3. **Phan / Mago は呼び先の戻り値型を解決する**（`name()` → `string` は正しい）が、
   `create()` については `Base|static` / `Base&static` を返し、`call_user_func` と**区別していない**。
4. **`call_user_func` の非転送を正しく扱えているのは PHPStan だけ**（`Base`）。
   Phan / Mago が `static` を残すのは実行時（必ず `Base`）より広く、**不正確**。
   Psalm は `call_user_func` すら `mixed`。

---

## 3. PHPStan 側のコード所在（gap の正体）

[`src/Analyser/ExprHandler/FuncCallHandler.php`](https://github.com/phpstan/phpstan-src/blob/2.2.x/src/Analyser/ExprHandler/FuncCallHandler.php) の `resolveType()` で、
**`call_user_func` と `call_user_func_array` だけ**が特別扱いされている:

```php
if ($functionReflection->getName() === 'call_user_func') {
    $result = ArgumentsNormalizer::reorderCallUserFuncArguments($expr, $scope);
    if ($result !== null) {
        [, $innerFuncCall] = $result;
        return $scope->getType($innerFuncCall);   // 内部呼び出しノードに正規化して解決
    }
}
// call_user_func_array も同様
```

`forward_static_call` / `forward_static_call_array` にはこの分岐が**無い**ため、
[`resources/functionMap.php`](https://github.com/phpstan/phpstan-src/blob/2.2.x/resources/functionMap.php) の宣言に落ちる:

```php
'forward_static_call'       => ['mixed', 'function'=>'callable', '...parameters='=>'mixed'],
'forward_static_call_array' => ['mixed', 'function'=>'callable', 'parameters'=>'array<int,mixed>'],
```

→ **`mixed`**。

なお `resources/functionMetadata.php` には**純粋性の記述のみ**存在する（`pureUnlessCallableIsImpureParameters`）。
戻り値型・LSB の記述は無い。引数チェックの `CallUserFuncRule` も `call_user_func` 系のみが対象。

PHPStan は**ネイティブの転送/非転送は完璧にモデル化できている**（`self::`/`parent::`/`static::` → `static(Caller)`、
明示クラス名 → `Base`）ので、機構自体は存在する。欠けているのは `forward_static_call` への接続のみ。

---

## 4. 実装するとしたら（2段階の gap）

1. **戻り値型の解決**（`mixed` → 呼び先の戻り値型）
   `call_user_func` と同様に `ArgumentsNormalizer` で内部 `StaticCall` に正規化し `$scope->getType()`。
   これだけでも `forward_static_call([Base::class,'name'])` → `string` になる（Phan/Mago と同水準）。
2. **LSB 転送**（`static` を**呼び出し元の `static` 型**にバインド）
   呼ばれるメソッドの解決は名指しクラス（`Base`）だが、戻り値型中の `static` は
   `$scope` の static 型（`static(Caller)`）に解決する必要がある。
   ネイティブの `self::`/`parent::` で既に動いている機構を流用できるはず。

---

## 5. 実装価値の評価（率直な所）

**実装しない判断も十分に合理的:**

- `forward_static_call()` は**利用頻度・知名度が著しく低い**。
- **他のどのツールも実装していない**（Phan/Mago ですら転送を区別しない）。実質的な業界標準は「未対応」。
- 現状 `mixed` を返すのは**不正確ではあるが unsound ではない**（`mixed` は常に安全側）。
  むしろ Phan/Mago が `call_user_func` に `static` を残しているほうが実害のリスクがある。
- 対して実装コストは、LSB 転送まで含めると `FuncCallHandler` + `ArgumentsNormalizer` + 型解決の
  static バインドに手を入れる必要があり、小さくはない。

**実装する価値がある観点:**

- 段階1（戻り値型の解決）だけなら比較的低コストで、`mixed` の伝播（level 9/10 で `mixed` 由来のエラー）を減らせる。
- PHPStan は `call_user_func` の非転送を唯一正しく扱えている＝この領域の**精度で他ツールに先行**しており、
  `forward_static_call` を対応すれば「転送/非転送を正しく区別する唯一の解析器」になる。
- PHPマニュアル側（php/doc-en#5681）で意味論が明文化されるタイミングと整合する。

→ **issue を立てて事実を提示し、メンテナに判断を委ねるのが妥当。**
（「rarely used のため wontfix」も納得できる結論。）

---

## 6. 試作実装（2026-07-15 完了）

ブランチ **`feature/forward-static-call-return-type`**（`phpstan/2.2.x` = `e520eb822` 起点）。
**提出済み**: issue https://github.com/phpstan/phpstan/issues/14958 / PR https://github.com/phpstan/phpstan-src/pull/6051
コミット3本: `376b3774d` 型推論 / `523ec08be` 多段継承テスト / `3cf80b275` CallUserFuncRule 拡張（引数チェック）。

**実装内容**（§4 の2段階を両方カバー）:
- `ArgumentsNormalizer::reorderForwardStaticCallArguments()` / `reorderForwardStaticCallArrayArguments()` —
  `call_user_func` 用の既存正規化を再利用し、callable が「クラス名＋メソッド名」（定数配列 `[Base::class,'m']` /
  定数文字列 `'Base::m'`）に解決できるときは合成 `StaticCall` ノードへ、それ以外（closure 等）は従来どおり
  内部 `FuncCall` へ正規化。
- `FuncCallHandler::resolveForwardStaticCallType()` — 合成 StaticCall を
  `$scope->resolveTypeByName($class)` の結果（**static-type リセット無し**）に対する
  `methodCallReturnType()` で直接解決。`resolveTypeByName` は名指しクラスが祖先なら
  caller-bound な ancestor 型、そうでなければ素の ObjectType を返すため、
  **「転送は呼び出し元の祖先系統内のみ」という実行時規則が自然に一致**する。

**設計上の要点（ハマりどころ）**:
1. 当初は合成ノードに attribute を付け `StaticCallHandler` の pinning をスキップさせる案だったが、
   `MutatingScope::getType()` の**式型キャッシュのキーは印字文字列**（`ScopeOps::nodeKey`）のため、
   実コードの `\NS\Base::create()` と合成ノードが**衝突**する。しかも `ScopeOps` は
   **phpstan_turbo ネイティブ拡張にシャドーされる**ため `nodeKey` の変更は不可。
   → 合成ノードを `getType()` に通さず **handler 内で直接解決**する方式に変更（キャッシュ非経由）。
2. 非転送のモデル化の正体は `StaticCallHandler::resolveTypeByNameWithLateStaticBinding()` の
   `getStaticObjectType()` による pinning。転送＝この pinning をしないこと。

**検証結果**（nsrt `forward-static-call.php`、TDD red→green）:
| 式 | 型 |
|---|---|
| `forward_static_call([Base::class,'create'])`（祖先） | `static(Caller)` ✅ |
| `forward_static_call('NS\Base::create')`（文字列 callable） | `static(Caller)` ✅ |
| `forward_static_call_array([Base::class,'create'], [])` | `static(Caller)` ✅ |
| `forward_static_call([Base::class,'name'])` | `string` ✅ |
| `forward_static_call([Other::class,'create'])`（無関係） | `Other` ✅（転送なし＝実行時と一致） |
| `forward_static_call(static fn(): int => 1)`（closure） | `1` ✅ |
| クラス外 | `Base` ✅（実行時は Error だが型としては妥当） |
| generics `identity('hello')` / `_array([42])` | `'hello'` / `42` ✅（引数から解決） |

回帰: nsrt 52テスト / `CallUserFuncRuleTest` 5 / `CallCallablesRuleTest` 23 全 green。自己解析・phpcs clean。

**未対応（意図的なスコープ外）**: 引数の型チェック（`CallUserFuncRule` 相当のルール適用）、
複数候補 union callable、`[$obj,'m']` インスタンス callable、`[static::class,'m']`（generic class-string は
保守的に fallback）。

## 付録: 検証手順

- 実行時: `php runtime.php`（`SubCaller::test()` を実行）
- PHPStan: `\PHPStan\dumpType()` / `bin/phpstan analyse -l 9`
- Psalm: `/** @psalm-trace $x */`
- Phan: `'@phan-debug-var $a, $b, ...';`
- Mago: `mago analyze`（意図的な型不一致のエラーメッセージから実際の型を読む）

fixture: `scratchpad/fsc/{runtime,stan,psalm,phan,mago}.php`

バージョン: PHP 8.5.8 / PHPStan 2.2.x (dev) / Psalm (global) / Phan (global) / Mago 1.43.0
