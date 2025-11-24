<?php

declare(strict_types=1);

namespace kuaukutsu\poc\queue\stream\event;

use Override;

final readonly class CallbackEvent implements EventInterface
{
    public function __construct(public string $id)
    {
    }

    #[Override]
    public function getMessage(): string
    {
        return sprintf('id: %s', $this->id);
    }
}
