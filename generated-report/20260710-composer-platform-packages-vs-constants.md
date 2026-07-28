# Composer の仮想パッケージと PHP 定数の対応、および OS 表明の不在

`php-64bit` から `PHP_INT_SIZE` を機械的に確定できるなら、同じ手が使える定数が他にもあるのではないか、という問いの調査記録。

**結論: `php-64bit` と同格のものは無い。とくに Composer は OS (Windows / Unix) を表明する手段を持たない。**

関連: [20260710-php-int-max-64bit-assumption.md](20260710-php-int-max-64bit-assumption.md), [20260710-issue-draft-int-width-semantics.md](20260710-issue-draft-int-width-semantics.md)

調査対象: composer/composer `8fbad1355` (2026-07-06)

---

## 1. Composer が読む定数は 16 個

`PlatformRepository::getConstant()` の呼び出しを全部拾った結果。

```
GD_VERSION              GMP_VERSION             ICONV_VERSION           INTL_ICU_VERSION
LIBXML_DOTTED_VERSION   LIBXSLT_DOTTED_VERSION  OPENSSL_VERSION_TEXT    PCRE_VERSION
PGSQL_LIBPQ_VERSION     PHP_DEBUG               PHP_INT_SIZE            PHP_VERSION
PHP_ZTS                 RD_KAFKA_VERSION        SODIUM_LIBRARY_VERSION  ZLIB_VERSION
```

## 2. 仮想パッケージとの対応

`PLATFORM_PACKAGE_REGEX` (`PlatformRepository.php:39`):

```
{^(?:php(?:-64bit|-ipv6|-zts|-debug)?|hhvm|(?:ext|lib)-[a-z0-9](?:[_.-]?[a-z0-9]+)*|composer(?:-(?:plugin|runtime)-api)?)$}iD
```

| 仮想パッケージ | 決定条件 | `platform_check.php` で実行時に表明 |
|---|---|---|
| `php` | `PHP_VERSION` | あり (`PHP_VERSION_ID >= X`) |
| **`php-64bit`** | **`PHP_INT_SIZE === 8`** | **あり (`PHP_INT_SIZE !== 8`)** |
| `php-zts` | `PHP_ZTS` が truthy | なし |
| `php-debug` | `PHP_DEBUG` が truthy | なし |
| `php-ipv6` | `AF_INET6` の定義、または `inet_pton('::')` | なし |
| `ext-*` | `extension_loaded()` | あり |
| `lib-*` | 各種バージョン定数 | なし |

`AutoloadGenerator::getPlatformCheck()` が `vendor/composer/platform_check.php` に書き出すのは `php` / `php-64bit` / `ext-*` のみ。

**`php-64bit` は「単一の定数比較で決まり、かつ install 時と実行時の両方で検査される」唯一の仮想パッケージ。**

## 3. OS の表明は存在しない (本調査の主眼)

- `PLATFORM_PACKAGE_REGEX` に `os-*` 相当の枠が無い
- `PlatformRepository` は `PHP_OS` / `PHP_OS_FAMILY` / `DIRECTORY_SEPARATOR` を一切読まない
- `Composer\Util\Platform::isWindows()` は内部ユーティリティとして存在するが、`PlatformRepository` からは使われず、`require` 可能なパッケージとして公開されていない

したがって次の定数は、composer.json から機械的に確定**できない**:

| 定数 | PHPStan の現在の型 |
|---|---|
| `DIRECTORY_SEPARATOR` | `'/'\|'\\'` |
| `PATH_SEPARATOR` | `':'\|';'` |
| `PHP_EOL` | `'\n'\|'\r\n'` |
| `PHP_OS_FAMILY` | `'BSD'\|'Darwin'\|'Linux'\|'Solaris'\|'Unknown'\|'Windows'` |

