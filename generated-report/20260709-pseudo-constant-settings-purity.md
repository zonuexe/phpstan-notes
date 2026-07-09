# set-once グローバル設定を疑似定数として扱う純粋性解析(設計メモ)

`@pure-unless-parameter-passed`(PR #6018)の functionMetadata 網羅調査から派生した設計メモ。
optional by-ref out パラメータを持つビルトイン関数を分類する過程で、「純粋性がグローバル状態に依存する」関数群の扱いが論点として浮上した。

関連: [20260703-effect-system-design.md](20260703-effect-system-design.md), [20260703-impure-points.md](20260703-impure-points.md), [20260706-pr3482-handoff.md](20260706-pr3482-handoff.md)

---

## 1. 背景

`@pure-unless-parameter-passed` は「optional な by-ref out パラメータを渡さなければ pure」という関数を対象とする(str_replace の `$count`、preg_match の `$matches` 等)。
by-ref out パラメータを持つビルトイン 79 関数を網羅分類したところ、5 件が FITS。うち `preg_filter`(preg_replace と同一シグネチャの純粋な正規表現置換)は PR #6018 に追加した。

残りの候補は「純粋計算に見えるが、結果が何らかのグローバル状態に依存する」ため保留となり、その依存の性質を精査すると **2 種類**に分かれることが分かった。

## 2. 依存の性質による切り分け:(A) ビルド定数 vs (B) 実行時可変

PHPStan の purity(副作用の有無 / result-unused 判定 / `@phpstan-pure` 文脈での許可)で本質的に効くのは、**1 回の実行内で決定的か・副作用が無いか**である。この観点で ICU/グローバル依存の関数は次の 2 群に分かれる。

### (A) ビルド時固定・実行時不変(実質的に定数)

| 関数 | 依存先 | 実行時に PHP から変更可能か |
|---|---|---|
| `idn_to_ascii` / `idn_to_utf8` | ICU の IDNA/UTS46 テーブル + 明示的 `$flags`/`$variant` 引数 | 不可(該当グローバルが無い) |
| `IntlTimeZone::getCanonicalID` | ICU コンパイル済み tz DB | 不可 |

`PHP_INT_SIZE` が 32/64bit ビルドで異なるのと同じ「ビルド定数」に相当する。実行中は不変で、同一引数なら同一結果 = **決定的かつ副作用なし** → purity の観点では pure 扱いが妥当。
当初の分類(調査エージェント)は保守的すぎ、`idn_to_ascii`/`idn_to_utf8` を IMPURE としていたが、この切り分けでは `getCanonicalID` と同じ **FITS 相当**に格上げできる。

### (B) 実行時可変グローバルに依存(真に非 pure)

| 関数 | 可変グローバル | PHP からの変更手段 |
|---|---|---|
| `mb_ereg` / `mb_eregi` | 内部エンコーディング | `mb_regex_encoding()` |
| `IntlDateFormatter::parse` / `::localtime` | 既定タイムゾーン | `date_default_timezone_set()` |
| `grapheme_extract`(WORD/SENTENCE モード) | 既定ロケール | `Locale::setDefault()` |

「無関係な別コードの呼び出し次第で、同一引数の結果が変わり得る」ため purity 違反。PR #6018 ではこれらを impure(安全側)のまま据え置くのが正しい。

### dev/prod 環境差のリスクについて

(A) には「開発環境と実行環境で ICU バージョンが違うと結果が変わる」リスクがあるが、これは **値(value)の移植性**の問題であって、**purity の問題ではない**。
`idn_to_ascii($d);`(戻り値破棄)は ICU バージョンが違っても「副作用が無く戻り値も使わない」点は不変なので、result-unused 判定・pure 文脈での許可は正当。差が出るのは「使われる値」だけで、破棄されるなら影響ゼロ。この意味で purity 分類とは直交する。

## 3. アイデア:pseudo constant setting(set-once グローバルのオプトイン宣言)

(B) の可変グローバルは、実運用の多くのアプリでは**ブートストラップ時に一度だけ設定し、以降不変**である(意図としては (A) のビルド定数と同じ扱いにしたい)。
そこで「これらのグローバルは set-once で固定する」とユーザーが明示宣言できる仕組みがあれば、PHPStan は該当グローバルを「その実行内で不変」とみなし、(B) の関数群を pure-unless-parameter-passed として扱える。

- **オプトイン必須**: デフォルトで仮定すると、実際に途中で汚染された場合に誤った purity 判定を出す。宣言した人だけが恩恵を受ける opt-in が正しい。
- **PHPStan の既存概念との親和性**: 解析の前提を config で明示するフラグ(`treatPhpDocTypesAsCertain` 等)や、環境依存値を宣言する `dynamicConstantNames` の系譜に収まる。
- **スコープ**: PR #6018 の対象外。phpstan/phpstan の別 issue(feature request)として提案する。
  - 想定タイトル: "Opt-in: treat set-once runtime globals (mb encoding, default timezone, ICU locale) as constant for purity analysis"

## 4. 解決手段のレイヤ分け:拡張で届く範囲と core が必要な範囲

候補関数との相互作用を考えると、purity の扱いは単一の手段では完結せず、**複数レイヤ**に分かれる。第三者拡張(PHPStan ビルトインではない)で解ける部分と、core にしか届かない部分がある。

- **DynamicFunctionReturnTypeExtension**: 引数に応じて**戻り値の型**を精緻化できる(例: `idn_to_ascii` の返り値型)。しかし purity / 副作用の分類(impurePoints、hasSideEffects)には**触れられない** → 「pure-unless-passed」化はできない。
- **FuncCall ノードに対するカスタム Rule**: 特定の関数呼び出しに対して独自の診断(例: 「pure 文脈で by-ref を渡した」)を**追加で報告**できる。ただし core の impurePoint / purity エンジン(`@phpstan-pure` 本体チェックや result-unused 判定を駆動する部分)とは統合されず、あくまで別建ての lint に留まる。
- **core にしか届かない領域**: ビルトイン関数の purity 分類そのもの(functionMetadata の `hasSideEffects` / `pureUnlessParameterPassedParameters` → `SimpleImpurePoint` / purity エンジン)。第三者拡張には「このビルトインを pure-unless-passed とみなせ」と宣言するフックが無い。**pseudo constant setting のような (B) 対応も、この core 領域に属する**ため、拡張だけでは解決しない。

まとめ: 候補関数の一部は「戻り値型の精緻化(拡張)」や「特定パターンの lint(拡張)」で部分的に扱えるが、**purity 分類の本丸(result-unused / pure 文脈許可 / set-once グローバルの定数視)は core またはメタデータ側の対応が必須**。この境界を意識して、拡張で足りる要求と core feature が要る要求を分けて提案するとよい。

## 5. アクションアイテム(将来)

1. **(A) の格上げ**: `idn_to_ascii` / `idn_to_utf8` / `IntlTimeZone::getCanonicalID` を FITS として `functionMetadata` に追加してよいか、staabm/ondrejmirtes にポリシー確認(「ビルド固定 ICU テーブル依存を pure とみなすか」)。合意が取れれば preg_filter と同手順で追加。
2. **(B) の pseudo constant setting**: opt-in feature request を phpstan/phpstan に別 issue で提案。対象関数リスト((B) の表)+ 汚染リスクゆえ opt-in、を明記。
3. **レイヤ分けの明文化**: 「拡張で届く範囲 / core が要る範囲」の境界を、上記提案時の説明材料として流用。

## 参考: 網羅調査の全体件数(79 関数)

| 分類 | 件数 | 内容 |
|---|---|---|
| REQUIRED-BYREF(対象外) | 12 | out param が必須=常に渡される |
| ALREADY-HANDLED | 11 | str_replace 等の登録済み + flock/apcu_* の `hasSideEffects: true` |
| IMPURE | 51 | プロセス・ネットワーク・IPC・キャッシュ・I/O・グローバル状態 |
| FITS | 5 | preg_filter(追加済)+ grapheme_extract / Spoofchecker::areConfusable / ::isSuspicious / IntlTimeZone::getCanonicalID |
