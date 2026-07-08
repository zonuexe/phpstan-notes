# PHPStan pure/impure: 現状基盤 vs エフェクトシステム 設計メモ

PHPStan の純粋性(pure/impure)解析が抱える問題と、その解決方針を比較検討するためのメモ。
関連: [IMPURE_POINTS.md](IMPURE_POINTS.md)(`ImpurePoint` / `SimpleImpurePoint` の相互関係)。

---

## 1. 対象とする問題

### 問題1: `@pure` 関数内の `preg_match()` が誤警告される

```php
/** @phpstan-pure */
function foo(string $s): int
{
	preg_match('/(\d+)/', $s, $matches); // ← Possibly impure call to function preg_match()
	return (int) ($matches[1] ?? 0);
}
```

`$matches` は by-ref で書き込まれるため「戻り値以外の副作用」を持つのは事実。
しかしローカル変数 `$matches` を書くだけで**外部を汚染しない**ので、実用上は純粋関数の中で使えて然るべき。
impurePoint が「ある/ない」の粗い判定であることに起因する。

### 問題2: `array_map()` の純度がコールバック依存

`array_map()` 呼び出し自体の純度は、渡すコールバックが pure か impure かで変わるべき。
現状はこの**多相性**を第一級で表現できない。

---

## 2. 検証で判明した「現状の実像」

`@pure` 関数に両者を書いて `phpstan analyse -l 8` を実際に走らせた結果:

| ケース | 実際に出るエラー | 機構 |
|---|---|---|
| `preg_match($p, $s, $matches)`(ローカル変数) | `possiblyImpure.functionCall` のみ | preg_match 自身が `hasSideEffects=Maybe` だから。ローカル `$matches` 書き込みは**追加のimpure点を生まない** |
| `preg_match($p, $s, $this->matches)`(プロパティ) | `impure.propertyAssign`(**certain**) + `possiblyImpure.functionCall` | by-ref のプロパティ汚染は**代入機構が独立に**impure点を立てる |
| `array_map($pureClosure, $arr)` | `possiblyImpure.functionCall` のみ | array_map 自身が Maybe。純粋クロージャは何も足さない |
| `array_map($impureClosure, $arr)` | `possiblyImpure.functionCall` + `impure.echo`(**certain**) | コールバック本体の echo が**即時起動として伝播** |
| `array_map('impureNamed', $arr)` | `possiblyImpure.functionCall` ×2 | 名前付きコールバックの不純性も**伝播** |

### 事実A: impurePoint は「バイナリ」ではなく既に3状態 + 死んだ色を持つ

- 状態は `absent / possibly(certain=false) / certain(certain=true)` の**3値**。
- `identifier`(`echo`/`propertyAssign`/`superglobal`/`functionCall` …20種)という**"色"のフィールドを既に持つ**。
- ただしこの色は**エラーメッセージ表示にしか使われず、判定ロジックには一切効いていない**。
  → 「色分けの器」は既にあるが**死んでいる**。

（識別子の全種類。`ImpurePointIdentifier` @ [src/Analyser/ImpurePoint.php](src/Analyser/ImpurePoint.php)）
```
'echo'|'die'|'exit'|'propertyAssign'|'propertyAssignByRef'|'propertyUnset'|'methodCall'
|'new'|'functionCall'|'include'|'require'|'print'|'eval'|'superglobal'|'yield'|'yieldFrom'
|'static'|'global'|'betweenPhpTags'|'staticPropertyAccess'
```

### 事実B: 現状でも部分的な"多相"が動いている

- `array_map` のコールバックは「即時起動 callable」(`isImmediatelyInvokedCallable` が Maybe→callable なら yes、
  [NodeScopeResolver::callCallbackImmediately](src/Analyser/NodeScopeResolver.php:3877))として**本体を inline 展開**するため、
  コールバックの不純性は既に array_map 呼び出しへ伝播する(`impure.echo` が出る)。
