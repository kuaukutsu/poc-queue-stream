<?php

declare(strict_types=1);

namespace kuaukutsu\poc\queue\stream\internal\workflow;

use Throwable;
use Amp\CancelledException;
use kuaukutsu\poc\queue\stream\exception\WorkflowException;
use kuaukutsu\poc\queue\stream\internal\stream\RedisStreamGroup;
use kuaukutsu\poc\queue\stream\internal\Context;
use kuaukutsu\poc\queue\stream\internal\Payload;

use function Amp\async;

/**
 * @psalm-internal kuaukutsu\poc\queue\stream
 */
final readonly class WorkflowCatch
{
    public function __construct(private RedisStreamGroup $stream)
    {
    }

    /**
     * @param non-empty-string $identity
     */
    public function __invoke(Context $ctx, string $identity, Payload $payload, Throwable $exception): void
    {
        if ($exception instanceof WorkflowException) {
            $this->dlq($ctx, $identity, $payload, $exception->getMessage());
            return;
        }

        if ($exception instanceof CancelledException) {
            $this->dlq($ctx, $identity, $payload, $exception->getMessage());
            return;
        }

        if ($ctx->maxExceededAttempts === 0) {
            $this->dlq($ctx, $identity, $payload, $exception->getMessage());
            return;
        }

        $pending = $this->pending($this->stream, $identity);
        $attempts = $pending['deliveryCount'] ?? 0;
        if ($attempts >= $ctx->maxExceededAttempts) {
            $this->dlq(
                $ctx,
                $identity,
                $payload,
                sprintf(
                    '[%d] The number of attempts has been exceeded. %s',
                    $attempts,
                    $exception->getMessage(),
                ),
            );

            $ctx->setAck($identity, $payload->uuid);
            $ctx->sendAck();
        }
    }

    private function dlq(Context $ctx, string $identity, Payload $payload, string $reason): void
    {
        $newUuid = preg_replace('/^\w{8}/', '0000000d', $payload->uuid);
        if (is_string($newUuid) === false || empty($newUuid)) {
            $newUuid = '0000000d' . $payload->uuid;
        }

        if ($ctx->copyData($payload->uuid, $newUuid, 1800) === false) {
            return;
        }

        try {
            $this->stream->addDLQ(
                [
                    'id' => $identity,
                    'reason' => $reason,
                    'uuid' => $newUuid,
                    'target' => $payload->target,
                ],
            );
        } catch (Throwable) {
            $ctx->copyData($newUuid, $payload->uuid);
        }
    }

    /**
     * @return array{}|array{
     *       "consumer": string,
     *       "elapsedMilliseconds": int,
     *       "deliveryCount": int,
     *   }
     */
    public function pending(RedisStreamGroup $command, string $identity): array
    {
        /**
         * @param non-empty-string $identity
         */
        $fn = static function (RedisStreamGroup $command, string $identity): array {
            /** @var non-empty-string $identity */
            try {
                return $command->pending($identity);
            } catch (Throwable) {
                return [];
            }
        };

        /**
         * @var array{}|array{
         *        "consumer": string,
         *        "elapsedMilliseconds": int,
         *        "deliveryCount": int,
         *    }
         */
        return async($fn(...), $command, $identity)->await();
    }
}
