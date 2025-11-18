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
final readonly class WorkflowMain
{
    public function __construct(
        private TaskRunner $action,
        private RedisStreamGroup $stream,
    ) {
    }

    public function __invoke(Context $ctx, WorkflowClaim $workflowClaim): void
    {
        $lastQueueClaim = time();

        /** @phpstan-ignore while.alwaysTrue */
        while (true) {
            $canAutoClaim = true;
            foreach ($this->read($this->stream) as $identity => $payload) {
                if ($this->action->run($ctx, $identity, $payload)) {
                    $ctx->setAck($identity, $payload->uuid);
                    $canAutoClaim = false;
                }
            }

            $ctx->sendAck();

            // autoclaim
            if ($canAutoClaim && $lastQueueClaim < strtotime('-30 seconds')) {
                $ctx->defer(
                    static function () use ($workflowClaim, $ctx): void {
                        $workflowClaim($ctx);
                    }
                );

                $lastQueueClaim = time();
            }
        }
    }

    /**
     * @return iterable<non-empty-string, Payload>
     */
    private function read(RedisStreamGroup $command): iterable
    {
        $fn = static function (RedisStreamGroup $command): iterable {
            $batch = $command->read();
            if ($batch === null || $batch === []) {
                return [];
            }

            /**
             * @var array<array<non-empty-string, string[]>> $src
             */
            $src = $batch[0][1] ?? [];
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
