<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Symfony\CodeQuality\Rector\ClassMethod\ResponseReturnTypeControllerActionRector;
use Rector\Symfony\Set\SymfonyLevelSetList;
use Rector\Symfony\Set\SymfonySetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src/Entity',
        __DIR__ . '/src/Form',
    ])
    // Solo migra annotations de Symfony (Validator, ParamConverter, etc.) a
    // attributes PHP 8. NO toca @ORM\ ni @Gedmo\ — las entidades siguen con
    // annotations de Doctrine 2.x; cuando subamos a Doctrine ORM 3 las
    // migraremos en otra pasada con DoctrineSetList::ANNOTATIONS_TO_ATTRIBUTES.
    ->withSets([
        SymfonySetList::ANNOTATIONS_TO_ATTRIBUTES,
    ]);
