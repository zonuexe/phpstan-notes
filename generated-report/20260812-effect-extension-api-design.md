# Effect ラベルの登録・付与 API 設計

[本体設計](20260812-effect-envelope-phpstan-port-design.md)(stage 1–6)の続き。
サードパーティが (1) **語彙を登録**し、(2) **呼び出しに effect を付与**するための表面を設計する。

動機となるシナリオ(Steins why-effects.md「Transport facts and semantic facts」):

> SendGrid SDK の `$mail->send()` は transport 事実 `io.net.http` に加えて、
> provider 操作 `sendgrid.mail.send`、アプリケーション意味 `email.send` を運ぶべき。
> 3 つのラベルは**共存**する(どれか1つに正規化しない)。

登場人物は3者で、それぞれ所有する名前空間が違う:

| 誰が | 何を所有 | 例 |
|---|---|---|
| PHPStan core | 組込語彙(transport) | `io.net.http`, `mutate.local` |
| SDK / extension 作者 | 自分の vendor ルート | `sendgrid.mail.send` |
| プロジェクト | アプリ意味のルート | `email.send`, `acme.db.master` |

## 0. 前提: 2つの役割の分離(本体設計から継承)

- **役割 A(caller-facing)**: 呼び出しサイトの effect 事実 → 呼び出し元の envelope 検査に流れる。
- **役割 B(declaration-checking)**: 宣言された envelope と自分の body の突き合わせ。

**付与 API(neon / extension)が供給するのは役割 A のみ**。役割 B は docblock
(と stub)だけが駆動する — 他人が付与したラベルに対して body を検査することはできない
(「他人の主張はこの body の違反ではない」の裏返し)。この非対称は Steins の
declared-lane 規律の PHPStan 版として明示する。

## 1. 表面 1: PHPDoc(実装済み、語彙登録でアンロックされる)

SDK が自分のコードに書く:

```php
/** @phpstan-impure io.net.http, sendgrid.mail.send */
public function send(Mail $email): Response { … }
```

stage 1–5 の実装でこれは**既に動く**が、`sendgrid.*` が語彙に無い間は S5 により
タグ全体が ⊤(= 現状の bare `@phpstan-impure` と同じ)。つまり
**語彙登録が SDK アノテーションの「スイッチ」になる**。SDK は今日からアノテーションを
書けて(BC 無風)、利用者が語彙を有効化した時点で検査が立ち上がる。
この「書くのは先、効くのは後」の性質は導入戦略として重要。

## 2. 表面 2: phpstan.neon(宣言的・プロジェクト側)

```neon
parameters:
	# (a) 語彙登録: プロジェクトは任意のルートを所有できる
	effectLabels:
		- email.send
		- acme.db.master
		- acme.db.replica

	# (b) 付与: コードに触れず 3rd-party 呼び出しへラベルを付ける
	effectMetadata:
		'SendGrid\Mail\Mail::send': [io.net.http, sendgrid.mail.send, email.send]
		'wp_remote_get': [io.net.http]
```

- (a) は `EffectLabelVocabulary` を「組込 21 + neon + provider(§3)」の合成に変える。
  `isKnown()` の祖先規則・Levenshtein 提案はそのまま全体集合に対して働く。
- (b) のキーは `functionMetadata` と同じ `'fn'` / `'Class::method'`。
  値は**そのシンボルの宣言ラベルの置換**(役割 A のみ)。既存前例
  (`earlyTerminatingMethodCalls` 型の Class::method マップ)に倣う。
- スキーマは `listOf(string())` / map。ラベル文法(小文字 dot-path)は
  スキーマ層では検証せず消費側で ⊤ に落とす(S5 と同一規律)+ 将来の opt-in 診断。
- neon パラメータは container config hash に入るため result cache 無効化は自動。

## 3. 表面 3: Extension API(DI タグ、引数依存・provenance 依存の付与)

`DynamicThrowTypeExtension` 族を鏡写しにする(役割 A の call-site 付与):

