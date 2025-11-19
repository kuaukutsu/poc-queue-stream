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

### Benchmark

```
make bench
```

```
PHPBench (1.4.3) running benchmarks...
with configuration file: /benchmark/phpbench.json
with PHP version 8.3.17, xdebug ✔, opcache ✔

\kuaukutsu\poc\queue\stream\benchmarks\PublisherRedisBench

    benchAsWhile............................I4 - Mo32.201ms (±5.32%)
    benchAsBatch............................I4 - Mo29.561ms (±1.30%)

\kuaukutsu\poc\queue\stream\benchmarks\PublisherValkeyBench

    benchAsWhile............................I4 - Mo31.663ms (±2.65%)
    benchAsBatch............................I4 - Mo30.349ms (±3.98%)

Subjects: 4, Assertions: 0, Failures: 0, Errors: 0
+----------------------+--------------+-----+------+-----+----------+----------+--------+
| benchmark            | subject      | set | revs | its | mem_peak | mode     | rstdev |
+----------------------+--------------+-----+------+-----+----------+----------+--------+
| PublisherRedisBench  | benchAsWhile |     | 10   | 5   | 1.983mb  | 32.201ms | ±5.32% |
| PublisherRedisBench  | benchAsBatch |     | 10   | 5   | 2.044mb  | 29.561ms | ±1.30% |
| PublisherValkeyBench | benchAsWhile |     | 10   | 5   | 1.983mb  | 31.663ms | ±2.65% |
| PublisherValkeyBench | benchAsBatch |     | 10   | 5   | 2.037mb  | 30.349ms | ±3.98% |
+----------------------+--------------+-----+------+-----+----------+----------+--------+
```
