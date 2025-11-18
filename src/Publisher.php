<?php

declare(strict_types=1);

namespace kuaukutsu\poc\queue\stream;

use Override;
use Throwable;
use WeakMap;
use Amp\Redis\RedisClient;
use kuaukutsu\poc\queue\stream\internal\Payload;
use kuaukutsu\poc\queue\stream\internal\stream\RedisString;
use kuaukutsu\poc\queue\stream\internal\stream\RedisStream;
use kuaukutsu\queue\core\exception\QueuePublishException;
use kuaukutsu\queue\core\PublisherInterface;
use kuaukutsu\queue\core\QueueContext;
use kuaukutsu\queue\core\QueueMessage;
use kuaukutsu\queue\core\QueueTask;
use kuaukutsu\queue\core\SchemaInterface;

/**
 * @api
 */
final class Publisher implements PublisherInterface
{
    private WeakMap $map;

    public function __construct(private readonly RedisClient $client)
    {
        $this->map = new WeakMap();
    }

    /**
     * @return non-empty-string
     * @throws QueuePublishException
     */
    #[Override]
    public function push(SchemaInterface $schema, QueueTask $task, ?QueueContext $context = null): string
    {
        $storage = new RedisString($this->client);
        $storage->set(
            $task->getUuid(),
            QueueMessage::makeMessage($task, $context ?? QueueContext::make($schema)),
        );

        try {
            $this->makeStream($schema)->add(
                Payload::fromTask($task)->toArray()
            );
        } catch (Throwable $exception) {
            $storage->del($task->getUuid());
            throw new QueuePublishException($schema, $exception);
        }

        return $task->getUuid();
    }

    /**
     * @param list<QueueTask> $taskBatch
     * @return list<non-empty-string>
     * @throws QueuePublishException
     */
    #[Override]
    public function pushBatch(SchemaInterface $schema, array $taskBatch, ?QueueContext $context = null): array
    {
        $list = [];
        foreach ($taskBatch as $task) {
            $list[] = $this->push($schema, $task, $context);
        }

        return $list;
    }

    private function makeStream(SchemaInterface $schema): RedisStream
    {
        /**
         * @var RedisStream
         */
        return $this->map[$schema] ??= new RedisStream($this->client, $schema);
    }
}
