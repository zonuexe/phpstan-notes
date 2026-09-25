# float range 2.3.x 移植中に見つかったバグ 3 件（handoff）

作成日: 2026-09-23。`float-nan-2.3.x` / `float-range-type-2.3.x` を `phpstan/2.3.x` へ移植し、turbo の C++ 版まで揃えた作業の副産物。どれも float range ブランチ自体の不具合ではなく、素の `phpstan/2.3.x`（確認時点 `ef16d6ead`）に存在する。別セッションでそれぞれ独立に直せるよう、再現手順・原因・修正方針・検証方法をまとめる。

| # | 内容 | 場所 | 深刻度 | 検証状況 |
|---|---|---|---|---|
| 1 | turbo の native `CombinationsHelper::combinations([])` が read-only の `zend_empty_array` に書き込んで SIGBUS / SIGSEGV | `turbo-ext/src/CombinationsHelper.cpp` | 高（2.3.x の CI の smoke が全プラットフォームで落ち、dev PHAR のコミットが止まった） | **upstream 9574d5f72 で修正済み**（同じ修正の phpstan/phpstan-src#6549 は重複でクローズ） |
| 2 | bleedingEdge の `assign.redundant` が `-0.0` を `0.0` にする代入を「冗長」と誤検知 | `AssignHandler::redundant()`（PHP と C++） | 中（誤検知。PHPStan 自身の self-analysis でも発火） | 素の 2.3.x で再現済み |
| 3 | 32-bit ホストで `IntegerRangeType::createAllGreaterThanOrEqualTo((float) PHP_INT_MAX)` が `never` を返す | `IntegerRangeType.php` と `IntegerRangeType.cpp` | 低（32-bit 解析ホストのみ） | **PR phpstan/phpstan-src#6546 で対応済み**（linux/386 で修正前の失敗を実測） |

---

## 1. native `CombinationsHelper::combinations()` が immutable な空配列で SIGBUS / SIGSEGV

### 症状

> **状況（2026-09-23 追記）:** upstream の 9574d5f72（Ondřej）で修正済み。こちらが出した同じ修正の #6549 は重複としてクローズされた。以下のうち、CI では落ちないという記述と、実行時に作った空配列なら落ちないという記述は誤りだったので訂正した。知見は [20260923-turbo-ext-immutable-refcount-knowhow-ja.md](20260923-turbo-ext-immutable-refcount-knowhow-ja.md) にまとめてある。

turbo 拡張を読み込んで `turbo-ext/tests/smoke.php` を実行すると、何も出力せずに落ちる。終了コードは macOS arm64 で 138（SIGBUS）、Linux と Windows で 139（SIGSEGV）。素の `phpstan/2.3.x` でも同じ。原因は smoke の `// ---- CombinationsHelper ----` 節の最初のケース `[]`。

### 再現

```bash
cd <phpstan/2.3.x の worktree>
composer install
make build-turbo
```

```php
<?php
// combi.php
require '<worktree>/turbo-ext/tests/activate-prefixed.php';
$n = \PHPStanTurbo\CombinationsHelper::combinations([]);
echo count(iterator_to_array($n, false)), " ok\n";
```

```bash
php -d extension=$PWD/turbo-ext/phpstan_turbo.so combi.php
```

| 入力 | 結果 |
|---|---|
| リテラル `[]`（変数経由でも同じ） | exit 138 |
| `array_slice([1], 1)` の結果（内部関数が返す共有の空配列） | exit 138 |
| `array_filter([1], fn () => false)` で作った空配列 | `1 ok` |
| `array_pop()` で空にした `[1]` | `1 ok` |
| PHP 版 `IterableHelper::combinations([])` | `1 ok` |

落ちるかどうかは、配列をリテラルで作ったか実行時に作ったかでは決まらない。決め手は、テーブルが共有の `zend_empty_array`（`IS_ARRAY_IMMUTABLE`）かどうか。`array_filter()` や `array_pop()` は PHP が確保したテーブルを返すので落ちないが、`RETURN_EMPTY_ARRAY` で返す内部関数の結果は落ちる。当初この表から「実行時に作った空配列なら落ちない」と結論したのは誤り。

### 原因（lldb で確認済み）

