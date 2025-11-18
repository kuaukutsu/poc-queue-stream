<?php

/**
 * Publisher.
 * @var Builder $builder bootstrap.php
 */

declare(strict_types=1);

use kuaukutsu\poc\queue\stream\Builder;
use kuaukutsu\poc\queue\stream\tests\stub\QueueHandlerStub;
use kuaukutsu\poc\queue\stream\tests\stub\QueueSchemaStub;
use kuaukutsu\queue\core\QueueContext;
use kuaukutsu\queue\core\QueueTask;

use function kuaukutsu\poc\queue\stream\tests\argument;

require dirname(__DIR__) . '/bootstrap.php';

$schema = QueueSchemaStub::from((string)argument('schema', 'low'));
echo 'publisher run: ' . $schema->getRoutingKey() . PHP_EOL;

$publisher = $builder->buildPublisher();

$task = new QueueTask(
    target: QueueHandlerStub::class,
    arguments: [
        'id' => 1,
        'name' => 'test name',
    ],
);

$publisher->push($schema, $task);
//$publisher->push($schema, $task);
//$publisher->push($schema, $task);

// range
foreach (range(1, 10) as $item) {
    $publisher
        ->push(
            $schema,
            new QueueTask(
                target: QueueHandlerStub::class,
                arguments: [
                    'id' => $item,
                    'name' => 'test range',
                ],
            ),
            QueueContext::make($schema)->withExternal(['requestId' => $item])
        );
}
