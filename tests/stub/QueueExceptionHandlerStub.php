<?php

declare(strict_types=1);

namespace kuaukutsu\poc\queue\stream\tests\stub;

use Override;
use LogicException;
use kuaukutsu\queue\core\QueueContext;
use kuaukutsu\queue\core\TaskInterface;

final readonly class QueueExceptionHandlerStub implements TaskInterface
{
    public function __construct(
        public int $id,
        public string $name,
        private TaskWriter $writer,
    ) {
    }

    #[Override]
    public function handle(QueueContext $context): void
    {
        if ($context->attempt === 1 && random_int(0, 1) === 1) {
            throw new LogicException('Random exception.');
        }

        $this->writer->print($this->id, $this->name, $context);
    }
}
