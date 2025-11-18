<?php

declare(strict_types=1);

namespace kuaukutsu\poc\queue\stream;

use Override;
use Throwable;
use Revolt\EventLoop;
use kuaukutsu\queue\core\exception\QueueConsumeException;
use kuaukutsu\queue\core\handler\HandlerInterface;
use kuaukutsu\queue\core\ConsumerInterface;
use kuaukutsu\poc\queue\stream\internal\Context;
use kuaukutsu\poc\queue\stream\internal\stream\RedisStream;
use kuaukutsu\poc\queue\stream\internal\stream\RedisStreamGroup;
use kuaukutsu\poc\queue\stream\internal\stream\RedisString;
use kuaukutsu\poc\queue\stream\internal\workflow\TaskRunner;
use kuaukutsu\poc\queue\stream\internal\workflow\WorkflowClaim;
use kuaukutsu\poc\queue\stream\internal\workflow\WorkflowMain;

/**
 * @api
 */
final readonly class Consumer implements ConsumerInterface
{
    private Context $ctx;

    private TaskRunner $runner;

    public function __construct(
        RedisStream $stream,
        private RedisStreamGroup $streamGroup,
        RedisString $string,
        HandlerInterface $handler,
    ) {
        $this->ctx = new Context($string, $this->streamGroup);
        $this->runner = new TaskRunner($string, $stream, $this->streamGroup, $handler);
    }

    /**
     * @param ?callable(string, Throwable):void $catch
     * @throws QueueConsumeException
     */
    #[Override]
    public function consume(?callable $catch = null): void
    {
        $this->streamGroup->create();

        $worflow = new WorkflowMain(
            $this->runner,
            $this->streamGroup,
        );

        EventLoop::queue(
            $worflow(...),
            $this->ctx->withCatch($catch),
            new WorkflowClaim(
                $this->runner,
                $this->streamGroup,
            ),
        );
    }

    public function disconnect(): void
    {
        $this->ctx->cancel();
        $this->streamGroup->delConsumer();
    }
}
