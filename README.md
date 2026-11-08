# phaenon/concurrent

> **Archived on 2026-11-08.** Concurrency left the Phaenon language until PHP core has a
> scheduler of its own: a `concurrently { }` block compiled to this package, amp and Revolt, three
> production dependencies the compiler chose for its users, and the compiled output of Phaenon
> now depends on nothing but PHP. The decision and its reasons are
> [roadmap D25](https://github.com/phaenonlang/design/blob/main/roadmap.md) and
> [concurrency.md §17](https://github.com/phaenonlang/design/blob/main/concurrency.md); the design
> stays as the specification to map onto PHP's own primitives when a release has them. A program
> that wants amp writes amp. The package is kept for the projects that pinned it; nothing is
> published from here any more.

Structured concurrency for code compiled from [Phaenon](https://phaenon.dev), a typed dialect
of PHP that compiles to PHP. A `concurrently { … }` block and `spawn` in Phaenon source compile
to calls on `\Phaenon\Concurrent\Scope`:

```php
// Phaenon
return concurrently {
    $user = spawn $this->users->find($id);          // Amp\Future<User>
    $orders = spawn $this->orders->forUser($id);    // Amp\Future<list<Order>>

    return new Profile($user->await(), $orders->await());
};
```

```php
// the compiled PHP
return Scope::run(function () use ($id) {
    $user = Scope::spawn(fn () => $this->users->find($id));
    $orders = Scope::spawn(fn () => $this->orders->forUser($id));

    return new Profile($user->await(), $orders->await());
});
```

The package is also usable from plain PHP: `Scope::run()` and `Scope::spawn()` are ordinary
static methods.

Install: `composer require phaenon/concurrent`

## What a scope guarantees

- A task is a Fiber the [Revolt](https://revolt.run) event loop schedules; its handle is
  [amp](https://amphp.org)'s `Future<T>`, awaited with `->await()`. Nothing here is a second
  event loop or a second kind of awaitable: amp code and Phaenon code call each other freely.
- **The block ends only when every task spawned in it has completed.** Tasks the body never
  awaited are awaited at the end.
- **A task's failure fails the block**: the other tasks are cancelled, the scope waits for them
  to finish, and the first failure is rethrown where the block ends — whether or not the body
  awaited the failing handle and caught its exception. When a failure is an answer (a probe
  that gets a 404), the task catches it itself and returns the answer.
- **Cancellation is cooperative**, as in every amp library. `Scope::cancellation()` is the
  current task's cancellation: hand it to amp APIs that accept one, and they are interrupted
  when the block is. `Scope::sleep()`, `Scope::all()`, `Scope::map()` and `Scope::checkpoint()`
  honour it on their own. A task in the middle of a wait that was given no cancellation runs to
  the end of that wait before it notices.
- **A deadline** is `Scope::run($body, timeout: 5.0)`; an outer cancellation is
  `Scope::run($body, cancellation: $token)`. A block that times out throws what amp's
  `TimeoutCancellation` reports: an `Amp\CancelledException` whose previous exception is the
  `Amp\TimeoutException`.
- **Synchronous entry.** The body runs in the calling fiber — the main fiber included — and the
  first wait inside is what turns the loop. A Symfony command, a FrankenPHP request handler or a
  Messenger handler calls a method that fans out inside and gets a value or an exception; no
  `EventLoop::run()` anywhere.

## API

| Method | Does |
|---|---|
| `Scope::run(Closure(): R $body, float\|int\|null $timeout = null, ?Cancellation $cancellation = null): R` | Opens a scope around the body, runs it in the calling fiber, joins every task, returns the body's value |
| `Scope::spawn(Closure(): T $task): Future<T>` | Starts a task in the innermost open scope of the current fiber; `LogicException` outside one |
| `Scope::all(iterable<K, Future<T>> $tasks): array<K, T>` | Awaits handles, results under the same keys, under the ambient cancellation |
| `Scope::map(iterable<K, V> $items, Closure(V): U $task): array<K, U>` | One task per element in a scope of its own; results under the same keys |
| `Scope::cancellation(): Cancellation` | The current task's scope cancellation; a `NullCancellation` outside a block |
| `Scope::sleep(float $seconds): void` | `Amp\delay()` under the ambient cancellation |
| `Scope::checkpoint(): void` | Throws `CancelledException` when the current scope is cancelled |
| `Scope::cancelled(): bool` | Whether the current scope is cancelled |

## Requirements

PHP 8.3 or later and `amphp/amp` 3.1 or later (which brings `revolt/event-loop`). Nothing else:
the package is deliberately separate from `phaenon/runtime` so that a Phaenon project without
concurrency installs no event loop.

## Design

[`concurrency.md`](https://github.com/phaenonlang/design/blob/main/concurrency.md) in the Phaenon
design repository: the model, the spelling, the semantics, the compilation, and the decisions
(C1–C10) behind them.
