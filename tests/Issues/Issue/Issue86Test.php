<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Issues\Issue;

use PHPModelGenerator\Exception\Dependency\InvalidSchemaDependencyException;
use PHPModelGenerator\Exception\Generic\InvalidTypeException;
use PHPModelGenerator\Exception\Object\RequiredValueException;
use PHPModelGenerator\Tests\Issues\AbstractIssueTestCase;

class Issue86Test extends AbstractIssueTestCase
{
    /**
     * @dataProvider validRefDataProvider
     */
    public function testDifferentRequiredConfigurationForReferencedObject(array $input, bool $implicitNull): void
    {
        $className = $this->generateClassFromFile('ref.json', implicitNull: $implicitNull);

        $object = new $className($input);
        $this->assertSame($input['required'], $object->getRequired());
        $this->assertSame($input['optional'] ?? null, $object->getOptional());
    }

    public function validRefDataProvider(): array
    {
        return [
            'both properties provided' => [['required' => 'Hello', 'optional' => 'World'], true],
            'optional property not provided' => [['required' => 'Hello'], false],
            'optional property null' => [['required' => 'Hello', 'optional' => null], true],
        ];
    }
    /**
     * @dataProvider invalidRefDataProvider
     */
    public function testDifferentRequiredConfigurationForReferencedObjectWithInvalidInput(
        array $input,
        bool $implicitNull,
        string $expectedException,
    ): void {
        $className = $this->generateClassFromFile('ref.json', implicitNull: $implicitNull);

        $this->expectException($expectedException);

        new $className($input);
    }

    public function invalidRefDataProvider(): array
    {
        return [
            'required property not provided' => [['optional' => 'World'], true, RequiredValueException::class],
            'optional property null - implicit null disabled' => [['required' => 'Hello', 'optional' => null], false, InvalidTypeException::class],
            'required property wrong type' => [['required' => 1, 'optional' => 'World'], true, InvalidTypeException::class],
            'optional property wrong type' => [['required' => 'Hello', 'optional' => 1], true, InvalidTypeException::class],
        ];
    }

    /**
     * @dataProvider validSchemaDependencyDataProvider
     */
    public function testDifferentSchemaDependenciesForReferencedObject(array $input): void
    {
        $className = $this->generateClassFromFile('schemaDependency.json');

        $object = new $className($input);
        $this->assertSame($input['property1'] ?? null, $object->getProperty1());
        $this->assertSame($input['property2'] ?? null, $object->getProperty2());
        $this->assertSame($input['property3'] ?? null, $object->getProperty3());
    }

    public function validSchemaDependencyDataProvider(): array
    {
        return [
            'No properties provided'        => [[]],
            'Property 3 without dependency' => [['property3' => true]],
            'Dependency Property 1'         => [['property1' => 'Hello', 'property3' => 'World']],
            'Dependency Property 2'         => [['property2' => 'Hello', 'property3' => 1]],
        ];
    }

    /**
     * @dataProvider invalidSchemaDependencyDataProvider
     */
    public function testDifferentSchemaDependenciesForReferencedObjectWithInvalidInput(array $input): void
    {
        $className = $this->generateClassFromFile('schemaDependency.json');

        $this->expectException(InvalidSchemaDependencyException::class);

        new $className($input);
    }

    public function invalidSchemaDependencyDataProvider(): array
    {
        return [
            'Dependency missing property 1'      => [['property1' => 'Hello']],
            'Dependency missing property 2'      => [['property2' => 'Hello']],
            'Dependency Property 1 invalid type' => [['property1' => 'Hello', 'property3' => 1]],
            'Dependency Property 2 invalid type' => [['property2' => 'Hello', 'property3' => 'World']],
            'Both dependencies required 1'       => [['property1' => 'Hello', 'property2' => 'Hello', 'property3' => 'World']],
            'Both dependencies required 2'       => [['property1' => 'Hello', 'property2' => 'Hello', 'property3' => 1]],
        ];
    }
}
