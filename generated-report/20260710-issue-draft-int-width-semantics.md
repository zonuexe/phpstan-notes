# Issue draft: int 幅の意味論 (#11711 リブート)

phpstan/phpstan に投げる issue の**最終文面**。
スコープは `PHP_INT_SIZE` / `PHP_INT_MAX` / 整数演算の float 化の意味論の整理に限定する。
`phpIntSize` オプションの提案は「選択肢のひとつ」として言及するに留め、実装 PR はこの issue で方向性が決まってから。

- playground: https://phpstan.org/r/558f9b4a-5762-4ab9-bd63-f708170573be
- 先行して提出済みの実例: [phpstan#14946](https://github.com/phpstan/phpstan/issues/14946) (`abs(PHP_INT_MIN)` internal error) / [phpstan#14947](https://github.com/phpstan/phpstan/issues/14947) (`-PHP_INT_MIN` 誤推論)
- 関連: [20260710-php-int-max-64bit-assumption.md](20260710-php-int-max-64bit-assumption.md)

## Title

> Settle the integer width semantics left over from 32bit support

`left over` が「意図した設計ではない」を含意し、maintainer のコードを責めずに済む。
`settle` は「決めてくれ」であって「こう直せ」ではないので、§5 の 4 択と矛盾しない。
移行軸 (`A migration path away from...`) は選択肢 (2) をタイトルで宣言してしまうため不採用。

次点: Reconcile `PHP_INT_MAX` with PHPStan's 64bit integer model

## Body

### Feature request

A narrow reboot of #11711, which @ondrejmirtes closed after this exchange:

> **@staabm**: I feel 32-bit php is super rare (I know it only from old rasperry-pi) and it doesn't matter for most apps.
>
> **@ondrejmirtes**: I agree, 32bit is extremely rare and I don't know how to do this without being annoying for 64bit users.

I agree with both, and I'm not asking PHPStan to model 32bit PHP.

But a half-model of 32bit is still in there, and it doesn't line up with the rest of the engine. One `+ 1` is enough to derive a type that no PHP build produces.

Every example below comes from this playground: https://phpstan.org/r/558f9b4a-5762-4ab9-bd63-f708170573be

#### 1. `PHP_INT_MAX + 1` infers a type that cannot exist

```php
\PHPStan\dumpType(PHP_INT_MAX + 1); // 2147483648|9.223372036854776E+18
\PHPStan\dumpType(PHP_INT_MAX * 2); // 4294967294|1.8446744073709552E+19
```

`PHP_INT_MAX + 1` overflows on both builds:

| build | value | type |
| --- | --- | --- |
| 64bit | `9.2233720368548E+18` | `float` |
| 32bit | `2147483648.0` | `float` |

No build gives `int(2147483648)`. That `int` shows up because PHPStan takes the 32bit branch of `PHP_INT_MAX` and applies 64bit arithmetic to it.

#### 2. Only the constants know about 32bit

[`ConstantResolver`](https://github.com/phpstan/phpstan-src/blob/2.2.x/src/Analyser/ConstantResolver.php#L221) resolves `PHP_INT_MAX` and `PHP_INT_MIN` into 32/64bit unions. Nothing else in the engine asks how wide an int is:

```php
\PHPStan\dumpType(3000000000);     // 3000000000 (int), a float on 32bit
\PHPStan\dumpType(2147483647 + 1); // 2147483648 (int), a float on 32bit
```

These are all fixed at 64bit:

- integer literal evaluation
- integer overflow to `float`
- `IntegerRangeType`'s bounds, which clamp at the host's `PHP_INT_MAX`

So the 32bit half of the union never meets 32bit semantics anywhere else. It makes nothing safer, it doubles the size of every type derived from `PHP_INT_MAX`, and some of those types hold values that cannot exist.

I hit two more of these while looking into it, both about integer overflow: #14946 (`abs(PHP_INT_MIN)` is an internal error) and #14947 (`-PHP_INT_MIN` is inferred as `PHP_INT_MIN`). I have PRs open for both.

#### 3. The union cannot be narrowed away

PHPStan resolves the three constants independently, so they don't correlate:

```php
if (PHP_INT_SIZE === 4) {
    \PHPStan\dumpType(PHP_INT_MAX);  // 2147483647|9223372036854775807
} elseif (PHP_INT_SIZE === 8) {
    //     ^^^^^^^^^^^^^^^^^^ Strict comparison using === between 8 and 8 will always evaluate to true.
    \PHPStan\dumpType(PHP_INT_MAX);  // 2147483647|9223372036854775807
} else {
    \PHPStan\dumpType(PHP_INT_MAX);  // 2147483647|9223372036854775807  (not *NEVER*)
}
```

`PHP_INT_SIZE` narrows itself, which is where the `identical.alwaysTrue` on the second branch comes from. `PHP_INT_MAX` doesn't follow. A 64bit-only project has no way to tell PHPStan what it already knows, not even with a runtime check.

`parameters.dynamicConstantNames` doesn't help either. `resolveConstant()` calls `resolvePredefinedConstant()` first and returns early, so it never reaches a user-configured type for `PHP_INT_MAX`, even though `conf/config.neon` lists that constant under `dynamicConstantNames`. That's the second half of #14216, which got closed once the first half turned out to be a misunderstanding. Happy to send a separate PR for it.

#### 4. The union also depends on PHPStan's own runtime

```php
if ($resolvedConstantName === 'PHP_INT_MAX') {
    return PHP_INT_SIZE === 8
        ? new UnionType([new ConstantIntegerType(2147483647), new ConstantIntegerType(9223372036854775807)])
        : new ConstantIntegerType(2147483647);
}
```

That `PHP_INT_SIZE` belongs to the PHP process running PHPStan, not to the analysed project. It guards representability: on a 32bit host, `new ConstantIntegerType(9223372036854775807)` would receive a `float`. So running PHPStan on 32bit drops the 64bit branch, while `PHP_INT_SIZE` itself still reports `4|8`. The two constants disagree about which platform is under analysis.

#### 5. Directions

32bit modelling is already ruled out, so today's behaviour carries the cost of the union without any of its safety. Some options:

1. **Make the union real.** Model integer width end to end: literals, overflow to float, `IntegerRangeType` bounds, and correlate the three constants. Consistent, but exactly what #11711 declined.
2. **Drop the 32bit branch.** `PHP_INT_MAX` becomes `9223372036854775807` and `PHP_INT_SIZE` becomes `8`. This matches the rest of the engine, and it matches what `random_int(PHP_INT_MIN, PHP_INT_MAX)` already infers today (`int<-9223372036854775808, 9223372036854775807>`). A BC break, so bleeding edge / 3.0.
3. **Make it configurable**, defaulting to today's behaviour. A project that requires `php-64bit` in `composer.json` is guaranteed 64bit: Composer registers that virtual package only when `PHP_INT_SIZE === 8`, and the generated `vendor/composer/platform_check.php` asserts it at runtime. That makes it zero-config for those projects.
4. **Status quo**, and document that types derived from `PHP_INT_MAX` are imprecise on purpose.

#### 6. (3) and (2) compose into a rollout

They aren't rivals. Done in order, they reach (2) without breaking anyone on the way.

**2.2.x: a `phpIntSize` parameter, opt-in, defaulting to `null`.** Setting `phpIntSize: 8` pins `PHP_INT_MAX`, `PHP_INT_MIN` and `PHP_INT_SIZE` to their 64bit values. Anyone who doesn't set it sees no change, so this fits a minor release. I have it working, along with a schema that rejects `phpIntSize: 4` for now, since nothing else in the engine would honour it.

Pinning `PHP_INT_SIZE` too is deliberate. Leaving it as `4|8` would keep `if (PHP_INT_SIZE === 4)` branches alive while `PHP_INT_MAX` inside them is already the 64bit value, which is the disagreement from section 4, now self-inflicted. Pinning it reports `identical.alwaysFalse` on those branches. For a project that opted in, that is the answer it asked for. A library that still supports 32bit should not set `phpIntSize`.

**Bleeding edge: infer it from `composer.json`.** Reading `require."php-64bit"` costs the user nothing, but it turns the feature on for projects that never asked. Those projects would start seeing `identical.alwaysFalse` on their `PHP_INT_SIZE === 4` branches, and different types everywhere `PHP_INT_MAX` flows. Bleeding edge exists for changes like that.

The same commit would fix a smaller thing: `ComposerPhpVersionFactory` reads `require.php` and nothing else, so a project that writes `"php-64bit": "^8.1"` without a separate `php` constraint gets no `phpVersion` inference at all. Composer treats both as constraints on the same version, and PHPStan should too.

**2.3.x or 3.0.x: make 64bit the default.** Bleeding edge users will have run on it for a cycle by then, and everyone else gets the change at a version boundary where a BC break is expected. `phpIntSize` stays as the escape hatch for whoever wants the union back.

That is the usual opt-in, bleeding edge, default path. It also means (2) doesn't have to be decided today: (3) is useful on its own, and it makes (2) a much smaller step later.

I'd still rather settle the semantics before writing more code. Whichever direction you pick, `PHP_INT_MAX + 1` inferring `int(2147483648)` is a bug, and it's the cheapest thing to point at while deciding.

### Did PHPStan help you today? Did it make you happy in any way?

Yes, as it does every day. I ran into this while chasing something else, and PHPStan's types are precise enough that the wrong one stood out.

---

## 意図した構成

1. **#11711 に反論しない**。「32bit をモデル化してほしい」と読まれた瞬間に同じ理由で閉じられる。
   maintainer の発言を引用したうえで「私も 32bit は要らないと思う」から始める
2. 論点を「現状が中途半端で、存在しない型を出している」に一本化する
3. `PHP_INT_MAX + 1` を最初に置く。1 演算で壊れることが一番強い
4. #14946 / #14947 を「机上の空論ではない」証拠として引く。両方とも提出済みで、
   整数オーバーフローの扱いという同じ話であることが効く
5. ホスト依存の三項演算子は「バグ」ではなく「表現可能性のガード」だと正しく説明する。
   ここを誤読して糾弾すると信用を失う
6. 選択肢を 4 つ並べ、maintainer が選べる形にする。(3) を推すが (2) のほうが良いかもと自分から言う
7. 最後に「どの方向でも `PHP_INT_MAX + 1` は直すべき」で締める。合意点を残す

## 貼る前の残作業

- [ ] `dynamicConstantNames` の件 (#14216 の後半) は 1 段落だけ。深入りせず、別 PR を申し出るに留める
- [ ] #14946 / #14947 がマージまたは反応を得てから投稿するかは要判断。
      先に本 issue を立てても、実例へのリンクとして機能する
