<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\If_\ExplicitBoolCompareRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveEmptyClassMethodRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPromotedPropertyRector;
use Rector\Set\ValueObject\SetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withSets([
        SetList::PHP_82,
        SetList::DEAD_CODE,
        SetList::CODE_QUALITY,
        SetList::EARLY_RETURN,
    ])
    ->withSkip([
        // Properties are accessed via reflection in BalanceItem::resolveProperty()
        __DIR__.'/tests/Fixtures',
        ExplicitBoolCompareRector::class,
        // Properties are accessed via reflection in BalanceItem::resolveProperty()
        RemoveUnusedPromotedPropertyRector::class => [
            __DIR__.'/tests/Unit/BalanceItemTest.php',
        ],
        RemoveEmptyClassMethodRector::class => [
            __DIR__.'/tests/Unit/BalanceItemTest.php',
        ],
    ]);