これらを固定したいユーザーに残された道は `dynamicConstantNames` の明示型のみ。
ただし現状 `resolveConstant()` が `resolvePredefinedConstant()` を先に呼んで早期 return するため効かない
([#14216](https://github.com/phpstan/phpstan/issues/14216) の後半)。
ブランチ `dynamic-constant-names-predefined` に修正あり (未コミット)。

→ **`php-64bit` のような「宣言が実行時にも裏打ちされる」構図は int 幅に固有であり、OS 依存定数には移植できない。**
`phpIntSize` を composer.json から推論する設計は、他のプラットフォーム union には一般化しない。

## 4. 同格に近い候補: `php-zts` / `php-debug` — 配線しない

形は `php-64bit` と同じ。`require` すれば定数値が確定する。

| 定数 | 現在の推論 | 確定できる値 |
|---|---|---|
| `PHP_ZTS` | `0\|1` | `1` |
| `ZEND_THREAD_SAFE` | `bool` | `true` |
| `PHP_DEBUG` | `0\|1` | `1` |
| `ZEND_DEBUG_BUILD` | `bool` | `true` |

**それでも価値が無い理由:**

1. `php-64bit` が特別なのは `PHP_INT_MAX` が**算術に流れ込む**から。`PHP_INT_MAX + 1` が存在しない型を生む。
   `PHP_ZTS` は葉であり、`0|1` のままでも下流の型を汚さない。union を潰す利益がほぼゼロ
2. 代償だけは同じ。`if (PHP_ZTS === 0)` の分岐が dead code になる
3. `platform_check.php` が表明しないので保証が一段弱い。`composer install` を通した後で非 ZTS 環境にデプロイしても実行時には何も起きない
4. `php-debug` を `require` するプロジェクトは実在しない

`php-ipv6` は `AF_INET6` の**存在**であって値ではないので型の話にならない。`ext-*` も同様。

## 5. 副産物: `lib-*` のバージョン定数がホストの値を漏らしている

Composer が `lib-*` の版として読む定数のうち、PHPStan の `dynamicConstantNames` 既定リストにも
`resolvePredefinedConstant()` にも入っていないものが、**解析マシンの値をそのまま型にしている**。実測 (筆者環境):

```
GD_VERSION              → '2.3.3'
INTL_ICU_VERSION        → '78.3'
GMP_VERSION             → '6.3.0'
ICONV_VERSION           → '1.11'
ZLIB_VERSION            → '1.2.12'
SODIUM_LIBRARY_VERSION  → '1.0.22'
LIBXSLT_DOTTED_VERSION  → '1.1.35'
OPENSSL_VERSION_TEXT    → 'OpenSSL 3.6.3 9 Jun 2026'
```

一貫していない:

| 定数 | `dynamicConstantNames` | `resolvePredefinedConstant()` | 結果 |
|---|---|---|---|
| `LIBXML_DOTTED_VERSION` | list | あり | `non-falsy-string` |
| `LIBXSLT_DOTTED_VERSION` | なし | なし | `'1.1.35'` (ホスト値) |
| `PCRE_VERSION` | なし | あり | `non-falsy-string` |
| `GD_VERSION` | なし | なし | `'2.3.3'` (ホスト値) |

`if (version_compare(GD_VERSION, '2.1', '>=')) { ... }` は筆者のマシンでは常に true に畳まれ、
else 側が dead code になる。CI と手元で解析結果が食い違いうる。

**独立した issue にする価値がある。** 修正は既定リストに 8 個足すだけ。
`php-64bit` の件とは無関係。

## 6. 現状のブランチ / issue / PR

| | |
|---|---|
| [phpstan#14948](https://github.com/phpstan/phpstan/issues/14948) | int 幅の意味論を整理する issue |
| [phpstan-src#6030](https://github.com/phpstan/phpstan-src/pull/6030) | `phpIntSize` + `composerPhp64Bit` (bleedingEdge) |
| [phpstan#14946](https://github.com/phpstan/phpstan/issues/14946) / [phpstan-src#6028](https://github.com/phpstan/phpstan-src/pull/6028) | `abs(PHP_INT_MIN)` internal error |
| [phpstan#14947](https://github.com/phpstan/phpstan/issues/14947) / [phpstan-src#6029](https://github.com/phpstan/phpstan-src/pull/6029) | `-PHP_INT_MIN` 誤推論 |
| ブランチ `dynamic-constant-names-predefined` | 明示型が predefined constant を上書きする修正 (未コミット) |
| 未着手 | `lib-*` バージョン定数のホスト値漏れ (5 節) |
