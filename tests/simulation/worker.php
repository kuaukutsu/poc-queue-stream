<?php

/**
 * Consumer.
 * @var Builder $builder bootstrap.php
 */

declare(strict_types=1);

use kuaukutsu\queue\core\interceptor\ArgumentsVerifyInterceptor;
use kuaukutsu\poc\queue\stream\interceptor\ExactlyOnceInterceptor;
use kuaukutsu\poc\queue\stream\tests\stub\QueueSchemaStub;
use kuaukutsu\poc\queue\stream\Builder;

use function Amp\Redis\createRedisClient;
use function Amp\trapSignal;
use function kuaukutsu\poc\queue\stream\tests\argument;

require dirname(__DIR__) . '/bootstrap.php';

$schema = QueueSchemaStub::from((string)argument('schema', 'low'));
echo 'consumer run: ' . $schema->getRoutingKey() . PHP_EOL;

$consumer = $builder
    ->withInterceptors(
        new ArgumentsVerifyInterceptor(),
        new ExactlyOnceInterceptor(createRedisClient('tcp://redis:6379')),
    )
    ->buildConsumer();

$consumer->consume($schema);
/** @noinspection PhpUnhandledExceptionInspection */
trapSignal([SIGTERM, SIGINT]);
$consumer->disconnect();
exit(0);
