<?php

declare(strict_types=1);

namespace kuaukutsu\poc\queue\stream;

use Closure;
use Override;
use Throwable;
use Amp\Redis\RedisClient;
use Revolt\EventLoop;
use kuaukutsu\queue\core\exception\QueueConsumeException;
use kuaukutsu\queue\core\handler\HandlerInterface;
use kuaukutsu\queue\core\ConsumerInterface;
use kuaukutsu\queue\core\SchemaInterface;
use kuaukutsu\poc\queue\stream\event\EventDispatcher;
use kuaukutsu\poc\queue\stream\internal\stream\RedisConsume;
use kuaukutsu\poc\queue\stream\internal\stream\RedisString;
use kuaukutsu\poc\queue\stream\internal\workflow\TaskHandler;
use kuaukutsu\poc\queue\stream\internal\workflow\WorkflowCatch;
use kuaukutsu\poc\queue\stream\internal\workflow\WorkflowClaim;
use kuaukutsu\poc\queue\stream\internal\workflow\WorkflowMain;
use kuaukutsu\poc\queue\stream\internal\Context;

/**
 * @api
 */
final class Consumer implements ConsumerInterface
{
    private ?Context $ctx = null;

    private readonly TaskHandler $handler;

    /**
     * @param ?Closure(?string, Throwable):void $catch
     */
    public function __construct(
        private readonly RedisClient $redis,
        private readonly StreamOptions $options,
        private readonly EventDispatcher $eventDispatcher,
        HandlerInterface $handler,
        ?Closure $catch = null,
    ) {
        $this->handler = new TaskHandler($handler, $catch);
    }

    /**
     * @throws QueueConsumeException
     */
    #[Override]
    public function consume(SchemaInterface $schema): void
    {
        $string = new RedisString($this->redis, $schema);
        $stream = new RedisConsume($this->redis, $this->options, $schema);
        $stream->create();

        $this->ctx = new Context(
            $schema,
            $stream,
            $string,
            $this->eventDispatcher,
        );

        EventLoop::queue(
            (new WorkflowMain($this->handler, $stream))(...),
            $this->ctx,
            new WorkflowClaim($this->handler, $stream),
            new WorkflowCatch($stream),
        );
    }

    #[Override]
    public function disconnect(): void
    {
        if ($this->ctx instanceof Context) {
            $this->ctx->cancel();
        }
    }
}
