# Analysis: phpstan#6732 / arnaud-lb 提案 / phpstan-src#6332

Date: 2026-09-03
Scope:
- Issue [phpstan/phpstan#6732](https://github.com/phpstan/phpstan/issues/6732) "Bidirectional type narrowing for generic types"
- 提案コメント [#6732 (comment-1062029088)](https://github.com/phpstan/phpstan/issues/6732#issuecomment-1062029088) by arnaud-lb
- PR [phpstan/phpstan-src#6332](https://github.com/phpstan/phpstan-src/pull/6332) "Resolve inferred template arguments from the body's usages (#6732 prototype)" by ondrejmirtes (Claude Fable 5 と共著、2026-09-01〜02、base `2.3.x`、OPEN)

## Conclusion

PR #6332 は arnaud-lb 提案の **rule 4（送信による解決）を実装し、rule 3 と rule 5 を意図的に別物へ置き換えた** prototype である。設計の中核（body-local な二段走査 + 未解決マーカー）は妥当で、14 件の false positive issue を実際に閉じている。一方で以下 3 点は投稿・マージ前に決着が必要である。

1. **rule 5 の置き換えにより真陽性を失っている。** メソッド呼び出しは「解決」ではなく「下界の union」として扱われ、`$c->add(1); $c->add('a')` はエラーにならない。bug-6993 の検出が消えているのは、issue の主題（型ホールを塞ぐ）と逆向きの後退である。
2. **PR 本文の性能主張と CI 実測が食い違う。** 本文は「ゼロと区別できない（−0.35 % / −0.97 %）」とするが、phpbench の regression gate は base と比較して 8 variant 中 6 つで **+4.5 %〜+18.5 %** の悪化を示し、`bug-8146b.php` は base で通っていたゲートを新たに落としている。
3. **downstream の integration/extension test が 6 本落ちている。** Shopware・Neos・Larastan・phpstan-doctrine・Rector で、`*NEVER*` 化と literal 保持に起因する新規エラーが出ている。PR 本文が Slevomat について挙げた `QueryResultSet<*NEVER*>` 問題は、その 1 プロジェクト固有ではなく一般的な形である。

---

## 1. Issue #6732 が主張していること

### 1.1 元の報告（00dani, 2022-03-04）

`$this->attribute = $value` の代入は `$this->attribute` の型を `$value` の型へ narrowing する。通常はこれで良いが、`$value` の型が generic 引数の面で **より広い** 場合、代入が declared type を広げてしまう。

```php
/** @var Collection<int, int> */    public iterable $ints;
/** @var Collection<int, string> */ public iterable $strings;

public function __construct() {
    $array = new ArrayCollection();      // ArrayCollection<*NEVER*, *NEVER*>
    $this->ints = $array;                // ArrayCollection<*NEVER*, *NEVER*> になる
    $this->strings = $array;             // 同上 — エラーにならない
}
```

同一オブジェクトが `Collection<int,int>` と `Collection<int,string>` の両方として alias される。これが型ホールである。報告者の提案は「代入時に双方向 unify し、`$this->ints = $array` の時点で `$array` を `ArrayCollection<int,int>` へ確定させる。すると 2 番目の代入がエラーになる」。

報告者自身が「珍しいケースであり、Psalm にも同じ穴がある」と認めている。

### 1.2 議論の変遷（重要）

- **arnaud-lb** が別例と、後述の 5 ルール案を提示（thumbs-up 9）。
- **marcospassos** が「実務上は argument narrowing の false positive のほうが多い」と主張し、`WeakMap` の例を挙げた。`__construct(\WeakMap $map = new \WeakMap())` が `WeakMap<Key,int>|WeakMap<object,mixed>` になって通らない、`$map ?? new \WeakMap()` も同様。
- **muglug**（Psalm）は [vimeo/psalm#8482](https://github.com/vimeo/psalm/issues/8482) を立て、「型変数 `#1` を作り制約 `#1 ≤ int`, `#1 ≤ string` を集めて末尾で充足不能を報告する」という解法を書いたうえで「実装が大変で、問題領域が稀なので割に合わない」と述べた。
- **ondrejmirtes**（2022-09-21）が方向を決定づけた。

  > because the type hole is a pretty small edge-case
  >
  > I consider the implementation suggestion above with `Collection<unresolved>` etc. to be a huge deal, because once implemented, it will allow us to get rid of generic types generalization (where `1` becomes an `int`).

  つまり **本命は型ホールではなく generalization（`new Foo(1)` を `Foo<int>` に丸める処理）の廃止** である。PR #6332 の内容はこの発言の直系であり、issue タイトルが示す範囲より遥かに広い。
- さらに [phpstan-src#2110](https://github.com/phpstan/phpstan-src/pull/2110)「NeverType: accept nothing」（2022-12-15 マージ済み）を「この後にできるようになる」と紐づけている。#6332 の "nothing inferred → never" はこの伏線の回収である。
- 2024 年の phpstan-bot コメントは、`Ds\Set<'a'>` や `Collection<mixed>` として **むしろ悪化した** 差分を報告しており、2.0.x 時点でこの領域が不安定だったことを示す。

**評価**: issue #6732 の本文だけを読むと「稀な edge case」だが、maintainer の意図は「generalization 廃止という大改修の入り口」である。PR の規模（88 files, +4512/−363）はこの意図と整合する。

---

## 2. arnaud-lb 提案（comment-1062029088）の分析

提案は 5 ルール。rule 1〜3 を「現行の挙動」、4〜5 を追加分と説明している。

| # | 提案内容 | 実態 |
|---|---|---|
| 1 | generic 型に「未解決の型引数」という概念を持たせる。`*NEVER*` でもよいし専用の型でもよい | 概念導入 |
| 2 | 推論する引数がなく解決できなかった型引数を unresolved にする | 概念導入 |
| 3 | generic 型は unresolved をどの位置でも受け入れる（`Foo<int>` は `Foo<unresolved>` を受け入れる） | **acceptance の緩和** |
| 4 | 変数を「送る」と、受け手の型で **すべての** 型引数が解決される。プロパティ代入・引数渡し・変数代入・closure の use・arrow fn の暗黙キャプチャを含む | **追加** |
| 5 | メソッド呼び出しは receiver の **すべての** 型引数を解決する | **追加** |

rule 4 と 5 に共通する強調は "*all* type parameters"。片方だけ解決すると別型の alias を作れてしまう、という理由づけである。

### 2.1 提案の理論的な位置づけ

muglug が Psalm 側で書いた「制約を集めて末尾で充足性を判定する」（Hack 方式）とは異なる。arnaud-lb 案は **最初の観測点で即座に確定させ、以降は通常の型検査に委ねる** 方式である。制約ソルバではない。

- 利点: 実装が単相で済む。既存の acceptance 判定をそのまま使えるので、エラーメッセージが素直に出る。
- 欠点: 「最初の送信」が確定点になるので、**文の順序に意味論が依存する**。`$this->ints = $c; $this->strings = $c;` はエラー、`$this->strings = $c; $this->ints = $c;` もエラーだが、報告されるメッセージは逆になる。制約ソルバなら「矛盾する 2 つの制約」として両方を指せる。

### 2.2 提案自体の弱点

- rule 3（`Foo<int>` が `Foo<unresolved>` を受け入れる）と rule 4（送信が解決する）は、**同一の送信点で両方が発火すると相互に無意味**になる。受け入れてから解決するのか、解決してから受け入れ判定するのか、順序が定義されていない。#6332 はここを「マーカーは rules に到達しない」で回避しており、事実上 rule 3 を捨てている（§3.3）。
- rule 5 の "resolve all type parameters" は、`$b->add(1)` の 1 回だけで `Bag<1>` に固定することを意味する。これは `$b->add(1); $b->add(2);` を即エラーにする。実用上ほぼ確実に受け入れられない厳しさで、arnaud-lb 自身が例に `$ints->add("a"); // error` しか書いていないのは、`add(2)` のケースを検討していないためと思われる。
- closure/arrow fn のキャプチャを送信とみなす rule 4 の後半は、キャプチャ時点では「何に送られたか」が分からない。#6332 では closure の本体を親 frame の下で歩くことで解決している（§3.4）が、提案には手順がない。

---

## 3. PR #6332 の実装分析

### 3.1 構成

feature toggle `featureToggles.unresolvedTemplateArguments`（`conf/config.neon` で false、`conf/bleedingEdge.neon` で true）。88 files。中核は 4 つ。

**(a) `src/Type/Generic/UnresolvedTemplateArgumentType.php`（新規 851 行）**

`CompoundType` 実装のマーカー。`(site: Expr, templateType: TemplateType, initialType: ?Type)` を持つ。

- 型に関するあらゆる質問は `getDelegate()`（= `initialType ?? template->getDefault() ?? template->getBound()`）へ委譲する。
- **`equals()` だけが不透明**。マーカーは「同じ site かつ同じ template 名のマーカー」とだけ等しく、delegate とは等しくない。invariant な generic 位置は引数を `equals()` で比べるため、`Foo<int>` は `Foo<unresolved(1)>` を受け入れない。union も両者を潰さない。これがマーカーが観測完了まで生き残る仕組みである。
- `unwrapBare()`: オブジェクトの引数としてではなく単体で現れたマーカー（`Foo<T>::get(): T` の戻り値など）は「派生値」であり site を制約しないので delegate に剥がす。

**(b) `src/Analyser/Generics/TemplateArgumentFrame.php`（新規 492 行）**

1 つの function-like body に 1 つ。scope 共有の `ExpressionResultStorageStack` に載る（サービスではない）。

- `sites`: site の `spl_object_id` → `[site, statement index]`。statement index は開始トークン位置の二分探索で求める。
- `observations`: `"<spl_object_id(site)>#<templateName>"` → `{marker, initial, sends[], lowerBounds[]}`。
- `finishObserving()` で全 key を解決する。解決規則（`resolveObservation`）:
  1. covariant テンプレートで initial が既知なら **clamp しない**（`Box<1>` のまま）。
  2. contravariant な送信先は下界として蓄積。
  3. covariant な送信先は上界。initial が無いときのみ fallback として採用。
  4. invariant な送信先は **inferred を受け入れる最初の 1 つが勝つ**。以降の非互換な送信は 2 パス目で通常のルールがエラーにする。
  5. どの送信も勝たなければ `union(initial, ...lowerBounds)`。
  6. 何も無ければ default → bound（`mixed` でない場合）→ **`never`**。
- `resolve()` は親 frame を辿る。

**(c) `src/Analyser/Generics/TemplateArgumentObserver.php`（新規 193 行）**

型どうしの純関数的な照合。`observeSend($frame, $declared, $actual)` は union を分解し、`getAncestorWithClassName()` で `@extends`/`@implements` を通した引数対応を取り、call-site variance map（`Collection<covariant int>` 等）と declared variance を突き合わせて `recordSend()` する。iterable の key/value へも再帰する。`observeArgument()` は send に加えて `observeLowerBound()`（`add(T $x)` の `T` に実引数を下界として置く）を行う。callable パラメータは contravariant 位置として下界から除外している。

**(d) `NodeScopeResolver::processBodyStmtNodesTwoPass()`**

- **pass 1（観測）**: `RecordingNodeCallback` に `(Node, Scope)` を記録し、rule への emission は行わない。外側の gatherer frame は一時停止する。各文の直前に `StatementListWalkState` の clone と記録オフセットを `entries[$i]` に保存する。
- サイトが 1 つも作られなければ（`src` 全体で 14,939 body 中 14,834 = 99.3 %、PR 計測）記録をそのまま replay して終わり。
- **pass 2**: 最初の site を含む文より前は replay。そこから先は、`getDifferingVariableRoots()` で「pass 1 の記録 scope と現在の scope で状態が違う変数の根」を求め、
  - site を持つ文 / それらの変数に言及する文 / 非変数キーの差分がある文 / label を含む body → **再走査**
  - それ以外 → replay + `withRecordedStatementDelta()` で記録された差分だけを現在の scope に載せる
  - 差分が空かつ以降に site が無ければ **early exit** して残り全部を replay
- 計測（PR 本文、full `src`）: 105/14,939 body が site を持つ、595 文 replay vs 307 文 re-walk、21 回 early exit。

**(e) producer 側**

- `NewHandler`: toggle 下では `generalizeInferredTemplateType` を通さない。コンストラクタが何も言わない引数は `unresolvedArgumentList()` でマーカー化。親コンストラクタ経由の synthetic `new` は `SYNTHETIC_SITE_ATTRIBUTE` を付け、`rekeyParentTemplateArgument()` で本物の site へ付け替える。
- `ResolvedFunctionVariantWithOriginal::getReturnTypeWithUnresolvedTemplateArguments()`: 呼び出しの戻り値に含まれる function-level template（`@return Collection<TKey, V>`）も同様にマーカー化。`getReturnType()` は従来どおり generalize するので、**extension は影響を受けない**。
- 合成呼び出し（`offsetGet`、`__toString`）は `SYNTHETIC_SITE_ATTRIBUTE` で site 登録から外す。
- `ClosureTypeResolver` のキャッシュキーに frame の解決状態サフィックスを追加。

### 3.2 arnaud-lb 提案とのマッピング

| rule | #6332 の実装 | 評価 |
|---|---|---|
| 1. unresolved の概念 | `UnresolvedTemplateArgumentType`。ただし **body 内の観測パスの間だけ存在し、rule には到達しない** | 実装。提案の「型システムに常駐する型」ではなく解析内部の一時マーカーに変更 |
| 2. 推論できなかった引数を unresolved に | 実装。さらに **推論できた引数も** マーカーにして generalization を廃止 | 提案を超えている（ondrejmirtes の意図どおり） |
| 3. `Foo<int>` が `Foo<unresolved>` を受け入れる | **実装せず。逆にした。** `UnresolvedTemplateArgumentTypeTest::testInvariantPositionIsOpaqueCovariantIsTransparent` が `->no()` を assert している | 意図的な反転。opacity が無いと union がマーカーを潰してしまうため |
| 4. 送信がすべての型引数を解決する | 実装。property（instance/static）、argument、return、`@var`、arrow fn 本体をカバー | 提案どおり。ただし「すべて」ではなく「マーカーが立っている引数だけ」 |
| 5. メソッド呼び出しが receiver を解決する | **実装せず。下界の union に置き換え。** `$b->add(1); $b->add('a');` → `Bag<1\|'a'>`（`nsrt/bug-6732.php`） | 最大の乖離。§4.2 参照 |

未対応（PR 本文の "What's left"）: パラメータのデフォルト値（`__construct(WeakMap $m = new WeakMap())` — marcospassos が挙げた実例そのもの）、ファイル top-level 文、closure/arrow fn の **引数**、dim-fetch のプロパティ、generator の `@return`。

### 3.3 rule 3 を捨てたことの含意

opacity は「マーカーが誰にも消されずに観測パス終了まで生き残る」ために必要である。代償として、マーカーが万一 rule 側に漏れると `Foo<int>` が `Foo<unresolved(int)>` を拒否する形の偽陽性になる。PR 本文は「全出力に `unresolved(` はゼロ」と検証したと述べており、`describe()` が `unresolved(...)` という目立つ文字列を返すのはこの grep 検証のための設計と読める。妥当な防御だが、**不変条件がテストではなく grep で守られている**点は弱い。`ShouldNotHappenException` を投げる assertion か、エラーメッセージ生成経路での明示的な検査があるほうが良い。

### 3.4 closure が動く理由（読解メモ）

`processBodyStmtNodesTwoPass` へ入る条件に `!$nodeCallback instanceof RecordingNodeCallback` がある。したがって **pass 1 の最中に出会った closure/arrow fn の本体は自前の frame を持たず、外側の frame の下で歩かれる**。だから

```php
$c = new Collection([1]);
$f = function () use ($c): void { takeInts($c); };
assertType('Bug6732\Collection<int>', $c);   // 通る
```

が成立する。arnaud-lb の rule 4 が要求した closure キャプチャの扱いは、専用処理ではなくこの入れ子規則の副産物として実現されている。逆に pass 2 の再走査では closure は自前の子 frame を得るが、その frame は site を持たないので `TemplateArgumentObserver::observeSend()` が `!$frame->hasSites()` で即 return する（親の site へは記録されない）。**pass 1 と pass 2 で観測の届く範囲が違う**ことになるが、pass 2 の観測結果は使われないので実害はない。とはいえ暗黙的すぎるので、コメントに明記する価値がある。

---

## 4. 意味論の変更点（テスト期待値から読む）

### 4.1 generalization 廃止の波及（望ましい方向）

| テスト | before | after |
|---|---|---|
| `generics-do-not-generalize.php` | `Foo<int>` | `Foo<1>` |
| `generics.php` | `A<int>`, `$a->get(): int` | `A<1>`, `$a->get(): 1` |
| `native-reflection-default-values.php` | `ArrayObject<string, int>` | `ArrayObject<'key', 1>` |
| `bug-10254.php` | `Option<int>` | `Option<3>`（`Option::some(1)->zip(Option::some(2))->map(fn => $a+$b)`） |
| `bug-5508.php` | `array<int, string>` | `array<0\|1, 'book'\|'cars'>` |
| `ext-ds.php` | `Ds\Map<int\|string, A\|B>` | `Ds\Map<1\|'a', A\|B>` |
| `assert-class-type.php` | `HelloWorld<int>`、`$b: int` | `HelloWorld<123>`、`$b: 123` |

`Option<3>` は明確な精度向上である。

### 4.2 失われた検出（要検討）

| 場所 | 消えたエラー / 変化 | 原因 |
|---|---|---|
| `nsrt/bug-6993.php` | `AndSpecificationValidator<TestSpecification, Foo>` → `<TestSpecification, Foo\|Bar>`。`$and->isSatisfiedBy(new Bar())` が通る | **rule 5 の union 化**。PR 本文も "−1 detection ... by design" と認める |
| `CallMethodsRuleTest::testBug5372` | 5 件 → **0 件**。`Collection<int,string>` に `Collection<int,non-falsy-string>` / `class-string` / `literal-string` を渡すエラーが全消滅 | 送信が invariant 位置で「最初に受け入れた送信が勝つ」ため、`Collection<int,string>` へ送った時点で string に確定してしまう |
| `ReturnTypeRuleTest::testBug4590` | 4 件 → 1 件 | 同上 |
| `testBug5065ExplicitMixed` | 1 件 → 0 件 | 同上 |
| `testGenericsInferCollection` | 4 件 → 1 件（`ArrayCollection2<(int\|string), mixed>` の 3 件が消滅） | `mixed` が `never` になり `isUninformativeSendTarget` 相当の扱いに落ちる |
| `TypesAssignedToPropertiesRuleTest::testBug3777` | `$lorem2` の 1 件が消滅 | 同上 |
| `nsrt/self-out.php` | `$i = new a(123); $i->test()` が `null` → **`never`** | `a<123>` が `$this is a<int>` を満たさなくなった（invariant）。PR 本文は「unreachable-code の報告が出る」と述べる |
| `LruCacheTest` | `/** @var LruCache<string> $cache */` の追記が **必要になった** | `new LruCache()` が `LruCache<never>` になり `get()` が静的に `null` になるため |

`testBug5372` の 5 件全消滅は最も気になる。これらは `Collection<int, string>` に非互換な collection を渡す典型的な variance エラーで、"Template type T on class Collection is not covariant" という教育的な tip 付きの真陽性である。それが 0 件になるのは「送信が型を決める」設計の直接的な帰結であり、**rule 4 の副作用として variance 違反の検出力が落ちる**ことを示す。この 5 件が本当に不要になったのか（= 送信先で確定するので矛盾しない）、それとも隠れたのかは、テストデータを個別に読んで判定する必要がある。

### 4.3 `never` fallback の影響

`nothing inferred → never` により以下が変わる。

```
new Collection()          → Collection<*NEVER*>       (旧: Collection<mixed>)
new \ArrayObject()        → ArrayObject<*NEVER*,*NEVER*>
new SplObjectStorage()    → SplObjectStorage<object, *NEVER*>
```

`tests/PHPStan/Levels/data/arrayAccess-*.json` の期待メッセージが `*NEVER*` に書き換わっている。PR 本文が Slevomat で観測した「B が A より 69 件多く、うち 67 件が `QueryResultSet<*NEVER*>` の 1 パターン」はこれである。**`@template-covariant TValue` を持ちコンストラクタが `TValue` に触れないクラスを `new` して、`T` を返すメソッドで読むだけ**という形は Doctrine/Shopware 系で普遍的であり、`never` fallback のままでは実用に耐えない。PR 本文自身が「読まれたが送られていない場合は bound に戻す」「宣言型へ流れる bare marker を上界の送信として扱う」という 2 案を open question として挙げている。ここは設計決着が必須である。

---

## 5. CI 実測（本レポート独自調査）

`gh pr checks 6332` 時点: **pass 752 / fail 14 / skipping 6**。

失敗 14 件の内訳と、base `2.3.x`（`8a83697`）の同一ジョブとの比較:

| ジョブ | 状態 | base での状態 | 判定 |
|---|---|---|---|
| PHPStan (8.1, windows-latest) | fail | **fail** | 既存。PR 無関係 |
| Test (PHP 7.4) — phpbench gate | fail (`bug-8215.php` +37.34 %) | **fail** (`bug-8215.php` +37.57 %) | 既存 |
| Test (PHP 8.5) — phpbench gate | fail (`or-chain-resolve-type` +37.15 %, **`bug-8146b.php` +24.32 %**) | fail (`or-chain-resolve-type` +33.76 % のみ) | **`bug-8146b.php` は PR による新規失敗** |
| Tests with old PHPUnit (8.1, windows-latest) | fail (`IntersectionTypeTest::testIsAcceptedBy` 3 件) | success | 要再実行（§5.2） |
| Mutation Testing (8.3 / 8.4) | fail | 比較対象なし | infection の CLI usage エラー。環境要因の可能性 |
| extension-tests / phpstan-doctrine (8.1 / 8.2) | fail | 比較対象なし | §5.3 |
| integration-tests / Larastan, phpstan-laravel, Rector, Rector downgrade, neos, shopware | fail | 比較対象なし | §5.3 |

### 5.1 性能: PR 本文の主張と実測の乖離

PR 本文の主張:

> | target | n pairs | user CPU | RSS |
> | `src/Type` | 8 | −0.35 % | −0.16 % |
> | nsrt corpus | 3 | −0.97 % | +0.24 % |
> Both indistinguishable from zero.

一方、phpbench は固定ベースライン（両 run で同一値、例 `890.713ms`）に対する比較なので、2 つの run の **variant 実測値どうし** を比べれば PR 由来の差が読める。

| bench | 2.3.x | PR #6332 | 差 |
|---|---|---|---|
| `bug-8146b.php` | 933.998 ms | **1.107 s** | **+18.5 %**（gate 突破 ✘） |
| `bug-7140.php` | 23.174 ms | 26.856 ms | **+15.9 %** |
| `bug-14462.php` | 130.196 ms | 144.320 ms | **+10.8 %** |
| `bug-12159.php` | 158.160 ms | 167.160 ms | +5.7 % |
| `bug-8215.php` (8.5) | 547.339 ms | 575.716 ms | +5.2 % |
| `finite-types-optional` | 8.054 ms | 8.416 ms | +4.5 % |
| `or-chain-resolve-type` | 1.621 s | 1.662 s | +2.5 % |
| `bug-8215.php` (7.4) | 633.528 ms | 632.469 ms | −0.2 % |

8 variant 中 7 つで悪化、うち 3 つが 2 桁 %。各 run 内の標準偏差は ±0.3〜1.6 % と小さい。GitHub hosted runner のマシン差は残るが、**方向が一貫しており、「ゼロと区別できない」という本文の結論は phpbench の観測と両立しない**。

本文が測った 2 ターゲット（`src/Type` = 8,399 body 中 site 2 個、nsrt = 5,868 body 中 site 146 個）は site 密度が低い。phpbench の対象は 1 ファイルを繰り返し解析するもので、two-pass 走査そのものの固定費（statement ごとの `clone $state`、`getDifferingVariableRoots()` による全 expressionTypes テーブル走査）が効く。**site が無い body でも pass 1 の記録とオブジェクト clone は必ず走る**ので、site 密度と無関係な一律コストが乗っているという読みが自然である。ここは本文の測定方法（マクロ 2 点）を phpbench 側の結果で補正すべきである。

### 5.2 `IntersectionTypeTest::testIsAcceptedBy` の 3 件

```
non-empty-list&callable(): mixed -> isAcceptedBy(array{string, string})
Expected 'Maybe' / Actual 'No'
```

`8.1, windows-latest` の old PHPUnit ジョブのみで失敗し、`8.1, ubuntu-latest` を含む他の全マトリクスは pass。base では全 pass。一見 unresolved template argument と無関係な `callable` 受け入れ判定であり、**順序依存またはフレークの可能性が高い**が、`UnresolvedTemplateArgumentType` は `TypeCombinator` を通る新しい `CompoundType` であり、`describe(VerbosityLevel::cache())` に `spl_object_id` を埋める（§6.3）。キャッシュ由来の順序依存を新規に持ち込みうる位置にいるので、**再実行して flake か決定的かを確定させるべき**である。

### 5.3 downstream の新規エラー

いずれも「literal 保持」と「`never` fallback」の直接的な帰結である。

**Shopware（5 errors）**
```
PHPDoc tag @var with type RepositoryIterator<EntityCollection<covariant Entity>>
  is not subtype of type RepositoryIterator<EntityCollection>.
Parameter #1 $element of method Collection<NestedEvent>::add() expects NestedEvent, object given.
Parameter #2 $events of class EntityWrittenContainerEvent constructor expects
  NestedEventCollection<EntityWrittenEvent<IDStructure of array<string,string>|string = string>>,
  NestedEventCollection<NestedEvent> given.
Call to method ReflectionClass<*NEVER*>::isSubclassOf() with '...\Struct' will always evaluate to true.
Parameter #2 $events ... expects NestedEventCollection<EntityWrittenEvent<'product-stream-1'|'product-stream…'>>,
  NestedEventCollection<EntityWrittenEvent<'product-stream-1'>|EntityWrittenEvent<'product-stream…'>> given.
```
最後の 1 件が本質的である。`Collection<A|B>` と `Collection<A>|Collection<B>` が invariant 下で別物になり、literal を保持したせいで両者が衝突するようになった。generalization は偶然この差を吸収していた。`ReflectionClass<*NEVER*>` は `never` fallback の典型的な誤爆で、`always evaluate to true` という二次的な誤検出まで誘発している。

**Neos（1 error）**
```
Strict comparison using === between EventMetadata and null will always evaluate to false.
```
narrowing が効きすぎて `null` の可能性を落とした形。

**Larastan / phpstan-laravel（同一）**
```
Parameter #1 $attributes of Model::update() expects array<model property of App\User, mixed>,
  array<string, string> given.
```
`model property of App\User` は Larastan の型 extension が返す key 型。従来は key が `string` に generalize されて通っていた。

**phpstan-doctrine（2 errors）**
```
Access to an undefined property Doctrine\ODM\MongoDB\Mapping\ClassMetadata<object>::$customRepositoryClassName.
```

**Rector**: 5 failures（`Failed asserting that string matches format description.`）+ downgrade 2 failures。Rector のスナップショットテストなので、出力型文字列の変化がそのまま差分になったものと推測されるが、内容確認が必要。

**評価**: これらは「bleeding edge を有効にしている下流プロジェクト」で起きる。つまり feature toggle があっても、**bleeding edge 利用者にはそのまま届く**。PR 本文は Slevomat と ShipMonk のみを downstream 検証対象として挙げ「ShipMonk B numbers are still owed」としているが、CI が既に 5 プロジェクト分の具体的な失敗を出している。この情報を本文の "What's left" に取り込むべきである。

---

## 6. 設計・実装上の指摘

### 6.1 rule 5 の置き換えは issue の主旨と衝突する（High）

issue #6732 は「型ホールを塞ぐ」ための feature request である。PR は property/argument 経由のホール（rule 4）を塞ぐ一方、メソッド呼び出しについては下界の union を採り、`bug-6993` の真陽性を失った。PR 本文は「first invariant send wins vs union fallback のどちらを長期の意味論とするか」を open decision として残している。**issue を閉じる PR として、この決着なしには出せない**。

なお、union 側が常に不当というわけではない。`$b->add(1); $b->add(2);` を `Bag<1|2>` とするのは自然で、arnaud-lb の rule 5 を字義どおり実装すると壊れる。妥当な着地点は「メソッド呼び出しは下界（union）、宣言型への送信は確定」であり、**それは今の実装そのもの**である。したがって取るべき対応は rule 5 の実装ではなく、**bug-6993 が本当に検出すべきものだったかの再評価**と、issue コメントでの明示的な説明である。

### 6.2 `never` fallback の再設計（High）

§4.3 のとおり。PR 本文の 2 案（bound へのフォールバック / bare marker の宣言型流入を上界送信として扱う）のうち、**後者のほうが情報量が多く筋が良い**。

```php
/** @var Product $p */
$p = $set->getSingleResult();   // QueryResultSet<T>::getSingleResult(): T
```
ここで `T` の上界が `Product` だと分かる。前者（bound へ戻す）は単に旧挙動に戻るだけで、`ArrayCollection2<(int|string), mixed>` の 3 件のような検出も戻らない。

### 6.3 `spl_object_id` によるキーイング（Medium）

3 箇所で使われている。

1. `TemplateArgumentFrame::key()` — site の AST ノードは解析中 parser cache に保持されるので実害は小さい。
2. `UnresolvedTemplateArgumentType::describe(VerbosityLevel::cache())` → `unresolved#<spl_object_id($site)>(...)`。**`SYNTHETIC_SITE_ATTRIBUTE` 付きのノードは walk ごとに新規生成されて解放される**ため、id が再利用されうる。この文字列は PHP 側の各種キャッシュキー（`MutatingScope` の式キー、`ClosureTypeResolver` の LRU）に入る。
3. `TemplateArgumentFrame::getResolutionCacheKeySuffix()` → `|templateArguments:<spl_object_id($frame)>`。frame は body の走査終了で解放されるので、後続の frame が同じ id を得る。`ClosureTypeResolver` の LruCache は body をまたいで生きるため、**同一 closure ノード × 同一 scope キー × id 再利用**が重なると stale hit になる。確率は低いが決定的でない不具合の温床である。

turbo 側（`turbo-ext/src/TypeCombinatorCache.cpp`）は、まさにこの問題を避けるため weak map と単調増加 serial を使っている（コメントに "addresses are reused once an object is freed, so two different types could hash alike" と明記）。**PHP 側も同じ方針（単調カウンタ）に揃えるべき**である。

### 6.4 `getVariableRootOfExpressionKey()` の正規表現（Medium）

```php
preg_match('/^\$([a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)/', $key, $matches)
```

式キー文字列をパースして変数名を取り出している。CLAUDE.md の「型を `describe()` で並べ替え・比較・変換しない」という規範の隣接ケースで、式キーの表現に暗黙依存する。`ExpressionTypeHolder` 側に「この holder の根になる変数名」を持たせるか、`MutatingScope` が式キーと一緒に根を記録するほうが堅い。少なくとも `$this` や `$$x` の扱いがキー表現の変更で静かに壊れうる。

### 6.5 pass 1 / pass 2 等価性の担保（Medium）

`withRecordedStatementDelta()` は「記録された entry→exit の差分だけを現在の scope に載せる」。前提は「replay 対象の文は、変わった変数に言及しないので同じ振る舞いをする」。この前提を崩しうる要素:

- `$this` 経由のプロパティ状態。`collectMentionedVariables()` は closure が static でなければ `'this'` を数えるが、**通常の `$this->foo` は `Expr\Variable('this')` を含むので拾える**。妥当。
- `compact`/`extract`/`get_defined_vars`/`eval`/`include`/`$$x` は `mentionsEverything` で全再走査。妥当。
- 非変数根の差分（static property、class constant fetch）は `differingRoots === null` として無条件再走査。妥当。
- `label`/`goto` があれば body 全体を再走査。妥当。
- 抜けているとしたら **by-reference な外部状態**（`static $x` 変数、参照渡しで束縛された変数）だが、これらも式キーは `$x` で始まるので拾えるはずである。

`MinimalReWalkTest` が「10 文中 3 文だけ再走査」を検証しているのは良い。ただし検証されているのは **性能特性であって等価性ではない**。等価性は「toggle on/off で出力が一致すること」でしか担保されていない（nsrt 全体で差分あり = 一致しない）。**pass 2 の replay 経路と全再走査経路の出力が一致することを確認する differential テスト**（`PHPSTAN_TEMPLATE_FORCE_REWALK=1` のような env で全文を再走査させ、通常経路と突き合わせる）を追加する価値が高い。

### 6.6 `MENTIONED_VARIABLES_ATTRIBUTE` の AST 汚染（Low）

`$stmt->setAttribute(self::MENTIONED_VARIABLES_ATTRIBUTE, ...)` はパース済み AST を書き換える。PHPStan の AST は cache で共有されるため、メモとして残り続ける。既存のパターン（`NewAssignedToPropertyVisitor` など）と同種なので許容範囲だが、メモリ増分は測っておきたい。

### 6.7 BC（Low）

`ResolvedFunctionVariant` インターフェースに `getReturnTypeWithUnresolvedTemplateArguments()` を追加している。`ResolvedFunctionVariant` 自身に `@api` は無く、親の `ExtendedParametersAcceptor` は `@api` + `@api-do-not-implement` なので、CLAUDE.md の BC ポリシー上は許容される。ただしこのインターフェースを実装している第三者ラッパがあれば壊れる。

### 6.8 その他

- `PHPSTAN_TEMPLATE_ARGUMENTS_DEBUG` の `echo` が本番経路に残っている（PR 本文も housekeeping として認識）。
- `TemplateArgumentStats::$enabled` は public static mutable。ベンチ専用と明記されているが、`if (TemplateArgumentStats::$enabled)` が hot path に散在する。
- PR 本文が「最後のコミットは全員に有効化する TMP コミット」と述べているが、**現在の HEAD（`33d76e7`）にそのコミットは無く、`conf/config.neon` は `false` のまま**である。本文が stale。
- PR が副産物として見つけた upstream バグ 2 件（`declare(strict_types=1)` を関数の途中に書くと `MissingReturnRule` が Internal error、1 ファイルの parse error が同一 run の他ファイルのエラーを全部握り潰す）は **2.2.x にも存在する**。#6332 とは独立に issue/PR を切るべきである。

---

## 7. Recommended next steps

優先度順。

1. **`never` fallback の決着**（blocker）。「bare marker が宣言型へ流れたら上界送信として扱う」案を実装し、Shopware の `ReflectionClass<*NEVER*>` と Slevomat の `QueryResultSet<*NEVER*>` が消えることを確認する。
2. **失われた検出の分類**（blocker）。`testBug5372` の 5 件、`testBug4590` の 3 件、`testBug5065`、`testGenericsInferCollection` の 3 件、`testBug3777` の 1 件、`bug-6993` を 1 件ずつ「送信で確定するので矛盾しない = 消えて正しい」「隠れた = 回帰」に仕分ける。少なくとも `bug-6993` は回帰である。
3. **性能の再測定**（blocker）。phpbench の `bug-8146b.php` / `bug-7140.php` / `bug-14462.php` を toggle on/off で ABBA 測定し、site 密度に依らない固定費（`clone $state`、`getDifferingVariableRoots()`）を特定する。site を持たない body では pass 1 の状態 clone を省く最適化が効くはず。CLAUDE.md の「アルゴリズムを直せ、上限で誤魔化すな」に照らして、two-pass の固定費そのものを削る方向で。
4. **downstream 検証の拡張**。Slevomat/ShipMonk だけでなく、CI が既に落としている Shopware・Neos・Larastan・phpstan-doctrine・Rector のエラー分類を PR 本文へ取り込む。
5. **`spl_object_id` を単調カウンタへ**（§6.3）。turbo 側と同じ方針に揃える。
6. **等価性の differential テスト**（§6.5）。
7. `IntersectionTypeTest` の windows-only 失敗を再実行して flake か決定的かを確定。
8. Rector の 5 failures の中身確認（現状 `Failed asserting that string matches format description.` しか出ておらず、原因が literal 保持なのか別要因なのか未確定）。
9. 未実装項目のうち、**パラメータのデフォルト値**（`__construct(WeakMap $m = new WeakMap())`）を優先。marcospassos が #6732 で挙げた実例そのもので、issue を閉じる根拠として引かれる可能性が高い。

## Appendix: PR が Closes している issue

12704, 12576, 10419, 14647, 13431, 12601, 12490, 12420, 11835, 11435, 10290, 10289, 5741, 8031（14 件、すべて false positive の解消）。

修正されないと明記されているもの: 8441（`new Collection()` の `T` がパラメータのデフォルト `= null` 由来 — default 推論も「既知の initial」に数えられる）、13325（`RegexIterator` の stub、第 3 引数が `Traversable<mixed, mixed>` に解決される）。
