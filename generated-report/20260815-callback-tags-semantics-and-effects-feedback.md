# callback 系タグの意味論整理と effect envelope へのフィードバック

`@param-immediately-invoked-callable` / `@param-later-invoked-callable` / `@pure-unless-callable-is-impure` の
3 タグの関係を実測で整理し、その知見を effect envelope ブランチ(stage 13 予約項目)へどう還元できるかを検討した記録。

関連: [20260815-invocation-timing-vs-purity.md](20260815-invocation-timing-vs-purity.md),
[20260812-effect-envelope-phpstan-port-design.md](20260812-effect-envelope-phpstan-port-design.md),
[20260703-effect-system-design.md](20260703-effect-system-design.md)

---

## 1. 3 タグは直交する 2 軸

| タグ | 問い | 何の性質か | purity verdict への影響 |
|---|---|---|---|
| `@param-immediately-invoked-callable` / `-later-` | **いつ**呼ばれるか(この呼び出し中か、戻った後か) | callee の**呼び出しタイミング** | **無し** |
| `@pure-unless-callable-is-impure` | callee 自身の副作用は callback **由来だけ**か | callee の**副作用の出どころ** | **有り** |

### 実測(level 10 + bleedingEdge、`$cb` は `callable():int`)

4 パターンの関数に pure / impure な callback を渡した結果:

| 関数 | pure callback を渡す | impure callback を渡す |
|---|---|---|
| A: タグ無し | Possibly impure | echo + Possibly impure |
| B: immediately のみ | **Possibly impure(救済なし)** | echo + Possibly impure |
| C: pure-unless のみ | **報告なし(pure)** | echo + **Impure(certain)** |
| D: 両方 | **報告なし(pure)** | echo + **Impure(certain)** |

- **B と C の差が両タグの役割の差**。`@param-immediately-invoked-callable` 単独では purity を救済しない。
- **C と D は同一** = purity verdict の観点では immediately タグは寄与しない。

### なぜ B が救済されないのが正しいか

`@param-immediately-invoked-callable` は「callback は今呼ばれる」としか言っておらず、
**callee が他に副作用を持たない保証はしていない**。

```php
/** @param-immediately-invoked-callable $cb */
function logAndRun(callable $cb): void { echo 'log'; $cb(); }  // 即時呼び出しだが自前で echo する
```

これを pure 扱いしたら誤り。purity 判定に必要なのは「私の副作用は $cb だけ」という
`@pure-unless-callable-is-impure` の宣言のほうであり、タイミング情報ではない。

## 2. 実装上のオーバーラップ: impure point の出所が 2 系統

callback 本体の副作用(`Impure echo`)が呼び出し側に見える経路は 2 つある。

1. **引数処理経路**(`NodeScopeResolver`、inline closure / arrow function / callable 値の 3 箇所):
   `callCallbackImmediately()` がゲート。`Maybe`(タグ無し)は「引数型が callable なら即時とみなす」フォールバックなので、
   **実質デフォルトで有効**。明示 later のときだけ止まる。
2. **callable verdict 経路**(`SimpleImpurePoint::createFromVariant` → `resolvePureUnlessCallableIsImpureVerdict`):
   `@pure-unless-callable-is-impure` がある時だけ効く。**タイミングは一切考慮しない**。

上表で A/B に echo が出るのは経路 1、C/D では両経路が同じ echo を報告しようとする(結果は重複排除)。
ただし **verdict の確定度(Possibly か certain か)を決めるのは経路 2 だけ**。C で `Impure`(certain)、
A/B で `Possibly impure` になる差がそれ。

## 3. `@param-later-invoked-callable` と `@pure-unless-callable-is-impure` は矛盾するか

**原理的には「callback を保持する限り矛盾する」。ただし PHPStan は既に間接的に検出している。**

later は「後で呼ぶ」= callback を callee の外(グローバル・プロパティ・キュー等)に**保持する**ことを含意し、
保持そのものが副作用。よって `@pure-unless-callable-is-impure`(私の副作用は callback 由来だけ)と両立しない。

実測:

```php
/** @param-later-invoked-callable $cb @pure-unless-callable-is-impure $cb */
function storeLater(callable $cb): void { $GLOBALS['q'][] = $cb; }
// → Impure access to superglobal variable in pure function storeLater()
```

**宣言側のチェックが自前副作用を検出して破綻させる**。専用の「両タグ併用禁止ルール」は不要で、
既存の purity チェックが整合性を実質的に強制している。

一方 `function discardLater(callable $cb): void {}`(本当に捨てる)は自前副作用が無いので両立する。
インターフェース実装や後方互換スタブで現実にあり、意味論的にも矛盾しない。

### 調査中の落とし穴(記録)

当初 `discardLater` に pure callback を渡しても `Impure` と報告され「later が原因か」と見えたが、
戻り値を `int` に変えると later の有無に関わらず両方 pure になった。原因は later ではなく
**void 戻り値ヒューリスティック**(`createFromVariant` の
`$certain = $certain || $variant->getReturnType()->isVoid()->yes()`)だった。
purity 実験では void 戻り値が別ルールで impure 判定を誘発する点に注意。

### 残る穴(実測で確認)

「保持は副作用」の検出は PHPStan が副作用と認識できる書き込みに依存する。保持経路を実測した結果:

