<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\TypeDeclaration\Rector\ClassMethod\ReturnTypeFromReturnNewRector;
use Rector\TypeDeclaration\Rector\Property\TypedPropertyFromAssignsRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPrivateMethodRector;
use Rector\CodingStyle\Rector\ClassConst\SplitGroupedClassConstantsRector;
use Rector\CodingStyle\Rector\ClassMethod\NewlineBeforeNewAssignSetRector;

return static function (RectorConfig $config): void {
    $config->paths([__DIR__ . '/src', __DIR__ . '/bin']);

    $config->rules([
        TypedPropertyFromAssignsRector::class,
        ReturnTypeFromReturnNewRector::class,
        RemoveUnusedPrivateMethodRector::class,
        SplitGroupedClassConstantsRector::class,
        NewlineBeforeNewAssignSetRector::class,
    ]);

    $config->phpVersion(80100);
};
