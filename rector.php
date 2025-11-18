<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Class_\InlineConstructorDefaultToPropertyRector;
use Rector\Config\RectorConfig;
use Rector\Php83\Rector\ClassMethod\AddOverrideAttributeToOverriddenMethodsRector;
use Rector\Set\ValueObject\SetList;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->paths(
        [
            __DIR__ . '/src',
            __DIR__ . '/tests',
        ]
    );

    // register a single rule
    $rectorConfig->rule(InlineConstructorDefaultToPropertyRector::class);
    $rectorConfig->ruleWithConfiguration(
        AddOverrideAttributeToOverriddenMethodsRector::class,
        [
            'allow_override_empty_method' => true,
        ]
    );

    // define sets of rules
    $rectorConfig->sets([
        SetList::PHP_81,
        SetList::PHP_82,
        SetList::PHP_83,
    ]);
};
