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
 * @psalm-internal kuaukutsu\poc\queue\stream
 */
final readonly class RedisStream
{
    use ForbidCloning;
    use ForbidSerialization;

    /**
     * @var non-empty-string
     */
    private string $key;

    public function __construct(
        private RedisClient $client,
        SchemaInterface $schema,
    ) {
        $this->key = StreamUtils::generateKey($schema);
    }

    /**
     * @param array<non-empty-string, float|int|string> $payload
     * @param positive-int|false $maxlen
     * @see https://redis.io/docs/latest/commands/xadd/
     */
    public function add(array $payload, int|false $maxlen = 100_000): ?string
    {
        $args = $this->preparePayload($payload);

        $identity = $maxlen > 0
            ? $this->client->execute(
                'XADD',
                $this->key,
                'MAXLEN',
                '~',
                $maxlen,
                '*',
                ...$args
            )
            : $this->client->execute(
                'XADD',
                $this->key,
                '*',
                ...$args
            );

        if (is_string($identity)) {
            return $identity;
        }

        return null;
    }

    public function dlq(array $payload): ?string
    {
        $identity = $this->client->execute(
            'XADD',
            $this->key . ':dlq',
            '*',
            ...$this->preparePayload($payload)
        );

        if (is_string($identity)) {
            return $identity;
        }

        return null;
    }

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
