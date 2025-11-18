<?php

declare(strict_types=1);

namespace kuaukutsu\poc\queue\stream\tests\stub;

use Override;
use kuaukutsu\queue\core\QueueContext;
use kuaukutsu\queue\core\TaskInterface;

final readonly class QueueHandlerStub implements TaskInterface
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
        $this->writer->print($this->id, $this->name, $context);
    }
}
