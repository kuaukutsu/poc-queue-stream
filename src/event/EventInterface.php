<?php

declare(strict_types=1);

namespace kuaukutsu\poc\queue\stream\event;

interface EventInterface
{
    public function getMessage(): string;
}
