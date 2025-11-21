<?php

declare(strict_types=1);

namespace kuaukutsu\poc\queue\stream\internal\stream;

use kuaukutsu\queue\core\SchemaInterface;

/**
 * @psalm-internal kuaukutsu\poc\queue\stream
 */
trait StreamUtils
{
    /**
     * @return non-empty-string
     */
    private function generateKey(SchemaInterface $schema): string
    {
        return 'queue:' . $schema->getRoutingKey();
    }

    /**
     * @param array<non-empty-string, float|int|string> $payload
     * @return list<non-empty-string|float|int|string>
     */
    private function preparePayload(array $payload): array
    {
        $args = [];
        foreach ($payload as $argKey => $argValue) {
            $args[] = $argKey;
            $args[] = $argValue;
        }

        return $args;
    }
}
