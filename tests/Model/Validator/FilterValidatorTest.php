<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Model\Validator;

use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\FilterValidator;
use PHPModelGenerator\PropertyProcessor\Filter\DateTimeFilter;
use PHPModelGenerator\PropertyProcessor\Filter\TrimFilter;
use PHPUnit\Framework\TestCase;

class FilterValidatorTest extends TestCase
{
    private function createProperty(): Property
    {
        return new Property('name', new PropertyType('string'), new JsonSchema('', ['type' => 'string']));
    }

    public function testWithoutExecutionReturnsANonExecutedCopyWhichStillDescribesTheFilter(): void
    {
        $filter = new TrimFilter();
        $validator = new FilterValidator(
            new GeneratorConfiguration(),
            $filter,
            $this->createProperty(),
            ['option' => 1],
        );

        $copy = $validator->withoutExecution();

        $this->assertNotSame($validator, $copy);
        $this->assertFalse($copy->isExecuted());
        $this->assertSame($filter, $copy->getFilter());
        $this->assertSame(['option' => 1], $copy->getFilterOptions());

        // The original stays untouched, other properties may still depend on it.
        $this->assertTrue($validator->isExecuted());
    }

    public function testNonExecutedTransformingFilterDoesNotInitializeTheTransformationState(): void
    {
        $validator = new FilterValidator(new GeneratorConfiguration(), new DateTimeFilter(), $this->createProperty());

        $this->assertSame('$transformationFailed = false;', $validator->getValidatorSetUp());
        $this->assertSame('', $validator->withoutExecution()->getValidatorSetUp());
    }
}
