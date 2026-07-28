# PHPStan issue ドラフト（未投稿・v3 feature request 版）

投稿先: https://github.com/phpstan/phpstan/issues/new （メタリポジトリ・**Feature request** テンプレート）
根拠レポート: [20260715-forward-static-call-lsb.md](20260715-forward-static-call-lsb.md)
試作ブランチ: `feature/forward-static-call-return-type`（未 push、3コミット: `376b3774d` 型解決 / `523ec08be` 多段継承テスト / `3cf80b275` 引数チェックルール）
状態: **投稿済み** → https://github.com/phpstan/phpstan/issues/14958 / PR: https://github.com/phpstan/phpstan-src/pull/6051

v2 からの変更: Bug report → **Feature request** に再構成（現状は functionMap の `mixed` 宣言そのままの
純粋な未実装であり、誤った型を返しているわけではないため）。ルール面の現状と提案を追記。

---

## Title

```
Support forward_static_call(): model the forwarded late static binding and check the callable's arguments
```

## Body

```markdown
### Feature request

`forward_static_call()` / `forward_static_call_array()` are *forwarding* calls: they propagate the caller's late static binding, unlike `call_user_func()` which resets it to the named class. PHPStan currently has no support for them beyond the plain `functionMap.php` signature — the return type is declared as `mixed` there, and the arguments are not checked against the callable — so this is an unimplemented feature rather than a wrong result.

(The forwarding / non-forwarding distinction is being documented in php/doc-en#5681.)

https://phpstan.org/r/<playground link>

```php
class Base {
    /** @return static */
    public static function create(): static { return new static(); }
    public static function name(): string { return static::class; }
}

class Caller extends Base {
    public static function test(): void {
        \PHPStan\dumpType(forward_static_call([Base::class, 'create']));       // mixed
        \PHPStan\dumpType(forward_static_call([Base::class, 'name']));         // mixed
        \PHPStan\dumpType(call_user_func([Base::class, 'create']));            // Base   (correct)
        \PHPStan\dumpType(self::create());                                     // static(Caller) (correct)
    }
}
class SubCaller extends Caller {}
SubCaller::test();
```

At runtime (`SubCaller::test()`):

| call | runtime | PHPStan |
|---|---|---|
| `forward_static_call([Base::class,'create'])` | `SubCaller` | `mixed` |
| `forward_static_call_array([Base::class,'create'], [])` | `SubCaller` | `mixed` |
| `forward_static_call([Base::class,'name'])` | `string` | `mixed` |
| `forward_static_call('Base::create')` (string callable) | `SubCaller` | `mixed` |
| `call_user_func([Base::class,'create'])` | `Base` | `Base` ✅ |
| `self::create()` / `parent::create()` | `SubCaller` | `static(Caller)` ✅ |

Proposed inference:

`forward_static_call([Base::class, 'name'])` → `string`
`forward_static_call([Base::class, 'create'])` → `static(Caller)` (the caller's `static`, so a subclass is possible)

On the rules side, a callable that is not callable at all — including a nonexistent method — is already reported by the generic parameter check (`Parameter #1 $callback of function forward_static_call expects callable(): mixed, array{'Base', 'nonexistent'} given.`), but the arguments passed through are not validated against the callable's signature, unlike `call_user_func()` which has `CallUserFuncRule` for this:

```php
forward_static_call([Base::class, 'greet']);     // greet(string $name) — no error today
forward_static_call([Base::class, 'greet'], 42); // no error today
```

### Runtime semantics (verified on PHP 8.5)

- The binding is forwarded only within the caller's own ancestry: naming an ancestor (or self) forwards it, naming an unrelated class resets it to the named class.
- Naming a *descendant* forwards only when the caller's runtime static happens to be a subclass of the named class, so statically the named class's object type covers both outcomes.
- Naming an ancestor calls the *named class's implementation* — overrides in the caller or below it do not re-dispatch — while the binding is still forwarded.
- Instance methods have an active class scope to forward as well.
- In a final class the forwarded binding collapses to the class itself.
- Outside a class scope the call throws (`Error: Cannot call forward_static_call() when no class scope is active`).

### Implementation notes

`FuncCallHandler::resolveType()` special-cases only `call_user_func` / `call_user_func_array`, normalising the args into an inner call node via `ArgumentsNormalizer` and resolving that. `forward_static_call*` have no such branch, so they fall back to `functionMap.php`.

PHPStan already models the native forwarding (`self::`/`parent::`/`static::` → `static(Caller)`) and non-forwarding (`Base::` → `Base`) calls correctly. The non-forwarding reset lives in `StaticCallHandler::resolveTypeByNameWithLateStaticBinding()` (the `getStaticObjectType()` pinning); resolving the named class through `Scope::resolveTypeByName()` *without* that reset yields the caller-bound ancestor type inside the caller's ancestry and a plain object type otherwise — which matches the runtime forwarding rule above exactly, including the descendant and unrelated-class cases.

I have a working prototype and will submit it as a PR:

- it normalizes the callable the same way `call_user_func` does and resolves the synthesized static call without the reset; the multi-level-hierarchy semantics above (distinct implementations per level, final collapse, instance context, generics through the passed arguments) are covered by tests,
- `CallUserFuncRule` is extended to `forward_static_call*`, reusing the same normalization, so missing or mismatched arguments of the forwarded callable are reported like for `call_user_func()`.

One implementation caveat for the record: the synthesized static call has to be resolved directly instead of through `MutatingScope::getType()`, because its printed form would collide with a real, non-forwarding static call on the same class in the expression-type cache.

For reference, no other analyser models the forwarding either — Psalm returns `mixed`, while Phan (`\Base|static`) and Mago (`Base&static`) resolve the return type but treat `forward_static_call` identically to `call_user_func`.

`forward_static_call()` is rarely used, so it is perfectly fine to weigh that against the maintenance cost — though the prototype turned out minimal, since all the needed machinery already exists.

### Did PHPStan help you today? Did it make you happy in any way?

I'm very excited to watch PHPStan Turbo growing on the 2.2.x branch!
```

---

## 投稿前の TODO

- [x] playground: https://phpstan.org/r/d389168e-e5f1-4d6b-a3fb-5134d98fa8e1
- [x] 投稿済み: phpstan/phpstan#14958
- [x] PR 作成済み: phpstan/phpstan-src#6051（base 2.2.x, MERGEABLE）
