# turbo-ext: immutable な値と refcount の扱い、再現と検証のノウハウ

作成日: 2026-09-23。`zv::Args::set(zval *, HashTable *)` の SIGBUS を直した作業（phpstan/phpstan-src#6558）で蓄積した、turbo-ext（C++ ネイティブ拡張）まわりの知識をまとめる。兄弟バグ `CombinationsHelper::combinations([])` の件は [20260923-float-range-2.3x-found-bugs-handoff.md](20260923-float-range-2.3x-found-bugs-handoff.md) の 1 を参照。その件を直した作業（phpstan/phpstan-src#6549）で得た知識も追記してある（1 の共有の空配列と `zp::Arr`、3 の監査の観点、5 の Linux での再現、6 の CI の調べ方、7 の PR 本文と署名行）。

## 結末

- #6558 はマージされずにクローズされた。メンテナが「参考にした」として phpstan/phpstan-src#6561（`Stop turbo-ext from touching refcounts of immutable arrays and interned strings`）を開いた。
- #6561 は #6558 のコミットをそのまま 1 本目に採用し、同じ種類のバグの修正を追加している。内訳は、interned string への `Z_ADDREF_P`、`VariableLivenessResolver` の防御的な書き換え、`makeOffsetRequired()` の `HT_ASSERT_RC1` 違反の 3 つ。
- 兄弟の `combinations([])` の件は upstream が 9574d5f72 で直した。こちらも同じ修正を phpstan/phpstan-src#6549 として出していたが、重複としてクローズされた。Ondřej は 9/22 22:47（+0200）に同じ修正をローカルでコミット済みで、push が #6549 の提出より後になっていた。修正の中身（`zp::Ht` + `ZVAL_ARR` をやめ、`zp::Arr` で受けた zval をそのまま渡す）は一致していた。
- 以下の「見落としたこと」は #6561 との差分から得た教訓。

## 1. Zend の共有・読み取り専用の値

### immutable な配列

- PHP の `[]` リテラルと `ZVAL_EMPTY_ARRAY` が指す `zend_empty_array` は `.rodata` にあり、`GC_FLAGS(ht) & IS_ARRAY_IMMUTABLE` が立っている。opcache が不変にした定数配列も同じ扱い。
- `ZVAL_ARR(zv, ht)` は flags を見ずに refcounted な type info（`IS_ARRAY_EX`）を設定する。この zval を `zend_call_function()` に渡すと、引数コピーの `Z_TRY_ADDREF` が refcount を書き換え、読み取り専用メモリへの書き込みになる。
  - 症状: macOS arm64 は SIGBUS（exit 138）、Linux は SIGSEGV（exit 139）
  - lldb では `zend_call_function + N` で `EXC_BAD_ACCESS (code=2)` として止まる
- 正しい書き方: `ZVAL_ARR(slot, ht); if (GC_FLAGS(ht) & IS_ARRAY_IMMUTABLE) Z_TYPE_INFO_P(slot) = IS_ARRAY;`
- 所有権ごと受け取るなら `zv::Arr::adoptTable()`、借用して addref するなら `zv::Arr::copyOfTable()` を使う。どちらもこの分岐を内蔵している。生の `ZVAL_ARR` と `Z_ADDREF` を手で組み合わせない。
- `zend_array_dup()` は空の入力でも必ず新しい refcount 1 のテーブルを返すので、この問題は起きない。
- 共有の空配列はリテラル以外からも来る。`RETURN_EMPTY_ARRAY` を使う内部関数の結果（例: `array_slice([1], 1)`）も `zend_empty_array` で、修正前の `combinations()` はこれでも exit 138 で落ちた。落ちないのは PHP が確保してから空にした配列（例: `array_pop()` で空にした `[1]`）。ハンドオフ文書の「実行時に作った空配列なら落ちない」は、`array_filter()` の結果だけを見た誤りだった。判定基準は作り方ではなく `IS_ARRAY_IMMUTABLE` が立っているかどうか。
- 受け取った配列引数をそのまま PHP の呼び出しに渡すだけなら、`zp::Ht` で受けて包み直すより、`zp::Arr`（`Z_PARAM_ARRAY`）で受けて zval ごと渡すのが最も簡単。型情報は呼び出し元のまま正しく、addref も増えない。型の違う引数に対する `TypeError` のメッセージは両者で同じ（どちらも `Z_EXPECTED_ARRAY`）。upstream の 9574d5f72 もこの形。

