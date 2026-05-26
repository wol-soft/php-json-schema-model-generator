<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Issues\Issue;

use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\BuilderClassPostProcessor;
use PHPModelGenerator\SchemaProcessor\PostProcessor\EnumPostProcessor;
use PHPModelGenerator\Tests\Issues\AbstractIssueTestCase;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;

class Issue137Test extends AbstractIssueTestCase
{
    public function testOneOfWithConflictingPropertyConstraintsCausesSerializationError(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $modelGenerator): void {
            $modelGenerator->addPostProcessor(new EnumPostProcessor(
                implode(DIRECTORY_SEPARATOR, [sys_get_temp_dir(), 'PHPModelGeneratorTest', 'Enum']),
                'Enum',
                true,
            ));
            $modelGenerator->addPostProcessor(new BuilderClassPostProcessor());
        };


        $className = $this->generateClassFromFile('oneOfWithArrayDef.json').'Builder';
        $builder = new $className();

        $method = new ReflectionMethod($builder, 'setStatusFilter');

        $typesByParam = [];

        foreach ($method->getParameters() as $param) {
            $type = $param->getType();

            if ($type === null) {
                $typesByParam[$param->getName()] = [];
                continue;
            }

            if ($type instanceof ReflectionUnionType) {
                $typesByParam[$param->getName()] = array_map(
                    fn(ReflectionNamedType $t) => $t->getName(),
                    $type->getTypes()
                );
                continue;
            }

            $typesByParam[$param->getName()] = [$type->getName()];
        }

        self::assertNotEquals(['statusFilter' => ['array', 'string', 'null']], $typesByParam, 'the SINGLE enum type is missing in the array here');
    }
}