- by-ref のプロパティ/静的/スーパーグローバル汚染は、**代入機構が独立に**impure点を立てるので捕捉される。

→ **preg_match / array_map の両問題は、"それ自身の Maybe" というノイズだけが余計**。
実際の外部汚染は既に別経路で捕捉済み。

---

## 3. 解決方針:3案の pros/cons

「現状 vs 新規」の二者択一より、間に"進化"案を挟んだ3段が実態に合う。

### 案A: 現状基盤のまま metadata/挙動でやりくり

`resources/bin/functionMetadata_original.php` に `hasSideEffects=false` を足す等で個別対応。

**Pros**
- 実装コスト最小。報告済み具体issueの大半を数行で消せる。
- BC 無風。`ImpurePoint`(`@api`)も `SimpleImpurePoint` もシグネチャ不変。
- ユーザーに新アノテーション不要。

**Cons**
- 下記「限界1〜4」が原理的に残る。特に**不透明コールバックでの多相が構造的に不可能**。
- 個別関数ごとの手当てはモグラ叩き(preg_match の次は sort/array_walk/…)。
- 「pure だが引数は書く」中間状態を表現できず、二値に押し込む**嘘**が残る。

**現状基盤の限界(やりくりで埋まらない穴)**
1. **不透明なコールバックで多相が崩れる。** 本体を見られない `callable` 型引数を `array_map` に渡すと inline 展開できず伝播しない。array_map を pure と印すと不純コールバックを**見逃す**、impure と印すと純粋でも**誤警告**。多相な契約(array_map の純度 = コールバック引数の純度)を宣言として表現できない。
2. **遅延起動コールバックを表現できない。** 即時起動は all-or-nothing。
3. **effect の種類で判定を変えられない。** 色フィールドが死んでいるので `@pure` は「effect が1つでもあれば全部ダメ」のまま。
4. **by-ref 引数汚染の捕捉は代入機構への依存で網羅は経験則。** effect が関数シグネチャの一部でないため、境界をまたぐ追跡が構造的でない。

### 案B(進化案): 既存 `identifier` を"死んだ色"から"生きた effect kind"へ昇格

`identifier` を判定に効く **effect ラティス**へ格上げし、`@pure` を「どの色の effect を禁止するか」で再定義。
`ImpurePoint` はほぼ現状構造のまま、色に意味を持たせる。

**Pros**
- **器が既にある**:`ImpurePointIdentifier`・`certain`・伝播経路(即時起動/代入機構)を再利用。ゼロからではない。
- 「local-mutation(引数書き込み)」色を新設すれば、preg_match は**嘘をつかず**「local effect のみ」と印せ、`@pure` がそれを許容できる → 問題1 が"正しく"解ける。
- effect kind の lattice(`none ⊑ local ⊑ io ⊑ global` …)で `@pure` / `@pure-local` 等の段階的アノテーションを導入可能。
- 実装が漸進的:色を判定に使う箇所を1つずつ増やせる。既存テスト資産と共存。

**Cons**
- `ImpurePoint` は `@api`。色の意味変更/追加は**下流ルールとの契約変更**を伴う(慎重な BC 設計)。
- **多相(案Cの核心)は依然入らない。** 色は付けられても「array_map の effect = 引数の effect」という**effect 変数**は表現できず、opaque コールバック問題は未解決。
- 色ラティス設計(何色・どんな subtyping)に合意形成コスト。色の増やしすぎは誤検知の温床。

### 案C(革命案): 多相エフェクトシステムを新設

関数シグネチャに **effect 行(effect row / effect 変数)** を持たせ、
`array_map: (callable() !e, array) -> array !e` のようにコールバックの effect を伝播する多相を第一級で表現。
effect の join / mask(局所化)も型システム的に扱う。

