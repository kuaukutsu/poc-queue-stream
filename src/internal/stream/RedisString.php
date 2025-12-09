<?php

declare(strict_types=1);

namespace kuaukutsu\poc\queue\stream\internal\stream;

use Throwable;
use Amp\Redis\Command\Option\SetOptions;
use Amp\Redis\RedisClient;
use kuaukutsu\queue\core\SchemaInterface;

/**
 * @psalm-internal kuaukutsu\poc\queue\stream
 */
final readonly class RedisString
{
    public function __construct(
        private RedisClient $client,
        private SchemaInterface $schema,
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
        return $this->client->set($this->generateKey($uuid), $value, $options->withTtl($ttl));
    }

    /**
     * @param non-empty-string $source
     * @param non-empty-string $destination
     * @param positive-int $ttl
     */
    public function copy(string $source, string $destination, int $ttl = 600): bool
    {
        $source = $this->generateKey($source);
        $destination = $this->generateKey($destination);

        if ($this->client->execute('COPY', $source, $destination) > 0) {
            return $this->client->expireIn($destination, $ttl);
        }

        return false;
    }

    /**
     * @param non-empty-string $uuid
     */
    public function get(string $uuid): ?string
    {
        $message = $this->client->get($this->generateKey($uuid));
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
            return $this->client->delete(
                $this->generateKey($uuid),
                ...array_map($this->generateKey(...), $uuids)
            );
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @param non-empty-string $uuid
     * @return non-empty-string
     */
    private function generateKey(string $uuid): string
    {
        return hash('xxh3', $uuid . $this->schema->getRoutingKey());
    }
}
