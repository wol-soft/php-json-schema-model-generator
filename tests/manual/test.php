<?php

use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\SchemaProcessor\PostProcessor\EnumPostProcessor;
use PHPModelGenerator\SchemaProvider\RecursiveDirectoryProvider;

require_once __DIR__ . '/../../vendor/autoload.php';

$generator = new ModelGenerator((new GeneratorConfiguration())
    ->setNamespacePrefix('\\ManualSchema\\Model')
    ->setImmutable(false)
);

$generator
    ->generateModelDirectory(__DIR__ . '/result/Model')
    ->addPostProcessor(new EnumPostProcessor(__DIR__ . '/result/Enum', '\\ManualSchema\\Enum'))
    ->generateModels(new RecursiveDirectoryProvider(__DIR__ . '/schema'), __DIR__ . '/result/Model');

$p = new \ManualSchema\Model\Person(['name' => 'Lawrence']);
var_export($p->getName());