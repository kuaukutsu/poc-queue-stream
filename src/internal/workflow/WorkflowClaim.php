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
final readonly class WorkflowClaim
{
    public function __construct(
        private TaskHandler $action,
        private RedisStreamGroup $stream,
    ) {
    }

    public function __invoke(Context $ctx, WorkflowCatch $catch): void
    {
        $action = $this->action->run(...);
        $workflow = static function (string $identity, Payload $payload) use ($action, $catch, $ctx): void {
            /** @var non-empty-string $identity */
            if ($action($catch(...), $ctx, $identity, $payload)) {
                $ctx->setAck($identity, $payload->uuid);
            }
        };

        while (true) {
            $list = [];
            foreach ($this->autoclaim($this->stream) as $identity => $payload) {
                $list[] = async($workflow(...), $identity, $payload);
            }

            if ($list === []) {
                $ctx->sendAck();
                break;
            }

            try {
                await($list, new TimeoutCancellation(1800));
            } /** @noinspection PhpRedundantCatchClauseInspection */ catch (CancelledException) {
                // @fixme: logger
            }

            $ctx->sendAck();
        }
    }

    /**
     * @return iterable<non-empty-string, Payload>
     */
    private function autoclaim(RedisStreamGroup $command): iterable
    {
        $fn = static function (RedisStreamGroup $command): iterable {
            $batch = $command->autoclaim();
            if ($batch === []) {
                return;
            }

            $src = $batch[1] ?? [];
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