```text
stop reason = EXC_BAD_ACCESS (code=2, address=0x101402508)
x8 = 0x0000000101402508  php`zend_empty_array
Address: php[0x0000000101402508] (php.__DATA_CONST.__const + 1071104)
frame #0: php`zend_call_function + 616
frame #1: php`zend_call_known_function + 84
frame #2: phpstan_turbo.so`pt_type_call_static(...)
frame #3: phpstan_turbo.so`pt_register_combinations_helper()::$_0::__invoke(...)
```

`turbo-ext/src/CombinationsHelper.cpp:120-128`（`phpstan/2.3.x` の `ef16d6ead`）は引数を `zp::Ht` で `HashTable *` として受け、`ZVAL_ARR(&arraysZv, arrays)` で zval に包み直して `pt_type_call_static()` に渡している。`ZVAL_ARR` は型情報を `IS_ARRAY_EX`（refcounted フラグ付き）にするので、`arrays` が immutable（リテラル `[]` の実体は `zend_empty_array`）でも refcounted 扱いになる。`zend_call_function` が引数をコピーする際の `Z_TRY_ADDREF` が、`__DATA_CONST` に置かれた `zend_empty_array` の refcount に書き込んで落ちる。

この包み直しは C++ 版の拡張を導入した 73cf457be からあった。ネイティブ実装が配列を読むだけだった間は無害で、9f07e1802（2026-09-22）で `pt_type_call_static()` 経由で PHP の twin に処理を委ねるようになってから落ちるようになった。つまり退行を持ち込んだのは 9f07e1802。

同じリポジトリ内に正しい書き方がある。`turbo-ext/src/SimpleImpurePoint.cpp:146-147` は immutable な表を包むときに `ZVAL_ARR(&value, entry); Z_TYPE_INFO(value) = IS_ARRAY;` としている。

### 同じパターンの調査（結果）

`git grep -n "ZVAL_ARR(&" phpstan/2.3.x -- turbo-ext/src` で引数由来の表を包み直している箇所は次のとおり。

- `CombinationsHelper.cpp:124`: 包んだ zval を PHP 呼び出しに渡す。**該当**。
- `TrinaryLogic.cpp:261` と `:397`: 安全。`zv::ArrRef` で走査して要素をコールバックに渡すだけで、配列の refcount には触れない。`lazyAnd` / `lazyOr` / `lazyMaxMin` にリテラル `[]` を渡しても落ちないことを実測した。`lazyExtremeIdentity([])` は包む前に例外を投げる。
- 他の `ZVAL_ARR` / `adoptTable` / `copyOfTable` は、自前で作った表を包んでいるか、immutable をすでに処理している。
- **見落とし:** `ZVAL_ARR(&` の grep では拾えない `zv::Args::set(zval *, HashTable *)`（zv.h）にも同じバグがあった。`pt_mutating_scope_add_conditional_expressions()` から PHP のオーバーライドに共有の空配列が渡りうる。別セッションで #6558 として出し、upstream の #6561 に取り込まれた。詳細は knowhow のノートを参照。

### CI での状況（訂正）

当初は「CI では落ちていない」と書いていたが、誤りだった。ef16d6ead の `phar.yml`（run 35776936322）では、turbo の compile / phpize ジョブ 38 件すべて（Linux gnu/musl の x86_64 と arm64、macOS arm64、Windows。PHP 8.3〜8.6、NTS と ZTS）が smoke ステップで落ちていた。終了コードは macOS が 138（`Bus error: 10`）、それ以外が 139（`Segmentation fault`）。

「Commit PHAR」はこれらのジョブに依存するので、最後に成功した fa79f349e 以降、2.3.x の dev PHAR はコミットされていなかった。Linux aarch64 の `php:8.5-cli` コンテナ（PHP 8.5.8）でも、修正前は exit 139、修正を当てると `ALL OK` になることを確かめた。ただし smoke はログを出さないので、CI のログからは落ちたケースまでは特定できない。

### 修正方針の候補

1. 引数を `HashTable *` でなく zval として受け、そのまま渡す（包み直さない）。
2. 包み直した後に `if (GC_FLAGS(arrays) & GC_IMMUTABLE) Z_TYPE_INFO(arraysZv) = IS_ARRAY;` とする。汎用化するなら `zv.h` の規約に沿ったヘルパーにする（turbo-ext/CLAUDE.md は one-off ヘルパーを禁じている）。

採用されたのは 1。`zp::Arr`（`Z_PARAM_ARRAY`）で受けた zval をそのまま渡す。#6549 と upstream の 9574d5f72 は同じ変更だった。

### 検証

- 回帰テストは既存の smoke ケース `[]` そのもの。修正前に落ち、修正後に `ALL OK` になることを確認する。
- `turbo-ext/src` を変えるので、turbo-ext/CLAUDE.md の手順（strict build、smoke、signature-parity、walk-trace、拡張を読み込んだフルスイート）に従い、最後に `make bump-turbo` の別コミットを作る。

---

## 2. `assign.redundant` が符号付きゼロの正規化を誤検知

### 症状

bleedingEdge（`featureToggles.unusedVariable`）で有効になる `UnusedVariableRule` の `assign.redundant` が、`-0.0` を `0.0` に揃える代入を冗長と報告する。

```php
<?php
function f(float $f): float {
	if ($f === 0.0) {
		$f = 0.0; // -0.0 を +0.0 にする。冗長ではない
	}
	return $f;
}
function g(): float {
	$x = -0.0;
	$x = 0.0;
	return $x;
}
```

```neon
includes:
	- <worktree>/conf/bleedingEdge.neon
parameters:
	level: 9
```

素の `phpstan/2.3.x` での出力:

```text
negzero.php:4:Variable $f is assigned value 0.0 but it already has that value. [identifier=assign.redundant]
negzero.php:9:Value assigned to variable $x is never read. [identifier=assign.unused]
negzero.php:10:Variable $x is assigned value 0.0 but it already has that value. [identifier=assign.redundant]
```

`g()` では 9 行目を「読まれない」、10 行目を「冗長」と同時に言っており、自己矛盾している。

実行時は符号が観測できる（PHP 8.5.10）。

```text
$x = -0.0;  $x === 0.0            => true
(string) $x                        => "-0"
var_export($x, true)               => "-0.0"
fdiv(1, $x)                        => -INF
正規化後の (string) / fdiv(1, …)   => "0" / INF
```

### 原因

- `src/Analyser/ExprHandler/AssignHandler.php:2980` の `redundant()` は、代入先の現在の型が有限型 1 つで、それが右辺の型と `equals()` なら冗長とみなす（`:3014-3019`）。
- `ConstantFloatType::equals()`（`src/Type/Constant/ConstantFloatType.php:44-47`）は `===` で比べるので `0.0` と `-0.0` を区別しない。
- さらに `$f === 0.0` の絞り込みは `ConstantFloatType(0.0)` を作るが、実行時の値は `-0.0` もありうる。したがって符号を区別する比較にしても `f()` は直らない。
- C++ 版 `turbo-ext/src/AssignHandler.cpp:4598` の `redundant()` も同じ判定。
- ルールは `src/Rules/DeadCode/UnusedVariableRule.php:73-85`。導入は `b1c223beb`（Ondřej、2026-09-10）。定数オフセットへの拡張が `3c1d809e8`。

### 修正方針の候補

- `redundant()` で、値が float のゼロ（符号どちらでも）なら冗長としない。PHP と C++ の両方を同じに直す。
- float 以外で `equals()` が値の同一性より粗い型がないかも確認する（NaN は `equals()` で互いに等しいが、PHP からは区別できないので冗長扱いで問題ない見込み）。

### 検証

- `tests/PHPStan/Rules/DeadCode/` の `UnusedVariableRuleTest` に上の `f()` / `g()` を回帰データとして足し、修正前に誤検知が出ることを確認してから直す。
- C++ も変えるので turbo の手順と `make bump-turbo` が必要。

### 波及

`float-nan-2.3.x` / `float-range-type-2.3.x` は `FloatRangeType::fromInterval()` の `-0.0` 正規化 2 行をこの誤検知のために `phpstan-baseline.neon` に入れている（2 件、`assign.redundant`）。この修正が入ったら、その 2 件は「一致しない ignore」になるので削除する。

---

## 3. 32-bit ホストで `IntegerRangeType` の float 境界が不健全

### 内容

`53cc365e9`（Ondřej、2026-09-22）は `createAllSmallerThan()` と `createAllGreaterThanOrEqualTo()` の float 引数の判定を `$value >= PHP_INT_MAX` に変えた。コメントは「float は PHP_INT_MAX そのものを表せないので `(float) PHP_INT_MAX` が int 範囲の直後」としているが、これは 64-bit でだけ正しい。

- 64-bit: `(float) PHP_INT_MAX` は 2^63 に丸め上がる。`>=` で正しい。
- 32-bit: `PHP_INT_MAX` = 2147483647 は float で正確に表せる。`2147483647.0 >= PHP_INT_MAX` が真になり、
  - `createAllGreaterThanOrEqualTo(2147483647.0)` が `never` を返す。int の `PHP_INT_MAX` 自身はこの条件を満たすので**不健全**（`$i >= 2147483647.0` の真側が `never` になる）。
  - `createAllSmallerThan(2147483647.0)` は `int` を返す。正しくは `int<min, 2147483646>`（健全だが不精密）。
- C++ 版 `turbo-ext/src/IntegerRangeType.cpp` の `againstMax >= 0` も同じ判定。

### 対応状況（2026-09-23 追記）

別セッションが PR [phpstan/phpstan-src#6546](https://github.com/phpstan/phpstan-src/pull/6546)（2.3.x 向け、ブランチ `int-range-float-bound-32bit`）として提出済み。`3da205c21` と同じ方式で `createAllSmallerThan()` / `createAllGreaterThanOrEqualTo()` だけを直し、C++ も移植して bump 済み。2.2.x 向けの既存 PR #6542 にも同じ修正を追加した（PR 本文の更新はユーザー承認待ち）。経緯は memory `int-range-float-bound-32bit`。

**訂正**: 下の「32-bit PHP がない」は誤りだった。Docker Desktop を起動すれば `docker run --platform linux/386 php:8.5-cli`（PHP 8.5.10、`PHP_INT_SIZE` 4）が動く。修正前は `2147483647.0` の 2 行が失敗（`*NEVER*` と `int`）、実際の `bin/phpstan` でも `$i >= 2147483647.0` の真側が `*NEVER*` になり何も報告されないことが実測された。

### 検証状況（当初）

- ローカルに 32-bit PHP がなく、Apple `container` は arm64 / amd64 しか動かせないため、2.3.x 上では**未実測**（→ 上の訂正参照）。
- 同じ比較（当時の float-nan ブランチの `>= PHP_INT_MAX`）は linux/386 PHP 8.5.9 で実測済み。`20260822-float-nan-branch-adversarial-review.md` の「High: the `IntegerRangeType` fix assumes a 64-bit host while reading native `PHP_INT_MAX`」を参照。

### 修正方針の候補

float-nan ブランチの旧コミット `3da205c21`（`float-nan` / `float-range-type` ブランチに残っている）の方式をそのまま使える。

- `FLOAT_ABOVE_INT_MAX = PHP_INT_MAX + 1.0`（64-bit で 2^63、32-bit で 2^31、どちらも正確）と比較する。
- 変換しようとしている `ceil()` / `floor()` 後の値で判定する。
- テストは `PHP_INT_SIZE` で期待値を分岐する（`3da205c21` の `IntegerRangeTypeTest::dataFloatBounds()`）。このテストは 64-bit では upstream の実装でも 12 件すべて通ることを確認済み。
- C++ 版も同じ判定に直し、`make bump-turbo`。

### 方針上の論点

32-bit の解析ホストを PHPStan がサポートするのかは明文化されていない（phpstan/phpstan#11711、#14948）。`composer.json` に `php-64bit` の要件もない。PR では「32-bit ホストでは不健全、64-bit では挙動不変」と書き、サポート方針の判断は Ondřej に委ねる。

---

## 追加で見つかったもの（2026-09-23、#6546 のセッションから）

未対応。どちらも素の 2.3.x にある。

- **`$i < NAN` の絞り込みが間違っている**: 64-bit でも `$i < NAN` / `$i >= NAN` が `int<0, max>` / `int<min, -1>` に絞り込まれる（正しくは真側が `never`）。内部で `(int) NAN` の PHP 8.5 警告が出るが CLI では握りつぶされる。`float-range-type-2.3.x` では比較 trait の NaN ガードで直っている（真側 `*NEVER*`、偽側 `int`）が、`float-nan-2.3.x` では直っていない。20260902 の float-nan レビュー M4 が「このガードを float-nan 側に移すべき」としていた件そのもの。
- **32-bit で起動のたびに警告**: `OptimizedDirectorySourceLocatorFactory.php:418` が `The float 10000000000 is not representable as an int` を出す。

## ローカルで turbo を検証するときの注意（3 件共通）

- **pcov**: この Mac の php.ini は pcov を読み込む。pcov を外した ini を `PHPRC` で渡すと安全（smoke のクラッシュ自体は pcov と無関係だった）。
- **拡張が黙って無効になる**: `turbo-ext/src` を触るコミットを積むと、ビルドに焼き込まれる版（`git log -1 -- turbo-ext/src` の短縮 SHA）と `TurboExtensionEnabler::EXPECTED_EXTENSION_VERSION` がずれ、拡張は読み込まれるが無効になる。phpunit はそのまま PHP 版で緑になるので、`PHPStanTurbo\Runtime::isShadowing()` で有効を確かめるか、`make bump-turbo` 後に再ビルドしてから検証する。
- **smoke の CombinationsHelper ケース**: バグ 1 は upstream の 9574d5f72 で直った。それより前の 2.3.x を基にしたブランチでは、smoke は `[]` ケースで落ちて最後まで走らない（どのプラットフォームでも）。その場合は 9574d5f72 を含むよう rebase するか、一時的にその修正を当てて確認する（コミットしないこと）。
- **32-bit の実測**: Docker Desktop を起動して `docker run --platform linux/386 php:8.5-cli` を使う。
- **stash 禁止**: stash はすべての worktree で共有されるので、修正前後の比較は `git checkout HEAD~1 -- src/` などで行う。

## 関連

- 移植作業の記録: memory `float-range-type-research`、`20260822-float-range-type-spec-research-ja.md`
- 移植済みブランチ: `float-nan-2.3.x`（`phpstan-src-wt/float-nan-2.3.x`）、`float-range-type-2.3.x`（`phpstan-src-wt/float-range-type-2.3.x`）。どちらも未 push。
