<?php

use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\Model\GeneratorConfiguration;

require_once __DIR__ . '/../../vendor/autoload.php';

$generator = new ModelGenerator((new GeneratorConfiguration())
    ->setNamespacePrefix('\\ManualSchema')
    ->setImmutable(false)
    ->setPrettyPrint(true)
);

try {
    $generator
        ->generateModelDirectory(__DIR__ . '/result')
        ->generateModels(__DIR__ . '/schema', __DIR__ . '/result');
} catch (Exception $e) {
    echo get_class($e) . "\n";
    echo $e->getMessage() . "\n";
    echo $e->getTraceAsString();

    if ($e->getPrevious()) {
        echo "\n\n\n";
        echo get_class($e->getPrevious()) . "\n";
        echo $e->getPrevious()->getMessage() . "\n";
        echo $e->getPrevious()->getTraceAsString();
    }
}
