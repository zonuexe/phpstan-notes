# Effect label issue draft / interop spec 敵対的レビュー

日付: 2026-08-12

## 判定

**FAIL。概念実証としては有望だが、現状のまま PHPStan upstream に仕様提案として投稿する準備はできていない。**

ブロッカーは次の2点である。

1. 「既存 docblock の意味は変わらない」という互換性説明が、提案自身の semantics と矛盾する。
2. 「body の全 effect を包含する upper bound」という契約を、working implementation の組み込みカタログが generic stream / URL-wrapper API で満たしていない。

これらは文章上の言い換えだけでは同時に解消できない。1は移行モデルの明記、2は実装と回帰テストの修正、または working implementation を sound verifier として提示しないことが必要になる。

## 固定した対象

- Issue draft: `/Users/megurine/repo/php/phpstan-notes/generated-report/20260812-issue-draft-effect-labels-spec.md`
  - phpstan-notes HEAD: `ebda704d1492572dd336e5cb36036da0011fc6c2`
- Interop spec: `/Users/megurine/repo/rust/steins/docs/type-specification/phpdoc-effects-interop.md`
  - steins HEAD: `297366690d38c0459b7e8b360b0884270694e976`
- PHPStan PoC の先行監査対象: effect-envelope HEAD `ca2577677d5fb37f21a348e071aa973d10a46545`
- 関連資料: phpstan-notes の `generated-report/20260812-*`

レビュー中に Steins checkout が古い feature branch `1905456d...` から `master` `2973666...` へ更新された。以下は更新後の現在の対象に対する判定である。古い checkout にだけ存在した 21-label vocabulary は finding に含めない。

## Findings

### [P1] 互換性の主張が提案自身の意味論と矛盾する

Issue draft 38行目は「labels only ever narrow」「no existing docblock changes meaning」、64行目は「Nothing widens, nothing is redefined」と断言する。Interop spec 89-91行目と204-206行目も同じ主張をする。一方、両文書は現在の PHPStan が `@phpstan-impure io` の suffix を無視し、boolean な impure、すなわち top として扱うことを明記している（draft 60-64行目、spec 193-201行目）。

提案を有効にすると、既存の `@phpstan-impure io` は top から bounded `io` に変わり、`nondet.time` などに新しい outside-envelope finding を出し得る。これは「構文として既存 parser で安全」と「新 semantics を有効にしても既存 docblock の意味が不変」を混同している。

同じ問題は vocabulary evolution にもある。新しい leaf の追加は、すでに認識されている粗い prefix bound に対しては非破壊的である。しかし、既存 docblock に未知語として書かれていた spelling が新たに vocabulary に入ると、その tag は top から bounded label に変わる。draft 43行目と spec 168-180行目の「adding a leaf is not breaking」は、このケースを除外しない限り一般には成立しない。

影響:

- upstream reviewer は BC の根拠を信用できない。
- default-on で導入すれば、既存コードに新しいエラーが発生し得る。
- default-off / bleeding-edge opt-in で導入しても、その移行条件を説明しない限り「意味は変わらない」という記述は誤りのままである。

最小修正:

- parser-level BC と semantic migration を別節に分ける。
- recognized suffix は feature 有効化時に再解釈される、と明記する。
- 初期導入は default-off、または migration release で行い、既存 suffix の監査方法を示す。
- 「leaf 追加が非破壊的」は、既存の recognized ancestor/sibling bounds に限定する。未知 spelling が既存 docblock に存在する場合は意味が変わると認める。

### [P1] Working implementation が generic stream / URL-wrapper effect を `io.fs.*` に過小近似する

Interop spec 60-66行目は、宣言した labels が body の全 effect を包含する upper bound だと定義する。Issue draft 1行目、9行目、76-85行目は Steins を試せる working implementation として提示する。

しかし現在の Steins catalog は `crates/steins-catalog/src/lib.rs` 431-436行目で次を固定的に分類している。

- `file_get_contents`, `fread` -> `io.fs.read`
- `file_put_contents`, `fwrite`, `copy`, `rename` -> `io.fs.write`
- `fopen` -> `io.fs`

これらの一部は local filesystem に限定されない。例えば `file_get_contents('https://...')` は URL wrapper を使い、`fread(fsockopen(...))` は network stream を読み、`fwrite(popen(...))` は process pipe に書ける。引数や resource provenance を見ない `io.fs.*` は `io.net` や `io.process` の effect を隠すため、segment-prefix envelope の upper bound ではない。

同じ欠陥クラスは先行の PHPStan PoC 監査でも public CLI で再現済みである。`20260812-effect-envelope-adversarial-review-ca2577677.md` が、stream resource と URL wrapper による false negative を P1 として記録している。テストスイートの green は、この境界を保護していない。

