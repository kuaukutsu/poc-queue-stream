<?php

declare(strict_types=1);

namespace kuaukutsu\poc\queue\stream\internal;

use kuaukutsu\queue\core\QueueTask;
use kuaukutsu\queue\core\TaskInterface;

/**
 * @psalm-internal kuaukutsu\poc\queue\stream
 */
final readonly class Payload
{
    /**
     * @param non-empty-string $uuid
     * @param class-string<TaskInterface> $target
     */
    private function __construct(
        public string $uuid,
        public string $target,
    ) {
    }

    public static function fromTask(QueueTask $task): self
    {
        return new self(
            uuid: $task->getUuid(),
            target: $task->target,
        );
    }

    public static function fromPayload(array $payload): self
    {
        /**
         * @psalm-suppress MixedArgument
         */
        return new self(
            uuid: $payload['uuid'] ?? 'unknown',
            target: $payload['target'] ?? 'unknown',
        );
    }

    /**
     * @return array{"uuid": non-empty-string, "target": class-string<TaskInterface>}
     */
    public function toArray(): array
    {
        return [
            'uuid' => $this->uuid,
            'target' => $this->target,
        ];
    }
}