**Pros**
- **array_map 問題が本質的に解ける。** opaque コールバックでも effect 変数 `!e` が伝播し false pos/neg が消える。
- effect kind × 多相 × masking が揃い、preg_match(local)・higher-order・遅延起動・IO 分離を統一的に表現。
  将来「例外行 throw も同じ枠組み」へ発展可能。
- pure/impure 二値の嘘が消え、モグラ叩きが原理的に終わる。

**Cons**
- **実装コスト最大。** effect を型推論(`getType`/`ParametersAcceptor`/generics 解決)に織り込む必要。
  NodeScopeResolver・Reflection・PhpDoc パーサ・result cache まで広範に波及。
- **BC 破壊が広い。** `ImpurePoint` / `CallableParametersAcceptor`(共に `@api`)、拡張者向け I/F に effect 概念を注入。
- **アノテーション/UX の複雑化。** effect 変数・row polymorphism はユーザーに難しく、「気軽に段階採用」思想と衝突。
- 推論性能・キャッシュ無効化の複雑化。exported-node 粒度も変わる。
- phpdoc への effect 構文追加という**言語設計の合意**が必要(psalm 等との整合も論点)。

---

## 4. 比較表

| 軸 | A: やりくり | B: 色の昇格 | C: 多相 effect |
|---|---|---|---|
| 問題1(preg_match local) | △ 嘘pureで止血 | ◎ 正しく解ける | ◎ |
| 問題2(array_map 可視callback) | ○ 既に部分的に動く | ○ 同左 | ◎ |
| 問題2(array_map opaque callback) | ✕ 構造的に不可 | ✕ 不可 | ◎ |
| 実装コスト | 最小 | 中 | 最大 |
| BC リスク | なし | 中(@api) | 大(@api 多数) |
| ユーザー UX | 不変 | 段階導入 | 複雑化 |
| モグラ叩き解消 | ✕ | △ | ◎ |
| 拡張性(throw 等へ) | ✕ | △ | ◎ |

---

## 5. 推奨(段階戦略)

**A で個別issueを止血しつつ、本命は B。C は B の自然な延長として温存する。**

- "色の器"と"部分的な伝播"は既に実装済み。C を一から作るより、**死んでいる `identifier` を生かす B が費用対効果で優位**。
  preg_match 問題(local effect の許容)は B で"嘘なく"解ける。
- array_map の opaque コールバック多相だけは B でも埋まらない**真の C 案件**。
  ただし「本体を見られない callable を pure 文脈で回す」頻度は相対的に低い。ここを C の投資対象に切り出し、
  B の effect kind を安定させてから effect 変数を載せると、C を「色の多相化」として自然に導ける(色 → 色の多相 の順)。
- `ImpurePoint` が `@api` である点が B/C 共通の最大リスク。**effect 概念は新インターフェース側に閉じ込め**、
  `ImpurePoint` は表示用の投影に留める設計にすると BC 面が楽。

### 次アクション候補
1. B の effect kind ラティスのたたき台を具体化(候補色: `local-mutation` / `io` / `nondeterministic` / `global-state` / `throw` …)。
2. 問題1 を案A(`hasSideEffects=false`)/案B(local-mutation 色)どちらの粒度で潰すか PoC。
3. 既存 issue 調査(下記 §6)。

---

## 6. 既存 issue との対応(調査結果 2026-07-02)

`gh search issues` で phpstan/phpstan を調査。**両問題とも既報。特に問題2は本家が実装に着手済み。**

### 問題2(array_map の多相純度)= 本家が実装中 ⭐

