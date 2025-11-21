<?php

declare(strict_types=1);

namespace kuaukutsu\poc\queue\stream\internal\stream;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Redis\Protocol\QueryException;
use Amp\Redis\RedisClient;
use kuaukutsu\queue\core\exception\QueueConsumeException;
use kuaukutsu\queue\core\SchemaInterface;
use kuaukutsu\poc\queue\stream\StreamOptions;

/**
 * @psalm-internal kuaukutsu\poc\queue\stream
 * @psalm-suppress MissingThrowsDocblock
 */
final readonly class RedisStreamGroup
{
    use ForbidCloning;
    use ForbidSerialization;
    use StreamUtils;

    /**
     * @var non-empty-string
     */
    private string $key;

    public function __construct(
        private RedisClient $client,
        private StreamOptions $options,
        private SchemaInterface $schema,
    ) {
        $this->key = $this->generateKey($this->schema);
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
        } /** @noinspection PhpRedundantCatchClauseInspection */ catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'BUSYGROUP Consumer Group name already exists')) {
                return false;
            }

            throw new QueueConsumeException($this->schema, $e);
        }

        return true;
    }

    /**
     * @param array<non-empty-string, float|int|string> $payload
     * @return ?non-empty-string
     * @see https://redis.io/docs/latest/commands/xadd/
     */
    public function addDLQ(array $payload): ?string
    {
        $key = $this->key;
        if (str_contains($this->options->templateDLQ, '%s')) {
            $key = sprintf($this->options->templateDLQ, $key);
        }

        $identity = $this->client->execute(
            'XADD',
            $key,
            '*',
            ...$this->preparePayload($payload)
        );

        if (is_string($identity) && $identity !== '') {
            return $identity;
        }

        return null;
    }

    /**
     * @return array{}|array<array{0: non-empty-string, 1: ?list<array{0: non-empty-string, 1: string[]}>}>
     * @see https://redis.io/docs/latest/commands/xreadgroup/
     */
    public function read(): array
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
             * @var array<array{0: non-empty-string, 1: ?list<array{0: non-empty-string, 1: string[]}>}>
             */
            return $result;
        }

        return [];
    }

    /**
     * @return array{}|array{0: non-empty-string, 1: ?list<array{0: non-empty-string, 1: string[]}>}
     * @see https://redis.io/docs/latest/commands/xautoclaim/
     */
    public function autoclaim(string $start = '0-0'): array
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
             * @var array{0: non-empty-string, 1: ?list<array{0: non-empty-string, 1: string[]}>}
             */
            return $result;
        }

        return [];
    }

    /**
     * @param non-empty-string $identity
     * @return array{
     *     "consumer": string,
     *     "elapsedMilliseconds": int,
     *     "deliveryCount": int,
     * }
     * @see https://redis.io/docs/latest/commands/xpending/
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
