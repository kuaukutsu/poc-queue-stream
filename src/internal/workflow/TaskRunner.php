<?php

declare(strict_types=1);

namespace kuaukutsu\poc\queue\stream\internal\workflow;

use Throwable;
use kuaukutsu\queue\core\handler\HandlerInterface;
use kuaukutsu\queue\core\QueueMessage;
use kuaukutsu\poc\queue\stream\exception\WorkflowException;
use kuaukutsu\poc\queue\stream\internal\stream\RedisString;
use kuaukutsu\poc\queue\stream\internal\stream\RedisStream;
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
        private HandlerInterface $handler,
    ) {
    }

    /**
     * @param non-empty-string $identity
     * @return bool TRUE отправить ACK; FALSE не отправлять ACK
     */
    public function run(Context $ctx, string $identity, Payload $payload): bool
    {
        $message = $this->string->get($payload->uuid);
        if ($message === null || $message === '') {
            // нет смысла убрать в DLQ
            return $ctx->tryCatch(
                '<NULL>',
                new WorkflowException(
                    "[$identity:$payload->uuid] The payload message must not be empty."
                ),
            );
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
            return $ctx->tryCatch($message, $exception);
        }

        return true;
    }

    public function pushDLQ(string $identity, Payload $payload, string $reason): bool
    {
        $this->string->expire($payload->uuid);

        try {
            $this->stream->dlq(
                [
                    'id' => $identity,
                    'reason' => $reason,
                    ...$payload->toArray(),
                ],
            );
        } catch (Throwable) {
            return false;
        }

        return true;
    }
}
