<?php

declare(strict_types=1);

namespace kuaukutsu\poc\queue\stream\internal\stream;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Redis\RedisClient;
use kuaukutsu\queue\core\SchemaInterface;

/**
 * @psalm-internal kuaukutsu\poc\queue\stream
 * @psalm-suppress MissingThrowsDocblock
 */
final readonly class RedisStream
{
    use ForbidCloning;
    use ForbidSerialization;
    use StreamUtils;

    public function __construct(private RedisClient $client)
    {
    }

    /**
     * @param array<non-empty-string, float|int|string> $payload
     * @param positive-int|false $maxlen
     * @return ?non-empty-string
     * @see https://redis.io/docs/latest/commands/xadd/
     */
    public function add(SchemaInterface $schema, array $payload, int|false $maxlen = 100_000): ?string
    {
        $identity = $maxlen > 0
            ? $this->client->execute(
                'XADD',
                $this->generateKey($schema),
                'MAXLEN',
                '~',
                $maxlen,
                '*',
                ...$this->preparePayload($payload)
            )
            : $this->client->execute(
                'XADD',
                $this->generateKey($schema),
                '*',
                ...$this->preparePayload($payload)
            );

        if (is_string($identity) && $identity !== '') {
            return $identity;
        }

        return null;
    }
}
