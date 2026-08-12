# 共通エフェクト仕様への追加フィードバック: 前例文献との突き合わせ

対象は Steins / PHPStan 拡張が共有する interop 仕様
(steins: `docs/type-specification/phpdoc-effects-interop.md`、ADR-0082/0083)と
D-V2 後の 25 ラベル語彙。実装から得た知見を、Koka・Flix ほかの前例に接地して
仕様レベルの原則に昇格できるものを選別した。各項に出典を付す。

## 1. 開放語彙の進化原理(規範化を推奨 — 仕様に節を切る価値がある)

prefix subsumption の語彙は**開いている**: 新しい葉 `io.xyz` を足しても、既存の
粗い bound(`@phpstan-impure io`)は自動的にそれを包含する。つまり:

- **葉の追加は宣言を壊さない**。粗い envelope は「その族の将来の細分も許す」と
  最初から言っている。細かい envelope(`io.db`)は新しい兄弟に影響されない。
- **節点の移動・削除は壊す**。D-V2 がその実例で、`output` → `io.output` の移設は
  属性側で breaking(unknown-label)、interop タグ側で安全劣化(タグ全体が無指定)
  という二層の劣化パスを踏んだ。

Koka との対比が原理を照らす: Koka の `io` は**別名(alias)であり、展開すると
効果行の閉じた列挙になる**(`io` は `console`, `net`, `fsys`, `ndet`, `div`,
`exn`, `st<global>` 等へ展開される)。別名は閉じているので、`io` に新メンバーを
足すことは既存の `io` 注釈の意味を変える破壊的変更である。prefix 木はこの点で
Koka の別名より強い進化特性を持つ — 粗い宣言の意味が「列挙」でなく「述語」だから。
代償は、粗い宣言の意味が registry の成長とともに黙って広がること。これは欠陥では
なく設計特性であり、**仕様に明文で書くべき**(下記 §3 の `-except` との相互作用も
この特性から導かれる)。

出典: Daan Leijen, "Koka: Programming with Row Polymorphic Effect Types"
(MSFP 2014); Koka 言語ドキュメント(koka-lang.github.io)の `std/core` 効果
別名。効果システムの起源として Lucassen & Gifford, "Polymorphic Effect
Systems" (POPL 1988)。

## 2. bare `io` ⊇ output の裁定は Koka の `io` と字義どおり一致する

D-V2 の「bare `io` envelope が出力を許容する」は議論の余地がある変更に見えるが、
Koka の `io` 別名は **`console` を含む**。つまり「io と言ったら console 込み」は
最も実績のある効果システムの既定と同一。write-up でこの一文を出典付きで言えると、
「独自の判断」ではなく「前例への収束」として提示できる。

出典: Koka std/core の `io` 別名定義(console を含む)。

## 3. `-except`(補集合上界)は開放語彙と相性が悪い — #312 に書き足すべき原則

正の bound は語彙の成長に対して**安定**(新しい葉は粗い bound に吸収されるだけ)。
補集合 bound `io -except io.db` は成長に対して**不安定**: 新しい葉 `io.xyz` が
増えるたび、除外されなかった側として黙って許容範囲が広がる。宣言者は「io.db 以外
禁止」のつもりで書いたのに、語彙の成長が宣言の意味を広げる。

前例: 行型システムの lacks 制約(「行にラベル ℓ が無い」)がまさに補集合情報で、
Gaster & Jones 以来、開放行と lacks の組み合わせは健全に定義できるが、**意味は
語彙(行変数の具体化)に依存し続ける**。Flix の Boolean 効果代数は補集合を
一級で持つ(¬Console のような式が書ける)が、Flix の効果は宣言されたクローズドな
アルファベット上の代数であり、開放 registry とは前提が違う。結論: `-except` を
実装する日が来たら、「除外は書いた時点の語彙でなく**評価時点の語彙**に対して
解釈される」ことを仕様に明記する(そしてそれが採用の主障壁であることも)。

出典: Benedict R. Gaster & Mark P. Jones, "A Polymorphic Type System for
Extensible Records and Variants" (Technical Report NOTTCS-TR-96-3, 1996);
Magnus Madsen & Jaco van de Pol, "Polymorphic Types and Effects with Boolean
Unification" (OOPSLA 2020)。

## 4. `pure` は停止性を主張しない — checker が purity で何をしてよいかを仕様に

Koka は非停止を効果 `div` として追跡し、`total` は「効果なし**かつ**停止」を
意味する。共通仕様の `@phpstan-pure` は div を持たない語彙上の「観測可能な効果
なし」であり、**停止は主張しない**。これは無害な省略ではなく、consumer 側の
最適化権限に効く: PHPStan は pure な呼び出しの値の記憶・重複呼び出しの同一視を
行う(`rememberPossiblyImpureFunctionValues` の周辺)。呼び出しの重複排除は、
関数が発散する場合に停止挙動を変えうる(2 回呼ぶはずが 1 回になる、は発散側では
観測差になりうる)。実用上は許容で良いが、**「pure は停止性を含意しない。純粋
呼び出しの重複排除・記憶は許可される」を仕様の一文にする**ことを推奨。前例が
まさにこの区別のために語彙を分けている(Koka の `pure` は歴史的に
`<exn,div>` を含む別名で、total と区別される)。

出典: Koka ドキュメントの `total`/`div`/`pure` の区別; Leijen (POPL 2017)。

## 5. `mutate.local` の理論的根拠は runST の被包化定理と同型