### interned string（#6561 で指摘された、こちらの見落とし）

- PHP のコードが作った文字列リテラル（例: `new Variable('foo')` の `'foo'`）は interned string になる。opcache が有効なら共有メモリ上にある。
- `Z_ADDREF_P` は interned かどうかを見ずに refcount を書き換える。借用した文字列には `Z_TRY_ADDREF_P` か `zend_string_copy()` を使う。`ZVAL_STR` は interned を判定するので安全。
- 検出するには `-d opcache.enable_cli=1 -d opcache.protect_memory=1` で実行する。共有メモリが書き込み保護され、誤った addref が即座に落ちる。ただし CI はこの設定で走らないので、テストで回帰は捕まえられない。

### debug ビルドの `HT_ASSERT_RC1`

- refcount が 2 以上のテーブルに書き込むと、debug ビルドの PHP では `HT_ASSERT_RC1` で落ちる。リリースビルドでは結果が正しく見えるので気づけない。
- #6561 の `makeOffsetRequired()` は、スナップショットを取るつもりでテーブルを共有したまま、その共有テーブルから削除しながら反復していた。スナップショットを取ってから `separate()` する順序に直された。

## 2. turbo-ext 側の道具と経路

- `zv::Args{...}` は PHP 呼び出しの引数列で、何も addref しない。
  - `const zval *` は `ZVAL_COPY_VALUE` でコピーされる。
  - `zend_object *` / `zend_string *` / `HashTable *` は借用のまま格納される。#6558 以降、`HashTable *` は immutable なら非 refcounted で包まれる。
- `PT_MS_INHERITS(scope, "lcname")` は、スコープのクラスがネイティブ MutatingScope そのものか、そのメソッドをオーバーライドしていないかを判定する。どちらでもなければ（PHP サブクラスがオーバーライドしていれば）、`pt_type_call()` で PHP のメソッドを名前で呼ぶ。潜在バグの多くはこの名前経由の経路に隠れている。
- prefixed モード（smoke.php / scope-family.php / initializer-escape.php）では、PHP の twin スコープはネイティブの `pt_ce_mutating_scope` を継承していない。そのため、ただの PHP スコープでも全呼び出しが名前経由の経路を通る。
- 借用テーブルを `ZVAL_ARR` で zval に包み、`zv::ArrRef` で読むだけ（addref しない）なら、immutable でも安全。`TrinaryLogic::lazyMaxMin([])` などで実測済み。

## 3. 監査の手法

- **型付きの呼び出し元を列挙する:** 疑わしいオーバーロード（例: `set(zval *, HashTable *)`）を一時的に `= delete` にして全翻訳単位をコンパイルすると、エラーの出た箇所が呼び出し元の一覧になる。#6558 ではこれで `MutatingScope.cpp` の 1 箇所だけと確定できた。
- **並列ビルドのログ:** `make -j` の出力は混ざって読めないので、`-fsyntax-only` で 1 翻訳単位ずつ個別のログに出す。macOS 標準の make 3.81 には `--output-sync` がない。`xargs -I{}` にコマンドを埋め込むと長すぎて失敗するので、小さなシェルスクリプトにして `xargs -P10 -n1` で回す。

  ```sh
  #!/bin/sh
  # one.sh — 引数の .cpp を構文チェックだけして logs/ に書く
  f="$1"
  c++ -std=c++17 -fsyntax-only -fno-color-diagnostics -DZEND_ENABLE_STATIC_TSRMLS_CACHE=1 $(php-config --includes) '-DPHPSTANTURBO_VERSION="x"' "$f" > "logs/$(echo "$f" | tr / _).log" 2>&1
  ```

  ```bash
  find src -name '*.cpp' | xargs -P10 -n1 ./one.sh
  ```

- **監査の範囲:** 「`zv::Args` の利用箇所」だけでは狭すぎた。#6561 の監査範囲は次のとおりで、次回はここまで見る。
  - すべての `ZVAL_ARR` / `RETURN_ARR`
  - 配列へのすべての refcount 操作
  - 配列への生の書き込み
  - 文字列への `Z_ADDREF`
