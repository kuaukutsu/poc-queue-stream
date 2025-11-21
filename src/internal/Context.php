<?php

declare(strict_types=1);

namespace kuaukutsu\poc\queue\stream\internal;

use Closure;
use Revolt\EventLoop;
use kuaukutsu\queue\core\SchemaInterface;
use kuaukutsu\poc\queue\stream\internal\stream\RedisStreamGroup;
use kuaukutsu\poc\queue\stream\internal\stream\RedisString;

/**
 * @psalm-internal kuaukutsu\poc\queue\stream
 */
final class Context
{
    /**
     * @var array<string, true>
     */
    private array $callbackList = [];

    /**
     * @var non-empty-string[]
     */
    private array $identityList = [];

    /**
     * @var non-empty-string[]
     */
    private array $payloadList = [];

    /**
     * @param non-negative-int $maxExceededAttempts
     */
    public function __construct(
        public readonly SchemaInterface $schema,
        private readonly RedisStreamGroup $streamGroup,
        private readonly RedisString $string,
        public readonly int $maxExceededAttempts = 3,
    ) {
    }

    /**
     * @param non-empty-string $uuid
     */
    public function getData(string $uuid): ?string
    {
        return $this->string->get($uuid);
    }

    /**
     * @param non-empty-string $source
     * @param non-empty-string $destination
     * @param positive-int $ttl
     */
    public function copyData(string $source, string $destination, int $ttl = 600): bool
    {
        return $this->string->copy($source, $destination, $ttl);
    }

    /**
     * @param non-empty-string $identity
     * @param non-empty-string $payloadUuid
     */
    public function setAck(string $identity, string $payloadUuid): void
    {
        $this->identityList[] = $identity;
        $this->payloadList[] = $payloadUuid;
    }

    public function sendAck(): void
    {
        if ($this->identityList !== []) {
            $this->streamGroup->ack(array_shift($this->identityList), ...$this->identityList);
            $this->identityList = [];
        }

        if ($this->payloadList !== []) {
            $this->string->del(array_shift($this->payloadList), ...$this->payloadList);
            $this->payloadList = [];
        }
    }

    /**
     * @param Closure(string):void $callback
     */
    public function defer(Closure $callback): void
    {
        $callbackId = EventLoop::defer($callback);
        $this->callbackList[$callbackId] = true;
        // truncation tail
        if (count($this->callbackList) > 32) {
            $this->callbackList = array_slice($this->callbackList, -32, null, true);
        }
    }

    public function done(string $callbackId): void
    {
        unset($this->callbackList[$callbackId]);
    }

    public function cancel(): void
    {
        $this->sendAck();
        foreach ($this->callbackList as $callbackId => $_) {
            EventLoop::cancel($callbackId);
        }

        $this->streamGroup->delConsumer();
    }
}
