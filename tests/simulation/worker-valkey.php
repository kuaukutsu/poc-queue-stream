<?php

declare(strict_types=1);

use Amp\Redis\RedisConfig;
use DI\Container;
use kuaukutsu\poc\queue\stream\Builder;
use kuaukutsu\poc\queue\stream\internal\FactoryProxy;
use kuaukutsu\poc\queue\stream\tests\stub\QueueSchemaStub;

use function Amp\trapSignal;
use function kuaukutsu\poc\queue\stream\tests\argument;

require dirname(__DIR__) . '/bootstrap.php';

$schema = QueueSchemaStub::from((string)argument('schema', 'valkey'));
echo 'consumer run: ' . $schema->getRoutingKey() . PHP_EOL;

/** @noinspection PhpUnhandledExceptionInspection */
$builder = (new Builder(new FactoryProxy(new Container())))
    ->withConfig(
        RedisConfig::fromUri('tcp://valkey:6379')
    );

$consumer = $builder->buildConsumer($schema);
$consumer->consume();

/** @noinspection PhpUnhandledExceptionInspection */
trapSignal([SIGTERM, SIGINT]);
$consumer->disconnect();
exit(0);
