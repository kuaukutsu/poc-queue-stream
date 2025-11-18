<?php

declare(strict_types=1);

namespace kuaukutsu\poc\queue\stream;

use Override;
use Throwable;
use Amp\Redis\RedisClient;
use Revolt\EventLoop;
use kuaukutsu\poc\queue\stream\internal\Context;
use kuaukutsu\poc\queue\stream\internal\stream\RedisString;
use kuaukutsu\poc\queue\stream\internal\stream\RedisStream;
use kuaukutsu\poc\queue\stream\internal\stream\RedisStreamGroup;
use kuaukutsu\poc\queue\stream\internal\workflow\TaskRunner;
use kuaukutsu\poc\queue\stream\internal\workflow\WorkflowClaim;
use kuaukutsu\poc\queue\stream\internal\workflow\WorkflowMain;
use kuaukutsu\queue\core\exception\QueueConsumeException;
use kuaukutsu\queue\core\handler\HandlerInterface;
use kuaukutsu\queue\core\ConsumerInterface;
use kuaukutsu\queue\core\SchemaInterface;

/**
 * @api
 */
final readonly class Consumer implements ConsumerInterface
{
    private Context $ctx;

    private TaskRunner $runner;

    private RedisStreamGroup $stream;

    public function __construct(
        RedisClient $client,
        SchemaInterface $schema,
        HandlerInterface $handler,
        ConsumerOptions $options,
    ) {
        $this->stream = new RedisStreamGroup($client, $schema, $options);

        $string = new RedisString($client);
        $this->ctx = new Context($string, $this->stream);

        $stream = new RedisStream($client, $schema);
        $this->runner = new TaskRunner($string, $stream, $handler);
    }

    /**
     * @param ?callable(string, Throwable):void $catch
     * @throws QueueConsumeException
     */
    #[Override]
    public function consume(?callable $catch = null): void
    {
        $this->stream->create();

        $worflow = new WorkflowMain(
            $this->runner,
            $this->stream,
        );

        EventLoop::queue(
            $worflow(...),
            $this->ctx->withCatch($catch),
            new WorkflowClaim(
                $this->runner,
                $this->stream,
            ),
        );
    }

    public function disconnect(): void
    {
        $this->ctx->cancel();
        $this->stream->delConsumer();
    }
}