なお、D-V2 の `2973666...` では `readfile` / `fpassthru`、`system` / `passthru`、`curl_exec` の direct output omission は `src/lib.rs` 440-446行目で修正されている。現在も残る finding は generic stream / wrapper provenance の過小近似であり、修正済みの3項目を再掲したものではない。

影響:

- `@phpstan-impure io.fs.read` が network read を filesystem-only として通す。
- `@phpstan-impure io.fs.write` が process/network/custom-wrapper write を通す。
- 「working implementation が spec を実証する」という中心的な説得材料が、spec の upper-bound 定義に反する。

最小修正:

- provenance 不明な stream / URL-wrapper API の引数非依存 default を、保守的な `io` または unknown/top にする。
- literal scheme、path、mode、resource provenance が証明できる場合だけ dynamic extension で `io.fs.*`、`io.net`、`io.process` に狭める。
- 少なくとも次の回帰を追加する。
  - `file_get_contents('https://...')` は `io.fs.read` bound を超える。
  - `fread` の arbitrary `resource` は `io.fs.read` だけでは通らない。
  - `fwrite` の socket/process resource は `io.fs.write` だけでは通らない。
  - local literal path を狭める実装を出すなら、その positive case も固定する。
- 直すまで issue draft では「sound upper-bound checker」ではなく「prototype with known catalog approximations」と限定する。

### [P2] `mutate.local` の説明に ownership / alias / escape 条件が欠けている

Issue draft 41行目と interop spec 92-98行目は、`preg_match(..., $matches)` と `sort($rows)` を「caller-frame by-ref writes」「nothing escapes」「no caller can observe」と説明する。この表現では、次の別物が区別されない。

- enclosing function が所有する、非 alias の local binding への一時的変更
- enclosing function の by-ref formal parameter への変更
- `global`、superglobal、property、static、reference alias、escaping closure から観測可能な変更
- `sort()` 呼び出し自体の effect と、それを内部で使う wrapper function の外部観測可能な effect

Steins の実装は文書より慎重である。`crates/steins-infer/tests/mutate_local.rs` は local `preg_match` を許容する一方、property（113-122行目）、superglobal（124-129行目）、by-ref parameter（139-147行目）、global alias（149-157行目）を `mutate.local` から除外する。したがって「実装が無条件に by-ref mutation を pure にする」という指摘は誤りだが、文書は実装に必要な前提を normative に述べていない。

さらに「pure call の memoization が licensed」という draft 42行目の文言は、外側の wrapper function の外部観測可能性にだけ適用できる。`sort($rows)` という call 自体を effect-free として削除・memoize すれば、直後の `$rows` は変わる。

最小修正:

- `mutate.local` を「enclosing function が所有し、alias がなく、関数境界から escape しない local binding の mutation」と定義する。
- by-ref formal parameters、globals、properties/statics、superglobals、`global`、reference aliases、escaping captures を明示的に除外する。
- `sort()` 自体が pure なのではなく、局所 mutation を内部に閉じ込めた enclosing function の envelope から discharge できる、と書く。
- memoization / CSE の許可は discharge 後の enclosing function に限る。

### [P2] Unknown-label と nearest-wins の組み合わせは fail-open である

Issue draft 39-40行目と interop spec 109-131行目は、unknown label で tag 全体を top にし、その tag が class-level bound より優先すると定める。

例えば class に `@phpstan-all-methods-impure io.db`、method に typo の `@phpstan-impure io.dbb` があると、method の envelope は top になり、同時に class の `io.db` bound も抑止される。これは既存の一語メモを壊さないという明示的な BC tradeoff ではあるが、「degrades safely」は片面的である。偽陽性を作らない一方、契約違反を検出しなくなる。

PHPStan PoC でも invalid-label reporting は通常設定では無効、bleeding edge で有効である。したがって typo diagnostic を完全に任意の別問題として扱うと、effect-envelope checking を有効にした利用者が silent fail-open を見落とす。

最小修正:

- legacy compatibility mode と labeled-envelope enforcement mode を分ける。
- enforcement mode では unknown label diagnostic を必須にするか、少なくとも silent top になったことを必ず通知する。
- 「safe」は「new false positive を作らない」という限定語に置き換え、lost enforcement を明記する。

### [P2] Interface contract の trust model と PHPStan PoC の選択が issue draft から見えない

Issue draft 78行目は interface method の docblock bound を call-site で読むと説明し、40行目と90行目は interface-to-implementation propagation を未決事項として残す。Interop spec 212-236行目は Steins 固有の安全弁として、docblock envelope は unchecked stratum であり taint を解除せず、call site では `≤label, possibly more` に留めると定義する。

