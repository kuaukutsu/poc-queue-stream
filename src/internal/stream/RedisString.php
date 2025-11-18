<?php

declare(strict_types=1);

namespace kuaukutsu\poc\queue\stream\internal\stream;

use Throwable;
use Amp\Redis\Command\Option\SetOptions;
use Amp\Redis\RedisClient;

/**
 * @psalm-internal kuaukutsu\poc\queue\stream
 */
final readonly class RedisString
{
    public function __construct(
        private RedisClient $client,
    ) {
    }

    /**
     * @param non-empty-string $uuid
     * @param non-empty-string $value
     * @param positive-int $ttl
     */
    public function set(string $uuid, string $value, int $ttl = 600): bool
    {
        $options = new SetOptions();
        return $this->client->set($uuid, $value, $options->withTtl($ttl));
    }

    /**
     * @param non-empty-string $uuid
     * @param positive-int $ttl
     */
    public function expire(string $uuid, int $ttl = 900): bool
    {
        return $this->client->expireIn($uuid, $ttl);
    }

    /**
     * @param non-empty-string $uuid
     */
    public function get(string $uuid): ?string
    {
        $message = $this->client->get($uuid);
        if ($message === null || $message === '') {
            return null;
        }

        return $message;
    }

    /**
     * @param non-empty-string $uuid
     * @param non-empty-string ...$uuids
     */
    public function del(string $uuid, string ...$uuids): int
    {
        try {
            return $this->client->delete($uuid, ...$uuids);
        } catch (Throwable) {
            return 0;
        }
    }
}
