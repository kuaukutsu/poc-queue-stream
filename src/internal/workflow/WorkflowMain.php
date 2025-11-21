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
        private TaskHandler $action,
        private RedisStreamGroup $stream,
    ) {
    }

    public function __invoke(Context $ctx, WorkflowClaim $claim, WorkflowCatch $catch): void
    {
        $lastAction = time();
        $action = $this->action->run(...);
        $workflow = static function (string $identity, Payload $payload) use ($action, $catch, $ctx): void {
            /** @var non-empty-string $identity */
            if ($action($catch(...), $ctx, $identity, $payload)) {
                $ctx->setAck($identity, $payload->uuid);
            }
        };

        /** @phpstan-ignore while.alwaysTrue */
        while (true) {
            $list = [];
            foreach ($this->read($this->stream) as $identity => $payload) {
                $list[] = async($workflow(...), $identity, $payload);
            }

            try {
                await($list, new TimeoutCancellation(1800));
            } /** @noinspection PhpRedundantCatchClauseInspection */ catch (CancelledException) {
                // @fixme: logger
            }

            $ctx->sendAck();

            if ($list === [] && $lastAction < strtotime('-30 seconds')) {
                $ctx->defer(
                    static function (string $callbackId) use ($claim, $ctx, $catch): void {
                        $claim($ctx, $catch);
                        $ctx->done($callbackId);
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
            if ($batch === []) {
                return;
            }

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
