<?php

declare(strict_types=1);

namespace kuaukutsu\poc\queue\stream;

use Override;
use Throwable;
use Amp\Redis\RedisClient;
use kuaukutsu\queue\core\exception\QueuePublishException;
use kuaukutsu\queue\core\PublisherInterface;
use kuaukutsu\queue\core\QueueContext;
use kuaukutsu\queue\core\QueueMessage;
use kuaukutsu\queue\core\QueueTask;
use kuaukutsu\queue\core\SchemaInterface;
use kuaukutsu\poc\queue\stream\internal\stream\RedisPublish;
use kuaukutsu\poc\queue\stream\internal\stream\RedisString;
use kuaukutsu\poc\queue\stream\internal\Payload;

use function Amp\async;
use function Amp\Future\await;

/**
 * @api
 */
final readonly class Publisher implements PublisherInterface
{
    public function __construct(private RedisClient $redis)
    {
    }

    /**
     * @return non-empty-string
     * @throws QueuePublishException
     */
    #[Override]
    public function push(SchemaInterface $schema, QueueTask $task, ?QueueContext $context = null): string
    {
        $string = new RedisString($this->redis, $schema);
        $stream = new RedisPublish($this->redis, $schema);

        $string->set(
            $task->getUuid(),
            QueueMessage::makeMessage($task, $context ?? QueueContext::make($schema)),
        );

        try {
            $stream->add(Payload::fromTask($task)->toArray());
        } catch (Throwable $exception) {
            $string->del($task->getUuid());
            throw new QueuePublishException($schema, $exception);
        }

        return $task->getUuid();
    }

    #[Override]
    public function pushBatch(SchemaInterface $schema, iterable $taskBatch, ?QueueContext $context = null): array
    {
        $groupAwait = [];
        foreach ($taskBatch as $task) {
            $groupAwait[] = async($this->push(...), $schema, $task, $context);
        }

        if ($groupAwait === []) {
            return [];
        }

        /**
         * @var list<non-empty-string>
         */
        return await($groupAwait);
    }
}
