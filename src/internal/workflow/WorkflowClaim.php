<?php

declare(strict_types=1);

namespace kuaukutsu\poc\queue\stream\internal\workflow;

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
            if ($this->action->run($ctx, $identity, $payload, 3)) {
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
}
