<?php

/** @noinspection PhpRedundantCatchClauseInspection */

declare(strict_types=1);

namespace kuaukutsu\poc\queue\stream\internal\workflow;

use Closure;
use kuaukutsu\poc\queue\stream\internal\stream\RedisConsume;
use kuaukutsu\poc\queue\stream\internal\Context;
use kuaukutsu\poc\queue\stream\internal\Payload;

use function Amp\async;

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
        static $action = $this->action->run(...);

        /**
         * @psalm-var Closure(Context, string, Payload, callable, callable): void $workflow
         */
        static $workflow = static function (
            Context $ctx,
            string $identity,
            Payload $payload,
            callable $action,
            callable $catch,
        ): void {
            /** @var non-empty-string $identity */
            if ($action($ctx, $identity, $payload, $catch(...))) {
                $ctx->setAck($identity, $payload->uuid);
            }
        };

        $lastIdentity = '0-0';
        while (true) {
            $list = [];
            foreach ($this->autoclaim($ctx, $this->stream, $lastIdentity) as $identity => $payload) {
                $list[] = async($workflow(...), $ctx, $identity, $payload, $action, $catch);
                $lastIdentity = $identity;
            }

            if ($list === []) {
                break;
            }

            $ctx->awaitFutures($list);
            $ctx->sendAck();
        }
    }

    /**
     * @return iterable<non-empty-string, Payload>
     */
    private function autoclaim(Context $ctx, RedisConsume $command, string $lastIdentity): iterable
    {
        /**
         * @psalm-var Closure(RedisConsume, string): iterable<non-empty-string, Payload> $fn
         * @phpstan-ignore varTag.nativeType
         */
        static $fn = static function (RedisConsume $command, string $lastIdentity): iterable {
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
         * @var iterable<non-empty-string, Payload>
         */
        return $ctx->awaitFuture(async($fn(...), $command, $lastIdentity)) ?? [];
    }
}