- **読むだけだったコードが PHP を呼ぶようになったら疑う:** `combinations()` の `ZVAL_ARR` による包み直しは 73cf457be（C++ 版の導入）からあったが、ネイティブ実装が配列を読むだけだった間は無害だった。9f07e1802 で `pt_type_call_static()` 経由で PHP の twin に処理を委ねるよう変わった時点で、この包み直しがクラッシュの原因になった。ネイティブ実装を PHP への委譲に置き換える差分を見るときは、渡す zval の作り方を確認する。
- **実際の解析への影響の見積もり方:** `src/` の PHP 側の呼び出し元を列挙し、空配列を渡す前に抜けるガードがあるかを読む。ネイティブ同士の呼び出しは別経路のことがある（`TypeUtils` / `IntersectionType` のネイティブ実装は `pt_combinations_helper_for_each()` を使い、PHP を呼ばない）。`combinations()` はどの呼び出し元も外側の配列を空で渡さず、解析自体は落ちなかった。実害は CI の smoke に限られていた。
- **推論より定型の書き方:** `VariableLivenessResolver` の `ZVAL_ARR` + `Z_ADDREF` は「`innerSlotByKey()` が必ず separate するので安全」と判断した。#6561 では、それでも `copyOfTable()` に置き換えられた。今は安全でも、定型の書き方に揃えておくほうが好まれる。

## 4. 再現テストの作り方

- **テストの置き場所:**
  - prefixed モードでネイティブのハンドラ（例: `\PHPStanTurbo\ForeachHandler`）を PHP の協力オブジェクトの上で動かすと、ネイティブが作った `PHPStanTurbo\ExpressionContext` が PHP の NodeScopeResolver の型宣言に弾かれる（型の壁）。`processStmt()` 全体を通す再現は smoke には置けない。
  - shadow モード（実際の名前でネイティブが動く）で再現できて CI でも走る置き場所が PHPUnit。`tests/bootstrap.php` が `TurboExtensionEnabler::activateIfCompatible()` を呼ぶし、phar.yml の turbo-run レグは拡張をロードして `make tests` を実行する。
- **スコープのサブクラス:**
  - scope factory は MutatingScope しか作らないので、作ったスコープのコンストラクタ引数をリフレクションで読み、サブクラスとして組み直す。
  - 狙った箇所までサブクラスのまま通すには、途中で新しいスコープを作るメソッド（今回は `enterForeach()` と `mergeWith()`）をオーバーライドして `$this` を返す。
- **自己解析のルールとの兼ね合い:**
  - `new ReflectionClass` を PHPStan のインターフェースを実装するクラス（Scope のサブクラスなど）の中に書くと、`phpstanApi.runtimeReflection` になる。リフレクションはテストクラス側に置く。
  - `ReflectionProperty::getValue()` で private を読むときは、先に `setAccessible(true)` を呼ぶ。PHP 8.1 未満では必須で、古い PHPUnit のジョブ（ダウングレードされた PHP で走る）が `Cannot access non-public member` で落ちる。このメソッドは 8.1 以降は何もせず、8.5 で非推奨になった。そのため PHP 8.5 向けの自己解析が `method.deprecated`（`Call to deprecated method setAccessible() of class ReflectionProperty.`）を報告するので、`build/php-85.neon` に ignore を足す（`ObjectTypeTest` と同じ扱い）。#6558 はここを見落とし、#6561 で直された。
  - テストのディレクトリだけを `bin/phpstan analyse` すると、`shipmonk.deadMethod`（オーバーライドしたメソッドが未使用）が誤って出る。`make phpstan` の全体解析では出ない。
  - `tests/PHPStan` は classmap autoload なので、新しいファイルを足したら `composer dump-autoload` を実行する。
- **失敗することの確認:** 修正を戻すときは `git stash` を使わない（stash は worktree 間で共有される）。`git checkout <base> -- turbo-ext/src/zv.h` で戻してビルドし、テストし、`git checkout HEAD -- …` で戻す。データセットを 1 つだけ走らせるには `--filter 'testName#0'`。

## 5. ビルド・バージョン・検証の手順