「呼び出し元フレームの束縛への by-ref 書き込みは、フレームから漏れなければ
どの呼び出し元からも観測不能 → あらゆる envelope が許容する」は、Haskell の
`runST` が ST 効果を破棄できる論拠(状態スレッドの被包化)の一階版そのもの。
Koka の `st<h>` がヒープ h のスコープ外で観測不能なら破棄できるのも、Flix の
region 内 mutation が region を出れば pure と見なされるのも同じ原理。write-up の
`hasSideEffects` 節にこの一文を足すと、「二度拒否されたフラグの代替」が場当たり
でなく定理の実装であることが伝わる。

出典: John Launchbury & Simon Peyton Jones, "Lazy Functional State Threads"
(PLDI 1994); Koka の `st` 効果と分離; Flix のリージョンとスコープ付き
mutation(doc.flix.dev)。

## 6. 条件付き purity タグ = 効果多相の一階エンコーディング

`@pure-unless-callable-is-impure $cb` は「この関数の効果 = 自身の効果 ⊔ $cb の
効果」という**効果多相**(Flix の `map: (a -> b \ ef, List[a]) -> List[b] \ ef`)を
PHPDoc で書ける範囲に落としたもの。仕様がこの対応を一言述べておくと、将来
「callable 型に効果注釈を持たせる」方向(`callable(): T \ io` 相当)への進化が
場当たりでなく既知の理論の段階的採用だと位置づけられる。Flix の最新形(関連
効果)は trait メソッドの効果をインスタンス側で決めさせる仕組みで、interface
envelope + Liskov の将来形として参考になる。

出典: Madsen & van de Pol (OOPSLA 2020); Matthew Lutze & Magnus Madsen,
"Associated Effects" (PLDI 2024); Flix ドキュメントの効果多相の例。

## 7. マスキング境界の前例: Koka `mask` の制限版

計画中の `@phpstan-masks io.output.buffer $fn`(ob_start ガード)は、Koka の
`mask<eff>`(内側の計算から効果 eff を隠す)を「単一ラベル・単一 HOF」へ制限
したもの。D-V2 が `.buffer` を独立の葉にしたのは、この mask の適用可能域を
prefix 1 個で判定させるため — Koka で言えば「mask できるのは handler が存在する
効果だけ」に対応する構造的事実を、階層自体に運ばせた。注意点も前例から拾える:
mask の健全性は**全経路で** handler(ob_get_clean 系)に到達することに依存する。
例外・early return で ob スタックが閉じない経路が 1 本でもあれば差し引けない —
これが §5.11 の「領域解析 or HOF 注釈」の二択の理論的な出どころ。

出典: Daan Leijen, "Type Directed Compilation of Row-Typed Algebraic Effects"
(POPL 2017 — inject/mask の形式化); Koka ドキュメントの `mask`。

## 8. ベンダー・ルート付き意味ラベルは「名前付き効果インスタンス」の静的版

`sendgrid.mail.send` が `io.net.http` に**重なって**共存する(ADR-0068)のは、
効果ハンドラ研究での「同一効果の複数インスタンスを名前で区別する」問題
(named handlers / effect instances)の静的・ラベル版。あちらでの結論 —
名前は生成時に一意化し、スコープを型で追う — は、こちらでは composer vendor
名 + registry 検証がその役を果たしている。将来、値の provenance をラベルに昇格
させる案(`acme.db.…connection.master` → 効果)を評価するときは、この文献群が
「インスタンス識別を型システムでどこまで運べるか」の限界を示していて参考になる。

出典: Ningning Xie, Youyou Cong, Kazuki Ikemori, Daan Leijen, "First-Class
Names for Effect Handlers" (OOPSLA 2022); 能力ベースの近縁として
Boruch-Gruszecki, Odersky et al., "Capturing Types" (TOPLAS 2023)。

## 9. Open question 1(固定語彙 vs 開放語彙)への推奨回答

前例側の事実: 成功した効果システムで**語彙を閉じたものは無い**。Koka も Flix も
ユーザー定義効果が中心機能で、そのかわり「効果は宣言され、型検査器が知っている」
ことを要求する。開放 + 登録 + 検証(= ADR-0068 の plugin registry と
`effect.unknown-label`)は、この「宣言された効果型」の PHPDoc 圏での道徳的
等価物。upstream への推奨は open-with-registration で、`@phpstan-ignore` の
識別子が既にこの形で運用されている、という一言が一番効く。

## 10. 反面教師: envelope を義務化しないこと(Java checked exceptions)

エフェクト注釈を書かせる仕組みの最大の失敗前例は Java の検査例外 — 中間層に
伝播注釈を強制し、`throws Exception` への退化と wrap-and-rethrow を蔓延させた。
共通仕様が既に取っている姿勢(envelope は永遠に opt-in、推論が仕事をし、宣言は
bound を足すだけ、非網羅は沈黙)は Koka/Flix の「推論が既定、注釈は検査される
文書」と同じ側にあり、これは堅持する価値がある。upstream 議論で「全関数に
書かせるのか」という反応が来たときの応答としてこの節を用意しておく。

出典: Anders Hejlsberg のインタビュー "The Trouble with Checked Exceptions"
(Artima, 2003)が最も引用される定式化。学術的には検査例外は効果システムの
一種(Lucassen-Gifford 系)であり、失敗は理論でなくエルゴノミクスにあった、
という整理が共通理解。

## 適用先の提案(まとめ)

| 項 | 落とし先 |
|---|---|
| §1, §3 | steins の interop 仕様に「Vocabulary evolution」節を新設(規範) + #312 にコメント |
| §2, §5, §6, §7 | write-up(Ondřej 向け)の "Prior art" 節として蒸留 |
| §4 | interop 仕様の pure 定義に一文(「停止性を含意しない」) |
| §8, §9, §10 | upstream 議論が始まったときの応答弾。いまは本レポートに置くだけ |
