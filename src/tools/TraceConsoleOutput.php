<?php

declare(strict_types=1);

namespace kuaukutsu\poc\queue\stream\tools;

use Override;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use kuaukutsu\poc\queue\stream\event\Event;
use kuaukutsu\poc\queue\stream\event\EventInterface;
use kuaukutsu\poc\queue\stream\event\EventSubscriberInterface;

final readonly class TraceConsoleOutput implements EventSubscriberInterface
{
    public function __construct(private ConsoleOutputInterface $output)
    {
    }

    #[Override]
    public function subscriptions(): array
    {
        $subscriptions = [];
        foreach (Event::cases() as $event) {
            $subscriptions[$event->name] = $this->trace(...);
        }

        /**
         * @var non-empty-array<string, callable(Event $name, EventInterface $event):void> $subscriptions
         * @phpstan-ignore varTag.nativeType
         */
        return $subscriptions;
    }

    public function trace(Event $name, EventInterface $event): void
    {
        $this->stdout(
            sprintf(
                '[%s] %s',
                $name->value,
                $event->getMessage(),
            )
        );
    }

    private function stdout(string $message): void
    {
        $this->output->writeln($message);
    }
}
