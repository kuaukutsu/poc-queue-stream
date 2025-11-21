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
    public function __construct(private RedisClient $client)
    {
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
     * @param non-empty-string $source
     * @param non-empty-string $destination
     * @param positive-int $ttl
     */
    public function copy(string $source, string $destination, int $ttl = 600): bool
    {
        $row = $this->client->execute('COPY', $source, $destination);
        if ($row > 0) {
            return $this->client->expireIn($destination, $ttl);
        }

        return false;
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
