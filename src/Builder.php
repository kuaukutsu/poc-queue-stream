<?php

declare(strict_types=1);

namespace kuaukutsu\poc\queue\stream;

use Override;
use Amp\Redis\RedisConfig;
use Amp\Redis\RedisException;
use kuaukutsu\queue\core\handler\FactoryInterface;
use kuaukutsu\queue\core\handler\HandlerInterface;
use kuaukutsu\queue\core\handler\Pipeline;
use kuaukutsu\queue\core\interceptor\InterceptorInterface;
use kuaukutsu\queue\core\BuilderInterface;
use kuaukutsu\queue\core\SchemaInterface;
use kuaukutsu\poc\queue\stream\internal\stream\RedisStream;
use kuaukutsu\poc\queue\stream\internal\stream\RedisStreamGroup;
use kuaukutsu\poc\queue\stream\internal\stream\RedisString;

use function Amp\Redis\createRedisClient;

/**
 * @api
 */
final class Builder implements BuilderInterface
{
    private RedisConfig $config;

    private StreamOptions $options;

    private HandlerInterface $handler;

    /**
     * @throws RedisException
     */
    public function __construct(
        FactoryInterface $factory,
        ?HandlerInterface $handler = null,
    ) {
        // redis://user:secret@localhost:6379/0
        $this->config = RedisConfig::fromUri('redis://localhost:6379');
        $this->handler = $handler ?? new Pipeline($factory);
        $this->options = new StreamOptions(
            consumerName: uniqid('consumer:', true),
            groupName: 'worker',
        );
    }

    public function withConfig(RedisConfig $config): self
    {
        $clone = clone $this;
        $clone->config = $config;
        return $clone;
    }

    public function withStreamOptions(StreamOptions $options): self
    {
        $clone = clone $this;
        $clone->options = $options;
        return $clone;
    }

    #[Override]
    public function withInterceptors(InterceptorInterface ...$interceptor): self
    {
        $clone = clone $this;
        $clone->handler = $this->handler->withInterceptors(...$interceptor);
        return $clone;
    }

    #[Override]
    public function buildPublisher(): Publisher
    {
        return new Publisher(createRedisClient($this->config));
    }

    #[Override]
    public function buildConsumer(SchemaInterface $schema): Consumer
    {
        $client = createRedisClient($this->config);

        return new Consumer(
            new RedisStream($client, $schema),
            new RedisStreamGroup($client, $schema, $this->options),
            new RedisString($client),
            $this->handler,
        );
    }
}
