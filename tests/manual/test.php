<?php

use PHPModelGenerator\Generator;
use PHPModelGenerator\Model\GeneratorConfiguration;

require_once __DIR__ . '../../vendor/autoload.php';

$generator = new Generator((new GeneratorConfiguration())
    ->setNamespacePrefix('\\ManualSchema')
    ->setImmutable(false)
    ->setPrettyPrint(false)
);

try {
    $generator->generateModels(__DIR__ . '/schema', __DIR__ . '/result');
} catch (Exception $e) {
    echo get_class($e) . "\n";
    echo $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
