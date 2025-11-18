<?php

declare(strict_types=1);

namespace kuaukutsu\poc\queue\stream\internal\stream;

use kuaukutsu\queue\core\SchemaInterface;

/**
 * @psalm-internal kuaukutsu\poc\queue\stream
 */
final readonly class StreamUtils
{
    private function __construct()
    {
    }

    /**
     * @return non-empty-string
     */
    public static function generateKey(SchemaInterface $schema): string
    {
        return 'queue:' . $schema->getRoutingKey();
    }
}
