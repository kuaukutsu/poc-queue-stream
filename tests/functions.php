<?php

declare(strict_types=1);

namespace kuaukutsu\poc\queue\stream\tests;

function argument(string $name, string | int | null $default = null): string | int | null
{
    global $argv;

    foreach ($argv as $item) {
        if (is_string($item) && str_starts_with($item, '--')) {
            [$key, $value] = explode('=', ltrim($item, '-'));
            if ($key === $name) {
                return $value;
            }
        }
    }

    return $default;
}