```php
interface DynamicFunctionEffectExtension
{
	public function isFunctionSupported(FunctionReflection $function): bool;

	/** @return list<string>|null null = 意見なし(次の供給源へフォールバック) */
	public function getEffectLabelsFromFunctionCall(
		FunctionReflection $function,
		FuncCall $call,
		Scope $scope,
	): ?array;
}
// + DynamicMethodEffectExtension / DynamicStaticMethodEffectExtension
```

これで表現できるもの:
- **引数依存**: `fopen($f, 'r')` → `[io.fs.read]`、`'w'` → `[io.fs.write]`
  (宣言側の `io.fs` を呼び出しサイトで**狭める**)。
- **receiver provenance**: master 接続の `PDO::query` → `[io.db, acme.db.master]`
  (receiver の型・generics・provenance から判定 — Steins の将来構想
  「connection-provenance effects」の PHPStan 版の入り口)。
- **SDK 拡張**: `sendgrid/phpstan-sendgrid` が extension-installer で入り、
  `Mail::send` に `[io.net.http, sendgrid.mail.send]` を返す。

語彙供給の extension 側:

```php
interface EffectLabelsProvider
{
	/** @return list<string> */
	public function getEffectLabels(): array;
}
```

**所有規律(Steins ADR-0068 の移植、ただし規約として)**: extension は
自分の composer vendor 名に等しいルート、または core ルートの子孫のみを登録すべき。
PHPStan は DI サービスから package 同一性を検証できないため、**強制はしない**
(規約 + ドキュメント + 将来の opt-in 診断)。プロジェクト(neon)は任意ルート可
(自分の名前空間は自分が所有)。

## 3.5 前例調査からの確定事項(2026-08-12、worktree 現物調査)

設計を PHPStan の現行機構に正確に合わせるための確認結果:

- **登録は attribute-first**。この 2.2.x ツリーでは内部拡張の neon `tags:` 登録は**ゼロ**。
  interface に `#[ExtensionInterface(tag: 'phpstan.…')]`、実装に `#[AutowiredService]`、
  消費側 ctor 引数に `#[AutowiredExtensions(of: X::class)] ExtensionsCollection`。
  サードパーティの neon `services: tags:` は引き続き有効
  (`ValidateServiceTagsExtension` が整合検証)。新 interface もこの型に従う:
  `#[ExtensionInterface(tag: DynamicFunctionEffectExtension::EXTENSION_TAG)]` + タグ定数。
- **throw 系 3 interface が最も近い鏡**(`phpstan.dynamic{Function,Method,StaticMethod}ThrowTypeExtension`)。
  ただし throw 系は registry なしの線形スキャンで **first-match が勝ち、
  supported + null 返却は throw point を抑制する**。effect 版は null の意味が違う
  (null = 意見なし → 次の供給源へフォールバック)ので、シグネチャは鏡でも
  null 意味論の差をドキュメントに明記する。`[]` の意味(「この呼び出しサイトに
  観測可能な effect なし」= 抑制)は open question 5 として保留。
- **消費点は ExprHandler 層**: `FuncCallHandler` / `MethodThrowPointHelper` 相当の位置。
  effect の場合は `createFromVariant()` 呼び出し箇所 + `NewHandler` — stage 2/5 で
  ラベルが付く場所と一致しており、consult の挿入点は既に整っている。
- **メソッド側の supported 判定**: return-type 系は `getClass()` + registry
  (per-class index)、throw 系は `isMethodSupported()` の線形スキャン。
  v1 は throw 系の単純な形(線形)で開始し、拡張数が増えたら registry 化
  (`DynamicReturnTypeExtensionRegistry` の鏡)を性能課題として後送。
- **member 参照キーの neon 前例**: `dynamicConstantNames` が唯一の
  `Class::MEMBER` キーのマップ(値は型文字列)。`effectMetadata` の
  `'Class::method' => [labels]` はこの前例に載る。スキーマは
  `arrayOf(listOf(string()))`。`earlyTerminatingMethodCalls`(class → methods リスト)は
  形が違うので参考に留める。
