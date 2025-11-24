<?php

declare(strict_types=1);

namespace kuaukutsu\poc\queue\stream\event;

use Override;
use Throwable;
use kuaukutsu\poc\queue\stream\internal\Payload;

final readonly class MessageErrorEvent implements EventInterface
{
    public function __construct(
        public Payload $payload,
        public Throwable $exception,
        public int $attempt = 1,
    ) {
    }

    #[Override]
    public function getMessage(): string
    {
        return sprintf(
            '[%s] Task, attempt: %d, error: %s',
            $this->payload->uuid,
            $this->attempt,
            $this->exception->getMessage(),
        );
    }
}
