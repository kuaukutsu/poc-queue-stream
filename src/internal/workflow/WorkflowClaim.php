<?php

declare(strict_types=1);

namespace kuaukutsu\poc\queue\stream\internal\workflow;

use Amp\CancelledException;
use Amp\TimeoutCancellation;
use kuaukutsu\poc\queue\stream\event\Event;
use kuaukutsu\poc\queue\stream\event\SystemExceptionEvent;
use kuaukutsu\poc\queue\stream\internal\stream\RedisConsume;
use kuaukutsu\poc\queue\stream\internal\Context;
use kuaukutsu\poc\queue\stream\internal\Payload;

use function Amp\async;
use function Amp\Future\await;

/**
 * @psalm-internal kuaukutsu\poc\queue\stream
 */
final readonly class WorkflowClaim
{
    public function __construct(
        private TaskHandler $action,
        private RedisConsume $stream,
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

        $lastIdentity = '0-0';
        while (true) {
            $list = [];
            foreach ($this->autoclaim($this->stream, $lastIdentity) as $identity => $payload) {
                $list[] = async($workflow(...), $identity, $payload);
                $lastIdentity = $identity;
            }

            if ($list === []) {
                break;
            }

            try {
                await($list, new TimeoutCancellation(1800));
            } /** @noinspection PhpRedundantCatchClauseInspection */ catch (CancelledException $exception) {
                $ctx->trigger(
                    Event::TimeoutCancellation,
                    new SystemExceptionEvent($exception),
                );
            }

            $ctx->sendAck();
        }
    }

    /**
     * @return iterable<non-empty-string, Payload>
     */
    private function autoclaim(RedisConsume $command, string $lastIdentity): iterable
    {
        /**
         * @return iterable<non-empty-string, Payload>
         */
        $fn = static function (RedisConsume $command, string $lastIdentity): iterable {
            $batch = $command->autoclaim($lastIdentity);
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
         * @phpstan-var iterable<non-empty-string, Payload>
         */
        return async($fn(...), $command, $lastIdentity)->await();
    }
}
