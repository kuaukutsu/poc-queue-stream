<?php

declare(strict_types=1);

namespace kuaukutsu\poc\queue\stream\tests\stub;

use Override;
use kuaukutsu\queue\core\SchemaInterface;

enum QueueSchemaStub: string implements SchemaInterface
{
    case low = 'low';
    case high = 'high';
    case redis = 'redis';
    case valkey = 'valkey';
    case dlq = 'dlq';

    #[Override]
    public function getRoutingKey(): string
    {
        return $this->name;
    }
}
