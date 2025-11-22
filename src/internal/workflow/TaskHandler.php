<?php

declare(strict_types=1);

namespace kuaukutsu\poc\queue\stream\internal\workflow;

use Closure;
use Throwable;
use Amp\CancelledException;
use Amp\TimeoutCancellation;
use kuaukutsu\queue\core\handler\HandlerInterface;
use kuaukutsu\queue\core\QueueMessage;
use kuaukutsu\poc\queue\stream\exception\WorkflowException;
use kuaukutsu\poc\queue\stream\internal\Context;
use kuaukutsu\poc\queue\stream\internal\Payload;

use function Amp\async;

/**
 * @note если задан обработчик исключений (tryCatch), считаем что отвественность за ошибки лежит на клиенте.
 * Иначе пробуем несколько раз выполнить, и перекидываем в DLQ.
 * @psalm-internal kuaukutsu\poc\queue\stream
 */
final readonly class TaskHandler
{
    public function __construct(
        private HandlerInterface $handler,
        private ?Closure $catch,
    ) {
    }

    /**
     * @param Closure(Context, non-empty-string, Payload, Throwable):void $catchHandle
     * @param non-empty-string $identity
     * @return bool TRUE отправить ACK; FALSE не отправлять ACK
     */
    public function run(Closure $catchHandle, Context $context, string $identity, Payload $payload): bool
    {
        $message = $context->getData($payload->uuid);
        if ($message === null || $message === '') {
            $exception = new WorkflowException("[$payload->uuid] The payload message must not be empty.");
            if ($this->tryCatch($message, $exception)) {
                return true;
            }

            $this->handleCatch(
                $catchHandle,
                $context,
                $identity,
                $payload,
                $exception,
            );

            return true;
        }

        try {
            $queueMessage = QueueMessage::makeFromMessage($message);
        } catch (Throwable $exception) {
            if ($this->tryCatch($message, $exception)) {
                return true;
            }

            $this->handleCatch(
                $catchHandle,
                $context,
                $identity,
                $payload,
                new WorkflowException("[$payload->uuid] The payload message is corrupted.", $exception),
            );

            return true;
        }

        $cancellation = null;
        if ($queueMessage->context->timeout > 0) {
            $cancellation = new TimeoutCancellation($queueMessage->context->timeout);
        }

        try {
            async(
                $this->handler->handle(...),
                $queueMessage
            )->await($cancellation);
        } /** @noinspection PhpRedundantCatchClauseInspection */ catch (CancelledException $exception) {
            if ($this->tryCatch($message, $exception)) {
                return true;
            }

            $this->handleCatch(
                $catchHandle,
                $context,
                $identity,
                $payload,
                $exception,
            );

            return true;
        } catch (Throwable $exception) {
            if ($this->tryCatch($message, $exception)) {
                return true;
            }

            $this->handleCatch(
                $catchHandle,
                $context,
                $identity,
                $payload,
                $exception,
            );

            return false;
        }

        return true;
    }

    private function tryCatch(?string $message, Throwable $throwable): bool
    {
        if (is_callable($this->catch)) {
            call_user_func($this->catch, $message, $throwable);
            return true;
        }

        return false;
    }

    /**
     * @param Closure(Context, non-empty-string, Payload, Throwable):void $handle
     * @param non-empty-string $identity
     */
    private function handleCatch(
        Closure $handle,
        Context $context,
        string $identity,
        Payload $payload,
        Throwable $exception,
    ): void {
        $context->defer(
            static function (string $callbackId) use (
                $handle,
                $context,
                $identity,
                $payload,
                $exception,
            ): void {
                $handle($context, $identity, $payload, $exception);
                $context->done($callbackId);
            }
        );
    }
}
