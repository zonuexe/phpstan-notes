# issue #14717 考察 — array_keys の位置精緻化と「宣言順の信用」問題

[phpstan/phpstan#14717](https://github.com/phpstan/phpstan/issues/14717)
（zonuexe 起票、VincentLanglet の反論を受け staabm が close・スレッドロック済み）を、
array-shapes vs list-shapes セッション（RFC
[#14939](https://github.com/phpstan/phpstan/discussions/14939)）の監査結果と
突き合わせた考察。

## 対象

- issue の playground: https://phpstan.org/r/fd908965-dae7-42a1-a5ff-0dba29c1c413
- 挙動を変えたコミット: **`cdf4b4df4` "More precise `array_keys` return type"**
  （staabm, 2025-07-17）。**リリースは 2.1.18**（issue は「2.2.0 の regression」と
  していたが誤認。`git tag --contains` = 2.1.18~6）。
- エンジン: `ConstantArrayType::getKeysOrValuesArray()` — optional キーを含む shape
  から、**宣言キー順を前提に**「非空部分列」の list-shape を計算する
  （`maxIndex` ループ。`array{foo?, bar?, baz?, qux?}` →
  `list{0: F|B|Z|Q, 1?: B|Z|Q, 2?: Z|Q, 3?: Q}`）。

## issue 内の議論の再評価

### VincentLanglet の「technically correct / improvement」は *この snippet に限れば* 正しい

OP の配列は `[$key1 => 1, $key2 => 2]` とローカルに構築され、PHPStan は
**挿入順を実際に観測**している。`'foo'|'bar'` と `'baz'|'qux'` は互いに素なので
実行時実現は4通り・常に2要素・常に key1系が先。よって：

- 真の正確な型: `list{'foo'|'bar', 'baz'|'qux'}`
- 2.1.18+ の推論: `list{0: 'bar'|'baz'|'foo'|'qux', 1?: 'bar'|'baz'|'qux', 2?: 'baz'|'qux', 3?: 'qux'}`
- 旧推論: `non-empty-list<'bar'|'baz'|'foo'|'qux'>`

新推論 ⊂ 旧推論（真に精密化）かつ真の型 ⊂ 新推論（健全）。regression ではなく精度向上。
**close 判断はこの snippet に対しては妥当**。

### ただし精度ロスの根は builder にある（RFC と同根 その1）

真の型との差は `getKeysOrValuesArray` ではなく **その前段** で生じている。
`ConstantArrayTypeBuilder::setOffsetValueType()` の union キー展開
（scalarTypes → 独立な optional キー群）は、
**相関を表現できない**：`foo ⊕ bar`（排他）、`baz ⊕ qux`、要素数=ちょうど2、
位置0は foo|bar 限定。optional キーは「独立に在るかも」しか言えず、
XOR 制約は 4-shape の union（`array{foo:1,baz:2}|...`）でしか表せない
（組合せ爆発のため PHPStan は採らない）。これは RFC 用語でいう
「structural set としての shape の表現限界」そのもの。

### そして staabm が要求した false positive は実在する（RFC と同根 その2 = 監査B）

close 条件は「assertType でなく実ルールの false-positive を見せよ」だった。
**構築・確認済み**（現行 stable、playground id `bef901ce-7272-4134-af80-c25301121492`）：

```php
/** @param array{foo?: 1, bar?: 1, baz?: 2, qux?: 2} $a */
function f(array $a): void
{
	$keys = array_keys($a);
	if (count($keys) === 2 && $keys[1] === 'foo') {
		echo "reachable at runtime!\n";
	}
}
f(['qux' => 2, 'foo' => 1]); // 受理される（shape 受理は順序非依存）
```

PHPStan の報告（現行 stable）：
```
Strict comparison using === between 'bar'|'baz'|'qux' and 'foo' will always evaluate to false.
Result of && is always false.
```

しかし実行時は `array_keys(['qux'=>2,'foo'=>1]) === ['qux','foo']` なので
`$keys[1] === 'foo'` は **true**。到達可能な分岐を「always false」と誤報する
**本物の false positive**。

## ギャップの正体 = 出所（provenance）の非区別

同じ `getKeysOrValuesArray` の位置精緻化が：

| shape の出所 | 宣言順の信頼性 | 位置精緻化 |
|---|---|---|
| ローカル構築（挿入順を観測） | 実順序そのもの | **健全**（#14717 の snippet、close 妥当） |
| PHPDoc / 受理経由（順序非依存で受理） | 単なる表記順 | **不健全**（上の false positive） |

`ConstantArrayType` には「順序を観測した」ことを示すビットがなく、
両者を区別できない。これは監査（20260709-pr3872-array-list-shapes.md §B）の
「位置射影（array_values/array_keys/array_slice/array_reverse）は宣言順を信用する
unsound 族」の、**具体的な実ルール false positive 付きの実例**。
#12725（isList Yes 誤報）・#14938（isList No 誤報）と同じ根
——「キー*集合*とキー*列*の混同」——の第3の顔である。

なお RFC の PR-B（isList Maybe 化・list{} 受理強制・describe）は
この false positive を**直さない**：`getKeysOrValuesArray` は isList を見ず
宣言順そのものを使うため。監査で「別トラック」に分離した §B ファミリーに属する。

## 取りうるアクション

1. **新 issue を起票**（#14717 はロック済みで追記不可）：上記 false positive を
   再現コード付きで報告し、#14717 の close 条件が満たされたことと、
   RFC #14939 §（順序の信用）との関連を明記。
   → **起票済み**: https://github.com/phpstan/phpstan/issues/14940 (2026-07-09)

## 判断（2026-07-09、セッション区切り）

**PR は作らず、チームの判断を待つ。** #14938 と異なり、#14940 の候補修正は
すべて方針決定を内包するため：

1. **位置精緻化を弱める** — `cdf4b4df4`（staabm の意図的な精度向上）の部分 revert
   になり、かつ「宣言順を信用する＝健全性より精度」という PHPStan の確立された
   折衷（`array_values → list{1,2}` と同族）への挑戦になる。先回りすべきでない。
2. **provenance ビット**（order-witnessed / order-declared）— 正しい修正だが
   `ConstantArrayType` のコア変更（equals/正規化/result cache に波及）で、
   RFC #14939 の意味論と直接絡む設計判断。
3. **RFC に統合** — §B（順序信用射影族）として提示済み。RFC 自体が反応待ち。

加えて戦術面：本日だけで PR 2件（#6024, #6025）＋ RFC ＋ issue 2件を提出済みで、
#6025/#6026 の A/B 判断も保留中。レビュー帯域を尊重し、ここで区切る。

代わりに [#14940 へ3案を列挙するコメント](https://github.com/phpstan/phpstan/issues/14940#issuecomment-4918455344)
を投稿し、「どの方向でも実装は引き受ける」と表明（#14938→#6025 と同じ協調パターン）。

**再開トリガー**: #14940 / RFC #14939 / #6025 vs #6026 のいずれかに
メンテナの反応があったとき。
2. 修正の方向（新 issue / RFC 側で提案）：
   - **保守的**: PHPDoc 由来（＝順序を観測していない）shape への位置精緻化を
     無効化し、`list{0?: U, 1?: U, 2?: U, 3?: U}`（U=全キー union、count 上限のみ）
     へ落とす。ただし「出所」ビットが型に無いため、現状は区別不能。
   - **本筋（RFC 整合）**: `array{...}` = 順序非依存という RFC モデルの下では、
     位置精緻化は「順序を観測した」配列（builder がリテラルから構築）に限る。
     つまり ConstantArrayType に order-witnessed / order-declared の区別を持たせるか、
     PHPDoc 経由の resolveArrayShapeNode 出力にだけ精緻化を抑制するマーカーを付す。
     RFC への追記事項として有力。
3. #14717 の「2.2.0 regression」という記述は 2.1.18 由来（`cdf4b4df4`）だった、
   という事実修正も新 issue に含める。

## 結論

- VincentLanglet / staabm の close は **提示された snippet に対しては正当**
  （挿入順が観測済みで健全な精密化だから）。
- しかし同じ機構が PHPDoc 由来 shape には **不健全** で、要求されていた
  false positive は実在する（構築・確認済み）。
- #14717 は「間違った例で正しい不安を提起した」issue であり、
  その正しい不安の正体は RFC #14939 の監査 §B（宣言順の信用）に既に
  定式化されている。新 issue として §B ファミリーに具体例を与えるのが次の一手。