| 保持経路 | 検出 | 備考 |
|---|---|---|
| superglobal 書き込み(`$GLOBALS['q'][] = $cb`) | **される** | `Impure access to superglobal variable` |
| static 変数(`static $q = []; $q[] = $cb;`) | **される** | `Impure static variable` |
| **by-ref 配列引数へ push**(`function f(callable $cb, array &$sink) { $sink[] = $cb; }`) | **されない** | **穴** |

by-ref パラメータ経由の保持は「呼び出し側の変数を書くだけ」で purity 違反にならないため、
`@param-later-invoked-callable` + `@pure-unless-callable-is-impure` の併用が**破綻せず通ってしまう**。

意味論的にはこれは不健全とまでは言い切れない(callee は自分のスコープ外に状態を作っておらず、
「呼び出し側が渡した容器に入れた」だけ)。しかし「後で呼ばれる impure callback が
どこかに残る」という利用者の直観からは乖離する。envelope 側では §4 F3 のとおり
`mutate`(引数の内容変更)として素直に表現できるため、この乖離は解消できる。

## 4. effect envelope ブランチへのフィードバック

移植設計メモ(20260812)は `@pure-unless-callable-is-impure` を
「案C の最小スライス(多相)であり、envelope(案B)とは直交・共存可能」と位置づけ、
stage 13 に **「effect-parametric 宣言(opaque callable)」「遅延起動 extension 同梱判断」** を予約している。
本調査はまさにその予約分野への実測入力になる。還元できる点は以下。

### F1. effect 多相の器は「タイミング」ではなく「出どころ」に置く

envelope に callback 由来の効果を組み込む際、`@param-immediately-invoked-callable` を
根拠にしてはならない。§1 の B/C 差が示すとおり、タイミングタグは「callee が他に副作用を持たない」ことを
何も保証しない。effect-parametric 宣言は **`@pure-unless-callable-is-impure` と同じ軸**
(「この関数の効果は引数 $cb の効果に等しい/を上界とする」)で設計する。

envelope 語彙での自然な表現は、ラベル集合の**変数化**:

```
@phpstan-impure io.fs.read, effects-of($cb)      // 仮記法: $cb の envelope を合成
```

これは v1 の「固定ラベル集合 = 上界」から「**ラベル集合に引数由来の項を許す**」への拡張であり、
bound 検査(4.3)は `union(固定ラベル, envelope($cb))` を上界として扱えばよい。

### F2. 呼び出しタイミングは envelope ではなく scoped forgetting 側の情報

later 情報が正当に効くのは「この関数から戻った時点で外側スコープの型を無効化しなくてよい」という
**可視性のタイミング**であって、効果の有無ではない。
移植メモ stage 13 の `scoped forgetting / discardability keys` は可視性・破棄可能性の話なので、
**later タグはそちらの入力として扱う**のが筋が通る。envelope の上界計算には混ぜない。

### F3. 「保持は効果」を語彙で明示できる

§3 の矛盾は、v1 語彙では `global.write` や `mutate` として現れる(実測では superglobal 書き込みが検出された)。
つまり **effect envelope は「callback を保持する高階関数」を素直に表現できる**:

```
@phpstan-impure mutate          // callback をプロパティに保持するイベント登録
@phpstan-impure global.write    // グローバルキューに積む
```

現行 purity の boolean モデルでは「保持している = 不純」としか言えず、
`@pure-unless-callable-is-impure` との併用が単に破綻するだけだったが、
envelope なら **「保持という効果 `mutate` を持ち、加えて $cb の効果を持つ」** と
両方を同時に宣言できる。これは envelope 導入の実利を示す具体例として提案文書に使える。

### F4. 二重経路の整理は envelope 移行時の設計論点

§2 の「impure point の出所が 2 系統」は envelope 移植でも再現する。
ImpurePoint → effect ラベル写像(移植メモ 4.4)を行う際、
引数処理経路から流れ込む callback 本体のラベルと、callable verdict 経路から来るラベルの
**二重計上・不整合**が起こり得る。v1 のうちに「callback 由来ラベルの単一の合流点」を決めておくとよい。
候補は verdict 経路(呼び出しの確定度も決めている側)に寄せること。

### F5. 実験手法の注意(移植テスト作成時)

void 戻り値ヒューリスティック(§3 の落とし穴)は envelope のテストでも誤診の元になる。
effect 関連のフィクスチャは **非 void 戻り値**で書くか、void の場合は
`pureFunction.void` / `function.resultUnused` を明示的に期待値に含める。

## 5. アクションアイテム

1. stage 13 の「effect-parametric 宣言(opaque callable)」設計時に **F1 の軸選択**(出どころであってタイミングでない)を前提として明記する。
2. later タグは **F2** に従い scoped forgetting / discardability の入力として位置づけ、envelope 計算からは外す。
3. **F3** の「保持は `mutate` / `global.write`」例を、envelope 導入の動機づけとして提案文書(issue draft)に追加検討。
4. **F4** の二重経路合流点を v1 実装中に決める(verdict 経路推奨)。
5. ~~§3 の保持経路の穴を実測~~ → 完了。superglobal / static は検出、**by-ref 配列 push は検出されない**。
   envelope では `mutate` として表現できるので、この差を提案文書の動機づけに使える。
