<?php

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
final readonly class WorkflowMain
{
    public function __construct(
        private TaskHandler $action,
        private RedisConsume $stream,
    ) {
    }

    public function __invoke(Context $ctx, WorkflowClaim $claim, WorkflowCatch $catch): void
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

        $lastAction = time();

        /** @phpstan-ignore while.alwaysTrue */
        while (true) {
            $list = [];
            foreach ($this->read($ctx, $this->stream) as $identity => $payload) {
                $list[] = async($workflow(...), $ctx, $identity, $payload, $action, $catch);
            }

            if ($list === []) {
                $this->autoclaim($ctx, $claim, $catch, $lastAction);
                continue;
            }

            $ctx->awaitFutures($list);
            $ctx->sendAck();
        }
    }

    /**
     * @return iterable<non-empty-string, Payload>
     */
    private function read(Context $ctx, RedisConsume $command): iterable
    {
        /**
         * @psalm-var Closure(RedisConsume):iterable<non-empty-string, Payload> $fn
         * @phpstan-ignore varTag.nativeType
         */
        static $fn = static function (RedisConsume $command): iterable {
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
        return $ctx->awaitFuture(async($fn(...), $command)) ?? [];
    }

    private function autoclaim(Context $ctx, WorkflowClaim $claim, WorkflowCatch $catch, int &$lastAction): void
    {
        if ($lastAction < strtotime('-30 seconds')) {
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
