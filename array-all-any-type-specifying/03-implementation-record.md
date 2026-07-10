# 実装記録と検証結果

日付: 2026-07-10
実装: Opus サブエージェント、レビュー・検証: メインループ (Fable)

## 最終構成

**新規 (5):**
- `ArrayCallbackParameterMapping` — value object。callback のどの位置引数が value/key か (filter の USE_ITEM/USE_KEY/USE_BOTH と all/any の valueAndKey を統一表現)
- `ArrayCallbackPredicate` — value object。`(itemVar, keyVar, 述語Expr)`。`expr === null` は「無効な関数名」(filter は ErrorType、all/any は narrowing 断念)
- `ArrayPredicateCallbackResolver` — 共有関心事1(callback 正規化: ArrowFunction / 単一return Closure / FCC / 定数文字列)+ 共有関心事2(`assignPredicateVariables`)。by-ref / variadic / 非Variable / 0引数は bail
- `ArrayAllAnyNarrowingHelper` — 要素 narrowing の型構築。`truthy` フラグで all(truthy絞り)/any(falsey絞り)切替。一般 ArrayType + ConstantArrayType オフセット単位精密化
- `ArrayAnyFunctionTypeSpecifyingExtension`

**変更 (2):** `ArrayAllFunctionTypeSpecifyingExtension`(全面書き直し)、`ArrayFilterFunctionReturnTypeHelper`(resolver 経由にリファクタ)

## 仕様からの逸脱と検証

### 逸脱1(採用・検証済み): 分岐判定は `$context->false()`

仕様書は「truthy/falsy で分岐」と書いたが、`array_all(...) === true` の else 分岐では context が `negate(createTrue)` = `0b1110` となり、**`truthy()` と `falsey()` の両方が true を返す**。TypeSpecifierContext のビット定義 (TRUE=0b0001, TRUTHY_BUT_NOT_TRUE=0b0010, FALSE=0b0100, FALSEY_BUT_NOT_FALSE=0b1000) をメインループで検証した結果:

| 書き方 | 分岐 | context値 | `false()` |
|---|---|---|---|
| `if (array_all(...))` | then | 0b0011 | false → 要素絞り込み |
| `if (!array_all(...))` | then | 0b1100 | true → non-empty |
| `=== true` | else | 0b1110 | true → non-empty |
| `=== false` | then | 0b0100 | true → non-empty |
| `=== false` | else | 0b1011 | false → 要素絞り込み |

bool を返す関数では TRUTHY_BUT_NOT_TRUE / FALSEY_BUT_NOT_FALSE は実現不能なので、**FALSE ビットの有無 = 「この分岐に false 結果が含まれるか」**が正確な判別子になる。これは他の bool 関数拡張にも使える知見。

### 逸脱2(採用): 定数文字列 callable の union は要素絞り込みスキップ

`$cond ? 'is_int' : 'is_string'` のような複数述語は non-empty 側のみ適用。
**将来の改善余地**: 各述語の narrowing の union を取れば健全かつ精密にできる(all-truthy: 「全て int または全て string」→ `array<int>|array<string>`)。小さい追加だが PR スコープ管理のため見送り。

### array_filter の挙動変更(意図的・改善)

- by-ref パラメータ: 旧実装は絞り込んでいた(callback が要素を書き換えうるので疑わしい)→ 新実装は bail
- variadic: 旧実装は `...$args` を item 変数として誤って扱っていた → bail
- Error ノードのパラメータ: 旧実装は ShouldNotHappenException を throw → bail
既存テスト(NodeScopeResolverTest 全 1686 件)は全通過。

## 検証(メインループで独立再実行)

- プローブ全項目修正確認: C/D→`array<mixed~null>`、E→`array{}`+誤報消滅、F→`non-empty-array<mixed>`、J/L→falsy除去、H(文字列callable)→`array<int>`
- nsrt array-all/array-any/array-filter/array-find (10 tests) OK
- ImpossibleCheckTypeFunctionCallRuleTest (100 tests) OK — `non-empty-list`+`is_string($k)` の always-false が正当に報告される新テスト含む
- phpcs / phpstan self-analysis エラーゼロ
- red/green: エージェントが拡張退避+dump-autoload で red を確認。メインループでも旧実装(コミット 64eb09553)に対するプローブ失敗 → 新実装で成功を独立観測

## 将来課題(PR スコープ外)

- 定数文字列 callable union の narrowing 合成(上記逸脱2)
- `mixed` 入力(`getArrays()` が空)での要素絞り込み — 現状 non-empty 側のみ
- 変数に格納した閉包(ClosureType は AST を持たない)— non-empty のみ適用
- `array_find`/`array_find_key` の truthy → non-empty(`!== null` は null コンテキスト対応が必要で別機構)
- 0引数 callback + 非空配列でのキャプチャ変数 narrowing
