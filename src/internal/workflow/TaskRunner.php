<?php

declare(strict_types=1);

namespace kuaukutsu\poc\queue\stream\internal\workflow;

use Throwable;
use kuaukutsu\queue\core\handler\HandlerInterface;
use kuaukutsu\queue\core\QueueMessage;
use kuaukutsu\poc\queue\stream\exception\WorkflowException;
use kuaukutsu\poc\queue\stream\internal\stream\RedisStream;
use kuaukutsu\poc\queue\stream\internal\stream\RedisStreamGroup;
use kuaukutsu\poc\queue\stream\internal\stream\RedisString;
use kuaukutsu\poc\queue\stream\internal\Context;
use kuaukutsu\poc\queue\stream\internal\Payload;

/**
 * @note если задан обработчик исключений (tryCatch), считаем что отвественность за ошибки лежит на клиенте.
 * Иначе пробуем несколько раз выполнить, и перекидываем в DLQ.
 * @psalm-internal kuaukutsu\poc\queue\stream
 */
final readonly class TaskRunner
{
    public function __construct(
        private RedisString $string,
        private RedisStream $stream,
        private RedisStreamGroup $streamGroup,
        private HandlerInterface $handler,
    ) {
    }

    /**
     * @param non-empty-string $identity
     * @param non-negative-int $maxExceededAttempts
     * @return bool TRUE отправить ACK; FALSE не отправлять ACK
     */
    public function run(Context $ctx, string $identity, Payload $payload, int $maxExceededAttempts = 0): bool
    {
        $message = $this->string->get($payload->uuid);
        if ($message === null || $message === '') {
            $exception = new WorkflowException("[$payload->uuid] The payload message must not be empty.");
            if ($ctx->tryCatch('<NULL>', $exception)) {
                return true;
            }

            // The payload message is empty.
            return $this->pushDLQ($identity, $payload, $exception->getMessage());
        }

        try {
            $queueMessage = QueueMessage::makeFromMessage($message);
        } catch (Throwable $exception) {
            if ($ctx->tryCatch($message, $exception)) {
                return true;
            }

            // The payload message is corrupted.
            return $this->pushDLQ($identity, $payload, $exception->getMessage());
        }

        try {
            $this->handler->handle($queueMessage);
        } catch (Throwable $exception) {
            if ($ctx->tryCatch($message, $exception)) {
                return true;
            }

            return $maxExceededAttempts > 0
                && $this->isExceededAttempts($identity, $payload, $exception->getMessage());
        }

        return true;
    }

    private function pushDLQ(string $identity, Payload $payload, string $reason): bool
    {
        $newUuid = $this->copyPayload($payload);
        if ($newUuid === false) {
            return false;
        }

        try {
            $this->stream->dlq(
                [
                    'id' => $identity,
                    'reason' => $reason,
                    'uuid' => $newUuid,
                    'target' => $payload->target,
                ],
            );
        } catch (Throwable) {
            $this->string->del($newUuid);
            return false;
        }

        return true;
    }

    /**
     * @return non-empty-string|false
     */
    private function copyPayload(Payload $payload): string|false
    {
        $destination = preg_replace('/^\w{8}/', '0000000d', $payload->uuid);
        if (is_string($destination) === false || empty($destination)) {
            $destination = '0000000d' . $payload->uuid;
        }

        if ($this->string->copy($payload->uuid, $destination, 900)) {
            return $destination;
        }

        return false;
    }

    /**
     * @param non-empty-string $identity
     */
    private function isExceededAttempts(string $identity, Payload $payload, string $previousReason): bool
    {
        $maxExceededAttempts = 3;

        try {
            $pendingState = $this->streamGroup->pending($identity);
        } catch (Throwable) {
            return false;
        }

        return $pendingState['deliveryCount'] >= $maxExceededAttempts
            && $this->pushDLQ(
                $identity,
                $payload,
                sprintf(
                    '[%d] The number of attempts has been exceeded. %s',
                    $pendingState['deliveryCount'],
                    $previousReason,
                ),
            );
    }
}
