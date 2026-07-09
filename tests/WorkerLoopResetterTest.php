<?php

declare(strict_types=1);

namespace Folk\Sdk\Tests;

use Folk\Sdk\Reset\ResettableInterface;
use Folk\Sdk\Worker\WorkerLoop;
use PHPUnit\Framework\TestCase;

final class WorkerLoopResetterTest extends TestCase
{
    public function testResettersRunAfterSuccessfulDispatch(): void
    {
        $loop = new WorkerLoop();
        $counter = new class implements ResettableInterface {
            public int $count = 0;
            public function reset(): void
            {
                $this->count++;
            }
        };
        $loop->registerResetter($counter);
        $loop->register('ok', static fn(mixed $params): mixed => ['done' => true]);

        $result = $loop->dispatchDirect('ok', []);

        self::assertSame(['done' => true], $result);
        self::assertSame(1, $counter->count, 'resetter must run after a successful dispatch');
    }

    public function testResettersRunAfterFailedDispatch(): void
    {
        $loop = new WorkerLoop();
        $counter = new class implements ResettableInterface {
            public int $count = 0;
            public function reset(): void
            {
                $this->count++;
            }
        };
        $loop->registerResetter($counter);
        $loop->register('boom', static function (mixed $params): mixed {
            throw new \RuntimeException('handler exploded');
        });

        $result = $loop->dispatchDirect('boom', []);

        self::assertArrayHasKey('__error', $result);
        self::assertSame('handler exploded', $result['__error']);
        self::assertSame(1, $counter->count, 'resetter must run even when the handler throws (#86)');
    }
}
