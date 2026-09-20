> これは日本語レビュー用の翻訳です。投稿および原文の正本は [英語版](20260812-issue-draft-effect-labels-spec.md) です。

# Issue draft: purity tag への effect label 付与、v3 (#15224 と PHPStan 2.3.x)

<!-- v3 では phpstan/phpstan#15224 の mbstring の ambient encoding のケースと、作業ブランチから得られた PHPStan 2.3.x の引数評価要件を追加した。v2 は 20260812-effect-label-docs-adversarial-review.md の後に書き直した（FAIL → 指摘事項をすべて対応済み）。
     phpstan/phpstan の issue（または #14220 へのコメント）用の草稿。GitHub の書式に合わせ、ハードラップはしない。
     投稿するかどうかは所有者が決める。以前のブロッカーは 2026-08-12 に両方とも解消済み：
     Steins のカタログの健全性が upstream に取り込まれた（steins 4f0efa3、PR #319）。
     PHPStan ブランチの stage 11（checkEffectEnvelopes トグル）が d1064a7da で取り込まれた。 -->

**Suggested title**: `RFC: opt-in effect labels as parameters of @phpstan-impure`

---

## まず提案内容

PHPStan は、現在は解析後に破棄している `@phpstan-impure` のサフィックスを、**opt-in** の effect-envelope パラメーターとして解釈すべきか。

```php
/** @phpstan-impure io.db, nondet.time (reads the clock for cache TTL) */
function refreshCache(string $key): int
{
	$expiry = time() + 3600;
	file_put_contents('/tmp/cache/' . $key, (string) $expiry);
	return $expiry;
}

/** @phpstan-impure io.fs */
function touchCache(): int
{
	$expiry = time() + 3600;                          // ← the body reads the clock
	file_put_contents('/tmp/cache/last', (string) $expiry);
	return $expiry;
}
```

- **デフォルト設定（機能オフ）**: どちらの関数もエラーを出さず、今日の出力とバイト単位で同一になる。サフィックスは無視されるテキストのままである。
- **`featureToggles.checkEffectEnvelopes: true` の場合**（提案：デフォルトは `false`、bleeding edge では `true`）：

```
Function touchCache() has effect nondet.time (call to function time()), but is declared @phpstan-impure io.fs, so nondet.time exceeds the envelope.
```

`refreshCache()` は黙ったままである。`io.fs.write ⊑ io` であり、`nondet.time` も宣言されているからだ。これが機能の全体である。ラベルのリストは、不純性の種類について **宣言された上限** であり、関数本体と照合される。