- **pcov を外した ini:** `/opt/homebrew/etc/php/8.5/php.ini` から `extension="pcov.so"` の行を除いたものを置いたディレクトリを `PHPRC` に指定する。フルスイートは paratest のワーカーにも拡張が必要なので、`extension=<worktree>/turbo-ext/phpstan_turbo.so` を足した別の ini を用意する。
- **ビルド:** turbo-ext で `make WARN_FLAGS="$(make -s print-warn-flags)" -j10`（strict ビルド）。`ld: warning: search path … not found` は Homebrew 環境由来なので無視してよい。
- **バージョン:**
  - バイナリのバージョンは `git log -1 --format=%h -- turbo-ext/src` から焼き込まれる。未コミットの変更ではバージョンが変わらないので、コミット前なら修正入りのビルドでも有効化される。
  - コミットするとバイナリのバージョンは新しい SHA になり、`make bump-turbo` でそれを `EXPECTED_EXTENSION_VERSION` に反映してリビルドするまで拡張は有効化されない。
  - rebase や amend で SHA が変わったら bump をやり直す。bump コミットが未 push なら、`make bump-turbo` はそのコミットをその場で更新する。
  - phpunit の結果を信じる前に `PHPStanTurbo\Runtime::isShadowing()` を確認する。`php -r` で確認するときは `vendor/autoload.php` のあとに `tests/bootstrap.php` を読み込む（有効化はここで行われる）。
  - shadow はクラス単位ではなくメソッド単位なので、`ReflectionClass::isInternal()` は shadow されていても false になる。ネイティブに差し替わったかは `ReflectionMethod::isInternal()` で確かめる。
- **検証一式（2.3.x）:**
  - strict ビルド
  - `php -d extension=… turbo-ext/tests/smoke.php`（ALL OK）
  - `turbo-ext/tests/signature-parity.php`
  - `php turbo-ext/bin/side-by-side.php`
  - `TURBO_DLL=… php -d extension=… turbo-ext/tests/walk-trace.php --shards=8`
  - 拡張をロードしたフルスイート（`make tests`）
  - `make phpstan`
  - `make bump-turbo` のコミットは別にする
- **ローカルで回せないもの:**
  - `make lint-turbo` は clang-tidy のメジャーバージョンが Makefile の `CLANG_TIDY_VERSION`（21）に固定されていて、ローカルに無いと実行できない。
  - `make sanitize-turbo` と debug ビルドの PHP での検証（`HT_ASSERT_RC1` を捕まえる）も今回は未実施。
- **無関係なクラッシュで smoke が止まるとき:** その修正を `git diff A^ A | git apply` で一時的に当ててビルドして確認し、`git checkout HEAD -- <file>` で戻す。
- **Linux での再現（Apple `container`）:** macOS だけでなく Linux でも落ちるかを確かめるには、ローカルにキャッシュ済みの `php:8.5-cli` イメージ（PHP 8.5.8、aarch64）が使える。所要は数分。
  - worktree のビルド成果物を汚さないよう、scratch に `git archive <base> | tar -x` で展開し、`vendor/` を `rsync -a --exclude '*.so'` でコピーする。
  - `.git` がないとバージョンを焼き込めずビルドが失敗するので、`turbo-ext/VERSION.txt` に適当な SHA を書く（scratch のコピーだけ。リポジトリには作らない）。
  - コンテナ内で `apt-get install -y g++ make` → `make` → smoke、修正の patch を当てて再ビルド → smoke、を 1 本のスクリプトで回す: `container run --rm -c 8 -m 8G -v <dir>:/work php:8.5-cli sh /work/run.sh`。
  - 結果: 修正前は `Segmentation fault`（exit 139）、修正だけを当てると `ALL OK`。CI の Linux の失敗と同じシグナル。
- **ブランチ間の違い:**
  - 2.2.x の turbo-ext は小さい（`src/` は 21 ファイル。2.3.x は 386）。`zv::Args` も `print-warn-flags` も `Runtime::isShadowing()` もなく、有効化の確認は `TurboExtensionEnabler::isActive()` で行う。
  - turbo の修正は基本的に 2.3.x が前提。

## 6. upstream の CI の状態を確かめる

