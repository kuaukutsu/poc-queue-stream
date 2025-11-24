<?php

declare(strict_types=1);

namespace kuaukutsu\poc\queue\stream\tools;

use LogicException;
use Override;
use Symfony\Component\Console\Formatter\OutputFormatterInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final readonly class NullConsoleOutput implements ConsoleOutputInterface
{
    public function __construct(private OutputInterface $output)
    {
    }

    #[Override]
    public function getErrorOutput(): OutputInterface
    {
        return $this->output;
    }

    #[Override]
    public function setErrorOutput(OutputInterface $error): void
    {
        // do nothing
    }

    /**
     * @throws LogicException
     */
    #[Override]
    public function section(): never
    {
        throw new LogicException('nullable');
    }

    #[Override]
    public function write(iterable | string $messages, bool $newline = false, int $options = 0): void
    {
        $this->output->write($messages, $newline, $options);
    }

    #[Override]
    public function writeln(iterable | string $messages, int $options = 0): void
    {
        $this->output->writeln($messages, $options);
    }

    #[Override]
    public function setVerbosity(int $level): void
    {
        $this->output->setVerbosity($level);
    }

    #[Override]
    public function getVerbosity(): int
    {
        return $this->output->getVerbosity();
    }

    #[Override]
    public function isQuiet(): bool
    {
        return $this->output->isQuiet();
    }

    #[Override]
    public function isVerbose(): bool
    {
        return $this->output->isVerbose();
    }

    #[Override]
    public function isVeryVerbose(): bool
    {
        return $this->output->isVeryVerbose();
    }

    #[Override]
    public function isDebug(): bool
    {
        return $this->output->isDebug();
    }

    #[Override]
    public function setDecorated(bool $decorated): void
    {
        $this->output->setDecorated($decorated);
    }

    #[Override]
    public function isDecorated(): bool
    {
        return $this->output->isDecorated();
    }

    #[Override]
    public function setFormatter(OutputFormatterInterface $formatter): void
    {
        $this->output->setFormatter($formatter);
    }

    #[Override]
    public function getFormatter(): OutputFormatterInterface
    {
        return $this->output->getFormatter();
    }
}
