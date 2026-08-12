# Steins カタログ soundness 修正案(レビュー P1-2 対応)

[docs レビュー](20260812-effect-label-docs-adversarial-review.md) P1-2 への Steins 側対応案。
PHPStan 側は stage 9(`c3d89f0fd`)で修正済み — 本案はその鏡。適用はユーザー判断。

## 問題(lib.rs:431-436 現在値)

`file_get_contents`/`fread` → `io.fs.read`、`file_put_contents`/`fwrite`/`copy`/`rename` →
`io.fs.write`、`fopen` → `io.fs` は、URL wrapper・socket・process pipe を通る実行を
`io.fs.*` envelope の下で隠す(upper bound 契約違反)。D-V2 同期(2973666)で直った
readfile/system/curl の output 欠落とは別クラスで、こちらは未修正。

## 修正原則(PHPStan stage 9 と同一)

1. **引数非依存の既定値は sound な上界へ**: wrapper 対応関数 → `io`、
   resource を取る `fread`/`fwrite`/`fgets`/`fputs`/`ftruncate` → `io`
   (provenance 不明の resource は何にでも繋がる)。
2. **狭化は証明できる call site だけ**: 定数文字列パスのスキームテーブル
   (PHPStan 側 `StreamWrapperFunctionEffectExtension` が参照実装 —
   local → `io.fs.*`(fopen は mode 合成)、`http(s)://` → `io.net.http`、
   `ftp/ssh2` → `io.net`、`php://output` → `io.output.buffer`、
   `php://stdout|stderr` → `io.output.{stdout,stderr}`、`php://input|stdin` → `io.input`、
   `php://memory`/`data://` → `mutate.local`、`php://temp`/`zlib`/`phar`/`glob` → `io.fs` 族、
   `php://filter/...resource=` は1段再帰、`expect://` → `io.process`、未知スキーム → 既定 `io`)。
   `fwrite(STDOUT|STDERR)` は定数の構文判定で `io.output.{stdout,stderr}`。
   `copy`/`rename` は per-argument union。
   Steins では call-site 狭化は transfer rule / 既存の symbolic argument 機構のどれに
   載せるかが設計判断(ADR-0064 の 5 seam のうち symbolic argument-dependent transfer が相当)。
3. **回帰テスト**(レビュー指定 + stage 9 相当):
   - `@phpstan-impure io.fs.read` + `file_get_contents('https://…')` → 超過
   - `@param resource` の `fread()` → `io.fs.read` bound で超過
   - socket/process resource への `fwrite()` → `io.fs.write` bound で超過
   - literal local path の陽性対照(狭化して沈黙)
   - `fsockopen('unix://…')` → `io.ipc`(`io.net` bound で超過)
4. **付随裁定の移植**: D-K1(kind ラベル `io.db` は transport を包含 — Redis 相当の
   カタログ行があれば `io.db` へ)、D-P1(`output` = PHP 層 emit、子プロセスの継承 fd は
   `io.process` 内)、D-W1(登録 wrapper は `io` 内という近似)、
   system/passthru/curl の出力成分は `.buffer` でなく親 `io.output`
   (捕獲可能性の資料が割れるため unmaskable 側へ)。

## 修正までの暫定措置(issue draft 側)

draft の Steins 紹介文を「sound upper-bound checker」ではなく
**「prototype with known catalog approximations」**に限定し、
known limitations に generic stream provenance を明記する(書き直し版で対応済み)。
