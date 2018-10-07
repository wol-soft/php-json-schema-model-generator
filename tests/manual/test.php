<?php

use PHPModelGenerator\Generator;
use PHPModelGenerator\Model\GeneratorConfiguration;

require_once __DIR__ . '/../../vendor/autoload.php';

$generator = new Generator((new GeneratorConfiguration())
    ->setNamespacePrefix('\\ManualSchema')
    ->setImmutable(false)
    ->setPrettyPrint(true)
);

try {
    $di = new RecursiveDirectoryIterator(__DIR__ . '/result', FilesystemIterator::SKIP_DOTS);
    $ri = new RecursiveIteratorIterator($di, RecursiveIteratorIterator::CHILD_FIRST);

    foreach ($ri as $file ) {
        $file->isDir() ?  rmdir($file) : unlink($file);
    }

    $generator->generateModels(__DIR__ . '/schema', __DIR__ . '/result');
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
