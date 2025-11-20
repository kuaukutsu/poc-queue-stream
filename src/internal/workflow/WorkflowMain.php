<?php

declare(strict_types=1);

namespace kuaukutsu\poc\queue\stream\internal\workflow;

use Amp\CancelledException;
use Amp\TimeoutCancellation;
use kuaukutsu\poc\queue\stream\internal\Context;
use kuaukutsu\poc\queue\stream\internal\Payload;
use kuaukutsu\poc\queue\stream\internal\stream\RedisStreamGroup;

use function Amp\async;
use function Amp\Future\await;

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
        $lastAction = time();
        $fn = static function (TaskRunner $action, Context $ctx, string $identity, Payload $payload): void {
            /** @var non-empty-string $identity */
            if ($action->run($ctx, $identity, $payload)) {
                $ctx->setAck($identity, $payload->uuid);
            }
        };

        /** @phpstan-ignore while.alwaysTrue */
        while (true) {
            $list = [];
            foreach ($this->read($this->stream) as $identity => $payload) {
                $list[] = async($fn(...), $this->action, $ctx, $identity, $payload);
            }

            try {
                await($list, new TimeoutCancellation(1800));
            } /** @noinspection PhpRedundantCatchClauseInspection */ catch (CancelledException) {
                // @fixme: logger
            }

            $ctx->sendAck();

            if ($list === [] && $lastAction < strtotime('-30 seconds')) {
                $ctx->defer(
                    static function () use ($workflowClaim, $ctx): void {
                        $workflowClaim($ctx);
                    }
                );

                $lastAction = time();
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
                return;
            }

            /**
             * @var array<array{0: non-empty-string, 1: string[]}> $src
             */
            $src = $batch[0][1] ?? [];
            foreach ($src as [$identity, $payload]) {
                $data = [];
                foreach (array_chunk($payload, 2) as [$k, $v]) {
                    $data[$k] = $v;
                }

                yield $identity => Payload::fromPayload($data);
            }
        };

        /**
         * @var iterable<non-empty-string, Payload>
         */
        return async($fn(...), $command)->await();
    }
}
