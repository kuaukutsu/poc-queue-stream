<?php

declare(strict_types=1);

namespace kuaukutsu\poc\queue\stream\internal\workflow;

use Throwable;
use Amp\CancelledException;
use kuaukutsu\queue\core\QueueMessage;
use kuaukutsu\poc\queue\stream\event\Event;
use kuaukutsu\poc\queue\stream\event\MessageErrorEvent;
use kuaukutsu\poc\queue\stream\event\SystemExceptionEvent;
use kuaukutsu\poc\queue\stream\exception\WorkflowException;
use kuaukutsu\poc\queue\stream\internal\stream\RedisConsume;
use kuaukutsu\poc\queue\stream\internal\Context;
use kuaukutsu\poc\queue\stream\internal\Payload;

use function Amp\async;

/**
 * @psalm-internal kuaukutsu\poc\queue\stream
 */
final readonly class WorkflowCatch
{
    public function __construct(private RedisConsume $stream)
    {
    }

    /**
     * @param non-empty-string $identity
     */
    public function __invoke(Context $ctx, string $identity, Payload $payload, Throwable $exception): void
    {
        if ($exception instanceof WorkflowException) {
            $ctx->trigger(
                Event::MessageCorruptedError,
                new MessageErrorEvent($payload, $exception),
            );

            $this->dlq($ctx, $identity, $payload, $exception->getMessage());
            return;
        }

        if ($exception instanceof CancelledException) {
            $ctx->trigger(
                Event::MessageTimeoutCancellation,
                new MessageErrorEvent($payload, $exception),
            );

            $this->dlq($ctx, $identity, $payload, $exception->getMessage());
            return;
        }

        if ($ctx->maxExceededAttempts === 0) {
            $ctx->trigger(
                Event::MessageHandleError,
                new MessageErrorEvent($payload, $exception),
            );

            $this->dlq($ctx, $identity, $payload, $exception->getMessage());
            return;
        }

        $attempts = $this->attempts($this->stream, $ctx, $identity);
        $ctx->trigger(
            Event::MessageHandleError,
            new MessageErrorEvent($payload, $exception, $attempts),
        );

        if ($attempts >= $ctx->maxExceededAttempts) {
            $this->dlq(
                $ctx,
                $identity,
                $payload,
                sprintf(
                    '[%d] The number of attempts has been exceeded. %s',
                    $attempts,
                    $exception->getMessage(),
                ),
            );

            $ctx->setAck($identity, $payload->uuid);
            $ctx->sendAck();
            return;
        }

        $this->incrAttempt($ctx, $payload, $attempts);
    }

    private function dlq(Context $ctx, string $identity, Payload $payload, string $reason): void
    {
        try {
            $this->stream->addDLQ(
                [
                    'id' => $identity,
                    'reason' => $reason,
                    'uuid' => $this->copyData($ctx, $payload),
                    'target' => $payload->target,
                ],
            );
        } catch (Throwable $exception) {
            $ctx->trigger(
                Event::RuntimeException,
                new SystemExceptionEvent($exception),
            );
        }
    }

    /**
     * @return non-empty-string
     */
    private function copyData(Context $ctx, Payload $payload): string
    {
        $newUuid = preg_replace('/^\w{8}/', '0000000d', $payload->uuid);
        if (is_string($newUuid) === false || empty($newUuid)) {
            $newUuid = '0000000d' . $payload->uuid;
        }

        return $ctx->copyData($payload->uuid, $newUuid, 1800) ? $newUuid : $payload->uuid;
    }

    /**
     * @param positive-int $currentAttempt
     */
    private function incrAttempt(Context $ctx, Payload $payload, int $currentAttempt): void
    {
        $message = $ctx->getData($payload->uuid);
        if ($message === null || $message === '') {
            return;
        }

        try {
            $queueMessage = QueueMessage::makeFromMessage($message);
            $ctx->setData(
                $payload->uuid,
                QueueMessage::makeMessage(
                    $queueMessage->task,
                    $queueMessage->context->incrAttempt(++$currentAttempt),
                )
            );
        } catch (Throwable $exception) {
            $ctx->trigger(
                Event::RuntimeException,
                new SystemExceptionEvent($exception),
            );
        }
    }

    /**
     * @param non-empty-string $identity
     * @return positive-int
     */
    private function attempts(RedisConsume $command, Context $ctx, string $identity): int
    {
        /**
         * @param non-empty-string $identity
         * @return positive-int
         */
        $fn = static function (RedisConsume $command, Context $ctx, string $identity): int {
            /** @var non-empty-string $identity */
            try {
                $pending = $command->pending($identity);
            } catch (Throwable $exception) {
                $ctx->trigger(
                    Event::RuntimeException,
                    new SystemExceptionEvent($exception),
                );
                return 1;
            }

            return max(1, $pending['deliveryCount']);
        };

        /**
         * @phpstan-var positive-int
         */
        return async($fn(...), $command, $ctx, $identity)->await();
    }
}
