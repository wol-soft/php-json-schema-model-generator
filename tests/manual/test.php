<?php

use ManualSchema\Person;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\SchemaProcessor\PostProcessor\AdditionalPropertiesAccessorPostProcessor;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PatternPropertiesAccessorPostProcessor;
use PHPModelGenerator\SchemaProvider\RecursiveDirectoryProvider;

require_once __DIR__ . '/../../vendor/autoload.php';

$generator = new ModelGenerator((new GeneratorConfiguration())
    ->setNamespacePrefix('\\ManualSchema')
    ->setSerialization(true)
    ->setImmutable(false)
);

$generator
    ->addPostProcessor(new AdditionalPropertiesAccessorPostProcessor(true))
    ->addPostProcessor(new PatternPropertiesAccessorPostProcessor())
    ->generateModelDirectory(__DIR__ . '/result')
    ->generateModels(new RecursiveDirectoryProvider(__DIR__ . '/schema'), __DIR__ . '/result');

$p = new Person(['name' => 'Larry', 'S_Hello' => 'Hello', 'I_World' => 'x']);

print_r($p->getPatternProperties("StringProperties"));
print_r($p->getPatternProperties("StringPropertiesShort"));
print_r($p->getAdditionalProperties());

$p->setAdditionalProperty('S_World', '          Blablabla       ');
$p->setAge(100);
$p->setName('Hannes');
echo "\n############################\n\n";

print_r($p->getPatternProperties('StringProperties'));
print_r($p->getPatternProperties("StringPropertiesShort"));
print_r($p->getAdditionalProperties());

$p->removeAdditionalProperty('S_World');
echo "\n############################\n\n";

print_r($p->getPatternProperties('StringProperties'));
print_r($p->getPatternProperties("StringPropertiesShort"));
print_r($p->getAdditionalProperties());

$p->setAdditionalProperty('nonono', 'NOOOO');

print_r($p->getPatternProperties('StringProperties'));
print_r($p->getPatternProperties("StringPropertiesShort"));
print_r($p->getAdditionalProperties());
