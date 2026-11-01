<?php

declare(strict_types=1);

namespace Phaenon\Concurrent\Tests;

use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\NullCancellation;
use Amp\TimeoutException;
use LogicException;
use Phaenon\Concurrent\Scope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use RuntimeException;

use function Amp\delay;
use function Amp\now;

final class ScopeTest extends TestCase
{
    #[Test]
    public function theBodyValueIsTheBlockValueAndTasksRunConcurrently(): void
    {
        $start = now();
        $value = Scope::run(function (): string {
            $a = Scope::spawn(static function (): string {
                delay(0.05);

                return 'a';
            });
            $b = Scope::spawn(static function (): string {
                delay(0.05);

                return 'b';
            });

            return $a->await().$b->await();
        });
        self::assertSame('ab', $value);
        self::assertLessThan(0.09, now() - $start, 'the two tasks waited at the same time');
    }

    #[Test]
    public function tasksNobodyAwaitedAreJoinedWhenTheBlockEnds(): void
    {
        $done = [];
        Scope::run(function () use (&$done): void {
            Scope::spawn(static function () use (&$done): void {
                delay(0.02);
                $done[] = 'late';
            });
            Scope::spawn(static function () use (&$done): void {
                $done[] = 'early';
            });
        });
        self::assertSame(['early', 'late'], $done);
    }

    #[Test]
    public function aFailingTaskCancelsItsSiblingsAndFailsTheBlock(): void
    {
        $siblingEnd = null;
        $start = now();
        try {
            Scope::run(function () use (&$siblingEnd): void {
                Scope::spawn(static function () use (&$siblingEnd): void {
                    try {
                        Scope::sleep(10.0);
                    } catch (CancelledException $e) {
                        $siblingEnd = $e;
                        throw $e;
                    }
                });
                Scope::spawn(static function (): void {
                    delay(0.01);
                    throw new RuntimeException('task failed');
                });
            });
            self::fail('the block must throw');
        } catch (RuntimeException $e) {
            self::assertSame('task failed', $e->getMessage());
        }
        self::assertInstanceOf(CancelledException::class, $siblingEnd);
        self::assertLessThan(1.0, now() - $start, 'the sleeping sibling was cancelled, not awaited');
    }

    #[Test]
    public function aTaskFailureFailsTheBlockEvenWhenTheBodyCaughtIt(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('probe');
        Scope::run(function (): int {
            $t = Scope::spawn(static function (): int {
                throw new RuntimeException('probe');
            });
            try {
                return $t->await();
            } catch (RuntimeException) {
                return -1; // caught here, but the block still fails (C5)
            }
        });
    }

    #[Test]
    public function theBodyExceptionWinsAndTheTasksAreCancelled(): void
    {
        $cancelled = false;
        $caught = null;
        try {
            Scope::run(function () use (&$cancelled): void {
                Scope::spawn(static function () use (&$cancelled): void {
                    try {
                        Scope::sleep(10.0);
                    } catch (CancelledException) {
                        $cancelled = true;
                    }
                });
                delay(0.01);
                throw new LogicException('body failed');
            });
        } catch (LogicException $e) {
            $caught = $e;
        }
        self::assertSame('body failed', $caught->getMessage());
        self::assertTrue($cancelled);
    }

    #[Test]
    public function aChildFailureReplacesTheCancellationTheBodyReceives(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('child first');
        Scope::run(function (): void {
            Scope::spawn(static function (): void {
                delay(0.01);
                throw new RuntimeException('child first');
            });
            Scope::sleep(10.0); // interrupted by the scope's own cancellation
        });
    }

    #[Test]
    public function aDeadlineCancelsTheBlockAsAmpReportsIt(): void
    {
        $start = now();
        $caught = null;
        try {
            Scope::run(function (): void {
                Scope::spawn(static fn () => Scope::sleep(10.0));
                Scope::sleep(10.0);
            }, timeout: 0.05);
        } catch (CancelledException $e) {
            $caught = $e;
        }
        self::assertNotNull($caught);
        self::assertInstanceOf(TimeoutException::class, $caught->getPrevious());
        self::assertLessThan(1.0, now() - $start);
    }

    #[Test]
    public function anOuterCancellationCancelsTheBlock(): void
    {
        $stop = new DeferredCancellation();
        EventLoop::delay(0.02, static fn () => $stop->cancel());
        $this->expectException(CancelledException::class);
        Scope::run(function (): void {
            Scope::spawn(static fn () => Scope::sleep(10.0));
        }, cancellation: $stop->getCancellation());
    }

    #[Test]
    public function spawnOutsideABlockIsALogicError(): void
    {
        $this->expectException(LogicException::class);
        Scope::spawn(static fn (): int => 1);
    }

    #[Test]
    public function nestedBlocksArePartOfTheirTaskAndFailuresPropagate(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('deep');
        Scope::run(function (): void {
            Scope::spawn(static function (): void {
                Scope::run(function (): void {
                    Scope::spawn(static function (): void {
                        throw new RuntimeException('deep');
                    });
                });
            });
        });
    }

    #[Test]
    public function aTaskSpawnsIntoItsOwnBlock(): void
    {
        $seen = [];
        Scope::run(function () use (&$seen): void {
            Scope::spawn(static function () use (&$seen): void {
                Scope::spawn(static function () use (&$seen): void {
                    delay(0.01);
                    $seen[] = 'grandchild';
                });
                $seen[] = 'child';
            });
        });
        self::assertSame(['child', 'grandchild'], $seen);
    }

    #[Test]
    public function mapKeepsKeysAndAllAwaitsHandles(): void
    {
        $squares = Scope::map(['a' => 2, 'b' => 3], static fn (int $n): int => $n * $n);
        self::assertSame(['a' => 4, 'b' => 9], $squares);

        $sum = Scope::run(function (): int {
            $tasks = [];
            foreach ([1, 2, 3] as $n) {
                $tasks[] = Scope::spawn(static fn (): int => $n * 10);
            }

            return array_sum(Scope::all($tasks));
        });
        self::assertSame(60, $sum);
    }

    #[Test]
    public function cancellationOutsideABlockNeverFires(): void
    {
        self::assertInstanceOf(NullCancellation::class, Scope::cancellation());
        self::assertFalse(Scope::cancelled());
        Scope::checkpoint();
    }

    #[Test]
    public function aBlockLeavesNoScopeBehind(): void
    {
        Scope::run(static fn (): int => 1);
        try {
            Scope::run(static function (): void {
                throw new RuntimeException('x');
            });
        } catch (RuntimeException) {
        }
        self::assertInstanceOf(NullCancellation::class, Scope::cancellation(), 'the stack is empty again after both blocks');
    }
}
