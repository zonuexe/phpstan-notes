# mbstring の暗黙エンコーディング依存と純粋性(phpstan/phpstan#15224)

[phpstan/phpstan#15224](https://github.com/phpstan/phpstan/issues/15224) と、それに対する VincentLanglet の[コメント](https://github.com/phpstan/phpstan/issues/15224#issuecomment-5655248189)(staabm と zonuexe に意見を求めたもの)の検討メモ。

関連: [20260709-pseudo-constant-settings-purity.md](20260709-pseudo-constant-settings-purity.md)((A)ビルド定数 / (B)実行時可変の切り分け), [20260812-issue-draft-effect-labels-spec.md](20260812-issue-draft-effect-labels-spec.md)(`global.read` / `global.write`), [20260815-callback-tags-semantics-and-effects-feedback.md](20260815-callback-tags-semantics-and-effects-feedback.md) §4.5(「有無」判定と「中身」判定の区別)

実測環境: PHP 8.5.10、PR #6018 ブランチ(`c6bc66ef5e`、base `2.2.x`)、level 10。

---

## 1. issue の要旨

- **Issue 1**: 試した mbstring 関数のうち `mb_str_pad` だけが impure 扱い。他と概念上の差が見当たらない。
- **Issue 2**: 純粋性を「同じ入力に同じ出力」「副作用なし」と定義するなら、`$encoding` を明示した呼び出しは pure、省略した呼び出しは `mb_internal_encoding` という外部状態に依存するので impure ではないか。

Vincent のコメントの要旨: 技術的には encoding を渡さなければ internal encoding に依存するので pure ではない。いずれにせよ不整合はある。#6018 が `@pure-unless-parameter-passed` を入れるが、逆向きのアノテーションも要るか。

## 2. 事実確認

### 2.1 現状の分類

`resources/functionMetadata.php` の `mb_*` は 50 エントリあり、`mb_str_pad` だけが `hasSideEffects: true`、残りはすべて `false`。
`mb_internal_encoding` / `mb_regex_encoding` はエントリ自体が無い(未知扱い)。

### 2.2 原因: JetBrains stubs の `#[Pure(true)]` の写像

`mb_str_pad` だけが stubs で `#[Pure(true)]`、他の `mb_*` は `#[Pure]`(または属性なし)。

JetBrains の属性定義(`vendor/jetbrains/phpstorm-stubs/meta/attributes/Pure.php`):

- 属性自体の意味: 実行後に使われるプログラム状態や引数へ影響しない。結果を使わないなら呼び出しを安全に削除できる。
- 引数 `$mayDependOnGlobalScope`: 結果が渡された変数以外に依存しうるか。

`bin/generate-function-metadata.php` はこの `true` を「副作用はあるが戻り値が重要」とコメントで説明したうえで `impureFunctions` に積み、`hasSideEffects: true` に写している。
コメントの説明は属性の定義(「依存しうる」)とずれているが、**写像の結果(impure)は §3 の厳密な定義とは整合する**。

同じ経路で impure になっている関数:

| stubs | `#[Pure(true)]` の関数 |
|---|---|
| `mbstring/mbstring.php` | `mb_str_pad` のみ |
| `date/date.php` | `date`, `gmdate`, `idate`, `mktime`, `gmmktime`, `strtotime`, `date_create*` など |
| `standard/*` | `localeconv`, `ob_get_contents` など |

つまり PHPStan の既存の事実上の規則は「stubs が大域状態に依存しうると言えば impure」。
`mb_*` の大半が pure なのは、**JetBrains がフラグを付けていない**からにすぎない(`mb_strtoupper` も `mb_str_pad` と同じく internal encoding を読む)。

フラグは関数単位で、引数単位ではない。`gmdate('Y', 0)` は時計もタイムゾーンも読まず完全に決定的だが、`gmdate` 全体が impure になっている。

`mb_str_pad` を `false` に上書きする場合、生成器は Pure(true) と original の `false` の食い違いで `ShouldNotHappenException` を投げるので、`ob_get_contents` と同じ例外リストへの追加が必要。

### 2.3 実行時の依存(実測)

```php
var_dump(mb_strlen("abc"));            // int(3)  (UTF-8)
mb_internal_encoding("UTF-16LE");
var_dump(mb_strlen("abc"));            // int(2)
var_dump(mb_strlen("abc", "UTF-8"));   // int(3)  明示すれば不変
```

`mb_strtoupper("\xE9")` も UTF-8 では `"?"`、ISO-8859-1 では `"\xC9"` になる。

### 2.4 PHPStan の「値の記憶」(remembering)への影響(実測)

PHPStan は `hasSideEffects: false` の呼び出し結果を**引数だけをキーに記憶し、間に impure な呼び出しを挟んでも忘れない**。

```php
/** @phpstan-impure */
function userImpure(): void {}

function a(string $s): void { if (mb_strlen($s) === 3) { mb_internal_encoding('UTF-16LE'); dumpType(mb_strlen($s)); } } // 3
function b(string $s): void { if (mb_strlen($s) === 3) { rand();                         dumpType(mb_strlen($s)); } } // 3
function c(string $s): void { if (mb_strlen($s) === 3) { userImpure();                   dumpType(mb_strlen($s)); } } // 3
function d(string $s): void { if (mb_strlen($s) === 3) { echo 'x';                       dumpType(mb_strlen($s)); } } // 3
function e(string $s): void { if (strtotime($s) === 3) { userImpure();                   dumpType(strtotime($s)); } } // int|false
```

`a()` は実行時には 2 になりうるのに PHPStan は `3` を保持する。`hasSideEffects: true` の `strtotime` は最初から記憶されない。

### 2.5 結果未使用の検出(実測)

```php
mb_strlen($s);            // Call to function mb_strlen() on a separate line has no effect.
mb_str_pad($s, 3);        // 報告なし(impure 扱いのため)
mb_internal_encoding();   // 報告なし(未知扱い)
```

`@phpstan-pure` 内では `mb_str_pad()` と `date()` が `Impure call` として報告され、`mb_strlen()` と `sprintf('%f', …)` は報告されない。

## 3. 純粋性の定義: 「副作用を及ぼさない」と「副作用の影響を受けない」

初回の検討では「`Pure(true)` は結果を捨てれば削除できる = 副作用なし」と書き、pure 寄りに読んでいた。
これに対し「呼んでも副作用は及ぼさないが、内部状態(ほかの副作用の結果)に影響されるので impure そのものではないか」という指摘があり、**その指摘が正しい**。

純粋性は 2 つの性質の連言:

- **P1(書かない)**: 呼び出しが観測可能な状態を変えない。
- **P2(読まない)**: 結果が引数だけで決まる(参照透過)。

| | P1 | P2 | 例 |
|---|---|---|---|
| pure | ✓ | ✓ | `strlen`, `mb_strlen($s, 'UTF-8')` |
| JetBrains `Pure(true)` | ✓ | ✗ | `mb_strlen($s)`, `date('Y')`, `ob_get_contents()` |
| impure(書く) | ✗ | — | `echo`, `str_replace(..., $count)` |

JetBrains の `Pure(true)` は「削除可能性」(P1)を表す属性であり、純粋性(P1∧P2)を表してはいない。

### PHPStan の単一の真偽値が抱える緊張

PHPStan には `hasSideEffects` という真偽値が 1 つしかないが、消費者ごとに必要な性質が違う。

| 消費者 | 必要な性質 | `hasSideEffects: false` にした場合 | `true` にした場合 |
|---|---|---|---|
| 結果未使用の検出(`function.resultUnused`) | P1 | 正しく検出 | **検出漏れ**(`mb_str_pad($s, 3);`) |
| 値の記憶(remembering) | P2 | **不健全**(§2.4 `a()`) | 正しく記憶しない |
| `@phpstan-pure` 本体の検査 | P1∧P2 | **見逃し** | 正しく報告 |

既存の前例(`date`, `time`, `ob_get_contents`, `localeconv` がすべて `true`)は P1∧P2 側を選んでいる。
この前例に照らすと、**不整合なのは `mb_str_pad` ではなく、暗黙エンコーディングを読む他の `mb_*` のほう**。

### 結果を捨てるのが無駄(D)は P1 で決まり、P1 で伝播する

**D**: 呼び出しの結果を捨てると、その呼び出しは無駄になる。

`mb_str_pad()` に絞ると、P2 違反(内部状態の影響を受けるか)は `$encoding` 次第で変わるが、D は引数に関係なく常に成り立つ。
一方、`$result = f();` として使う `f()` の本体で `mb_str_pad()` を使えば `f()` は impure になるが、それは `$result` を捨ててよいという意味ではない。
つまり D を決め、合成で伝播するのは P1 であり、P2 違反は別の軸で伝播すべきもの。

実測(level 10、結果を捨てて呼ぶ):

```php
/** @phpstan-pure */   function padPure(string $s): string { return mb_str_pad($s, 3); }
/** @phpstan-impure */ function padImpure(string $s): string { return mb_str_pad($s, 3); }
function padUnannotated(string $s): string { return mb_str_pad($s, 3); }
#[\NoDiscard]
/** @phpstan-impure */ function padImpureNoDiscard(string $s): string { return mb_str_pad($s, 3); }
```

| 呼び出し | 本体の検査 | 結果を捨てたときの報告 |
|---|---|---|
| `padPure($s)` | `Impure call to function mb_str_pad()` | `function.resultUnused` |
| `padImpure($s)` | なし | **なし** |
| `padUnannotated($s)` | なし | **なし** |
| `padImpureNoDiscard($s)` | なし | `function.resultDiscarded` |
| `mb_str_pad($s, 3)` | — | **なし** |
| `mb_str_pad($s, 3, ' ', STR_PAD_RIGHT, 'UTF-8')` | — | **なし** |

仕組み: `function.resultUnused` は「式に impure point が無い」ことから導出される(`ExpressionHandler` が `NoopExpressionNode` を発行し、`CallToFunctionStatementWithoutSideEffectsRule` がそれを受ける)。
P2 違反も impure point として表現されるので、P2 違反が D を打ち消す。

- `mb_str_pad()` 自身: encoding を明示しても D の報告が出ない(メタデータは関数単位)。
- ラッパー `f`: 正直に `@phpstan-impure` にすると D を失う。D を残すには `@phpstan-pure` と偽って本体のエラーを抱えるしかない。

注釈の語彙は `@phpstan-pure`(P1∧P2)と `@phpstan-impure`(⊤)の 2 段しかなく、「書かないが読む」(P1 のみ)を宣言する段が無い。P2 違反が一つ混ざるだけで D ごと ⊤ に落ちる。

注意: `mb_str_pad()` は pad 文字列が空のときや encoding が不正なときに `ValueError` を投げる。D を厳密に定義するなら throw しない条件も要るが、現行の `resultUnused` も throw を考慮しない(explicit never のみ除外)ので、現状とは整合する。

### `#[\NoDiscard]` との関係

PHPStan の実装状況(PR #6018 ブランチで確認):

- `CallToFunctionStatementWithNoDiscardRule` / `CallToMethodStatementWithNoDiscardRule` / `CallToStaticMethodStatementWithNoDiscardRule`、level 0、`PhpVersion::supportsNoDiscardAttribute()`(8.5 以上)でのみ有効。
- 識別子 `function.resultDiscarded` / `method.resultDiscarded` / `callable.resultDiscarded`(ignore 不可)。NoDiscard でない呼び出しへの `(void)` は `function.inVoidCast`。
- `mustUseReturnValue()` は `#[\NoDiscard]` 属性の有無だけを見る。純粋性からの導出も PHPDoc の代替表記も無い。組み込みは phpstan/php-8-stubs 由来(8.5 の `DateTimeImmutable::modify()` / `add()` / `sub()` / `setTime()` など)。

性質の関係:

- **NoDiscard ⇏ P1**: `#[\NoDiscard] function mustUse(): int { echo 'effect'; return 1; }` は合法で、PHPStan も何も言わない。NoDiscard が宣言するのは「捨てるのはおそらくバグ」であって副作用の有無ではない。
- **P1 ⇒ NoDiscard に値する**: P1(かつ throw しない)なら捨てるのは実際に無駄なので、NoDiscard は意味的に正しい宣言になる。
- 両者が重なるのは P1 な関数だけ。`$d->modify('+1 day');`(level 4)は `method.resultDiscarded` と `method.resultUnused` が二重に出る。

ラッパー `f` の D を残す手段として NoDiscard は弱い。

- 宣言であって導出ではないので伝播しない(`f` を呼ぶ P1 な `g` にも自分で付ける必要がある)。
- 解析対象が PHP 8.5 以上でないと検査されない。PHP 8.5 の実行時には警告が出るので、実行時の挙動も変わる。

effect labels では `@phpstan-impure global.read` が「P1 のみ」の段になる。宣言した上限が Discardable 集合([20260812-effects-vs-nodiscard-decomposition.md](20260812-effects-vs-nodiscard-decomposition.md))に収まれば D が導出され、本体の検査で上限が伝播する。

## 4. 以前の (A)/(B) 整理との接続

`mb_internal_encoding` は [20260709](20260709-pseudo-constant-settings-purity.md) の **(B) 実行時可変**に該当する(同メモの `mb_ereg` / `mb_regex_encoding` と同じ系統)。
§3 の定義では (B) は P2 違反なので impure。当時の「pseudo constant setting」案は、ブートストラップ時にだけ設定する運用をユーザーが opt-in で宣言し、(B) を (A) 同様に定数とみなす**例外**として位置づけ直せる。

同じ問題を抱える範囲は mbstring に閉じない。

- stubs で `$encoding = null` を持つ `mb_*` が少なくとも 35 個。
- `htmlspecialchars` / `html_entity_decode`(省略時は `default_charset`)、iconv 系(`iconv.internal_encoding`)。
- ロケール依存の `sprintf('%f')`(現状 pure)。

## 5. 逆向きタグ案の評価

`$encoding` を渡せば pure になる、という #6018 の逆向きタグは、#6018 の鏡像としては成立しない。

1. **「有無」ではなく「値」の判定になる。** `mb_strlen($s, null)` は internal encoding を使い、`?string` 変数を渡されたら Maybe。#6018 の有無判定ではなく、#3482 寄りの中身判定([20260815](20260815-callback-tags-semantics-and-effects-feedback.md) §4.5 の区別)。
2. **違反する性質が違う。** #6018 は「引数を渡すと P1 違反(by-ref 書き込み)が生じる」、逆向きは「引数を渡すと P2 違反(大域の読み取り)が消える」。真偽値に畳むと、暗黙エンコーディングの呼び出しで §3 の結果未使用の検出漏れがそのまま発生する。
3. **影響範囲が大きい。** §4 の範囲すべてに付与しないと新たな不整合を生む。

## 6. effect labels との関係

[effect-labels issue 案](20260812-issue-draft-effect-labels-spec.md)は P1/P2 を分けて扱える。

- `mb_internal_encoding('X')` を `global.write` とする。
- `mb_strlen($s)`(暗黙エンコーディング)を `global.read` とする。`$encoding` が非 null なら付かない、という引数条件は §5 の 1 と同じく値依存。
- `global.read` だけの呼び出しは、捨てれば dead statement として導出できる(P1 側の消費者を満たす)。
- 値の記憶は `global.write` を挟んだ時点で `global.read` 由来の記憶を捨てる(P2 側の消費者を満たし、§2.4 の穴を塞ぐ)。

単一の真偽値では §3 の表のどちらかを必ず失うが、ラベルなら両立する。

## 7. 選択肢

| 案 | 内容 | 利点 | 代償 |
|---|---|---|---|
| A | `mb_str_pad` を pure に揃える | 変更が 1 行 + 生成器の例外リスト | P1∧P2 の前例(`date` 等)と逆向き。記憶の不健全さを 1 関数ぶん増やす |
| B | 暗黙エンコーディングの `mb_*` を impure に揃える | 前例と整合、記憶が健全 | 結果未使用の検出を失う。`@phpstan-pure` コードにエラー多発。§4 の範囲まで揃えないと不整合が残る |
| C | 値条件つきタグ(§5) | 明示エンコーディングの呼び出しを救える | 真偽値のままでは B の代償が残る。付与範囲が大きい |
| D | effect labels(§6) | P1/P2 の消費者を両立 | 大きな設計変更。v1 の範囲外 |
| E | 現状維持 + 方針を明文化 | 変更なし | 記憶の不健全さと issue の不整合が残る |

## 8. メンテナに確認したい点

1. PHPStan の「pure」は P1 だけか、P1∧P2 か(前例は P1∧P2、キー名 `hasSideEffects` は P1 寄り)。
2. 値の記憶が impure な呼び出しを挟んでも pure 呼び出しの結果を忘れないのは意図どおりか(§2.4)。
3. 設定値(encoding / `default_charset` / ロケール)を定数とみなすのが事実上の方針なら、明文化するか。

## 9. 未確認事項

- phpstan.org のドキュメントが `@phpstan-pure` をどう定義しているかは未確認。
- JetBrains が `mayDependOnGlobalScope` を付ける基準(`mb_str_pad` だけに付いている理由)は未確認。
- `mb_internal_encoding` / `mb_regex_encoding` が未知扱いのままでよいか(引数ありは `global.write`、なしは読み取り)は未検討。
- 副産物: `(void) mustUse();` と `(void) pureFn();` の両方で `Casting to mixed something that's already int.`(`cast.useless`)が出た。`(void)` キャストを通常のキャストとして扱っている誤報に見える。upstream で報告済みかは未確認。
