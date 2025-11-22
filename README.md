## Обработка задач через внешнюю очередь Redis Stream

[![PHP Version Require](http://poser.pugx.org/kuaukutsu/poc-queue-redis/require/php)](https://packagist.org/packages/kuaukutsu/poc-queue-redis)
[![Latest Stable Version](https://poser.pugx.org/kuaukutsu/poc-queue-redis/v/stable)](https://packagist.org/packages/kuaukutsu/poc-queue-redis)
[![License](http://poser.pugx.org/kuaukutsu/poc-queue-redis/license)](https://packagist.org/packages/kuaukutsu/poc-queue-redis)
[![Psalm Level](https://shepherd.dev/github/kuaukutsu/poc-queue-redis/level.svg)](https://shepherd.dev/github/kuaukutsu/poc-queue-redis)
[![Psalm Type Coverage](https://shepherd.dev/github/kuaukutsu/poc-queue-redis/coverage.svg)](https://shepherd.dev/github/kuaukutsu/poc-queue-redis)

Очередь: **Redis** / **ValKey**

Драйвер для работы: **amphp/redis**, this package provides non-blocking access to Redis instances.

Дополнительно: support for **interceptors**,
which can be used to add functionality to the application without modifying the core code of the application.

### Installation

```shell
composer require kuaukutsu/poc-queue-stream
```

### Concept

#### push

- **XADD** — записываем только идентификаторы.
- **STRING** — полезную нагрузку записываем через `string.set` с коротким таймаутом, чтобы не было призраков.
- Идентификатор `stream.key` выбирается согласно указанной схеме (`SchemaInterface`).
- Если запись в `stream` завершилась ошибкой, то удаляем `payload` (`string.del`) и возвращаем ошибку.

Для корректной работы, прежде чем пушить задачи в очередь, необходимо:

- либо запустить подписчиков,
- либо создать группу (`XGROUP CREATE`) с подпиской на нужный стрим(ы).

#### consume

- Подписчик читает стрим через **XREADGROUP**, пробует получить сообщение из хранилища (`string.get`) и выполнить задачу.
- Если сообщения в хранилище нет, то выходим с ошибкой, стрим переносим в dead letter queue (DLQ).
- Если сообщение получено, но выполнение завершилось ошибкой, отправляем на повторный круг — точнее, оставляем в очереди (PEL); свободный консумер получит это сообщение позже через **XAUTOCLAIM** и попробует выполнить ещё раз. Таких попыток по умолчанию будет 2; после третьей попытки сообщение переносим в DLQ.
- Если для консумера задан обработчик исключений (`catch exception`), то обработка ошибок лежит на клиентском ПО, т. е. механизм DLQ работать не будет.

Сомнительно, но окей (?):

- Прочитанные сообщения «акаются» (**ACK**) пачками; также вместе с командой ACK удаляются сообщения из хранилища.
  Альтернатива: акаем по завершению таска, с одной стороны убираем await и подтверждаем здесь и сразу, 
  с другой сокращаем количество запросов.

#### чтиво

- https://redis.io/docs/latest/develop/data-types/streams/
- https://habr.com/ru/articles/456270/

### example

`tests/simulation`

#### common

```php
// необходим для внедрения зависимостей в объект задачи
$container = new Container();
$builder = (new Builder(new FactoryProxy($container)))
    ->withConfig(
        RedisConfig::fromUri('tcp://redis:6379')
    );
    
// например из аргументов получаем схему - название очереди
$schema = QueueSchemaStub::from((string)argument('schema', 'low'));
```

#### publish

```php
// где-то в своём проекте (app), через Interface и Decorator заводим сервис PublisherInterface
$publisher = $builder->buildPublisher();

// создание отложенной задачи
$task = new QueueTask(
    target: QueueHandlerStub::class,
    arguments: [
        'id' => 1,
        'name' => 'test name',
    ],
);

$publisher->push($schema, $task);
```

Или массовая публикация
```php
$batch = [];
foreach (range(1, 100) as $item) {
    $batch[] = new QueueTask(
        target: QueueHandlerStub::class,
        arguments: [
            'id' => $item,
            'name' => 'test batch',
        ],
    );
}

$publisher->pushBatch($schema, $batch);
```

#### consume

consumer.php
```php
$consumer = $builder
    // задаём обработчик ошибок, если необходимо и не устраивает глобальный TryCatchInterceptor
    ->withCatch(
        static function (?string $message, Throwable $exception): void {
            echo sprintf("data: %s\nerror: %s", $message, $exception->getMessage());
        }
    )
    ->withInterceptors(
        new ArgumentsVerifyInterceptor(),
        new ExactlyOnceInterceptor(createRedisClient('redis://redis:6379')),
        new TryCatchInterceptor(),
    )
    ->buildConsumer();
    
$consumer->consume($schema);
trapSignal([SIGTERM, SIGINT]);
$consumer->disconnect();
exit(0);
```

### Benchmark

```
make bench
```

```
PHPBench (1.4.3) running benchmarks...
with configuration file: /benchmark/phpbench.json
with PHP version 8.3.17, xdebug ✔, opcache ✔

\kuaukutsu\poc\queue\stream\benchmarks\PublisherRedisBench

    benchAsWhile............................I4 - Mo29.287ms (±15.55%)
    benchAsBatch............................I4 - Mo17.785ms (±2.50%)

\kuaukutsu\poc\queue\stream\benchmarks\PublisherValkeyBench

    benchAsWhile............................I4 - Mo30.377ms (±8.72%)
    benchAsBatch............................I4 - Mo17.267ms (±3.16%)

Subjects: 4, Assertions: 0, Failures: 0, Errors: 0
+----------------------+--------------+-----+------+-----+----------+----------+---------+
| benchmark            | subject      | set | revs | its | mem_peak | mode     | rstdev  |
+----------------------+--------------+-----+------+-----+----------+----------+---------+
| PublisherRedisBench  | benchAsWhile |     | 10   | 5   | 2.053mb  | 29.287ms | ±15.55% |
| PublisherRedisBench  | benchAsBatch |     | 10   | 5   | 4.589mb  | 17.785ms | ±2.50%  |
| PublisherValkeyBench | benchAsWhile |     | 10   | 5   | 2.046mb  | 30.377ms | ±8.72%  |
| PublisherValkeyBench | benchAsBatch |     | 10   | 5   | 4.589mb  | 17.267ms | ±3.16%  |
+----------------------+--------------+-----+------+-----+----------+----------+---------+
```
