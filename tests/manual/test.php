<?php

use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\SchemaProvider\RecursiveDirectoryProvider;

require_once __DIR__ . '/../../vendor/autoload.php';

$generator = new ModelGenerator((new GeneratorConfiguration())
    ->setNamespacePrefix('\\ManualSchema')
    ->setImmutable(false)
);

$generator
    ->generateModelDirectory(__DIR__ . '/result')
    ->generateModels(new RecursiveDirectoryProvider(__DIR__ . '/schema'), __DIR__ . '/result');