- **語彙の合成点**: 現 `EffectLabelVocabulary` は `#[AutowiredService]` の const 配列。
  「組込 + `#[AutowiredParameter] effectLabels` + `#[AutowiredExtensions(of:
  EffectLabelsProvider)]`」の合成に変えるのは局所変更(全消費者が DI 注入済み)。

## 4. 優先順位(1 呼び出しサイトの effect 集合の決定)

```
Dynamic*EffectExtension (最初に non-null を返した extension)
  > effectMetadata (neon)
  > callee の宣言 envelope ラベル(docblock / stub / functionMetadata)
```

- **non-null は置換**(union ではない)。`DynamicReturnTypeExtension` の前例に一致し、
  狭化(fopen 'r')が可能になる。意味の追加は「全部入りで返す」ことで表現する
  (transport + semantic を並べる)。
- union にしない理由: union だと狭化が不可能になり、`fopen('r')` が
  `{io.fs, io.fs.read}` になって呼び出し元は結局 `io.fs` を宣言させられる。
- 付与されたラベルの未知判定は消費側で従来どおり(未知を含む point は ⊤ → スキップ)。
  供給源が語彙も登録していれば通常発生しない。

## 5. SendGrid シナリオの通し検証(机上)

1. `sendgrid/phpstan-sendgrid`: `EffectLabelsProvider` が `['sendgrid']` を登録、
   `DynamicMethodEffectExtension` が `Mail::send` に
   `[io.net.http, sendgrid.mail.send]` を返す。
2. プロジェクト neon: `effectLabels: [email.send]`、
   `effectMetadata: {'App\Mailer::send': [email.send, io.net.http, sendgrid.mail.send]}`。
3. `App\Mailer::send()`(wrapper)の docblock:
   `@phpstan-impure io.net.http, sendgrid.mail.send` — 役割 B: body の SendGrid 呼び出し
   (extension が付与した2ラベル)が envelope に収まることを検査。
4. Controller が `@phpstan-impure io.db, output` で `Mailer::send()` を呼ぶと:
   役割 A: effectMetadata の3ラベルが point に乗り、`email.send` も `io.net.http` も
   bound 外 → finding。「このコントローラはメールを送るようになった」が検査可能な事実になる。
5. ポリシー(「controller から `sendgrid.*` を直接触るな」等)は envelope の上に載る
   将来の独立ルール — 本設計のスコープ外として予約。

## 5.5 高階関数と多相エフェクト(array_map / array_filter / 遅延起動)

「`array_map` の効果 = コールバックの効果」という多相を extension で扱うべきか — 整理:

### 即時起動(array_map/array_filter/usort/…): extension 不要、既に多相が成立

効果は array_map からではなく、**即時起動インライン展開されたコールバック本体**から来る。
`isImmediatelyInvokedCallable` 機構がコールバックの ImpurePoint を呼び出し元へ伝播させ、
stage 2 以降ラベルはこの経路を verbatim に通る(stage 2 テスト (h) で固定済み:
array_map 内のクロージャが labeled 関数を呼ぶ → 呼び出し元 envelope 検査に届く)。
Steins の「視えるコールバックに効果変数は不要」と同じ構図。
array_map 自身の Maybe point はラベルなし・uncertain → envelope 検査はスキップ。
**ここに extension で callback ラベルを付与するとインライン伝播と二重報告になる** —
即時起動関数への Dynamic*EffectExtension はアンチパターンとして明記する。
pure 文脈での array_map ノイズは、2.2.x にマージ済みの
`@pure-unless-callable-is-impure`(functionMetadata 24 エントリ)が担当(直交・共存)。

### callable object の効果を「読む」インフラ: stage 2 で完成済み

`ClosureType`/`CallableType` の `getImpurePoints()` が返す `SimpleImpurePoint` は
stage 2 からラベルを運ぶ(`ClosureTypeResolver` の変換もラベル転送済み)。
extension から読むレシピ:

```php
$type = $scope->getType($callbackArg);
foreach ($type->getCallableParametersAcceptors($scope) as $acceptor) {
    foreach ($acceptor->getImpurePoints() as $point) {
        $labels = $point->getEffectLabels();
        if ($labels === null) { return null; }   // ⊤ が1つでもあれば意見なし
        $union = [...$union, ...$labels];
    }
}
return $union;   // 正規化(subsume される子を落とす)して返す
```

