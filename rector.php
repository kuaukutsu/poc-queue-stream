<?php

/**
 * @noinspection PhpUnhandledExceptionInspection
 */

declare(strict_types=1);

use Rector\CodeQuality\Rector\Class_\InlineConstructorDefaultToPropertyRector;
use Rector\Php83\Rector\ClassMethod\AddOverrideAttributeToOverriddenMethodsRector;

return Rector\Config\RectorConfig::configure()
    ->withPaths(
        [
            __DIR__ . '/src',
            __DIR__ . '/tests',
        ]
    )
    ->withParallel()
    ->withCache('/tmp/var/rector')
    ->withPhpSets()
    ->withRules(
        [
            InlineConstructorDefaultToPropertyRector::class,
        ]
    )
    ->withConfiguredRule(
        AddOverrideAttributeToOverriddenMethodsRector::class,
        [
            'allow_override_empty_method' => true,
        ]
    );
