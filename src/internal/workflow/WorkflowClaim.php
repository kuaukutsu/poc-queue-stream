<?php

declare(strict_types=1);

namespace kuaukutsu\poc\queue\stream\internal\workflow;

use Throwable;
use kuaukutsu\poc\queue\stream\internal\Context;
use kuaukutsu\poc\queue\stream\internal\Payload;
use kuaukutsu\poc\queue\stream\internal\stream\RedisStreamGroup;

use function Amp\async;

/**
 * @psalm-internal kuaukutsu\poc\queue\stream
 */
final readonly class WorkflowClaim
{
    public function __construct(
        private TaskRunner $action,
        private RedisStreamGroup $stream,
    ) {
    }

    public function __invoke(Context $ctx): void
    {
        foreach ($this->autoclaim($this->stream) as $identity => $payload) {
            if ($this->action->run($ctx, $identity, $payload)) {
                $ctx->setAck($identity, $payload->uuid);
            } elseif ($this->isExceededAttempts($identity, $payload)) {
                $ctx->setAck($identity, $payload->uuid);
            }
        }

        $ctx->sendAck();
    }

    /**
     * @return iterable<non-empty-string, Payload>
     */
    private function autoclaim(RedisStreamGroup $command): iterable
    {
        $fn = static function (RedisStreamGroup $command): iterable {
            $batch = $command->autoclaim();
            if ($batch === null) {
                return [];
            }

            /**
             * @var array<array<non-empty-string, string[]>> $src
             */
            $src = $batch[1] ?? [];
            foreach ($src as [$identity, $payload]) {
                $data = [];
                foreach (array_chunk($payload, 2) as [$k, $v]) {
                    $data[$k] = $v;
                }

                yield $identity => Payload::fromPayload($data);
            }

            return [];
        };

        /**
         * @var iterable<non-empty-string, Payload>
         */
        return async($fn(...), $command)->await();
    }

    /**
     * @param non-empty-string $identity
     */
    private function isExceededAttempts(string $identity, Payload $payload): bool
    {
        $maxExceededAttempts = 2;

        try {
            $pendingState = $this->stream->pending($identity);
        } catch (Throwable) {
            return false;
        }

        return $pendingState['deliveryCount'] >= $maxExceededAttempts
            && $this->action->pushDLQ($identity, $payload, 'The number of attempts has been exceeded.');
    }
}