### extension + closure 読みの実用例は「遅延起動」高階関数

`register_shutdown_function($cb)` / `spl_autoload_register($cb)` のような
**保存して後で呼ぶ**関数はインライン展開が働かない。ここでこそ
「この呼び出しの効果 = 渡された callable の効果(遅延して起きる)」を
extension が付与する意味がある。上のレシピがそのまま実装になる。

### opaque callable: v1 では原理的に不可(将来仕様へ)

`callable $cb` パラメータは型に効果情報がなく extension でも読めない。
1-bit 版は `@pure-unless-callable-is-impure` が既に提供。ラベル版の一般化
(`@phpstan-impure-of $callback` のような effect-parametric 宣言、あるいは
callable 型構文への envelope 付与)は open question 6 として予約 —
Steins ADR-0063(semantic-first、宣言的条件形は opaque のみ)と同じ着地。

## 5.9 stage 7 実装で確定・発覚したこと(2026-08-12)

- 実装コミット `fe77a923a`。優先順位・置換意味論・宣言クラス限定は §4 のとおり実装。
  `MethodCallHandler`/`StaticCallHandler` は extension に渡す前に named-args を
  正規化順へ並べ替え(throw 系の慣例に一致 — `@api` 面の一貫性)。
- fopen 狭化 PoC は red チェック付きで動作('r' → io.fs.read が宣言 io.fs に勝つ)。
- SendGrid シナリオは実 neon でエンドツーエンド動作確認済み。
- **発覚した制約 → D-U1 で解決(stage 8)**: 役割 A の付与ラベルは、callee が
  未注釈(isPure=Maybe)かつ非 void だと uncertain point になり、envelope 検査の
  certain ゲートで読まれなかった。決定: **ラベルを持つ uncertain point は
  possibly 級(`may have effect …` / `possiblyImpure*.effectOutsideEnvelope`)で
  検査する**。proven-only 規律は「ラベルなし uncertain のスキップ」として存続。
  これで curl_exec/mail 等の Maybe builtin ラベル(stage 5 で保留)も生きる。
- `hasSideEffects()->no()` の短絡は維持 — 付与 API は「PHPStan が pure と知る callee」に
  impure point を捏造できない(expr.resultUnused 等への漏出防止)。

## 5.10 プロトコルラッパーのスキーム別狭化(stage 9 で実装)

定数文字列引数のスキームから effect を振り分ける — 引数依存狭化の代表例
(php.net/manual/en/wrappers.php.php 準拠):

| スキーム | effect |
|---|---|
| `php://stdout` `php://stderr` `php://output` | `output` |
| `php://input` | `global.read`(リクエストボディ = superglobal 読みと同族) |
| `php://memory` / `data://` | `mutate.local`(**pure でも許容**される — stage 4 と合成) |
| `php://temp` | `io.fs` |
| `php://filter/...` | `resource=` 部を**再帰解決**(`.../resource=https://…` → `io.net.http`) |
| `file://` / スキームなし | `io.fs.read`/`io.fs.write`(mode と合成) |
| `http(s)://` | `io.net.http` |
| `ftp(s)://` `ssh2.*://` | `io.net` |
| `zlib://` `compress.*://` `glob://` `phar://` | `io.fs` 族(mode 合成) |
| `expect://` | `io.process` |
| 未知スキーム | 狭化しない → カタログ既定 `io` |

`copy`/`rename` の2引数は両方を解決して **union**(`copy('https://…', '/tmp/x')` →
`[io.net.http, io.fs.write]`)。どちらかが解決不能なら全体 null。

**D-W1(意図的な近似)**: `stream_wrapper_register` されたユーザー wrapper は任意の
PHP コードを実行するため、厳密には `io` すら上界でない(wrapper 内で echo できる)。
「登録 wrapper は `io` に収まる」を実用的近似として採用し、ユーザー wrapper コードの
モデル化はしない。documented first-class semantics(https/socket/直接出力)の隠蔽
(レビュー P1)とは質の異なる、記録された判断。

