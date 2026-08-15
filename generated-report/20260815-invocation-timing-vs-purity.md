# `@param-immediately-invoked-callable` / `@param-later-invoked-callable` と純粋性評価(調査メモ)

高階関数の side-effects 評価にあたり、呼び出しタイミングのタグ(`@param-immediately-invoked-callable` / `@param-later-invoked-callable`)を purity 判定に考慮すべきか、また現状すでに使える情報がないかを実測込みで調査した記録。

関連: [20260703-effect-system-design.md](20260703-effect-system-design.md), [20260703-impure-points.md](20260703-impure-points.md), [20260709-pseudo-constant-settings-purity.md](20260709-pseudo-constant-settings-purity.md)

---

## 1. 現状の実装:フラグは誰が消費しているか

`isImmediatelyInvokedCallable()` の実質的な消費点は `NodeScopeResolver` の 2 つだけ。

- `callCallbackImmediately(?ParameterReflection, ?Type, $calleeReflection): bool`
  - `Yes` → 即時呼び出しとみなす / `No` → みなさない / `Maybe` → 引数型が callable なら即時とみなすフォールバック。
- `shouldInvalidateCallbackExpressions(?ParameterReflection): bool`
  - `!$parameter->isImmediatelyInvokedCallable()->no()`。later と明示された場合のみ、呼び出し後のスコープ無効化を省略する。
  - コード内コメントの主旨: 引数として渡された callback は現在のスコープを脱出して呼ばれ得るので、その変更は外側スコープを無効化しなければならない。ただし later と明示された場合は「現在の関数から戻った後に実行される」ので、その変更はこの時点ではまだ見えない。

**purity 側(`src/Reflection/Callables/SimpleImpurePoint.php`, `src/Rules/Pure/FunctionPurityCheck.php`)はこのフラグを一切参照していない**(grep で確認)。

ただし `NodeScopeResolver` の引数処理経路では、`callCallbackImmediately()` が真のときにだけ closure/arrow function 本体の impure points を呼び出し側にマージしている(inline closure 経路・arrow function 経路・callable 値経路の 3 箇所とも同じ形)。つまり **フラグは間接的に purity へ効いている**。

## 2. 実測: 単独で使った場合

```php
/** @param-immediately-invoked-callable $cb @param callable():void $cb */
function callsNow(callable $cb): void { $cb(); }

/** @param-later-invoked-callable $cb @param callable():void $cb */
function callsLater(callable $cb): void {}

/** @phpstan-pure */
function pureWithImmediate(): void { callsNow(static function (): void { echo 'x'; }); }

/** @phpstan-pure */
function pureWithLater(): void { callsLater(static function (): void { echo 'x'; }); }
```

| ケース | 報告される impure point |
|---|---|
| immediately-invoked | `Impure call to function callsNow()` + **`Impure echo`**(closure 本体の副作用が伝播) |
| later-invoked | `Impure call to function callsLater()` のみ(**`Impure echo` は出ない**) |

→ later では closure 本体の impure point が引数処理経路で抑制されている。

## 3. 実測: `@pure-unless-callable-is-impure` と併用した場合

```php
/** @pure-unless-callable-is-impure $cb @param-later-invoked-callable $cb @param callable():int $cb */
function mapLater(callable $cb): int { return 1; }

/** @phpstan-pure */
function pureLaterImpure(): int { return mapLater(static function (): int { echo 'x'; return 1; }); }
```

この場合は **later でも `Impure echo` が報告される**。単独の later(§2)では抑制されていたのに、である。

### 差の原因: `Impure echo` の出所が 2 系統ある

1. **引数処理経路**(`NodeScopeResolver`、inline closure / arrow function / callable 値の 3 箇所): `callCallbackImmediately()` でゲート済み → later なら抑制される。
2. **callable verdict 経路**(`SimpleImpurePoint::createFromVariant` → `resolvePureUnlessCallableIsImpureVerdict`): 引数の `getCallableParametersAcceptors()` から `isPure()` を見て verdict を決める。ここは **immediately / later を一切考慮しない** → later でも impure と判定される。

§2 の later で echo が出なかったのは経路 1、§3 で出たのは経路 2 が働いたため。

## 4. 判断: purity 判定に呼び出しタイミングを持ち込むべきか

**結論: 持ち込むべきではない。現状(purity 側がフラグを見ない)が意味論的に正しい。**

- `@param-later-invoked-callable` が伝えるのは「**いつ**呼ばれるか(現在のスコープを抜けた後)」であって「**呼ばれないか**」ではない。後で呼ばれるなら副作用は必ず起きる。
- purity は「この呼び出しがプログラム全体に副作用を持つか」の問題であり、実行が遅延しても副作用は消えない。impure callback を渡せば、即時実行でも遅延実行でも最終的にプログラムは汚染される。
- このフラグが正当に効くのは**スコープ無効化**(`shouldInvalidateCallbackExpressions`)側。later なら「この関数から戻った時点ではまだローカル変数は書き換わっていない」ので現在のスコープの型を無効化しなくてよい。これは純粋性ではなく**タイミング(可視性)の問題**であり、フラグ本来の用途と合致する。

したがって `@pure-unless-callable-is-impure` の verdict に immediately/later を混ぜる変更は行わない。

## 5. 副次的に見つかった不整合(経路 1 の抑制は過剰かもしれない)

上記の論理を素直に適用すると、経路 1 が later で closure 本体の impure point を抑制しているのは**過剰**とも言える。遅延実行される impure closure の副作用を見逃していることになる。

```php
/** @param-later-invoked-callable $cb */
function register(callable $cb): void { /* 保存して後で呼ぶ */ }

/** @phpstan-pure */
function f(): void {
    register(static function (): void { echo 'x'; });  // ← echo は報告されない
}
```

ただし実害は限定的:

- `register()` 自体は impure と報告される(§2 の実測どおり)ので、`@phpstan-pure` 文脈では結局エラーになる。
- 真に問題化するのは `register()` が pure と宣言できてしまう場合だが、by-ref out パラメータも `@pure-unless-callable-is-impure` も無い限りそうはならない。

変更すると `array_map` 系をはじめ広範な既存挙動に影響するため、現時点で手を入れる価値は低い。記録に留める。

## 6. まとめ

| 論点 | 結論 |
|---|---|
| purity 判定に immediately/later を考慮すべきか | **No**。タイミング情報であって副作用の有無ではない |
| 現状すでに使える情報はあるか | **Yes(ただし purity 用途ではない)**。スコープ無効化の省略に正当に使われている。引数処理経路の impure point 伝播ゲートにも使われている |
| `@pure-unless-callable-is-impure` の verdict は | immediately/later を見ない。**この設計を維持する** |
| 残る不整合 | 経路 1 が later で closure 本体の impure point を抑制する点。理屈上は見逃しだが実害小、変更は影響大につき据え置き |
