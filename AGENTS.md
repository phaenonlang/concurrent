# phaenon/concurrent — agent guide

The Composer package `phaenon/concurrent`: `\Phaenon\Concurrent\Scope`, the class behind
`concurrently { … }` blocks and `spawn` in code compiled from Phaenon (design:
`../design/concurrency.md`, decisions in its §15). It runs on amp v3 and the Revolt event loop
and adds the structure: a block's tasks are joined when it ends, a failure cancels the siblings
and is rethrown, cancellation and deadlines flow down the scope.

The map of every Phaenon repo, the working method and the commit rules are in the compiler
repo: read `../phaenon/AGENTS.md` first.

```sh
composer install
composer test        # PHPUnit 12, against the real amp and Revolt
composer stan        # PHPStan at level max over src/ and tests/
```

## The source is in the compiler

**`src/Scope.php` is generated: never edit it.** The compiler compiles it from its own
`../phaenon/stdlib/concurrent/Scope.phn` — written in Phaenon, with a `concurrently` block
inside, so the class is the first dogfood of its own syntax — with
`phn stdlib --concurrent-out src` (run in this repository), and reads that `.phn` as its
declaration of the API. A wrong behaviour is fixed in the `.phn` source, then regenerated; the
compiler's `TestConcurrentPackageFresh` (`internal/emit`) fails while the copy here is stale,
and `phn stdlib --concurrent-out src --check` reports the same.

The amp and Revolt declarations the compiler relies on live beside the source,
`../phaenon/stdlib/concurrent/amp/*.php`: signatures of amphp/amp 3.1 and revolt/event-loop
1.0. A new amp method the class needs is declared there first.

## Rules

- The compiler emits calls to `Scope::run`, `Scope::spawn`, `Scope::all` and `Scope::map`
  (`../phaenon/internal/emit/concurrency.go`): renaming or removing a public method breaks
  compiled code.
- `composer.json` requires PHP 8.3 and `amphp/amp ^3.1`. The package stays separate from
  `phaenon/runtime` so that a project without concurrency installs no event loop (C4).
- Tests: one final test class per unit in `tests/`, PHPUnit attributes, `self::assert…`; the
  semantics of `../design/concurrency.md` §5 — join at the end, failure cancels siblings, the
  body's exception wins, deadlines, outer cancellation, nesting — are each a test with a real
  loop underneath. PHPStan runs on the tests too.
- Commit messages follow Conventional Commits (`feat:`, `fix:`, `docs:`, `test:`, `build:` …
  with a body that says what changed and why), with no attribution lines and no
  `Co-Authored-By`: the repository will be public.
