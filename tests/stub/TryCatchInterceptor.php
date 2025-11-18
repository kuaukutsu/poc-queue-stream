<?php

declare(strict_types=1);

namespace kuaukutsu\poc\queue\stream\tests\stub;

use Override;
use Throwable;
use kuaukutsu\queue\core\handler\HandlerInterface;
use kuaukutsu\queue\core\interceptor\InterceptorInterface;
use kuaukutsu\queue\core\QueueMessage;

/**
 * @note: Перехватываем исключение, обрабатываем, пишем в log, sentry, trace...
 * Если нужно в consume выполнить nack()/reply() что-то иное, то после обработки прокидываем ошибку наверх.
 * И обрабатываем через callable $catch(...).
 */
final readonly class TryCatchInterceptor implements InterceptorInterface
{
    #[Override]
    public function intercept(QueueMessage $message, HandlerInterface $handler): void
    {
        try {
            $handler->handle($message);
        } catch (Throwable $exception) {
            echo 'error: ' . $exception->getMessage() . PHP_EOL;
        }
    }
}
