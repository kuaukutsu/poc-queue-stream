<?php

declare(strict_types=1);

use DI\Container;
use Amp\Redis\RedisConfig;
use kuaukutsu\poc\queue\stream\Builder;
use kuaukutsu\poc\queue\stream\internal\FactoryProxy;

require dirname(__DIR__) . '/vendor/autoload.php';

$container = new Container();
$builder = (new Builder(new FactoryProxy($container)))
    ->withConfig(
        RedisConfig::fromUri('tcp://redis:6379')
    );
