<?php

namespace PHPModelGenerator\Tests\PropertyProcessor;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\PropertyProcessor\Property\ArrayProcessor;
use PHPModelGenerator\PropertyProcessor\Property\BooleanProcessor;
use PHPModelGenerator\PropertyProcessor\Property\IntProcessor;
use PHPModelGenerator\PropertyProcessor\Property\NullProcessor;
use PHPModelGenerator\PropertyProcessor\Property\NumberProcessor;
use PHPModelGenerator\PropertyProcessor\Property\ObjectProcessor;
use PHPModelGenerator\PropertyProcessor\Property\StringProcessor;
use PHPModelGenerator\PropertyProcessor\PropertyCollectionProcessor;
use PHPModelGenerator\PropertyProcessor\PropertyProcessorFactory;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use PHPUnit\Framework\TestCase;

/**
 * Class PropertyProcessorFactoryTest
 *
 * @package PHPModelGenerator\Tests\PropertyProcessor
 */
class PropertyProcessorFactoryTest extends TestCase
{
    /**
     * @dataProvider validPropertyProvider
     * @param string $type
     * @param string $expectedClass
     *
     * @throws SchemaException
     */
    public function testGetPropertyProcessor(string $type, string $expectedClass): void
    {
        $propertyProcessorFactory = new PropertyProcessorFactory();

        $propertyProcessor = $propertyProcessorFactory->getPropertyProcessor(
            $type,
            new PropertyCollectionProcessor(),
            new SchemaProcessor('', '', new GeneratorConfiguration())
        );

        $this->assertInstanceOf($expectedClass, $propertyProcessor);
    }

    /**
     * Provide valid properties which must result in a PropertyProcessor
     *
     * @return array
     */
    public function validPropertyProvider(): array
    {
        return [
            'array' => ['array', ArrayProcessor::class],
            'boolean' => ['boolean', BooleanProcessor::class],
            'int' => ['int', IntProcessor::class],
            'null' => ['null', NullProcessor::class],
            'number' => ['number', NumberProcessor::class],
            'object' => ['object', ObjectProcessor::class],
            'string' => ['string', StringProcessor::class]
        ];
    }

    /**
     * @throws SchemaException
     */
    public function testGetInvalidPropertyProcessorThrowsAnException()
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('Unsupported property type Hello');

        $propertyProcessorFactory = new PropertyProcessorFactory();

        $propertyProcessorFactory->getPropertyProcessor(
            'Hello',
            new PropertyCollectionProcessor(),
            new SchemaProcessor('', '', new GeneratorConfiguration())
        );
    }
}
