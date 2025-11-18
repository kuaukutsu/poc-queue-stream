<?php

declare(strict_types=1);

namespace kuaukutsu\poc\queue\stream\internal;

use Closure;
use Throwable;
use Revolt\EventLoop;
use kuaukutsu\poc\queue\stream\internal\stream\RedisString;
use kuaukutsu\poc\queue\stream\internal\stream\RedisStreamGroup;

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

    public function __construct(
        private readonly RedisString $string,
        private readonly RedisStreamGroup $stream,
        private readonly ?Closure $catch = null,
    ) {
    }

    /**
     * @param ?callable(string, Throwable):void $catch
     */
    public function withCatch(?callable $catch): self
    {
        if ($catch === null) {
            return $this;
        }

        return new Context($this->string, $this->stream, $catch(...));
    }

    public function tryCatch(string $message, Throwable $throwable): bool
    {
        if (is_callable($this->catch)) {
            call_user_func($this->catch, $message, $throwable);
            return true;
        }

        return false;
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
            $this->stream->ack(array_shift($this->identityList), ...$this->identityList);
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
        if (count($this->callbackList) > 3) {
            $this->callbackList = array_slice($this->callbackList, -3, null, true);
        }
    }

    public function cancel(): void
    {
        $this->sendAck();
        foreach ($this->callbackList as $callbackId => $_) {
            EventLoop::cancel($callbackId);
        }
    }
}
