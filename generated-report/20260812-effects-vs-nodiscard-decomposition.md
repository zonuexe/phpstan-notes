# hasSideEffects の三役分解: 文の無効果 / must_use / 記憶の無効化をラベルで整理する

ユーザー提示の 5 参照(下記)は、ひとつの構図の別々の症状に見える:
PHPStan の boolean `hasSideEffects` が**互いに独立な 3〜4 個の述語を
一人で背負っており**、各参照はその無理が表面化した箇所である。エフェクト
ラベルはこの述語群を分解し、それぞれを小さなラベル集合の判定に還元する。

## 0. 参照の症状学

| 参照 | 症状 |
|---|---|
| [phpstan-src#698](https://github.com/phpstan/phpstan-src/pull/698) "Mark file resource functions as having side effects" | ある consumer のために boolean を true に倒すと、他の consumer も道連れになる |
| [phpstan#8440](https://github.com/phpstan/phpstan/issues/8440) `file_get_contents()` の FP | 「読みは捨ててよい効果、通信は捨ててはいけない効果」を 1 bit が表せない。同じ関数が引数次第で両側に落ちる |
| [phpstan-src#2037](https://github.com/phpstan/phpstan-src/pull/2037) "side effects flipped by parameters" | boolean に**引数依存の狭化**を後付けした — 機構としては正しい方向、値域が 1 bit のまま |
| [phpstan#12738](https://github.com/phpstan/phpstan/issues/12738) "must_use function" | 効果とは**直交する第三の軸**(値の必須使用)が boolean に混ざり込めず、別枠を要求 |
| [phpstan-src#3880 の review comment](https://github.com/phpstan/phpstan-src/pull/3880/changes#r1997515279) | boolean 表の手動キュレーションは差分レビューが「この行も怪しい」の応酬になる |

## 1. boolean が背負っている述語(consumer 別)

1. **文の無効果**(`CallTo*StatementWithoutSideEffectsRule`):
   「結果を捨てた呼び出し文は無意味か」。欲しいのは
   「世界に観測可能な変化がない ∧ throw しない ∧ 結果未使用」。
2. **値の記憶と忘却**([remembering-and-forgetting](https://phpstan.org/blog/remembering-and-forgetting-returned-values)):
   実は**二つの副問**がある —
   (a) 同一呼び出しの結果を同一視してよいか(`rand()` は不可 = nondet)、
   (b) この呼び出しは**他の**記憶を無効化するか(`clearstatcache()` は
   stat 系の記憶を消す)。boolean はこの二つを区別できず、`rand()` を
   跨いだだけで `is_dir($x)` の記憶まで捨てる。
3. **must_use / #[\NoDiscard]**(#12738、PHP 8.5 の `#[\NoDiscard]`):
   「結果を捨てることがバグか」。これは効果の性質ではなく**値の関連性**
   の宣言 — 効果からは導出できない軸。
4. (Steins のみ: 定数畳み込みの許可 = 決定的 ∧ 効果空 ∧ width-safe。)

## 2. ラベルによる分解

鍵は **read 効果と write/通信効果の区別**で、これは効果システムの原点
(Lucassen & Gifford の read/write/alloc 区別,
[POPL 1988](https://dl.acm.org/doi/10.1145/73560.73564))がそのまま使える。

**捨ててよい効果の集合**(policy として定義する、仕様の候補):

```
Discardable = { global.read, nondet.random, nondet.time, io.fs.read }
```

- `io.fs.read` は「atime を効果と数えない」という明示の近似判断
  (数えるなら外す — policy がラベル 1 個の出し入れで書ける、が要点)。
- `io.input` は**入らない**: ストリーム位置が進む = 後続の読みから観測可能。
- `mutate.local` も**入らない**: `sort($rows);` は呼び出し元の束縛を変える
  ためにこそ呼ぶ(callee の Pure envelope が許容することと、call site で
  捨ててよいことは別の述語 — ここも boolean では潰れていた区別)。

これで各 consumer は:

1. **文の無効果** = `proven ⊆ Discardable ∧ throws = ∅ ∧ 結果未使用`。
   throw の除外条件は Steins では throws レーンが最初から別勘定
   (ADR-0006「one color, one spelling」)なので追加機構なしで手に入る。
2. **記憶**: (a) 同一視の可否 = `nondet.* ∈ labels` で判定、
   (b) 無効化 = 記憶の依存 footprint とラベルの交差
   (spec の informative 節「labels as invalidation keys」に反映済み)。
   `rand()` は (a) では不可・(b) では何も消さない — boolean では
   表現不能だった組み合わせ。
3. **must_use**: 宣言のまま(導出不能)。ただし**既定が導出できる**:
   `proven ⊆ Discardable` なら捨てる = 死文なので must_use は暗黙に成立
   (注釈不要)。`#[\NoDiscard]` が本当に必要なのは
   「効果があり、かつ結果が本体」の象限だけに縮む。

## 3. 象限表

| | 結果を使う | 結果を捨てる |
|---|---|---|
| proven ⊆ Discardable(+ no throw) | OK | **死文 — 計算で導出、注釈不要**(`ob_get_contents();` は global.read なのでここ。#12738 の動機例は実は導出可能) |
| それ以外の効果あり | OK | 既定 OK(効果のために呼んだ)。`#[\NoDiscard]` が opt-in で報告に変える(`fopen()` 捨て = リーク、がこの象限の真の住人) |

- `DateTimeImmutable::modify()` の捨て忘れ(有名なバグ類型)は左上→右上
  ではなく**第 1 行**: pure メソッドなので計算で導出でき、クラス単位なら
  `@phpstan-all-methods-pure` 1 個で全メソッド分が済む。
- PHP 8.5 の `#[\NoDiscard]` は第 2 行専用の宣言として綺麗に共存する。
  ラベルは NoDiscard を置き換えない — **NoDiscard を書くべき場所を
  最小化し、残りを計算に置き換える**。

## 4. 参照への個別の答え

- **#8440 は #318 の狭化がそのまま解く**: `file_get_contents('/config')`
  → `io.fs.read`(Discardable、捨てたら死文)/
  `file_get_contents($url, false, $ctx)` → 証明不能 → 既定 `io`
  (非 Discardable、報告しない)。#2037 が boolean で近似した
  「context 引数があれば side effects」は、「証明できなければ広い方」
  という狭化の既定則に包含される。
- **#698 の資源関数**: `fopen` は `io`(row)+ `#[\NoDiscard]`(第 2 行の
  真の住人)で、boolean の二役が分離する。
- **#3880 の査読疲れ**: 表が「関数 → ラベル」+「述語 → ラベル集合」に
  分かれると、差分レビューは「このラベルは正しいか」という局所判定になる
  (#318 で 19 関数を一括で広げ・狭化した査読可能性がその実例)。

## 5. 共通仕様への反映候補(未反映 — 提案)

1. spec に informative 節「labels as discardability keys」を追加:
   Discardable 集合の定義と、文の無効果 / must_use の既定導出 /
   `#[\NoDiscard]` との分業(第 3 の consumer — invalidation keys 節の姉妹)。
2. issue draft の scoped-forgetting 節に一文追記: 同じ分解が
   `CallTo*WithoutSideEffectsRule` 族と PHP 8.5 `#[\NoDiscard]` の
   分業も与える(#8440/#2037/#12738 を名指しできる)。
3. Steins 側の将来 issue: 死文検知(`statement.no-effect` 相当)を
   Discardable 述語で実装する(現在 Steins に該当ルールは無い —
   mechanics 層の新 id、proven-only、throws ∅ 条件)。

## 付記: PHPStan 現行実装との整合

`hasSideEffects` の void 硬性規則(void 返し = 常に副作用あり)は
第 1 行の「結果未使用」判定を void 関数に適用しない、という形で
象限表と両立する(void は捨てる結果が無い)。また #2037 の
parameter-flip 機構は、ラベル化後は「引数が証明できない → 広い既定」に
吸収されて専用機構ごと不要になる。
