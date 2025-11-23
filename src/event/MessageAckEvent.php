<?php

declare(strict_types=1);

namespace kuaukutsu\poc\queue\stream\event;

use Override;

final readonly class MessageAckEvent implements EventInterface
{
    /**
     * @param non-empty-string[] $identityList
     */
    public function __construct(public array $identityList)
    {
    }

    #[Override]
    public function getMessage(): string
    {
        return implode(', ', $this->identityList);
    }
}
