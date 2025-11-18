<?php

declare(strict_types=1);

namespace kuaukutsu\poc\queue\stream\exception;

use Throwable;
use RuntimeException;

final class WorkflowException extends RuntimeException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct(message: $message, previous: $previous);
    }
}