- **[#11101](https://github.com/phpstan/phpstan/issues/11101)(OPEN)** — 本命issue。
  「`array_filter()` / `array_map()` / `array_reduce()` はコールバックが純粋なら純粋とみなすべき」。
- **[#11100](https://github.com/phpstan/phpstan/issues/11100)(OPEN, bug)** — 「純粋メソッド内の array_map が Possibly impure と誤警告」。
  本メモの問題2そのもの(static な純粋コールバックを渡しても誤警告)。
- [#11710](https://github.com/phpstan/phpstan/issues/11710)(CLOSED) — array_map の空呼び出しは "no effect" と報告すべき。
  **「by-ref や closure-use を持つコールバックの場合は報告すべきでない」と明記** — 本メモの「限界1/2」と同じ論点。
- [#14562](https://github.com/phpstan/phpstan/issues/14562)(CLOSED, #11101 の重複) — pure メソッドが array_filter/array_find 等を呼べない。
- [#11065](https://github.com/phpstan/phpstan/issues/11065)(CLOSED, completed) — 暗黙 pure なメソッド呼び出しがクロージャを impure にする。
  維持者コメント「デフォルト純度を設けるのは筋が悪い(コードは pure/impure 半々)」。

**実装(進行中):**
- **[phpstan-src PR #3482](https://github.com/phpstan/phpstan-src/pull/3482)(OPEN, WIP)** —
  **`@pure-unless-callable-impure`** アノテーションを導入。「コールバックが impure なら impure、さもなくば pure」という
  **宣言的な条件付き純度**。→ 本メモの **案B と案C の中間**(effect 行の完全な多相ではなく、"callable 引数の effect に依存する" という
  1変数の条件付き契約を宣言で表現)。functionMap への適用は済、rules/tests は未。
- [phpstan-src PR #3106](https://github.com/phpstan/phpstan-src/pull/3106)(CLOSED) —
  先行実装。`CallToFunctionStatementWithoutSideEffectsRule` が array_filter/map/reduce の純度をチェック。
- [phpdoc-parser PR #253](https://github.com/phpstan/phpdoc-parser/pull/253) — アノテーション構文サポート。

→ **本家の方向性は本メモの推奨(案C全部ではなく、宣言的な条件付き effect)と一致。**
`@pure-unless-callable-impure` は「effect 多相の最小スライスを、行多相ではなくアノテーションで表現」する現実解。
我々の検討は PR #3482 の設計に相乗り/レビューする形が有力。

### 問題1(preg_match の by-ref local)= 一般化された既報あり

- **[#11884](https://github.com/phpstan/phpstan/issues/11884)(OPEN, feature-request)** —
  「`str_replace` は by-ref 引数を渡さなければ pure とみなせる」。
  **「str_ireplace / preg_replace でも直すべき。ただし具体的な関数シグネチャに依存しない解を見つけたい」と明記** —
  これは preg_match の $matches と**同じ「by-ref out 引数を持つ関数の条件付き純度」問題**の一般化。
  → 本メモの案B(`local-mutation` 色)や「by-ref 引数の有無で純度が変わる条件付き契約」が刺さる領域。
- [#11862](https://github.com/phpstan/phpstan/issues/11862)(CLOSED) — `preg_match $matches` の false negative。
  ただし**純度ではなく $matches の shape 推論**の話なので本メモとは別件(誤ヒット)。
- preg_match の**純度**に特化した単独 issue は未発見。一般形は #11884。

### 関連(将来の一般化)

- [#11884](https://github.com/phpstan/phpstan/issues/11884) の「シグネチャ非依存の一般解が欲しい」という要望は、
  まさに本メモ案B/Cの動機。`@pure-unless-callable-impure`(引数の effect に依存)の**姉妹形として
  `@pure-unless-byref-passed`(by-ref 引数の有無に依存)** のような条件付き純度が考えられる。

### まとめ

| 本メモの問題 | 既報 issue | 本家の対応状況 |
|---|---|---|
| 問題2: array_map 多相純度 | #11101(本命)/#11100/#11710/#14562/#11065 | **PR #3482 で `@pure-unless-callable-impure` 実装中(WIP)** |
| 問題1: preg_match by-ref local | #11884(str_replace として一般化) | feature-request のまま。実装未 |

→ **新規に別issueを立てる必要は薄い。** 問題2は #11100/#11101 と PR #3482 に、
問題1は #11884 に、それぞれ知見をコメント/レビューで寄せるのが筋。

---

## 7. PR 変遷と「案A が2回リジェクトされた」経緯(2026-07-02 調査)

この問題には**2つの実装アプローチ**が競合し、案A(metadata化)が繰り返し却下されている。

### アプローチX = 案A:builtin を `hasSideEffects=false` と印すだけ

- [src PR #5580](https://github.com/phpstan/phpstan-src/pull/5580)(CLOSED, 2026-05-02, unmerged) —
  array_filter/map/reduce + PHP8.4 array_find 等を metadata で pure 化。`BetterReflectionProvider::getCustomFunction()` が
  stub 由来関数の metadata を見ていなかったバグ修正も含む。
- [src PR #5912](https://github.com/phpstan/phpstan-src/pull/5912)(CLOSED, **2026-07-02 today**, unmerged) —
  #5580 の再挑戦。同じく metadata に `hasSideEffects=false`(array系 + `preg_replace_callback` 系)。
  **CI 全緑・staabm が APPROVED。それでもクローズ。**

**推進者の論拠(VincentLanglet, 2026-05-01, #3482):**
> このアノテーション(`@pure-unless-callable-is-impure`)は無用では? PHPStan は既に immediately/later-invoked
> callable でコールバックの純度を扱える。だから array_map 等を pure と宣言するだけでよい。

この主張には**実証的な裏付けがある**。opaque な `callable $cb`(本体を見られない)を pure 文脈で `array_map($cb, $arr)`
に渡すと、現状でも `Possibly impure call to a callable`(`possiblyImpure.functionCall`)が
**array_map 自身のフラグとは独立に**伝播する(検証済み)。つまり array_map を pure と印しても、
コールバック経由の不純性は失われない。→ 案A は「immediate-invocation 経路に関しては健全」。

**それでも却下された理由(staabm の結論 on #5912):**
> ok. this needs `@pure-unless-callable-impure` instead.

ondrejmirtes が Slack で難色(スレッドは非公開・未確認)。VincentLanglet も #5580 を
「ondrej が納得しなかったので」クローズしていた。**案A が健全でも却下される設計上の理由**は次と解釈できる:

1. **`hasSideEffects=false` は"嘘"**。array_map はコールバック次第で副作用を持つ。フラグは immediate-invocation 経路
   *以外*(`SimpleImpurePoint::createFromVariant`、デッドコード判定、result cache、サードパーティ拡張が読む
   `hasSideEffects()`)でも消費される。そこで一律 false は誤った結論を生みうる。
   `@pure-unless-callable-is-impure` は**条件付きの真**(「callable 引数が impure なら impure」)を正確に符号化する。
2. **一般解が欲しい**([#11884](https://github.com/phpstan/phpstan/issues/11884) が明言)。関数ごとの metadata フラグは
   場当たり。アノテーションは宣言的で再利用可能な機構。
3. **by-ref 姉妹形へ拡張できる**。同じ設計思想が
   [phpdoc-parser #259 `@pure-unless-parameter-passed`](https://github.com/phpstan/phpdoc-parser/pull/259)(OPEN)へ繋がり、
   **str_replace/preg_match の by-ref out 引数問題(問題1)を宣言的に解ける**。metadata の bool では
   「第3引数を渡さなければ pure」を表現できない。

→ **結論:案A(#5580/#5912)が却下されたのは"動かないから"ではなく、"嘘のフラグで場当たり・非拡張的だから"。
維持者の合意は案B/C 系のアノテーション(= あなたの #3482)。**

### アプローチY = 案B/C:`@pure-unless-callable-is-impure` アノテーション(あなたの #3482)

- [src PR #3482](https://github.com/phpstan/phpstan-src/pull/3482) — base `2.1.x`、**DRAFT / CONFLICTING(DIRTY)**、+371 -39 / 41 files。
- 依存の [phpdoc-parser #253](https://github.com/phpstan/phpdoc-parser/pull/253)(構文サポート)は
  **2024-09-26 に MERGED 済み** → 構文は上流で利用可能。
- 実装の骨子:
  - PhpDoc: `PhpDocNodeResolver` / `ResolvedPhpDocBlock` に `getPureUnlessCallableIsImpureTagValues()` /
    `pureUnlessCallableIsImpureParameters`。
  - Reflection: `isPureUnlessCallableIsImpureParameter()` を **~30 のリフレクションクラス**へ横展開
    (`ExtendedParameterReflection` 他)。← コンフリクトの主因はここの機械的追加。
  - Metadata 生成: `bin/generate-function-metadata.php` / `functionMetadata_original.php` に
    `pureUnlessCallableIsImpureParameters` を出力。
  - Analyser: `NodeScopeResolver` / `MutatingScope` で純度判定に反映。
  - テスト: `tests/PHPStan/Analyser/nsrt/param-pure-unless-callable-is-impure.php`。
- コミットが `wip` 主体で、`hasSideEffects default true` など未整理。**rules/tests 未完**(元PR body の TODO のまま)。

### 2.2.x での再構築プラン(たたき台)

1. **rebase**:`feature/pure-unless-callable-is-impure` を `2.2.x` へ。コンフリクトの大半は
   ~30 リフレクションクラスへのメソッド追加(機械的)。2.1→2.2 で `ExtendedParameterReflection` 等の
   I/F が変わっていないか差分確認。
2. **phpdoc-parser の版**:#253 は merged 済みなので composer の phpdoc-parser を必要版へ。
3. **hasSideEffects との関係整理**:「`@pure-unless-callable-is-impure` を持つ関数は、
   デフォルト `hasSideEffects=Maybe`、かつ該当 callable 引数が pure と判れば実質 pure」という判定を
   `createFromVariant` 側で正しく合流させる(案A の"嘘フラグ"を避ける要)。
4. **rules/tests を完成**:pure/impure/unknown コールバックの3系統(#11100 の static callback、
   #75ed18fc の pure-closure 変数、opaque callable)を回帰テスト化。#5912 が追加したテスト資産
   (`bug-11101*.php`)を流用可能。
5. **by-ref 姉妹形は別PR**:問題1(preg_match/str_replace)は `@pure-unless-parameter-passed`
   ([phpdoc-parser #259](https://github.com/phpstan/phpdoc-parser/pull/259) がまだ OPEN)なので、
   #3482 とは分離し、#259 マージ後に着手。

### 関連PR/issue早見表

| 種別 | 番号 | 状態 | 意味 |
|---|---|---|---|
| src PR | [#3482](https://github.com/phpstan/phpstan-src/pull/3482) | OPEN/Draft/DIRTY | **本命**。`@pure-unless-callable-is-impure`(あなたのPR) |
| src PR | [#5580](https://github.com/phpstan/phpstan-src/pull/5580) | CLOSED unmerged | 案A(metadata)。ondrej 難色でクローズ |
| src PR | [#5912](https://github.com/phpstan/phpstan-src/pull/5912) | CLOSED unmerged(today) | 案A 再挑戦。CI緑+承認でも「#3482 でやるべき」で却下 |
| src PR | [#3106](https://github.com/phpstan/phpstan-src/pull/3106) | CLOSED | 先行のルール側実装 |
| phpdoc-parser | [#253](https://github.com/phpstan/phpdoc-parser/pull/253) | **MERGED** | `@pure-unless-callable-is-impure` 構文(#3482 の依存・解決済) |
| phpdoc-parser | [#259](https://github.com/phpstan/phpdoc-parser/pull/259) | OPEN | `@pure-unless-parameter-passed` 構文(問題1 の鍵) |
| issue | #11101/#11100/#11710/#11884 | 各種 | 上記 §6 参照 |