このパラメーターの位置は、@ondrejmirtes がスケッチしたもの（[X, 2026-08-09](https://x.com/OndrejMirtes/status/2086491960089444580)）である：「The effect could just be a parameter after @phpstan-impure PHPDoc tag 😊 Like @phpstan-impure io」。#14220 は背景となる議論であり、この issue はそこで求められた「コード例と期待される出力を含む具体的な提案」にしようとするものである。

## v1 が #14220 のユーザーストーリーに答える内容

| #14220 の要望 | v1 での回答 |
|---|---|
| `I/O なし` の純粋性 | `@phpstan-impure` に `io` を含めないラベルを付ける（または `@phpstan-pure`） |
| `出力なし` | `io.output` を包含しない envelope。さらに `io.output.buffer` と `.stdout` の違いで、`ob_start` で捕捉できる出力と直接の fd 書き込みを区別する |
| 例外の純粋性を設定可能にする | 対象外。例外は `@throws` の領域に残る |
| fiber / generator | v1 では対象外（`yield` はラベルに寄与せず、envelope に対して報告されることもない） |

## エンジン内でのもう一つの効果：スコープ付きの忘却

envelope のチェックだけが effect の「種類」を使うわけではない。PHPStan 自身の [値の記憶と忘却の仕組み](https://phpstan.org/blog/remembering-and-forgetting-returned-values) は、途中の呼び出しによって値が変わり得る場合に、記憶していた値を忘れなければならない。しかし boolean の `hasSideEffects` ではトリガーが全か無かになる。統計キャッシュに触れていないことが明らかなのに、途中の `rand()` によって `is_dir($x)` まで忘却させられる。ラベルなら無効化の範囲を絞れる。統計由来の記憶は、ラベルが `io.fs` または `global.write` に到達する呼び出しで無効化され、`clearstatcache()` は **正確に** `global.write` である。一方、`nondet.random` はその記憶を維持する。つまりユーザーが envelope を一つも書く前から、語彙はエンジン内で効果を発揮し、ブログ記事が挙げた関数にも共有 boolean ではなく正確な分類を与えられる。

この分解によって no-effect statement のルールと PHP 8.5 の `#[\NoDiscard]` も整理できる。読み取りの形を持つラベル（`global.read`、`nondet.*`、`io.fs.read`）なら、結果を捨てた呼び出しは **dead statement** になる。これは注釈なしで導出できるため、[#8440](https://github.com/phpstan/phpstan/issues/8440)/[phpstan-src#2037](https://github.com/phpstan/phpstan-src/pull/2037) で見えている `hasSideEffects` の負担（引数の narrowing 下で、反転した boolean が「証明できない対象 ⇒ 広いデフォルト」になる）にも決着がつく。また [#12738](https://github.com/phpstan/phpstan/issues/12738) では `ob_get_contents();` は `global.read` なので、導出可能であり `must_use` は不要になる。`#[\NoDiscard]` は、本当に宣言が必要な象限、つまり結果自体が目的である effectful call（`fopen()`）に縮小される。さらに boolean の最も難しいケースも表現できる。`rand()` を一つの呼び出しにまとめることはできない（`nondet.random`）が、単独の `rand();` 文は dead である。1 つのラベルで両方の答えが得られる。

## 現在の動機となるバグ：mbstring の ambient encoding

公開中の [phpstan/phpstan#15224](https://github.com/phpstan/phpstan/issues/15224) で報告者は、encoding を明示した mbstring 呼び出しは同じ引数に対して同じ結果を返す一方、encoding を省略した呼び出しは `mb_internal_encoding()` による可変なプロセス状態に依存すると示している。[`mb_strlen()`](https://www.php.net/manual/en/function.mb-strlen.php) と [`mb_str_pad()`](https://www.php.net/manual/en/function.mb-str-pad.php) の PHP マニュアルには、encoding が省略または `null` の場合は内部 encoding にフォールバックするとある。[`mb_internal_encoding()`](https://www.php.net/manual/en/function.mb-internal-encoding.php) はその設定を getter と setter として公開する。したがって引数の有無だけでは条件を表せない。nullable 変数を使うと effect は不確実になる。[コントリビューターもこの区別を確認している](https://github.com/phpstan/phpstan/issues/15224#issuecomment-5658653555)。表では `$s` の native type が `string` であると仮定する。

| Call | 推論される effect |
|---|---|
| `mb_strlen($s, 'UTF-8')` | none |
| `mb_strlen($s)` または `mb_strlen($s, null)` | `global.read` |
| `mb_strlen($s, $encoding)`（`$encoding` が `?string`） | `global.read`（保守的な may-effect） |
| `mb_internal_encoding()` | `global.read` |
| `mb_internal_encoding('UTF-8')` | `global.write` |

PHPStan の単一の `hasSideEffects` ビットは、二つの consumer に一つの答えを共有させる。ambient state の reader を pure と印付けすると、`function.resultUnused` は捨てられた結果を報告できるが、同時に analyzer は `mb_internal_encoding('UTF-16LE')` の後でもその結果を記憶してしまう。reader を impure と印付けすれば記憶は保護されるが、unused-result 診断が抑制される。公開中の [phpstan-src#6441](https://github.com/phpstan/phpstan-src/pull/6441) では、作成者が mbstring の同種関数と同じく `mb_str_pad()` を side-effect-free と印付けすることを提案している。作成者はこれを実用上の override と説明し、encoding に依存する純粋性は対象外としている。effect label があれば PHPStan は両方の事実を保持できる。remembering consumer は `global.write` の後に `global.read` の結果を無効化でき、no-effect statement のルールは読み取り専用呼び出しの破棄を引き続き報告できる。

### Value-sensitive effect は PHP の評価順序を保持しなければならない

analyzer は各引数の評価位置における値から条件を解決しなければならない。呼び出し後の最終 scope だけでは不十分である。

```php
$encoding = null;
mb_strlen(encoding: $encoding, string: $encoding = 'payload');
```

[PHP は named argument によってパラメーター束縛の順番が変わる場合も含め、引数式を左から右へ評価する](https://www.php.net/manual/en/functions.arguments.php)。encoding 引数が受け取るのは `null` である。そのため、引数評価後には `$encoding` に `'payload'` が入っていても、この呼び出しは内部 encoding を読む。最終 scope だけを問い合わせる resolver は、誤って pure という結果を出す。

PHPStan 2.3.x は呼び出し引数を左から右へ一度だけ処理し、その `ExpressionResult` の値を `ArgsResult` に保持する。call-site effect resolver は `processArgs()` の後に実行し、最終 scope で引数式を再評価するのではなく、捕捉済みの結果を読む必要がある。[2.3.x adaptation review](20260915-effect-envelope-23x-adaptation-adversarial-review.md) には、function、instance-method、static-method の呼び出しについて、named argument、narrowing、widening、PHPDoc/native type の違いを含む回帰が記録されている。

引数評価前の scope をフォールバックに使うのも、先行する引数が後続の引数を widen する場合には sound ではない。

```php
$mode = 'r';
// $newMode has type string
fopen($mode = $newMode, $mode);
```

filename 式と mode 式は、どちらも widen された `string` の値を受け取る。entry scope にあったリテラル `'r'` を復元すると、この呼び出しを誤って狭め、広い `io` effect を保持しなくなってしまう。

dynamic effect extension は、PHPDoc type を信頼するのか native type だけを信頼するのかを宣言しなければならない。narrowing は証明に基づく。選択した type view からより小さい label set を証明できなければ、resolver は override を返さず、callee が宣言した、またはカタログ化された保守的な effect が有効なままになる。したがって native `string` 上の PHPDoc リテラル `'r'` は、native type を使う設定の resolver には不十分である。

## 文法

```ebnf
impure-tag       = "@phpstan-impure" [ label-list [ comment ] ] ;
class-impure-tag = "@phpstan-all-methods-impure" [ label-list [ comment ] ] ;
label-list       = label { "," label } ;
label            = segment { "." segment } ;          (* segment = [a-z][a-z0-9]* *)
comment          = "(" text-without-close-paren ")" ;
```

`@phpstan-ignore` の形をそのまま再利用する。一つ制約がある。comment は **少なくとも一つの label の後でのみ** 許される。tag name の直後に `(` が来ると、phpdoc-parser の Doctrine-annotation path に進む（phpdoc-parser 2.3.3 で検証済み）。

## 意味論

1. **チェックは segment を認識する prefix subsumption である**。宣言された `io` は推論された `io.net.http` を許可するが、`iota` は拒否する。推論される label は保守的な may-effect である。可能性のある経路が global state を読むなら、推論集合には `global.read` が含まれる。この prefix テストが subsumption 関係の核であるが、MVP の対象範囲全体はそれより広い。list grammar、三状態の読み取り（absent / unbounded / bounded）、vocabulary、言語構文とカタログ化された builtin への effect attribution、call propagation、precedence、diagnostics まで含む。下記の working branch にそのすべてが実装されている。
2. **bare tag は今日の意味を維持する**（⊤。「impure、方法については何も述べない」）。
3. **legacy docblock text に未知の label がある場合、tag 全体を unspecified（⊤）として読む**。認識できた subset としては決して読まない。`/** @phpstan-impure database */` は実際に使われている合法的な人間向けメモであり、これによって run が失敗し始めてはならない。この fail-open は意図的なもので、新しい false positive を作らない代わりに bound を黙って失う。したがって enforcement mode では、**vocabulary diagnostic**（未知の label と typo の候補）を組み合わせ、劣化が宣言箇所で少なくとも見えるようにする。structured source は別扱いである。configuration metadata と dynamic-extension result は、保守的な label set を置き換える前に vocabulary 検証しなければならない。structured な未知 label は configuration または extension diagnostic を出し、その override を破棄して callee が宣言した、またはカタログ化された fallback を保持する。
4. **class-level tag は出荷済みの意味をそのまま維持する**（2.1.39）。method tag は class tag を上書きする（unbounded であっても fallback しない）。`all-methods-pure` は constructor には適用されるが void method には適用されない。*class* tag は interface から implementation へ伝播しない。
5. **`@phpstan-pure` は空の bound であり、`mutate.local` は許容する**。これは、enclosing function が所有し、alias されず、frame の外へ escape しない local binding の mutation である。例えば local への `preg_match(..., $matches)`、local copy への `sort($rows)` が該当する。property、static、superglobal、`global` alias、by-ref formal parameter、または escape する capture への by-ref write は **`mutate.local` ではない** ため、引き続き報告される（assignment machinery が独立に捕捉する）。`sort()` 自体は pure ではない。*enclosing function* の envelope が local mutation を解消し、その解消後のその enclosing function だけが memoization または CSE の候補になる。
6. **vocabulary の進化**。leaf の追加によって、*認識済みの* ancestor や sibling の bound が変わることはない（prefix は閉じた列挙ではなく predicate である）。ただし、未知の label として同じ綴りをすでに持っていた docblock の意味は変わり得る（⊤ → bounded）。このような docblock にとって vocabulary の追加は semantic event であり、release notes に記載するべきである。
7. **これをまったく理解しない checker も何も失わない**。tag は boolean の意味を維持し、label は無視される text として付随する。
8. **consumer は必要な effect の部分を選ぶ**。remembering が気にするのは、後続の write が先行する read を無効化できるかどうかである。no-effect statement のルールが気にするのは、結果を破棄したときに call における唯一の observation も破棄されるかどうかである。read label は、call を referentially transparent でなくするというだけの理由で unused-result diagnostic を抑制してはならない。
9. **call-site narrowing は評価位置の値と明示的な type-trust policy を使う**。argument expression は source order で一度だけ評価される。extension が narrowing できるのは、宣言された native または PHPDoc の type view において、捕捉された値からのみである。より狭い set を証明できなければ、保守的な effect を維持する。
10. **constructor には狭い v1 の境界がある**。constructor に宣言された label は `new` を通じて伝播する。configuration metadata または dynamic extension による call-site constructor attribution は v1 の対象外である。

## 提案する v1 vocabulary（25 labels）

```
exit  ffi
global.read  global.write
io  io.db  io.fs  io.fs.read  io.fs.write  io.input  io.ipc  io.net  io.net.http
io.output  io.output.buffer  io.output.header  io.output.stdout  io.output.stderr
io.process  io.signal
mutate  mutate.local
nondet  nondet.random  nondet.time
```

output は `io` 配下の ambient channel であり、一つの問いで分割する。その出力を `ob_start()` で capture できるか。`io.output.buffer`（echo、print、inline HTML、`php://output`）は capture 可能な側である。一方、`.stdout`/`.stderr`（直接 fd に書くもの。`fwrite(STDOUT, …)`、`php://stdout`）と `.header` は機械的にその外側にあるため、将来の masking rule を一つの prefix テストで表せる。bare `io` が output を意図的に許可し、`io.db` は `echo` との境界を維持する。project は vocabulary を拡張できる（`email.send` のような独自 root）。configuration または extension によって third-party symbol に label を付与することもできる。

## 後方互換性：二つの別個の主張

**Parser compatibility（無条件）。** 現在、`@phpstan-impure io` は tag と `GenericTagValueNode("io")` として parse され、PHPStan はこれを無視する。parse error はなく、behavioral change もなく、`InvalidPHPStanDocTagRule` は tag name を正確に一致させる。phpdoc-parser 2.3.3 / phpstan-src 2.2.x で検証済みである。誰でも今すぐ label を書き始められる。

**Semantic migration（opt-in）。** `checkEffectEnvelopes` を有効にすると、既存の認識済み suffix が *再解釈* される。`@phpstan-impure io` は ⊤ から bounded claim `io` に変わり、body が `nondet.time` を実行すると新しい finding が出る。これは意図どおり機能しているが、既存の認識済み suffix に対する semantic change である。そのため default は `false`、bleeding edge は `true` とし、有効化前に既存 suffix を監査できるよう vocabulary diagnostic を設ける。将来の vocabulary 追加にも同じことが当てはまる（semantics rule 6）。

## Trust model（この提案が採る判断）

interface method に付けた docblock envelope は、その interface に対して型付けされた call site で上限として信頼する。この信頼に整合性を持たせるには、implementation がそれを広げてはならない。したがって enforcement には **Liskov inclusion check** を含める。override する method の envelope は、override される側の envelope に subsume されなければならない（既存の impure-overriding-pure check がある `reportMethodPurityOverride` の配下で行う。`pure = ∅`、bare impure = ⊤）。これは、docblock envelope を *unchecked stratum* として読む Steins とは異なる。Steins では envelope は上限にはするが証明はせず、native attribute より下位にあるため Liskov rule の対象外である。PHPStan には docblock しかなく、purity check ですでに `@phpstan-pure`/`@phpstan-impure` の宣言を信頼している。label に対して信頼と substitutability obligation の両方を拡張するのが、PHPStan にとって一貫した選択である。

## 引数条件付き effect：presence、callback、value

二つの tag が body ではなく argument に応じて反転する purity を宣言する。一つは merge 済みで、もう一つは review 中である。

- `@pure-unless-callable-is-impure $cb`（phpstan-src#3482、merge 済み）。callee の effect は callback のものと完全に同じなので、purity は *`$cb` が何をするか* で反転する。
- `@pure-unless-parameter-passed $count`（phpstan-src#6018、提案中）。by-ref out-parameter は caller が binding を渡した場合だけ write されるので、purity は *`$count` が渡されたかどうか* で反転する。

どちらも同じ形、つまり call-site argument に条件付けられた effect であり、一つの軸の両端に位置する。callback tag は effect-polymorphic である。envelope は argument 自身の effect（call site ごとに解決される variable）を含む。parameter tag は presence-gated である。envelope は argument の presence によって有効になる固定 effect（by-ref write）を含む。first-order callback slice はすでに Prior art で Flix 風 effect polymorphism の断片として現れており、parameter のケースは同じ一般化の中で厳密に容易な端にある。

#15224 の報告は value-gated のケースを与える。省略または `null` の encoding は `global.read` を有効にし、既知の non-null encoding はそれを除去する。`?string` は保守的に保持する。逆向きの `pure-unless-parameter-passed` tag では explicit `null` の call を誤分類し、不確実なケースを表現できない。call-site effect extension には、捕捉した argument type から導く value/type-gated な答えが必要である。

label の下では、parameter のケースはもはや tag ではない。by-ref out-parameter の write は parameter ごとの `mutate` effect である。どの argument が渡されたかによって call site ごとに envelope を評価すれば、`@pure-unless-parameter-passed` は annotation ではなく signature から *導出される* 事実になる。同じ理由で、non-optional parameter 上の tag を拒否する branch diagnostic（常に渡される ⇒ pure にならない ⇒ misuse）も消える。non-optional by-ref out-parameter は単に unconditional な `mutate` を持ち、特別扱いは不要である。

二つの tag はそれぞれの導入時期に取り込めるし、後から argument-conditional effect の sugar にできる。別の `pure-unless-Y` tag が現れる前に、一般機構は value-gated effect も扱わなければならない。#15224 は具体的なテストを与える。同じ signature について、omission、explicit `null`、known non-null、nullable unknown がそれぞれ異なる判定にならなければならない。

constructor について、v1 は declared constructor envelope を `new` に通すが、object creation 時に call-site metadata や dynamic extension を呼び出さない。この制限は意図的であり、function と method のケースから推測せず文書化するべきである。

## 先行事例（各 precedent が裏付けるもの）

- **Koka と Flix は user-extensible な effect mechanism を出荷している**ため、閉じた vocabulary より open-with-registration を支持する。Koka の粗い `io` alias には console output も含まれる。これはこの提案が `io.output ⊑ io` とするのと同じ設計判断である（[Koka book](https://koka-lang.github.io/koka/doc/book.html)、[Leijen, MSFP 2014](https://arxiv.org/abs/1406.2061)）。Koka の closed alias と違い、dot-path prefix は open predicate である。それによって vocabulary evolution（semantics rule 6）が可能になる。
- **`mutate.local`** は `runST` の encapsulation argument（[Launchbury & Peyton Jones, PLDI 1994](https://dl.acm.org/doi/10.1145/178243.178246)）に従う。scope の外にいる誰も観測できない state は解消してよい。Koka の `st<h>` と [Flix regions](https://doc.flix.dev/) も同じ手法を使う。
- **`@pure-unless-callable-is-impure`** は（phpdoc-parser に merge 済み）Flix 風 effect polymorphism の first-order slice である（[Madsen & van de Pol, OOPSLA 2020](https://dl.acm.org/doi/10.1145/3428222)）。effect-labeled callable はその自然な拡張であり、作り直しではない。これと `@pure-unless-parameter-passed` が一つの形を共有する方法は、上記 *Argument-conditional effects* を参照。
- **Masking**: `io.output.buffer` の境界により、[Koka の `mask`](https://dl.acm.org/doi/10.1145/3093333.3009872) を一つの label に制限する。
- **Cautionary**: checked propagation を必須にすることには、エルゴノミクス上の反対意見が記録されている（[Hejlsberg on Java's checked exceptions, Artima 2003](https://www.artima.com/articles/the-trouble-with-checked-exceptions)）。ここにあるものはすべて opt-in であり、推論が作業を担う。tag がない場合の意味は今日と同じである。

## 実装例（証拠であり、証明ではない）

**PHPStan branch:** local `worktree-effect-envelope` の `f303c6398`。PHPStan 2.3.x の `77841303f` の上に 12 commit を積み、review 済みの 2.3.x adaptation patch を加えている。この branch は envelope-checking slice を実装する。parsing、`checkEffectEnvelopes` の背後で行う checks、class-level tag、call propagation、constructor propagation、`mutate.local` tolerance、Liskov inclusion、vocabulary diagnostics、sound upper bound として audit した builtin catalog、call site ごとの narrowing、project extension point である。2.3.x patch は `processArgs()` 後に dynamic effect を解決し、各 argument の source-order における evaluation-time type を保持する。完全な suite は 21,983 tests と 97,575 assertions で pass し、PHPStan の self-analysis も error なしと報告する。[実装と adversarial review](20260915-effect-envelope-23x-adaptation-adversarial-review.md) に patch fingerprint と validation command がある。この issue を投稿する前に、local revision を公開コミットへのリンクに置き換えること。

**Steins**（[rigortype/steins](https://github.com/rigortype/steins) @ `735d350`、rustc ≥ 1.97 が必要）は、同じ spec を external analyzer として三方向すべてで実装する。reading（interface-typed call site は `≤ io.db, possibly more` を報告）、checking（作成者の綴りを引用する `effect.envelope-exceeded`）、writing（`steins transform effects-envelope` は **exhaustive inference の場合だけ tag を出力し、non-exhaustive function には tag を付けない**。また、読めない既存 tag には触れない）。その builtin catalog も PHPStan branch と同じ soundness audit を備える。wrapper-capable stream API はデフォルトで `io` とし、証明可能な literal target の場合だけ narrow する（`file_get_contents('https://…')` → `io.net.http`、`fwrite(STDOUT, …)` → `io.output.stdout`、role ごとの `copy`）。そのため envelope checking は両実装で sound upper bound を判定する。

## upstream への未解決事項

1. branch が選んだ project-level registration 付きの固定 v1 vocabulary か、それとも `@phpstan-ignore` のように typo-distance diagnostic だけを備えた完全に open な identifier か。
2. class-level purity tag は interface → implementation に伝播させるべきか（現在は伝播せず、branch もそうしている。上の Liskov check は method-level *envelope* の substitutability に関するもので、別の問いである）。
3. label のある世界で、`all-methods-pure` の void-method exclusion は維持するか。void method でも `@phpstan-impure io.output` を宣言する価値はあり得る。
4. argument-conditional effect、つまり `pure-unless-*` family は first-class envelope feature（parameter ごとの effect を call site ごとに解決）にするべきか。そうすればこれらの tag は独立した概念ではなく derived sugar に縮小できる。
5. `global.read`/`global.write` は internal encoding、default charset、locale、timezone state に対して十分に正確か。それとも v1 で `global.read.encoding` と `global.write.encoding` のような finer leaf を予約するべきか。
