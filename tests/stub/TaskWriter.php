<?php

declare(strict_types=1);

namespace kuaukutsu\poc\queue\stream\tests\stub;

use Revolt\EventLoop;
use kuaukutsu\queue\core\QueueContext;

final readonly class TaskWriter
{
    public function print(int $id, string $name, QueueContext $context): void
    {
        $delay = ($id % 5) === 0 ? 2. : 0.5;

        // check  non-blocking
        EventLoop::delay($delay, static function () use ($id, $name, $context): void {
            echo sprintf(
                "task: %d, %s, route: %s, date: %s\r\n",
                $id,
                $name,
                $context->routingKey,
                $context->createdAt,
            );
        });
    }
}
