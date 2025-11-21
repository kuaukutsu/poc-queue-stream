<?php

declare(strict_types=1);

namespace kuaukutsu\poc\queue\stream;

final readonly class StreamOptions
{
    /**
     * @param non-empty-string $consumerName
     * @param non-empty-string $groupName
     * @param positive-int $batchSize
     * @param positive-int $minIdleTime milliseconds
     * @param positive-int $timeoutBlocking milliseconds
     * @param non-empty-string $templateDLQ
     */
    public function __construct(
        public string $consumerName,
        public string $groupName,
        public int $batchSize = 25,
        public int $minIdleTime = 60_000,
        public int $timeoutBlocking = 5_000,
        public string $templateDLQ = '%s:dlq',
    ) {
    }
}
