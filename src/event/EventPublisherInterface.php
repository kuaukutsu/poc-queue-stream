<?php

declare(strict_types=1);

namespace kuaukutsu\poc\queue\stream\event;

interface EventPublisherInterface
{
    public function on(EventSubscriberInterface $subscriber): void;

    public function off(EventSubscriberInterface $subscriber): void;
}
