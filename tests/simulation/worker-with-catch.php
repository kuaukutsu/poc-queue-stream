<?php

/**
 * Consumer.
 * @var Builder $builder bootstrap.php
 */

declare(strict_types=1);

use kuaukutsu\poc\queue\stream\Builder;
use kuaukutsu\poc\queue\stream\tests\stub\QueueSchemaStub;
use kuaukutsu\poc\queue\stream\tests\stub\TryCatchInterceptor;

use function Amp\trapSignal;
use function kuaukutsu\poc\queue\stream\tests\argument;

require dirname(__DIR__) . '/bootstrap.php';

$schema = QueueSchemaStub::from((string)argument('schema', 'low'));
echo 'consumer run: ' . $schema->getRoutingKey() . PHP_EOL;

$consumer = $builder
    ->withInterceptors(
        new TryCatchInterceptor(),
    )
    ->buildConsumer($schema);

$consumer->consume(
    static function (string $message, Throwable $exception): void {
        echo sprintf("data: %s\nerror: %s", $message, $exception->getMessage());
    }
);

/** @noinspection PhpUnhandledExceptionInspection */
trapSignal([SIGTERM, SIGINT]);
$consumer->disconnect();
exit(0);