## 5.11 output の細分化と ob_start マスキング(設計判断、2026-08-12)

**D-V2: `output` → `io.output` 移設(2026-08-12 改訂 — ユーザー裁定)**

当初は「io envelope の切れ味低下 + Steins との語彙同期」を理由に io 配下への移設を
非推奨としたが、ユーザー裁定により方針転換: **Steins は開発初期で語彙はまだ変えられる
(今が変えるチャンス)**。切れ味の懸念は正当なので構造で解決する。

採用構造 — 組織原理は「**チャネルの由来**」(開いた資源 vs ambient チャネル)。
`io.output` の内部は「**OB 層経由(マスキング可能)か、プロセス fd 直接か**」で分ける
(2026-08-12 ユーザー指摘による改訂 — マスキング可能性の境界を prefix として階層に持たせる):
```
io.output            「何かしら出力する」の傘
io.output.buffer     OB 層経由 = ob_start() で捕獲可能: echo / print / printf /
                     inline HTML / php://output(マニュアル: print・echo と同一機構)/
                     flush / ob_flush
io.output.stdout     プロセス fd 直接 = OB の影響を受けない: php://stdout, fwrite(STDOUT)
                     (php-fpm では応答ですらない別チャネル)
io.output.stderr     同上: php://stderr, STDERR
io.output.header     応答メタデータ: header() / setcookie()(OB 対象外)
io.input             php://input / php://stdin(ambient 入力。旧設計の
                     php://input → global.read の不整合を解消。$_GET 等の
                     パース済みメモリ読みは global.read のまま)
```

この分割の狙い: 将来のマスキング(下記)の規則が「**`io.output.buffer` に subsume される
ラベルだけ差し引ける**」という prefix 1 個の判定になる。`fwrite(STDOUT, $x)` /
`file_put_contents('php://stdout', $x)` は `io.output.stdout` であり、ob_start では
原理的に捕獲できないことを階層自体が表現する。なお `fwrite(STDOUT, …)` の狭化は
resource provenance 不要 — 引数が `STDOUT`/`STDERR` の ConstFetch であることの
構文判定で足りる(extension に1ケース追加)。

根拠:
- **stage 9 自体が証拠**: `fwrite = io, output` / `system = io.process, output` /
  `readfile = io, output` というぎこちないペアの量産は、階層が現実
  (ストリームの行き先に stdout が含まれる)と喧嘩している症状。移設で
  fwrite → `io`、readfile 既定 → `io` に潰れる。Koka の `io`(console 込みの傘)と同型。
- **切れ味を失うのは bare `io` のみ**で、stage 9 後の bare `io` は「行き先不明の
  ストリーム作業」の既定なので output 許容はむしろ正直。細ラベル
  (`io.db` 等)の envelope は無傷(`io.db ⋣ io.output`)。
- 「io するが出力しない」の表現手段: 子の列挙、または予約済み `-except`
  (`io -except io.output`)— 移設が `-except` に初の具体的動機を与える。

移行順序: stage 9(現行語彙での soundness 修正)着地 → stage 10 で PHPStan 側を
機械的リネーム(vocabulary / 構文マップ echo→io.output / カタログ / スキームテーブル /
デモ / テスト)→ Steins レジストリ + interop 仕様書の語彙節を同期変更(ユーザーの
リポジトリ側、着手指示待ち)。

**ob_start ガード = 効果マスキング(将来機能、v1 は現状維持が sound)**
- `ob_start(); echo …; ob_get_clean()` の echo は外に漏れない(net effect は
  `mutate.local` + バッファスタック操作)。現状の「echo = output のまま」は
  **過大近似 = false positive 側なので sound**(P1 の under-approximation とは逆方向)。
- 正確化の2案: (a) 領域解析 — 全経路(例外・early return)で clean 系により閉じる証明、
  flush 系(出力を解放する側)との区別、ネスト追跡が必要な CFG データフロー。
  (b) **HOF + マスキング注釈** `@phpstan-masks io.output.buffer $fn` — 「$fn の効果 −
  io.output.buffer」を宣言する、仕様予約済み `-except` の親戚(Flix の effect masking 相当)。
  健全性の証明が helper 関数1個に局所化される (b) が有望。open question 9 へ。