- **前提を鵜呑みにしない:** ハンドオフ文書は「CI では落ちていないので原因は環境差」としていたが、実際には upstream の CI も同じ箇所で落ちていた。ef16d6ead の `phar.yml`（run 35776936322）では、turbo の compile / phpize ジョブ 38 件すべて（Linux gnu/musl、macOS、Windows、PHP 8.3〜8.6、NTS/ZTS）が smoke ステップで落ちていた（macOS は exit 138、Linux と Windows は exit 139）。CI での再現有無を書くときは、実行ログで確認してから書く。
- **smoke が落ちると dev PHAR が止まる:** `phar.yml` の「Commit PHAR」は turbo-compile / turbo-compile-musl-arm64 / turbo-compile-windows / turbo-phpize に `needs:` で依存する。smoke が落ちると「Aggregate Turbo Extension Artifact」「Run with Turbo Extension」とともにスキップされ、PHAR がコミットされない。
- **調べ方（`gh`）:**
  - 実行一覧: `gh run list -R phpstan/phpstan-src --workflow phar.yml --branch 2.3.x --limit 20 --json headSha,conclusion,createdAt`。途中の実行は後続の push でキャンセルされていることが多いので、「最後に成功した実行」と「問題のコミットを含む最初の実行」を並べて見る。キャンセルされた実行については何も言えない。
  - 失敗したステップの集計は API で取る（`gh run view --json` は `steps` を返さない）: `gh api --paginate 'repos/phpstan/phpstan-src/actions/runs/<run>/jobs?per_page=100' --jq '.jobs[] | select(.conclusion=="failure") | ...'` に `.steps[] | select(.conclusion=="failure") | .name` を組み合わせる。
  - ログは `gh api --allow-escape-sequences repos/phpstan/phpstan-src/actions/jobs/<job>/logs`。`--allow-escape-sequences` がないと本文の代わりに警告 1 行だけが返る。`gh run view --log-failed` は非常に遅く、120 秒のタイムアウトを超えた。ログ取得はバックグラウンドで回す。
  - smoke は最後まで何も出力しないので、CI のログからはどのケースで落ちたかはわからない。CI の失敗をあるバグのせいだと書くなら、同じプラットフォームで修正前後を再現してからにし、それ以外は「この PR の CI で確認する」と書く。
- **提出前に upstream の動きを見る:** 2.3.x の CI が赤いのは、メンテナがその日に push した変更が原因であることが多く、メンテナ自身がすでに直しているかもしれない。#6549 はまさにそれで重複になった。PR を出す直前に `git fetch phpstan 2.3.x` し、該当ファイルや CI の状態が変わっていないかを確認する。

## 7. 作業上のつまずき

- 別の worktree 向けの git コマンドをセッションのカレントディレクトリで実行すると、意図しないブランチが書き換わる。今回は `git commit --amend` で upstream のコミットを amend してしまった。`git -C <path>` か `cd <path> &&` を必ず付ける。ツリーが同一なら `git reset --soft <元の SHA>` で戻せる。
- PR 本文や GitHub のコメントは 1 段落 1 行で書く。送信はユーザーが承認してから。
- **PR 本文の検証:** 下書きを別のサブエージェント（Opus）に敵対的レビューさせたところ、誤った主張（「実行時に作った空配列は落ちない」）、書き漏らしていた一番大きな動機（dev PHAR が止まっていること）、監査範囲を超えた言い切り（`zv::Args` の見落とし）が見つかった。公開する本文の事実関係は、投稿前にこの方法で洗う価値がある。
- **PR 本文の書き方（ユーザーの好み）:** 冒頭で「何を解決するか」を言い切る。修正理由を見出し付きの箇条書きで並べる形は避ける。影響のように並べて比べられる事実は見出しと箇条書き、原因のように因果の流れがある説明は文章、修正前後の結果は表にする、という使い分けが最終的に採用された。
- **Co-authored-by の後付け:** セッションのシステム指示で署名行の付与が禁止されている場合は、ユーザーに次の 2 つを実行してもらう。push する前に済ませれば force-push はいらない。
  - `git -C <worktree> rebase <base> --exec 'git commit --amend --no-edit --trailer "Co-authored-by: Claude Opus 5.5 <noreply@anthropic.com>"'`（`-i` なしで動き、エディタも開かない）
  - 修正コミットの SHA が変わるので `make -C <worktree> bump-turbo`。未 push の bump コミットを `--no-edit` で amend するので、トレーラーは残る。その後、再ビルドして版と `isShadowing()` を確認してから push する。
