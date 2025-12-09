<?php

declare(strict_types=1);

namespace kuaukutsu\poc\queue\stream\internal;

use Closure;
use Revolt\EventLoop;
use kuaukutsu\queue\core\SchemaInterface;
use kuaukutsu\poc\queue\stream\event\Event;
use kuaukutsu\poc\queue\stream\event\EventInterface;
use kuaukutsu\poc\queue\stream\event\EventDispatcher;
use kuaukutsu\poc\queue\stream\event\MessageAckEvent;
use kuaukutsu\poc\queue\stream\event\CallbackEvent;
use kuaukutsu\poc\queue\stream\internal\stream\RedisConsume;
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
        private readonly RedisConsume $streamGroup,
        private readonly RedisString $string,
        private readonly EventDispatcher $eventDispatcher,
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
     * @param non-empty-string $uuid
     * @param non-empty-string $value
     * @param positive-int $ttl
     */
    public function setData(string $uuid, string $value, int $ttl = 600): bool
    {
        return $this->string->set($uuid, $value, $ttl);
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

    public function trigger(Event $name, EventInterface $event): void
    {
        $fn = $this->eventDispatcher->trigger(...);
        EventLoop::defer(
            static function () use ($fn, $name, $event): void {
                $fn($name, $event);
            }
        );
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
            $this->streamGroup->ack($this->identityList[0], ...array_slice($this->identityList, 1));
            $this->trigger(Event::MessageAck, new MessageAckEvent($this->identityList));
            $this->identityList = [];
        }

        if ($this->payloadList !== []) {
            $this->string->del($this->payloadList[0], ...array_slice($this->payloadList, 1));
            $this->payloadList = [];
        }
    }

    /**
     * @param Closure(string):void $callback
     */
    public function defer(Closure $callback): void
    {
        $callbackId = EventLoop::defer($callback);
        $this->trigger(Event::CallbackDeferred, new CallbackEvent($callbackId));
        $this->callbackList[$callbackId] = true;
        // truncation tail
        if (count($this->callbackList) > 128) {
            $this->callbackList = array_slice($this->callbackList, -128, null, true);
        }
    }

    public function done(string $callbackId): void
    {
        $this->trigger(Event::CallbackDone, new CallbackEvent($callbackId));
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
