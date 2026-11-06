# Contributing to phaenon/concurrent

Issues about this package go to [its tracker](https://github.com/phaenonlang/concurrent/issues);
issues about the compiler or the language, to
[phaenonlang/phaenon](https://github.com/phaenonlang/phaenon/issues). A vulnerability is
reported privately: see [SECURITY.md](SECURITY.md).

## Where a change goes

`src/Scope.php` is generated. The Phaenon compiler compiles it from its own source,
[`stdlib/concurrent/Scope.phn`](https://github.com/phaenonlang/phaenon/blob/main/stdlib/concurrent/Scope.phn)
in the compiler repository, with `phn stdlib --concurrent-out src`, and reads that `.phn` as its
declaration of this API. A change to the class, a fix included, is therefore a pull request to
the compiler that changes the `.phn` source; the file here is regenerated from it. An edit to
`src/Scope.php` here would be undone by the next regeneration, and the compiler's test suite
fails while the two disagree.

- The amp and Revolt declarations the compiler checks the class against live beside its source,
  in `stdlib/concurrent/amp/`: an amp method the class starts to use is declared there first.
- `tests/` is edited here.

The compiler emits calls to `Scope::run`, `Scope::spawn`, `Scope::all` and `Scope::map`, so
renaming or removing a public method breaks compiled code: it is a breaking change.

## Running the tests

PHP 8.3 or later and Composer:

```sh
composer install
composer test        # PHPUnit, against the real amp and Revolt
composer stan        # PHPStan at level max over src/ and tests/
```

CI runs both on PHP 8.5 for every pull request.

## Pull requests

A pull request is squash-merged: its title becomes the commit on `main`, and its commits'
messages that commit's body. So a pull request needs:

- **A Conventional Commit title, and Conventional Commits**
  ([conventionalcommits.org](https://www.conventionalcommits.org)): `type(scope): subject`, the
  type one of `feat`, `fix`, `docs`, `style`, `refactor`, `perf`, `test`, `build`, `ci`,
  `chore`, `revert`, with `!` after it (or a `BREAKING CHANGE:` footer) for a breaking change.
  Merge commits are exempt; `fixup!` and `squash!` commits are squashed before the merge.
- **A title as strong as its strongest commit, and no stronger**: a breaking change above
  `feat`, `feat` above `fix` and `revert`, those above the rest. A `fix:` title over a `feat:`
  commit understates the change; a `feat:` title over `docs:` commits promises one that is not
  there.
- **Green checks**: `composer validate --strict`, `composer test` and `composer stan`. A change
  in behaviour comes with the tests that show it.

The PR semantics check enforces the first two points, CI the third.
