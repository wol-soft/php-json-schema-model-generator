<?php

use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\Model\GeneratorConfiguration;

require_once __DIR__ . '/../../vendor/autoload.php';

$generator = new ModelGenerator((new GeneratorConfiguration())
    ->setNamespacePrefix('\\ManualSchema')
    ->setImmutable(false)
    ->setPrettyPrint(true)
);

$generator
    ->generateModelDirectory(__DIR__ . '/result')
    ->generateModels(__DIR__ . '/schema', __DIR__ . '/result');

require_once __DIR__ . '/result/Example.php';

new ManualSchema\Example(['example' => 5]);