- どちらの案でも**差し引けるのは `io.output.buffer` 配下のみ**(D-V2 の子構造がこの
  境界を prefix として運ぶ)。`io.output.stdout` / `io.output.stderr` / `io.output.header` は
  ob_start で捕獲できないため、マスキングの対象外であることが階層から機械的に決まる。

## 6. 決定事項と divergence

- **D-E1(Steins ADR-0064 からの意図的乖離)**: Steins は「第6の拡張機構」を拒否し
  5 seam に分類するが、PHPStan のネイティブな慣用は extension interface + DI タグ。
  移植先の慣用に従う。
- 役割 A / 役割 B の分離(§0)は維持 — 付与 API は宣言検査に影響しない。
- Liskov(stage 3)は宣言レベルの比較のみなので付与 API と相互作用しない。
- 「エフェクトを発生させる」= 静的な付与のこと。runtime の perform/handle は
  スコープ外(Steins「What Steins is not」と同じ線引き)。

## 7. 実装順(stage 7 候補)

1. `EffectLabelVocabulary` の合成化(組込 + `effectLabels` param + provider タグ)
   — 全消費箇所は DI 注入済みなので変更は局所的。
2. `effectMetadata` param → 役割 A の供給源に(`createFromVariant` 経由)。
3. `Dynamic*EffectExtension` 3 interface + registry(`DynamicThrowTypeExtensionProvider`
   の鏡)+ 呼び出しサイトでの consult。
4. 検証: SendGrid シナリオを test fixture 化(fake SDK + fake extension +
   neon 設定のテスト)。fopen の狭化 PoC。

## 8. Open questions

1. vendor ルート所有の強制手段(現状は規約のみ)。extension.neon にメタデータを
   持たせる案 vs 諦める案。
2. `effectMetadata` のキーに interface メソッドを書いたとき、実装クラス経由の呼び出しに
   適用するか(v1: 宣言クラス一致のみ、hierarchy walk なし — 明記)。
3. 語彙エントリに説明文を持たせるか(typo 提案・ドキュメント生成用)。v1 は素の文字列。
4. `Dynamic*EffectExtension` が役割 B(宣言検査)にも影響するモードは将来も作らない、
   でよいか(§0 の非対称の恒久化)。
5. extension が `[]`(空リスト)を返す意味 —「この呼び出しサイトに観測可能な effect なし」
   (= point の抑制に相当)を許すか。throw 系の「supported + null = 抑制」との対応を
   取るなら `[]` = 抑制が自然だが、pure 許容(stage 4)の guard が現在
   `[] → 許容しない` なので整合の再設計が要る。v1 では `[]` を予約(返却禁止を
   ドキュメント化)して逃げる案が有力。
6. opaque callable の effect-parametric 宣言(§5.5)—
   `@phpstan-impure-of $callback` のような「この関数の効果 = このパラメータの callable の
   効果(+ 固定ラベル)」を綴る構文。`@pure-unless-callable-is-impure` のラベル版一般化。
   phpdoc-parser への構文追加が要るため独立の仕様検討として切り出す。
7. 遅延起動高階関数(register_shutdown_function 等)への「callback の効果を読む」
   extension を PHPStan 本体が同梱するか、レシピの文書化に留めるか(§5.5)。
8. ~~`output.stdout` / `output.stderr` の builtin 語彙昇格~~ → D-V2(§5.11)で
   `io.output.{stdout,stderr,header}` + `io.input` として解決方針決定。
   残件は `io.input` を stage 10 に含めるか(推奨: 対称性のため含める)と
   Steins 側レジストリ・interop 仕様書の同期変更のタイミング。
9. 効果マスキング(§5.11)— `@phpstan-masks output $fn` 形式の HOF 注釈 vs
   ob_start/clean の領域解析。`-except`(spec 予約)と同じ「差し引き」族として設計する。
