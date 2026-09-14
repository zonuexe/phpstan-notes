# Adversarial Review: PHPStan Float Range Proposal

Date: 2026-08-22

## Conclusion

現状のまま phpstan/phpstan#6963 に投稿するのは推奨しない。`NaN-free interval + open/closed bounds` という中核案は有望だが、解決範囲の過大申告と prototype の未処理境界が、提案全体の信頼性を落としている。

## Blockers

### 1. 「5件を同時に解決」は事実ではない

Proposal の “answers ... at once” は撤回すべきである。

- [#15094](https://github.com/phpstan/phpstan/issues/15094): prototype は `is_nan()` narrowing の前提を作るだけで、PHP 8.5+ の warning rule は未実装。
- [#13859](https://github.com/phpstan/phpstan/issues/13859): 要求の中心である算術伝播を proposal 自身が follow-up 扱いにしている。
- [#9250](https://github.com/phpstan/phpstan/issues/9250): `finite` / `infinite` の入力可能な名前付き型は未実装。
- [#13504](https://github.com/phpstan/phpstan/issues/13504): `-INF` を parse できない問題は未解決。
- [#14394](https://github.com/phpstan/phpstan/issues/14394): prototype で `$f === NAN` を解析すると、常に false とは報告されず、truthy branch を `NAN` として扱う。

“answers” ではなく “provides a foundation for” または “unblocks” が正確である。

### 2. Syntax survey は提案根拠として使用不能

Survey は PHP の double を「連続・無限集合」と扱う一方、proposal は正しく「有限で successor がある」と述べており、根本前提が相互矛盾している。

ほかにも以下の問題がある。

- Rust の `Range::contains(NaN)` は「予測不能」ではなく、公式例で明確に `false` である。[Rust公式](https://doc.rust-lang.org/std/ops/struct.Range.html)
- C# の型は `ReadOnlySlice` ではなく `ReadOnlySpan<T>` で、`Range` は sequence index である。float interval の先例ではない。[Microsoft公式](https://learn.microsoft.com/en-us/dotnet/csharp/tutorials/ranges-indexes)
- PostgreSQL の `numrange` は `numeric` 用である。float8 range は user-defined range の別例である。[PostgreSQL公式](https://www.postgresql.org/docs/current/rangetypes.html)
- `[^22]` や `[^28]` など、記述を支持しない二次資料・無関係な repository が引用されている。

Survey は投稿に添えず、PHP、PHPStan、phpdoc-parser の一次資料だけに縮小するのが安全である。

## High-Severity Findings

### 3. Successor 未処理は「equality gap」だけではない

`FloatRangeType::fromInterval()` は `min === max` しか空区間判定しない。

実行すると、double が存在しない次の区間を `NeverType` ではなく `float<(0.0, 5.0E-324)>` として保持した。

```text
(0.0, 5.0E-324)
```

これは containment だけでなく、intersection、到達不能判定、union canonicalization にも影響する。successor-aware canonicalization を実装するか、初期 proposal では明示的な既知制約として扱う必要がある。

### 4. `toPhpDocNode()` が型の意味を失う

`FloatRangeType::toPhpDocNode()` は open bounds を closed envelope へ黙って widen する。

```text
float<(-inf, inf)> -> float<min, max>
```

これにより finite 型が ±INF を再び含む。PHPStan には PHPDoc node の round-trip test もあるため、単なる表示上の問題ではない。

「`describe()` は非 parseable な open syntax、`toPhpDocNode()` は別の closed type」という二重仕様は、公開前に解消すべきである。`mixed~(...)` の debug 表示は PHPDoc 構文の precedent としては弱い。

## Medium-Severity Findings

### 5. 64-bit固定は許容可能だが、暗黙のhost依存にはすべきでない

`ConstantFloatType::castToInt()` は 2^63 / 2^64 を hard-code している。この64-bit前提自体は、初期実装の blocker ではない。

[#11711](https://github.com/phpstan/phpstan/issues/11711) では32-bit PHPは極めて稀で、64-bit利用者を煩わせずに対応する必要性は低いという maintainer の見解により feature request が close された。[#14948](https://github.com/phpstan/phpstan/issues/14948) も完全な32-bit modeling を求めるものではなく、現状の不整合な「部分対応」を解消し、64-bitを既定または設定可能な意味論にする方向を提示している。

したがって、2^63 / 2^64 を `PHP_INT_SIZE` から導出して解析ホスト依存にする修正は推奨しない。それは #14948 が問題視する不整合を再生産する。proposal では、初期実装が決定的な64-bit integer semantics を採用し、32-bit target/runtime modeling は #14948 の決着まで非目標であると明記すべきである。定数は散在させず、将来の幅ポリシー変更に追随できる一箇所へ集約するのが望ましい。

### 6. Open bound から int への変換が不正確

`(0.0, 1.0)` の `toInteger()` は `int<0, 1>` を返すが、`1` には到達しない。sound な over-approximation ではあるものの、proposal の「truncation」は exact propagation に読める。

Inclusivity を反映するか、意図的な widening と明記すべきである。

### 7. Green という説明には重要な但し書きがある

検証結果は以下のとおり。

- `make tests`: 21,440 tests、97,132 assertions、65 skipped、成功。
- `make phpstan`: 2,430 files、diagnosticなしで完走。
- 実差分: 23 files、+1451/-62。記載の約+1100/-40は古い。
- `make phpstan` は、`FloatRangeType` の deprecated/error-prone な `instanceof IntersectionType` を新しく baseline に追加した状態で green。

したがって「full suite green」は正しい一方、「新規 baseline suppression なしで clean」ではない。これは投稿前に直した方がよい。

### 8. RFCの表現を修正すべき

Proposal にある `(bool)`、`(array)` は implicit coercion ではなく explicit casts である。[PHP 8.5 RFC](https://wiki.php.net/rfc/warnings-php-8-5)どおり、“implicit or explicit coercion” と書くのが正確である。

## Recommended Scope for the Proposal

提案を次の範囲まで狭めると強くなる。

> A `FloatRangeType` represents a convex set of non-NaN PHP doubles with independently open or closed bounds. This prototype covers closed PHPDoc input, set operations, comparison narrowing, and `is_nan`/`is_finite`/`is_infinite`. It does not yet implement PHP 8.5 warning rules, arithmetic propagation, parseable open-bound syntax, or all NaN equality cases.

その上で、次の順序を推奨する。

1. Cross-issue は「解決」ではなく「将来実装の基礎」と位置づける。
2. Syntax survey は外す。
3. Canonical syntax と round-trip を先に決める。
4. 64-bit integer semantics と32-bit非対応を明記し、adjacent-float と open-bound cast の tests を追加する。
5. PHP 8.5 warning 回避の既存型修正は別PRへ分離する。

## Verification Performed

- 英語版 `20260822-issue-draft-float-range-proposal.md` と `20260822-range-syntax-survey.md` をレビュー。
- PHPStan issues #15094、#6963、#13859、#9250、#13504、#14394、#14948、#11711 と PHP 8.5 RFC を照合。
- Local prototype branch `float-range-type` (`83757944d39703c2a19df9c208b275bbefde3aa5`) を確認。
- Targeted unit/NSRT tests、full test suite、`make phpstan` を実行。
- Adjacent-double open interval、PHPDoc変換、open-bound integer cast、`$f === NAN` を実行確認。

## Post-remediation Re-review

Target: `float-range-type` at `2a4fbd12ca7eba17244f743454886573155bad39` (three commits from `79a31bb78f2a0e24cbd63d4bca16736a86d2c188`).

### Verdict

最初のレビューで挙げた主要問題の多くは修正されている。特に adjacent-double canonicalization、parseable な open-bound syntax と PHPDoc round-trip、64-bit integer semantics の明文化は確認できた。

ただし「作業は完了し、残るのは投稿・push判断だけ」という判定には同意しない。以下の仕様欠落と設計上の review item が残るため、現commitのまま #6963 へ投稿して実装完成を主張するのは早い。

### Standards Axis

#### S1. `FloatRangeType` の具体型分岐が広範に散っている

Repository guidance の “Type system: never use `instanceof` to check types” および “add methods to the `Type` interface instead of one-offing conditions” に対し、新規の `instanceof FloatRangeType` が `TypeCombinator`、`UnionType`、`UnionTypeHelper`、`FloatType`、`ConstantFloatType`、`InitializerExprTypeResolver` に分散している。

`IntegerRangeType` という既存 precedent はあるため、直ちに runtime bug とは言えない。一方で、新しい refined float representation を追加するたびに同じ dispatch sites を更新する構造であり、maintainer review では明示的な設計承認が必要である。少なくとも proposal の「baseline は既存 precedent と同じ」という説明だけでは、このより広い concrete-type dispatch 全体への回答になっていない。

### Spec Axis

#### P1. Pure `int` に対する float predicates が仕様どおり narrow されない

Proposal は `is_finite()` について “ints count as finite” としている。しかし `FloatPredicateFunctionTypeSpecifyingExtension::specifyTypes()` は、argument type の `isFloat()` が `no` なら generic checks に任せるとして早期 return する。実際には generic path はこの事実を処理しない。

実行確認では次の3箇所すべてで期待した `never` に対して `int` が残った。

```php
function f(int $i): void
{
    if (!is_finite($i)) { /* actual: int; expected: never */ }
    if (is_nan($i)) { /* actual: int; expected: never */ }
    if (is_infinite($i)) { /* actual: int; expected: never */ }
}
```

既存 NSRT は `int|float` だけを検証しているため、この pure-int path を通していない。少なくとも `is_finite(int)` の falsy side と `is_nan(int)` / `is_infinite(int)` の truthy side を追加し、extension の対象判定を修正する必要がある。

#### P2. Open-bound range を比較右辺に置くと strict narrowing が1 ULP広い

`getSmallerType()` と `getGreaterType()` は written bound を使っており、canonical bound を使っていない。

- RHS が `float<[a, b)>` のとき、`x < RHS` の候補に `x = nextDown(b)` が残る。しかし RHS の最大値も `nextDown(b)` なので、この値で strict comparison が真になることはない。
- RHS が `float<(a, b]>` のとき、`x > RHS` の候補に `x = nextUp(a)` が残る。同様に真にはならない。

これは impossible value を残す safe over-approximation であり、unsoundness ではない。ただし proposal が emptiness、containment、equality、merging、int truncation に限定して canonical bounds を使うならその限定を明記すべきであり、comparison narrowing まで正確と主張するなら canonical min/max に基づいて修正すべきである。

#### P3. 新規 baseline suppression は残っている

`FloatRangeType::isSubTypeOf()` の `instanceof IntersectionType` に対する `phpstanApi.instanceofType` baseline entry は残っている。`IntegerRangeType` と同じ構造で、一般の `CompoundType` 委譲が再帰するという説明は合理的だが、suppression が解消されたわけではない。これは functional blocker ではなく maintainer acceptance risk として proposal に残すのが正確である。

### Re-verification

- `ConstantFloatTypeTest`、`IntegerRangeTypeTest`、`FloatRangeTypeTest`、`TypeToPhpDocNodeTest`: 305 tests、503 assertions、成功。
- `make phpstan`: 2,432 files、error なしで完走。
- Direct type-level probe: half-open RHS の canonical endpoint が strict comparison candidate に残ることを確認。
- Temporary NSRT probe: pure-int predicate の3 unreachable branches がすべて `int` のままであることを確認。
- Worktree は clean、target HEAD は `2a4fbd12ca7eba17244f743454886573155bad39`。

## Second Post-remediation Re-review

Target: `float-range-type` at `ab64ea1ec63dfe359c6846ce31a689f6ca05a8a5`.

### Verdict

P1 と P2 の直接修正は確認できた。

- Pure `int` に対する `is_finite()` / `is_nan()` / `is_infinite()` は真偽両側で正しく narrow される。
- Strict / non-strict の4 comparison helpers は canonical min/max を使うようになり、half-open range を右辺に置いた1 ULPの過大近似は解消した。
- S1 の concrete `instanceof FloatRangeType` dispatch と P3 の baseline は、解消済みとはせず proposal の design question / maintainer acceptance risk として明示された。

ただし、P1 の gate 修正に partial-union の soundness gap が残っているため、依然として「投稿・push判断だけが残る」状態ではない。

### Standards Axis

S1 の判定は変わらない。設計上の問題を proposal に開示したことは適切だが、repository guidance への準拠を実装したことにはならない。#6963 で設計判断を求めることは可能だが、PR review では request-changes になり得る。

### Spec Axis

#### P4. Numeric-string を含む union が predicates で unsound に narrow される

更新後の gate は次の条件だけで extension をスキップする。

```php
$argumentType->isFloat()->no() && $argumentType->isInteger()->no()
```

Pure `numeric-string` は両方 `no` なのでスキップされるが、`numeric-string|float` や `numeric-string|int` では一方が `maybe` になり、extension が適用される。その後、float/int だけからなる asserted type と交差するため、numeric-string member が消える。

実機で次を確認した。

- `is_finite("1") === true` だが、`float|numeric-string` の truthy branch は `float<(-inf, inf)>` になり、reachable な `"1"` を失う。
- `is_infinite("1e500") === true` だが、同じ union の truthy branch は `-INF|INF` になり、reachable な `"1e500"` を失う。

PHPStan はこの呼び出しに別途 `argument.type` を報告するが、runtime-reachable value を型から除外してよい理由にはならない。また proposal 自身が “numeric strings are left alone since `"1e500"` coerces to `INF`” と明記しているため、実装と仕様が直接矛盾する。

安全な最小方針は、argument type 全体が `int|float` の subtype であると確認できる場合だけ extension を適用し、numeric-string などを一部でも含む union は丸ごと generic path に残すことである。これによりその union の float member も narrow されなくなるが、finite / infinite numeric-string を区別できない現状では sound な選択になる。

### Re-verification

- `ConstantFloatTypeTest`、`IntegerRangeTypeTest`、`FloatRangeTypeTest`、`TypeToPhpDocNodeTest`: 311 tests、509 assertions、成功。
- `NodeScopeResolverTest` の `float-range-types.php`: 成功。
- `make phpstan`: 2,432 files、error なしで完走。
- Temporary NSRT probe: P1/P2 の期待型は成功。numeric-string union の truthy branch から reachable string が消えることを確認。
- PHP 8.5.9 runtime: `is_finite("1")` は `true`、`is_infinite("1e500")` は `true`。
- Full suite 21,509 tests と phpcs は今回独立には再実行していない。

## Third Post-remediation Re-review

Frozen target: `96631c0afb67ca8246943bac8ee4e1298271f3e7` (`backup/float-range-type-pre-split`).

Review中に共有worktreeで branch split が始まり、`float-nan` / `float-range-type` のrefsが移動したため、報告対象commitを一時detached worktreeへ固定して検証した。

### Verdict

P4 は解消した。仕様軸には新たな blocker を確認できず、`96631c0af` は proposal に記載されたpredicate semanticsと一致する。

S1 の concrete `instanceof FloatRangeType` dispatch と baseline risk は未解消だが、proposalが明示的なdesign questionとして提示している。これはPRのstandards reviewではrequest-changesになり得る一方、#6963へ設計判断を求めるissue commentを投稿すること自体のcorrectness blockerではない。

### Standards Axis

前回判定から変更なし。`FloatRangeType`の具体型dispatchはrepository guidanceのType polymorphism方針と衝突する。Proposalで開示したことは適切だが、standards準拠を実装したことにはならない。

### Spec Axis

新規findingなし。

- `is_finite()` / `is_infinite()` は、argument type全体が`int|float`のsubtypeと確定した場合だけ適用される。
- `numeric-string|float`や`mixed`は両側とも変更されず、reachableなcoerced valueを失わない。
- `is_nan()`は任意型へ適用され、truthy sideを`NAN`、falsy sideから`NAN`だけを除く。

PHP 8.5.9でnull、bool、int、finite numeric string、overflow numeric string、`"NAN"` / `"INF"`、array、resource、object、Stringable、NANを確認した。`is_nan()`がtrueになったのはNANだけで、nonnumeric string / array / resource / object / StringableはTypeError、その他はfalseだった。したがって、関数がreturnする経路でtruthy sideをNANへ限定するのはsoundである。

### Re-verification

- Frozen `96631c0af` sourceを優先するbootstrapで対象unit tests: 311 tests、509 assertions、成功。
- `NodeScopeResolverTest`の`float-range-types.php`: 成功。
- Runtime coercion matrix: `is_nan()`のany-type方針と一致。
- Full suite 21,509 tests、`make phpstan`、phpcsは他agentの成功報告を確認したが、このroundでは独立再実行していない。
- Review後に進んだsplit後のbranch headsは、この判定の対象外。
