<?php

declare(strict_types=1);

namespace kuaukutsu\poc\queue\stream\event;

use Override;
use Throwable;

final readonly class SystemExceptionEvent implements EventInterface
{
    public function __construct(public Throwable $exception)
    {
    }

    #[Override]
    public function getMessage(): string
    {
        return sprintf('Workflow error: %s', $this->exception->getMessage());
    }
}
