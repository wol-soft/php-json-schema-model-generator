<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\EarlyReturn\Rector\If_\ChangeOrIfContinueToMultiContinueRector;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Rector\Php80\Rector\Ternary\GetDebugTypeRector;
use Rector\Php81\Rector\Array_\FirstClassCallableRector;
use Rector\Php83\Rector\ClassMethod\AddOverrideAttributeToOverriddenMethodsRector;
use Rector\PHPUnit\AnnotationsToAttributes\Rector\ClassMethod\DataProviderAnnotationToAttributeRector;
use Rector\PHPUnit\CodeQuality\Rector\Class_\PreferPHPUnitThisCallRector;
use Rector\PHPUnit\CodeQuality\Rector\Class_\YieldDataProviderRector;
use Rector\PHPUnit\CodeQuality\Rector\ClassMethod\DataProviderArrayItemsNewLinedRector;
use Rector\PHPUnit\PHPUnit100\Rector\Class_\StaticDataProviderClassMethodRector;

return RectorConfig::configure()
    ->withBootstrapFiles([__DIR__ . '/tests/bootstrap.php'])
    ->withAutoloadPaths([__DIR__ . '/vendor/autoload.php'])
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withPhpSets()
    ->withPreparedSets(
        deadCode: true,
        typeDeclarations: true,
        privatization: true,
        earlyReturn: true,
        phpunitCodeQuality: true,
    )
    ->withRules([
        ClassPropertyAssignToConstructorPromotionRector::class,
        DataProviderAnnotationToAttributeRector::class,
        StaticDataProviderClassMethodRector::class,
    ])
    ->withSkip([
        GetDebugTypeRector::class,
        ChangeOrIfContinueToMultiContinueRector::class,
        YieldDataProviderRector::class,
        DataProviderArrayItemsNewLinedRector::class,
        PreferPHPUnitThisCallRector::class,
        FirstClassCallableRector::class,
        AddOverrideAttributeToOverriddenMethodsRector::class,
    ]);