Steins test `crates/steins-infer/tests/interop_envelope_check.rs` 371-390行目は、pure interface を impure implementation が破っても interop Liskov finding を出さないことを意図的に固定している。この設計は「interface tag を証明として信じない」限り false negative を避けられるが、upper-bound contract を通常の modular contract として利用できない。

一方、PHPStan PoC は `MethodSignatureRule` で親 method envelope への inclusion check を実装しており、Steins の interop stratum と異なる判断をしている。Issue draft が「working implementation」と「upstream の open question」を並べるだけでは、どの trust model を PHPStan に提案しているのか判別できない。

最小修正:

- class-level tag の inheritance と method contract の substitutability を別の質問にする。
- interface docblock を call-site upper bound として信用するなら、override inclusion / Liskov check を必要条件にする。
- Liskov を行わないなら、その bound は non-exhaustive hint で、unknown effects を discharge できないと normative に書く。
- PHPStan PoC がすでに選んだ挙動と Steins の unchecked stratum の差を列挙する。

### [P2] 「prefix test が entire minimal adoption surface」は実装範囲を過小表示する

Issue draft 37行目と interop spec 83-85、187行目は、採用面を segment-wise prefix test に還元する。しかし実際の PHPStan PoC は少なくとも grammar/parser、absent/unbounded/bounded の3状態、vocabulary registry、impure-point attribution、call propagation、reflection API、class/method precedence、override checks、diagnostics、configuration toggles、catalog/extensions を必要とした。関連 port design も stage 1-10 に分割されている。

Prefix test は subsumption relation の core であって、採用 surface 全体ではない。upstream maintainer が工数と BC risk を判断する箇所で、この過小表現は逆効果になる。

最小修正: 「the core subsumption relation is one segment-prefix test」とし、parser、propagation、diagnostics、catalog、BC toggles を MVP の実装面として短く列挙する。

### [P2] Issue draft が #14220 の要求に対する actionable な最小提案になっていない

phpstan/phpstan-src#14220 では maintainer が、提案が vague なので code sample、expected output、必要なら config parameter の提案を求めている。現在の draft には Steins の console output 例はあるが、PHPStan に入力する最小 PHP、期待する PHPStan diagnostic、導入 toggle/config、採用してほしい最小 decision が揃っていない。最後も vocabulary、interface propagation、void method semantics という基礎的な open question で終わる。

また原 issue の user story は exceptions、fibers/generators、no-I/O など configurable purity である。25-label vocabulary へ飛ぶ前に、どのケースを v1 が解くかを mapping しないと、scope expansion に見える。

最小修正:

- 冒頭の ask を「`@phpstan-impure` の suffix を effect-envelope parameter として opt-in で解釈するか」に絞る。
- 1つの PHP input、default config の結果、feature 有効時の expected diagnostic を示す。
- config 名、default、bleeding-edge policy を提案する。
- vocabulary と Steins は evidence / prototype に下げ、MVP の受理条件と未決事項を分ける。

### [P2] Writer の保証を「every call resolved」に弱めており、再現手順も不足する

Issue draft 85行目は tag emission を「only where every call resolved」と要約するが、interop spec 241-248行目の規範は「only from exhaustive inference; non-exhaustive functions get no tag」である。全 call target が解決したことは、dynamic dispatch、extensions、native catalog、reflection、unknown body まで含む inference の exhaustiveness と同義ではない。

また「working implementation to poke at」に対し、draft は pinned commit、build/install 手順、demo file、既知の catalog limitation を示さない。今回のローカル環境では既存 `steins` binary が `effects-envelope` subcommand より古く、source rebuild は rustc 1.95 に対して project requirement 1.97 のため実動 transform まで到達できなかった。これは source defect の証拠ではないが、reviewer が記載どおりに試せる状態ではない。

最小修正:

- 「only from exhaustive inference; non-exhaustive functions get no tag」と正本に合わせる。
- 実装 commit/PR を pin し、1つの clone/build/check/transform 手順と期待出力を載せる。
- current known limitations に generic stream provenance を含める。

### [P3] 出典と prior-art の表現が反論を招く

