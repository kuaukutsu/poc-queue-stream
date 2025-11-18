<?php

declare(strict_types=1);

namespace kuaukutsu\poc\queue\stream\internal\stream;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Redis\Protocol\QueryException;
use Amp\Redis\RedisClient;
use kuaukutsu\queue\core\exception\QueueConsumeException;
use kuaukutsu\queue\core\SchemaInterface;
use kuaukutsu\poc\queue\stream\ConsumerOptions;

/**
 * @psalm-internal kuaukutsu\poc\queue\stream
 * @psalm-suppress MissingThrowsDocblock
 * @psalm-internal kuaukutsu\poc\queue\stream
 */
final readonly class RedisStreamGroup
{
    use ForbidCloning;
    use ForbidSerialization;

    /**
     * @var non-empty-string
     */
    private string $key;

    public function __construct(
        private RedisClient $client,
        private SchemaInterface $schema,
        private ConsumerOptions $options,
    ) {
        $this->key = StreamUtils::generateKey($schema);
    }

    /**
     * @throws QueueConsumeException
     * @see https://redis.io/docs/latest/commands/xgroup-create/
     */
    public function create(): bool
    {
        try {
            $this->client->execute(
                'XGROUP',
                'CREATE',
                $this->key,
                $this->options->groupName,
                '$',
                'MKSTREAM',
            );
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'BUSYGROUP Consumer Group name already exists')) {
                return false;
            }

            throw new QueueConsumeException($this->schema, $e);
        }

        return true;
    }

    /**
     * @return ?array<array{0: non-empty-string, 1: array<non-empty-string, string[]>}>
     * @see https://redis.io/docs/latest/commands/xreadgroup/
     */
    public function read(): ?array
    {
        $result = $this->client->execute(
            'XREADGROUP',
            'GROUP',
            $this->options->groupName,
            $this->options->consumerName,
            'COUNT',
            $this->options->batchSize,
            'BLOCK',
            $this->options->timeoutBlocking,
            'STREAMS',
            $this->key,
            '>',
        );

        if (is_array($result)) {
            /**
             * @var array<array{0: non-empty-string, 1: array<non-empty-string, string[]>}>
             */
            return $result;
        }

        return null;
    }

    /**
     * @return null|array<empty>|array{0: non-empty-string, 1: array<non-empty-string, string[]>}
     * @see https://redis.io/docs/latest/commands/xautoclaim/
     */
    public function autoclaim(string $start = '0-0'): ?array
    {
        $result = $this->client->execute(
            'XAUTOCLAIM',
            $this->key,
            $this->options->groupName,
            $this->options->consumerName,
            $this->options->minIdleTime,
            $start,
            'COUNT',
            $this->options->batchSize,
        );

        if (is_array($result)) {
            /**
             * @var array<empty>|array{0: non-empty-string, 1: array<non-empty-string, string[]>}
             */
            return $result;
        }

        return null;
    }

    /**
     * @param non-empty-string $identity
     * @return array{
     *     "consumer": string,
     *     "elapsedMilliseconds": int,
     *     "deliveryCount": int,
     * }
     */
    public function pending(string $identity): array
    {
        $template = [
            'consumer' => 'unknown',
            'elapsedMilliseconds' => 0,
            'deliveryCount' => 0,
        ];

        $result = $this->client->execute(
            'XPENDING',
            $this->key,
            $this->options->groupName,
            $identity,
            $identity,
            1,
        );

        if (is_array($result)) {
            return [
                /**
                 * @phpstan-ignore offsetAccess.nonOffsetAccessible,cast.string
                 */
                'consumer' => (string)($result[0][1] ?? $template['consumer']),
                'elapsedMilliseconds' => (int)($result[0][2] ?? $template['elapsedMilliseconds']),
                'deliveryCount' => (int)($result[0][3] ?? $template['deliveryCount']),
            ];
        }

        return $template;
    }

    /**
     * @see https://redis.io/docs/latest/commands/xack/
     */
    public function ack(string $identity, string ...$identities): bool
    {
        $result = $this->client->execute(
            'XACK',
            $this->key,
            $this->options->groupName,
            $identity,
            ...$identities,
        );

        return $result > 0;
    }

    public function delConsumer(): bool
    {
        $result = $this->client->execute(
            'XGROUP',
            'DELCONSUMER',
            $this->key,
            $this->options->groupName,
            $this->options->consumerName,
        );

        return $result === 1;
    }
}
