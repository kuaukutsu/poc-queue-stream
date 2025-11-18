<?php

/**
 * Publisher, make task with exception.
 * @var Builder $builder bootstrap.php
 */

declare(strict_types=1);

use kuaukutsu\poc\queue\stream\Builder;
use kuaukutsu\poc\queue\stream\tests\stub\QueueSchemaStub;
use kuaukutsu\queue\core\QueueTask;

use function kuaukutsu\poc\queue\stream\tests\argument;

require dirname(__DIR__) . '/bootstrap.php';

$schema = QueueSchemaStub::from((string)argument('schema', 'low'));
echo 'publisher run: ' . $schema->getRoutingKey() . PHP_EOL;

$publisher = $builder->buildPublisher();

$task = new QueueTask(
/** @phpstan-ignore argument.type */
    target: stdClass::class,
    arguments: [
        'id' => 1,
        'name' => 'test exception',
    ],
);

$publisher->push($schema, $task);
