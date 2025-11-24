<?php

declare(strict_types=1);

namespace kuaukutsu\poc\queue\stream\event;

interface EventSubscriberInterface
{
    /**
     * @return array<string, callable(Event $name, EventInterface $event):void>
     */
    public function subscriptions(): array;
}
