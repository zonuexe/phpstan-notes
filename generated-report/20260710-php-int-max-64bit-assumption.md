# `PHP_INT_MAX` / `PHP_INT_MIN` の 32/64bit union と、64bit 固定オプトインの設計メモ

`PHP_INT_MAX` が `2147483647|9223372036854775807` という union になるため、これを起点とする計算の型が急速に煩雑化する問題の調査と設計。

きっかけの再現: https://phpstan.org/r/776cd264-4db3-4084-b0da-1204df330e61

```php
$max = PHP_INT_MAX;
$max_minus_one = $max - 1;
dumpType($max);                                  // 2147483647|9223372036854775807
dumpType($max_minus_one);                        // 2147483646|9223372036854775806
dumpType($max_minus_one / 2147483647 / 6);       // 0.16666666658905646|715827883
```

最後の行で `float|int` の union にまで崩れる。広く配布されるライブラリなら 32/64bit 両対応の想定は正しいが、業務アプリや社内ライブラリでは 32bit が到来しない環境も多い。

---

## 1. 現状の実装

[`src/Analyser/ConstantResolver.php:221`](https://github.com/phpstan/phpstan-src/blob/2.2.x/src/Analyser/ConstantResolver.php#L221)

```php
if ($resolvedConstantName === 'PHP_INT_MAX') {
    return PHP_INT_SIZE === 8
        ? new UnionType([new ConstantIntegerType(2147483647), new ConstantIntegerType(9223372036854775807)])
        : new ConstantIntegerType(2147483647);
}
if ($resolvedConstantName === 'PHP_INT_MIN') {
    // Why the -1 you might wonder, the answer is to fit it into an int :/ see https://3v4l.org/4SHIQ
    return PHP_INT_SIZE === 8
        ? new UnionType([new ConstantIntegerType(-9223372036854775807 - 1), new ConstantIntegerType(-2147483647 - 1)])
        : new ConstantIntegerType(-2147483647 - 1);
}
if ($resolvedConstantName === 'PHP_INT_SIZE') {
    return new UnionType([new ConstantIntegerType(4), new ConstantIntegerType(8)]);
}
```

### 重要な誤読ポイント

この `PHP_INT_SIZE === 8` は **解析対象の環境ではなく、PHPStan 自身を動かしている PHP のもの**である
(`use const PHP_INT_SIZE;` がファイル冒頭にある)。

つまりこの三項演算子は「ターゲットが 64bit か」を判定しているのではなく、
**「自分が `9223372036854775807` を int として構築できるか」を守っているだけ**。
32bit ホストで実行すると `new ConstantIntegerType(9223372036854775807)` は float 引数となり TypeError になるので、
三項演算子の遅延評価で回避している。`-9223372036854775807 - 1` の `- 1` も同じ理由(リテラル `-9223372036854775808` は
単項マイナス適用前に `9223372036854775808` がオーバーフローして float になる)。

したがって 32bit ホストで PHPStan を動かすと `PHP_INT_MAX` は暗黙に `2147483647` 単独になる。
一方 `PHP_INT_SIZE` 定数は無条件に `4|8`。**ターゲット環境の int 幅という概念がそもそもモデル化されていない。**

## 2. 現状の union は 32bit セマンティクスを模倣していない(実測)

### 決定的な一例: `PHP_INT_MAX + 1`

```php
dumpType(PHP_INT_MAX + 1);  // 2147483648|9.223372036854776E+18
```

`PHP_INT_MAX` を 1 つ超えた値は、**どのビルドでも必ず float** になる。
64bit では `9.2233720368548E+18`、32bit では `2147483648.0`。
ところが PHPStan は 32bit 側の枝に `int(2147483648)` を出す。**どの PHP ビルドでも決して観測できない型。**

`+ 1` という最小の演算ひとつで存在しない型が導出される。
union に「32bit も考慮している」という安全性はなく、むしろ嘘の型を生む分だけ有害。

### なぜそうなるのか

`bin/phpstan analyse -l 8` で確認:

| 式 | PHPStan の推論 | 実際の 32bit PHP |
|---|---|---|
| `3000000000` | `3000000000` (int) | **float** |
| `2147483647 + 1` | `2147483648` (int) | **float** |
| `PHP_INT_MAX + 1` | `2147483648\|9.223372036854776E+18` | **float のみ** |
| `9223372036854775807 + 1` | `9.223372036854776E+18` | (32bit ではリテラル自体が float) |

- 整数リテラルの評価: 64bit 決め打ち
- 算術オーバーフローの float 化: 64bit 決め打ち
- `IntegerRangeType` の境界: ホストの `PHP_INT_MAX` (= 実質 64bit)

**int 幅に依存するモデルのうち、定数 `PHP_INT_MAX` の値だけが 2 値になっている。**
その 2 値が周囲の 64bit 決め打ちなモデルと合流した瞬間、上のような存在しない型が出る。
union が実現しているのは「この定数だけ値が 2 通り」であって、32bit 環境の安全性ではない。

## 3. 逃げ道が存在しない

### 3-1. `PHP_INT_SIZE` で絞り込めない

3 定数は互いに独立した union として解決されるため、相関しない。実測 (default 設定):

```php
if (PHP_INT_SIZE === 4) {
	dumpType(PHP_INT_MAX);   // 2147483647|9223372036854775807   ← 2147483647 だけであってほしい
} elseif (PHP_INT_SIZE === 8) {
	//     ^^^^^^^^^^^^^^^^^^ "Strict comparison using === between 8 and 8 will always evaluate to true"
	dumpType(PHP_INT_MAX);   // 2147483647|9223372036854775807   ← 9223372036854775807 だけであってほしい
} else {
	dumpType(PHP_INT_MAX);   // 2147483647|9223372036854775807   ← *NEVER* であってほしい
}
```

**`PHP_INT_SIZE` は自分自身を絞り込む** (第 1 分岐が false なので `4|8` → `8` になり、第 2 の比較が always-true と報告される)。
にもかかわらず `PHP_INT_MAX` はそれに追随しない。ランタイムチェックによる絞り込みが効かず、
「32bit ならこう、64bit ならこう」と書き分けたコードのどちらの枝でも union のまま。

`if (PHP_INT_MAX === 9223372036854775807)` と定数自身を比較すれば絞り込めるが、全コードベースに書くのは非現実的。

→ この相関ナローイングは **今回のスコープ外**。6-1 節参照。

### 3-2. `dynamicConstantNames` も `stubFiles` も効かない

`ConstantResolver::resolveConstant()` は `resolvePredefinedConstant()` を先に呼んで早期 return するため、
ユーザー設定は predefined constant に一切届かない。実測:

```neon
parameters:
	dynamicConstantNames:
		PHP_INT_MAX: '9223372036854775807'
		PHP_INT_SIZE: '8'
```
→ 変化なし (`2147483647|9223372036854775807`, `4|8`)

これは [phpstan#14216](https://github.com/phpstan/phpstan/issues/14216) の後半で報告された挙動そのもの
(「`parameters.dynamicConstantNames` が無視される」)。issue は前半の誤解が解けた時点で close されており、
この部分は未修正のまま残っている。

### 3-3. `PHP_INT_MIN` は PHPDoc 型として表現できない

仮に 3-2 を直しても、`dynamicConstantNames` の値は PHPDoc 型文字列として `TypeStringResolver` に渡される。実測:

```php
/** @param 9223372036854775807 $a  @param -9223372036854775808 $b */
//                                        ^^^^^^^^^^^^^^^^^^^^ → int に劣化
/** @param -9223372036854775807 $c */  // → -9223372036854775807 (OK) */
/** @param int<-9223372036854775808, -9223372036854775808> $d */  // → int に劣化 */
```

`-9223372036854775808` は const 型としても int-range 境界としても表現できない
(1-2 節と同じ、単項マイナス適用前のオーバーフロー)。
**`dynamicConstantNames` 経由の解決は `PHP_INT_MIN` に対して原理的に不可能。**

## 4. 上流の温度感

[phpstan#11711](https://github.com/phpstan/phpstan/issues/11711) "32/64 bit int vs float"
(32bit では float になる計算を検出したい、という逆方向の feature request):

- @staabm: "I feel 32-bit php is super rare (I know it only from old rasperry-pi) and it doesn't matter for most apps."
- @ondrejmirtes: "I agree, 32bit is extremely rare and I don't know how to do this without being annoying for 64bit users."

→ close (not planned 相当)。**64bit 側に倒す提案は歓迎されやすい土壌がある。**

関連: [phpstan#5657](https://github.com/phpstan/phpstan/issues/5657) (`PHP_INT_MAX` が `positive-int` のデフォルト値にできない FP)。

## 5. 設計案の比較

### 案A: predefined constant にもユーザー設定を効かせる

`resolveConstant()` で `getConfiguredGlobalConstantType()` を `resolvePredefinedConstant()` より先に見る。
`PHP_INT_MAX` だけでなく `PHP_EOL` / `DIRECTORY_SEPARATOR` / `PATH_SEPARATOR` / `PHP_OS_FAMILY` / `PHP_SAPI`
といった同種の「プラットフォーム union」すべてに一括で抜け道ができる。#14216 の不満も解消。

- ✅ 実装コスト小、汎用性高、独立したバグ修正として価値がある
- ❌ **3-3 により `PHP_INT_MIN` を書けない**ので、本件の解にはならない
- ❌ 3 定数の相互整合はユーザー任せ (`PHP_INT_MAX: '9223...'` + `PHP_INT_SIZE: '4'` が書けてしまう)
- ❌ 設定名が `dynamic`ConstantNames なのに「固定する」用途で使うのは意味的にねじれている

### 案B: 専用パラメータ `phpIntSize` **(採用)**

```neon
parameters:
	phpIntSize: 8   # 8 | null (default)
```

- ✅ 3 定数を一箇所で整合させられる
- ✅ 値の構築が PHPDoc 文字列を経由しないので 3-3 を回避
- ✅ 将来 `IntegerRangeType` の境界や算術オーバーフローを int 幅に連動させる受け皿になる
- ✅ `phpVersion` (int | `{min,max}` | null) という既存の前例に形が揃う

**`4` は当面受け付けない。** 2 節のとおりリテラル評価も算術も 64bit のままなので、
`phpIntSize: 4` を許すと「`PHP_INT_MAX` は 2147483647 だが `2147483647 + 1` は `int(2147483648)`」という、
今の union の 32bit 枝と同じ嘘を、今度はユーザーが明示的に選んだ設定として抱え込むことになる。
32bit を本当にサポートするのは別の(はるかに大きい)仕事。schema を `anyOf(8)` にしておけば後方互換に `4` を足せる。

### 案C: composer.json の `require."php-64bit"` から自動推論 **(採用)**

- ✅ ゼロ設定。「64bit を仮定してよい」という主張が静的解析と実行時の両方で裏打ちされる
- ✅ `ComposerPhpVersionFactory` は既に `$composer['require']['php']` を読んでいるので追加コストが小さい
- ✅ **副次効果**: `php-64bit` は PHP のバージョン制約でもあるため、`"php-64bit": "^8.1"` だけを書いている
  プロジェクトは現在 phpVersion の自動推論を取りこぼしている。これを直すと int 幅と phpVersion 範囲が同時に改善する

優先順位: `phpIntSize` (NEON) > `require."php-64bit"` (composer.json) > 現状の union。

#### composer 本体のコードによる裏付け

以下すべて composer/composer `8fbad1355` (2026-07-06) を実地に確認。

**(1) `php-64bit` は `PHP_INT_SIZE === 8` のときだけ、`php` と同一のバージョンで登録される**

`src/Composer/Repository/PlatformRepository.php:156-176`:

```php
$php = new CompletePackage('php', $version, $prettyVersion);   // $version は PHP_VERSION を正規化したもの
$php->setDescription('The PHP interpreter');
$this->addPackage($php);
// ...
if ($this->runtime->getConstant('PHP_INT_SIZE') === 8) {
    $php64 = new CompletePackage('php-64bit', $version, $prettyVersion);   // ← 同じ $version
    $php64->setDescription('The PHP interpreter, 64bit');
    $this->addPackage($php64);
}
```

`tests/.../PlatformRepositoryTest.php:77` の期待値も `'php' => '7.2.31'` と `'php-64bit' => '7.2.31'` で一致。
ドキュメント `doc/articles/composer-platform-dependencies.md:38` も
「PHP (`php` and the **subtypes**: `php-64bit`, `php-ipv6`, `php-zts`, `php-debug`)」と明記。

**(2) 32bit 環境で `composer install` が確実に落ちる**

`tests/.../Fixtures/installer/outdated-lock-file-with-new-platform-reqs-fails.test:36` の期待出力:

```
- Root composer.json requires php-64bit ^25 but your php-64bit version (%s) does not satisfy that requirement.
```

32bit では `php-64bit` パッケージ自体が存在しないので、solver が要求を解決できず失敗する。

**(3) `php-64bit` は「バージョン制約」と「64bit 表明」を同時に担う**

`src/Composer/Autoload/AutoloadGenerator.php:828-837` (`getPlatformCheck()`):

```php
if (in_array($link->getTarget(), ['php', 'php-64bit'], true)) {
    $constraint = $link->getConstraint();
    if ($constraint->getLowerBound()->compareTo($lowestPhpVersion, '>')) {
        $lowestPhpVersion = $constraint->getLowerBound();
    }
}
if ('php-64bit' === $link->getTarget()) {
    $requiredPhp64bit = true;
}
```

生成される `vendor/composer/platform_check.php` が決定的:

| composer.json | 生成される check |
|---|---|
| `{"php-64bit": "^7.2.8"}` | `PHP_VERSION_ID >= 70208` **と** `PHP_INT_SIZE !== 8` の両方 |
| `{"php-64bit": "*"}` | `PHP_INT_SIZE !== 8` のみ |

(`tests/.../Autoload/Fixtures/platform/specific_php_64bit_required.php` および `php_64bit_required.php`)

**(4) 制約は「どちらか」ではなく「両方」— 実装を修正した**

(3) の `$lowestPhpVersion` は `php` と `php-64bit` の**下限の最大値**を取る。
両者は同一バージョンの仮想パッケージなので、solver 的には**制約の積集合**が効く。

当初の実装は `$composer['require']['php'] ?? $composer['require']['php-64bit']` と
「`php` があればそちらを優先」していたが、これは composer のセマンティクスと食い違う:

```json
{"require": {"php": "^8.1", "php-64bit": "^8.3"}}
```

- composer: 実効下限は 8.3
- 当初実装: 8.1 (誤り)

`Composer\Semver\Constraint\MultiConstraint::extractBounds()` は conjunctive のとき
下限の最大・上限の最小を取る(= 積集合)ことを確認したので、
`ComposerPhpVersionParser::parse()` を `non-empty-list<string>` 受け取りに変更し、
`MultiConstraint::create($constraints, true)` で積を取るようにした。
上の例で `int<80300, 80599>` になることを確認済み。

### 案D: 64bit を既定にして opt-out

4 節の温度感からすると将来的にはあり得る。2 節の「32bit 枝が自己矛盾している」という事実はこの案を後押しする。
ただし BC break なので bleedingEdge → 3.0 の経路。まず B+C を入れて実運用の反応を見るのが順当。

## 6. 決定

**案B + 案C を実装する。** 案A は独立した PR として別途。

上流に投げる際の切り口は「オプションが欲しい」ではなく
**「`PHP_INT_MAX + 1` は 32bit 枝で `int(2147483648)` になる。どの PHP ビルドでも観測できない型が、
`+ 1` ひとつで導出される。union が守っているものは実在しない」** から始める。
そこが認められれば `phpIntSize` は自然な帰結として通りやすい。

### 6-1. スコープ外: 定数間の相関ナローイング

3-1 節の以下を成立させる、という方向性もありうる:

```php
if (PHP_INT_SIZE === 4) {
	assertType('2147483647', PHP_INT_MAX);
} elseif (PHP_INT_SIZE === 8) {
	assertType('9223372036854775807', PHP_INT_MAX);
} else {
	assertType('*NEVER*', PHP_INT_MAX);
}
```

`PHP_INT_SIZE` / `PHP_INT_MAX` / `PHP_INT_MIN` を独立した union ではなく、
単一の隠れた「プラットフォーム」パラメータから導かれる**従属型**として扱うことになる。
PHPStan には定数どうしを相関させる仕組みが無いため、`TypeSpecifier` に
「`PHP_INT_SIZE` の `ConstFetch` を絞り込んだら他 2 定数の `ConstFetch` も同時に絞り込む」
という専用の相関を持ち込む必要があり、`MutatingScope` の式キー管理にも手を入れることになる。

**今回はスコープ外とする。** 理由:

- 2 節のとおり、32bit 側を絞り込めたところで周囲の算術・リテラル評価は 64bit 決め打ちのまま。
  相関ナローイングだけ入れても第 1 分岐の中で `PHP_INT_MAX + 1` が `int(2147483648)` になる問題は残る
- `phpIntSize: 8` を入れれば第 1 分岐が dead code として報告されるので、
  64bit 前提のプロジェクトにとっては相関ナローイングと同等の効果が得られる
- 相関が本当に必要なのは「32bit と 64bit を書き分けているライブラリ」だけで、
  それは opt-in しない側の人々。彼らにとっての正しい解は 32bit セマンティクスの完全実装 (案D の裏返し)であり、
  部分的な相関ナローイングは中途半端

将来やるなら、`phpIntSize` が導入する「ターゲットの int 幅」という概念がそのまま土台になる。

## 7. 実装方針

1. `conf/parametersSchema.neon`: `phpIntSize: schema(anyOf(8), nullable())`
2. `conf/config.neon`: `phpIntSize: null`
3. `src/Php/ConfiguredPhpIntSizeHelper` (新規, `#[AutowiredService]`)
   - `%phpIntSize%` → composer.json `require."php-64bit"` の有無 → `null`
   - `getIntSize(): ?int` (現状 `8|null`)
   - **composer.json の読み取りは featureToggle `composerPhp64Bit` (bleedingEdge) の下でのみ行う。**
     頼んでいないプロジェクトで有効になり `identical.alwaysFalse` 等が新たに出るため。
     `phpIntSize` の明示指定はトグルと無関係に常に効く
4. `src/Php/ComposerPhpVersionFactory::getComposerRequireVersions()`
   - `require.php` と `require."php-64bit"` の両方を集めて `list<string>` で返す
   - `php-64bit` の読み取りは同じ `composerPhp64Bit` トグルの下。
     `php-64bit` だけを書いたプロジェクトの phpVersion 範囲が狭まり、
     `PHP_VERSION_ID >= X` が `alwaysTrue` になりうるため、int 幅と同じ扱いにする
   - `ComposerPhpVersionParser::parse()` が `MultiConstraint::create($constraints, true)` で積集合を取る
     (5-案C-(4) 参照)
5. `src/Analyser/ConstantResolver`
   - `PHP_INT_MAX` / `PHP_INT_MIN` / `PHP_INT_SIZE` の 3 箇所
   - **ホストの `PHP_INT_SIZE` ガードは残す**。32bit ホストでは int64 定数を構築できないので
     `phpIntSize: 8` を honor できない (1 節参照)。ここはコメントを残す
6. `src/Analyser/ConstantResolverFactory` で新ヘルパを注入

### テスト

- 既存の `tests/PHPStan/Analyser/data/predefined-constants-{64,32}bit.php` はホスト依存で分岐している
  (`NodeScopeResolverTest.php:113`)。ここは触らない
- 新規に `TypeInferenceTestCase` + `getAdditionalConfigFiles()` で `phpIntSize: 8` を与えるテストを追加
  (`DynamicConstantsTest` と同じ形)
- composer.json 由来の推論は `ConfiguredPhpIntSizeHelper` の単体テスト + fixture composer.json
- result cache は `projectConfig` 全体をハッシュしているので新パラメータ追加で自動的に無効化される

## 8. 実装結果 (2026-07-10, branch `php-int-size`)

7 節の方針どおり実装し、動作確認済み。

### 効果

| 式 | default | `phpIntSize: 8` |
|---|---|---|
| `PHP_INT_MAX` | `2147483647\|9223372036854775807` | `9223372036854775807` |
| `PHP_INT_MIN` | `-9223372036854775808\|-2147483648` | `-9223372036854775808` |
| `PHP_INT_SIZE` | `4\|8` | `8` |
| `($max - 1) / 2147483647 / 6` | `0.16666666658905646\|715827883` | `715827883` |
| `PHP_INT_MAX + 1` | `2147483648\|9.223372036854776E+18` | `9.223372036854776E+18` |

最下段が 2 節で指摘した「存在しない値」の解消。オプトインすると 64bit セマンティクスとして正しい `float` 単独になる。

### composer.json 由来の推論

`require."php-64bit"` があれば `phpIntSize: 8` 相当。副次効果も実証:

| composer.json | 実装前 | 実装後 (既定) | 実装後 (bleedingEdge) |
|---|---|---|---|
| `{"php": "^8.1"}` | `int<80100, 80599>` | `int<80100, 80599>` | `int<80100, 80599>` |
| `{"php-64bit": "^8.1"}` | `int<50207, 80599>` | `int<50207, 80599>` | `int<80100, 80599>` |
| `{"php": "^8.1", "php-64bit": "^8.3"}` | `int<80100, 80599>` | `int<80100, 80599>` | `int<80300, 80599>` |

既定では composer.json 由来の推論は一切起きず、完全に BC。CLI で実測済み。

1 行目以外はどちらも改善。`php-64bit` だけを書いているプロジェクトは phpVersion 推論を取りこぼしていた。
3 行目は制約の積集合 (5-案C-(4))。

### オプトインの副作用(意図的)

```php
if (PHP_INT_SIZE === 4) { /* 32bit fallback */ }
// phpIntSize: 8 → "Strict comparison using === between 8 and 4 will always evaluate to false"
```

32bit フォールバックを持つコードは opt-in すべきでない、という区別が型レベルで現れる。
これはオプトインである理由そのものなので、望ましい挙動。

### 検証

- `phpIntSize: 4` は schema が拒否: `The item 'parameters › phpIntSize' expects to be 8|null, 4 given.`
- 制約の積集合は `composer-php-64bit-narrower` fixture (`php: ^8.1` + `php-64bit: ^8.3` → 80300) で担保
- 新規テストが修正前に正しい理由で落ちることを確認済み
  - `PhpIntSize8Test`: `getIntSize()` を `null` 固定にすると 4/4 failure
  - `ComposerPhpVersionFactoryTest`: `php-64bit` 読み取りを戻すと `require php-64bit only` が `null !== 80100` で失敗
- `NodeScopeResolverTest` (1743 tests) / `tests/PHPStan/Php` / `tests/PHPStan/DependencyInjection` / self-analysis すべて green
- `#[AutowiredService]` の新規追加なので `composer dump-autoload` (composer-attribute-collector) が必要

### 変更ファイル

| ファイル | 内容 |
|---|---|
| `conf/parametersSchema.neon` | `phpIntSize: schema(anyOf(8), nullable())`, `featureToggles.composerPhp64Bit: bool()` |
| `conf/config.neon` | `phpIntSize: null`, `featureToggles.composerPhp64Bit: false` |
| `conf/bleedingEdge.neon` | `featureToggles.composerPhp64Bit: true` |
| `src/Php/ConfiguredPhpIntSizeHelper.php` | 新規。NEON → composer.json → null |
| `src/Php/ComposerPhpVersionFactory.php` | `require.php` と `require."php-64bit"` の両方を収集 |
| `src/Php/ComposerPhpVersionParser.php` | `parse()` が制約リストを受け取り `MultiConstraint` で積を取る |
| `src/Analyser/ConstantResolver.php` | 3 定数。ホストの `PHP_INT_SIZE` ガードは維持 |
| `src/Analyser/ConstantResolverFactory.php` | ヘルパ注入 |
| `src/Testing/PHPStanTestCase.php` | 同上 (コンテナから取得するのでテストの追加 NEON が効く) |
| `src/DependencyInjection/ValidateIgnoredErrorsExtension.php` | 同上 (`new ConfiguredPhpIntSizeHelper(null, [])`) |

### 残作業

- 案A (predefined constant がユーザー設定を踏み潰す件) は未着手。別 PR
- 定数間の相関ナローイング (6-1 節) はスコープ外。`phpIntSize` が土台になる
- phpstan/phpstan 側の config reference ドキュメント追記
- 上流に issue を立てるか、PR を直接出すか要判断 (2 節の `PHP_INT_MAX + 1` が説得の起点)

## 9. 参考

- 実装: `src/Analyser/ConstantResolver.php:221-237`
- 既存の類似機構: `src/Php/ConfiguredPhpVersionRangeHelper.php`, `src/Php/ComposerPhpVersionFactory.php`
- `dynamicConstantNames` の型指定形式: commit `9abd515b8` ("Validate `define()` and `const` values against explicit types in `dynamicConstantNames`")
- issues: [#11711](https://github.com/phpstan/phpstan/issues/11711), [#14216](https://github.com/phpstan/phpstan/issues/14216), [#5657](https://github.com/phpstan/phpstan/issues/5657)
- Composer platform packages: https://getcomposer.org/doc/01-basic-usage.md
- composer/composer `8fbad1355` (2026-07-06) の裏付け箇所:
  - `src/Composer/Repository/PlatformRepository.php:39` — `PLATFORM_PACKAGE_REGEX` に `php-64bit`
  - `src/Composer/Repository/PlatformRepository.php:156-176` — `php` と `php-64bit` の登録 (同一 `$version`)
  - `src/Composer/Autoload/AutoloadGenerator.php:828-837, 914-922` — `getPlatformCheck()`
  - `tests/Composer/Test/Autoload/Fixtures/platform/{specific_php_64bit_required,php_64bit_required}.php`
  - `tests/Composer/Test/Repository/PlatformRepositoryTest.php:77` — `php` と `php-64bit` が同一バージョン
  - `tests/Composer/Test/Fixtures/installer/outdated-lock-file-with-new-platform-reqs-fails.test:36`
  - `doc/articles/composer-platform-dependencies.md:38` — 「`php` and the subtypes」
- `Composer\Semver\Constraint\MultiConstraint::extractBounds()` — conjunctive は下限の最大・上限の最小