Ondřej Mirtes の提案自体は確認できる。直接の出典は [2026-08-09 の X 投稿](https://x.com/OndrejMirtes/status/2086491960089444580) であり、引用は次のとおりである。

> The effect could just be a parameter after @phpstan-impure PHPDoc tag 😊 Like @phpstan-impure io

したがって「提案者の裏付けがない」という finding は採用しない。ただし issue draft 9行目の書き方は、引用が #14220 内にあるように読める。X を直接 citation にし、[#14220](https://github.com/phpstan/phpstan-src/issues/14220) は問題背景と maintainer feedback の出典に分けるべきである。

ほかに、次の断言は supplied evidence より強い。

- draft 89行目の「no successful effect system ships a closed vocabulary」は Koka と Flix の例から一般化できない。
- draft 70行目の「match Koka literally」は、その直後に closed alias と open prefix の差を認めており、同一 semantics ではない。
- draft 74行目の「Java checked exceptions failed」は、採用・利用実態と設計批判を一語で断定している。
- draft 68行目の「None of the design is novel for novelty's sake」は根拠を増やさず、防御的に見える。

最小修正:

- 「Koka and Flix support user-defined/open effect mechanisms, so these examples favor open-with-registration」と観測範囲に限定する。
- Koka については「coarse `io` が console/output を含む点を共有する」とする。
- Java は「mandatory checked propagation has documented ergonomics objections」とする。
- 防御的な前置きを削り、各 precedent が支える decision だけを書く。

## 採用しなかった候補指摘

### 25-label vocabulary の文書間不一致

不採用。レビュー開始時の Steins checkout `1905456d...` は旧 21-label `output` vocabulary だったが、現在の対象 `2973666...` は `io.input` と `io.output.*` を含む25 labels で、issue draft 46-56行目と一致する。版ずれは process risk として記録するが、現在の target defect ではない。

### Ondřej Mirtes の提案を確認できない

不採用。提示された X 投稿と公開取得結果で、投稿者、post id、引用本文が一致した。修正点は一次出典へ直接 link することだけである。

### `mutate.local` 実装がすべての by-ref write を pure にする

不採用。Steins tests は property、superglobal、by-ref formal parameter、global alias を除外している。問題は実装ではなく、文書が ownership / escape preconditions と call boundary を説明していないことである。

### Redundant-label の二次時間 DoS

不採用。先行レビューで adversarial cardinality を再検証したが、blocker とする有効な再現を確立できなかった。本文書レビューに持ち込まない。

### `readfile` / `system` / `curl_exec` の output omission

現在形の finding としては不採用。D-V2 で direct mapping は修正済みである。generic stream / wrapper の provenance 問題は別に残る。

## 確認できた適合点

- Grammar の label/comment shape と Doctrine-annotation hazard は phpdoc-parser の挙動と一致する。
- Segment-aware prefix は `io` / `io.net.http` と `iota` を正しく分離する。
- 現在の両文書は D-V2 の25-label vocabulary で一致する。
- Unknown label が tag 全体を top にする規則は、部分的な recognized subset で作者の宣言を捏造しないという目的には一貫している。
- Steins は interop envelopes を unchecked/non-exhaustive lane に残し、interface docblock を証明として扱わない。
- `mutate.local` 実装は alias/escape の代表的な adversarial cases を区別している。
- PHPStan PoC の full tests と static analysis は先行レビュー時点で green だった。ただし catalog soundness の反例を防げなかったため、green suite は P1 finding を覆さない。

## 投稿前の最短修正順

1. Steins と PHPStan PoC の generic stream / URL-wrapper default を保守化し、provenance regression を追加する。
2. BC 節を parser compatibility と opt-in semantic migration に分け、既存 suffix の再解釈を認める。
3. `mutate.local` の ownership / escape / call-boundary 条件を normative にする。
4. unknown-label fail-open を明記し、enforcement mode の diagnostic policy を決める。
5. interface method contract の Liskov / non-exhaustive trust model を1つ選び、Steins と PHPStan PoC の差を説明する。
6. Issue draft を PHPStan input、expected output、config、最小 ask を先頭にした構成へ縮める。
7. Working implementation を commit pin と再現コマンド付きにし、emission contract を「exhaustive inference」に合わせる。
8. X を直接の提案出典にし、prior-art の普遍断言を観測可能な比較へ弱める。

## 検証記録

- 対象2文書と `20260812-*` を全件照合した。
- 現在の phpstan-notes / steins の HEAD と clean status を確認した。
- 仕様、品質、安全性、実動QA、履歴/文脈の5レーンで独立にレビューし、root で候補 finding を反証した。
- QA matrix は6 behavioral scenarios と11 adversarial cases を含む。
- Steins transform の実動確認は、ローカル binary の版不足と rustc 1.95 / required 1.97 の不一致で未完了。source defect とは数えず、再現性リスクとしてのみ扱った。
- 対象文書は変更していない。

## 最終評価

提案の核である「既存 tag の parameter position」「segment-prefix envelope」「unknown を partial bound にしない」「coarse `io` に output を含める」は、upstream discussion に持ち込む価値がある。

ただし、現状の draft は soundness を実証する working implementation と backward-compatible な最小変更を同時に主張しながら、その両方に反例がある。P1 2件を解消し、PHPStan 向けの最小 ask と expected diagnostic に絞るまで、投稿は止めるべきである。
